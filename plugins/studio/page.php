<?php
// ============================================================================
// KutPod · Estudio · página del panel (/admin/studio) y endpoints AJAX
// ============================================================================
if (!defined('KUTPOD_VERSION')) { exit; }

studio_ensure_schema();
$studio_user = $current_user ?? kp_current_user();
if (!in_array($studio_user['role'] ?? '', ['owner', 'admin', 'editor', 'author'], true)) { http_response_code(403); exit('403'); }

$st_do = preg_replace('/[^a-z_]/', '', (string)($_GET['do'] ?? ''));
$st_id = (int)($_GET['id'] ?? 0);

// ── Utilidades ──────────────────────────────────────────────────────────────

function studio_json($data, int $code = 200): void {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store');
  $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
  $inline = $GLOBALS['studio_inline_job'] ?? null;
  if ($inline) { header('Connection: close'); header('Content-Length: ' . strlen($body)); }
  echo $body;
  if ($inline) {
    // Sin posibilidad de lanzar un proceso aparte (proc_open desactivado): responde y ejecuta aquí
    ignore_user_abort(true); @set_time_limit(0);
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request(); else { @ob_flush(); flush(); }
    studio_job_run((int)$inline);
  }
  exit;
}
function studio_fail(string $msg, int $code = 400): void { studio_json(['error' => $msg], $code); }

function studio_rmtree(string $dir): void {
  if (!is_dir($dir)) return;
  foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
    $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
  }
  @rmdir($dir);
}

function studio_upload_error(int $code): string {
  return match ($code) {
    UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'El archivo supera el límite de subida de PHP (upload_max_filesize=' . ini_get('upload_max_filesize')
      . ', post_max_size=' . ini_get('post_max_size') . '). Para grabaciones largas déjalo en la carpeta de entrada (storage/studio/inbox) y elígelo desde ahí.',
    UPLOAD_ERR_NO_FILE => 'No se recibió ningún archivo',
    default => 'Error al subir el archivo (código ' . $code . ')',
  };
}

const STUDIO_EXTS = ['wav','mp3','flac','m4a','aac','ogg','opus','wma','aif','aiff','mp4','mov','mkv','webm'];

function studio_inbox_list(): array {
  $out = [];
  foreach (glob(studio_inbox() . '/*') ?: [] as $f) {
    if (is_file($f) && in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), STUDIO_EXTS, true)) $out[] = ['name' => basename($f), 'bytes' => filesize($f)];
  }
  usort($out, fn($a, $b) => strcasecmp($a['name'], $b['name']));
  return $out;
}

function studio_engine_ready(): array {
  if (studio_opt('engine') === 'faster-whisper') {
    [$c, , ] = studio_exec_available() ? studio_run([studio_opt('python'), '-c', 'import faster_whisper']) : [1, '', ''];
    return ['ok' => $c === 0, 'label' => 'faster-whisper (' . studio_opt('fw_model') . ')', 'hint' => $c === 0 ? '' : 'Instala faster-whisper (pip install faster-whisper) o cambia de motor en Plugins → Estudio → Configurar.'];
  }
  $ok = trim((string)studio_opt('api_url')) !== '' && (trim((string)studio_opt('api_key')) !== '' || !str_contains((string)studio_opt('api_url'), 'api.openai.com'));
  return ['ok' => $ok, 'label' => 'API (' . studio_opt('api_model') . ')', 'hint' => $ok ? '' : 'Falta la clave de la API de transcripción en Plugins → Estudio → Configurar.'];
}

function studio_state(array $p): array {
  $pdir = studio_pdir((int)$p['id']);
  $podcast = kp_one("SELECT id, title, slug FROM podcasts WHERE id = ?", [$p['podcast_id']]);
  $tracks = array_map(fn($t) => [
    'id' => $t['id'], 'name' => $t['name'], 'role' => $t['role'] ?? 'voice', 'ready' => !empty($t['ready']),
    'pending' => !empty($t['src']) && empty($t['ready']), 'error' => $t['error'] ?? null,
    'duration' => $t['duration'] ?? null, 'offset' => $t['offset'] ?? 0, 'sync_conf' => $t['sync_conf'] ?? null, 'sync_ncc' => $t['sync_ncc'] ?? null,
    'sync_to' => $t['sync_to'] ?? '', 'manual_offset' => !empty($t['manual_offset']), 'gain_db' => $t['gain_db'] ?? 0,
    'transcribed' => !empty($t['transcribed']), 'breaks' => $t['breaks'] ?? [], 'no_segments' => !empty($t['no_segments']),
  ], $p['tracks']);
  $music = [];
  foreach (['intro', 'outro'] as $k) $music[$k] = !empty($p['music'][$k]['name']) ? ['name' => $p['music'][$k]['name']] : null;
  $job = studio_active_job((int)$p['id']) ?: studio_last_job((int)$p['id']);
  return [
    'id' => (int)$p['id'], 'title' => $p['title'], 'status' => $p['status'], 'podcast' => $podcast,
    'tracks' => $tracks, 'music' => $music, 'settings' => $p['settings'],
    'job' => $job ? ['id' => (int)$job['id'], 'type' => $job['type'], 'status' => $job['status'], 'progress' => (float)$job['progress'], 'message' => $job['message'],
                'result' => $job['result_json'] ? json_decode($job['result_json'], true) : null] : null,
    'result' => $p['result'], 'has_preview' => is_file($pdir . '/preview.mp3'), 'episode_id' => $p['episode_id'],
    'engine' => studio_engine_ready(), 'inbox' => studio_inbox_list(),
    'limits' => ['upload' => ini_get('upload_max_filesize'), 'post' => ini_get('post_max_size')],
    'duration' => studio_duration($p),
    'transcribed' => count(array_filter($p['tracks'], fn($t) => !empty($t['transcribed']))),
  ];
}

function studio_stream_file(string $file, string $mime, ?string $downloadName = null): void {
  if (!is_file($file)) { http_response_code(404); exit('No existe'); }
  while (ob_get_level()) ob_end_clean();
  $size = filesize($file); $start = 0; $end = $size - 1;
  header('Accept-Ranges: bytes');
  header('Content-Type: ' . $mime);
  header('Cache-Control: private, max-age=0, must-revalidate');
  header('Last-Modified: ' . gmdate('D, d M Y H:i:s', filemtime($file)) . ' GMT');
  if ($downloadName) header('Content-Disposition: attachment; filename="' . str_replace('"', '', $downloadName) . '"');
  if (!empty($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)) {
    if ($m[1] !== '') { $start = (int)$m[1]; if ($m[2] !== '') $end = min($end, (int)$m[2]); }
    elseif ($m[2] !== '') { $start = max(0, $size - (int)$m[2]); }
    if ($start > $end || $start >= $size) { http_response_code(416); header("Content-Range: bytes */$size"); exit; }
    http_response_code(206);
    header("Content-Range: bytes $start-$end/$size");
  }
  header('Content-Length: ' . ($end - $start + 1));
  $fh = fopen($file, 'rb'); fseek($fh, $start);
  $left = $end - $start + 1;
  while ($left > 0 && !feof($fh)) { $chunk = fread($fh, min(1048576, $left)); echo $chunk; $left -= strlen($chunk); flush(); }
  fclose($fh);
  exit;
}

// ── Endpoints ───────────────────────────────────────────────────────────────

if ($st_do !== '') {
  session_write_close();
  $isPost = $_SERVER['REQUEST_METHOD'] === 'POST';

  if ($st_do === 'create') {
    if (!$isPost) studio_fail('Método no permitido', 405);
    $title = trim((string)($_POST['title'] ?? ''));
    $podId = (int)($_POST['podcast_id'] ?? 0);
    if ($title === '') studio_fail('Pon un título al proyecto');
    if (!$podId || !kp_one("SELECT 1 FROM podcasts WHERE id = ?", [$podId]) || !kp_can_edit_podcast($podId, $studio_user)) studio_fail('Podcast no válido', 403);
    kp_exec("INSERT INTO studio_projects (podcast_id, title, created_by) VALUES (?,?,?)", [$podId, $title, $studio_user['id']]);
    $newId = (int)kp_db()->lastInsertId();
    // La música de entradilla/salida por defecto del podcast (biblioteca) se copia al proyecto
    $music = [];
    foreach (['intro', 'outro'] as $k) {
      foreach (glob(studio_library($podId) . "/$k.*") ?: [] as $lf) {
        $dst = studio_pdir($newId, 'music') . '/' . $k . '.' . pathinfo($lf, PATHINFO_EXTENSION);
        if (copy($lf, $dst)) { $music[$k] = ['file' => basename($dst), 'name' => (@file_get_contents(studio_library($podId) . "/$k.name") ?: basename($lf))]; }
        break;
      }
    }
    if ($music) studio_update_project($newId, function (&$p) use ($music) { $p['music'] = $music; });
    studio_json(['id' => $newId]);
  }

  // A partir de aquí, todo requiere un proyecto existente y permisos sobre su podcast
  $proj = $st_id ? studio_project($st_id) : null;
  if (!$proj) studio_fail('Proyecto no encontrado', 404);
  if (!studio_can_access($proj, $studio_user)) studio_fail('Sin permisos sobre este podcast', 403);
  $pid = (int)$proj['id'];
  $busy = studio_active_job($pid);

  switch ($st_do) {

    case 'status':
      studio_json(studio_state($proj));

    case 'transcript': {
      $words = studio_words($proj);
      $plan = studio_plan($proj, $words);
      studio_json([
        'words' => array_map(fn($w) => [$w['k'], $w['w'], $w['s'], $w['e'], $w['spk'], $w['kind']], $words),
        'speakers' => array_map(fn($t) => $t['name'], studio_voice_tracks($proj)),
        'deleted' => $proj['cuts']['deleted'],
        'plan' => ['cut' => $plan['cut'], 'out' => $plan['duration_out'], 'src' => $plan['duration_src'], 'stats' => $plan['stats']],
      ]);
    }

    case 'cuts': {
      if (!$isPost) studio_fail('Método no permitido', 405);
      $del = json_decode((string)($_POST['data'] ?? '[]'), true);
      if (!is_array($del)) studio_fail('Datos no válidos');
      $del = array_values(array_unique(array_filter($del, fn($k) => is_string($k) && preg_match('/^t\d+:\d+$/', $k))));
      $proj = studio_update_project($pid, function (&$p) use ($del) { $p['cuts']['deleted'] = $del; });
      $plan = studio_plan($proj);
      studio_json(['plan' => ['cut' => $plan['cut'], 'out' => $plan['duration_out'], 'src' => $plan['duration_src'], 'stats' => $plan['stats']]]);
    }

    case 'audio':
      studio_stream_file(studio_pdir($pid) . '/preview.mp3', 'audio/mpeg');

    case 'file': {
      $name = (string)($_GET['name'] ?? '');
      if (!preg_match('/^[A-Za-z0-9_-]+\.(mp3|vtt|json|mp4)$/', $name)) studio_fail('Archivo no válido');
      $mimes = ['mp3' => 'audio/mpeg', 'vtt' => 'text/vtt; charset=utf-8', 'json' => 'application/json', 'mp4' => 'video/mp4'];
      $ext = pathinfo($name, PATHINFO_EXTENSION);
      $dl = isset($_GET['download']) ? (kp_slugify($proj['title']) . '-' . $name) : null;
      studio_stream_file(studio_pdir($pid, 'render') . '/' . $name, $mimes[$ext], $dl);
    }

    case 'track_add': {
      if (!$isPost) studio_fail('Método no permitido', 405);
      if ($busy) studio_fail('Hay un proceso en marcha; espera a que termine.');
      $added = [];
      $newTrack = function (string $srcName, string $label, bool $inbox) use (&$proj, &$added) {
        $n = 1; $ids = array_column($proj['tracks'], 'id');
        while (in_array("t$n", $ids, true)) $n++;
        $t = ['id' => "t$n", 'name' => $label, 'role' => 'voice', 'src' => $srcName, 'src_inbox' => $inbox, 'offset' => 0];
        $proj['tracks'][] = $t; $added[] = $t;
      };
      $dir = studio_pdir($pid, 'src');
      foreach (['files', 'file'] as $key) {
        if (empty($_FILES[$key])) continue;
        $f = $_FILES[$key];
        $names = (array)$f['name']; $tmps = (array)$f['tmp_name']; $errs = (array)$f['error'];
        foreach ($names as $i => $nm) {
          if ($errs[$i] !== UPLOAD_ERR_OK) studio_fail(studio_upload_error((int)$errs[$i]));
          $ext = strtolower(pathinfo($nm, PATHINFO_EXTENSION));
          if (!in_array($ext, STUDIO_EXTS, true)) studio_fail("Formato no admitido: .$ext");
          $safe = 'up' . bin2hex(random_bytes(3)) . '_' . studio_safe_name($nm);
          if (!move_uploaded_file($tmps[$i], "$dir/$safe")) studio_fail('No se pudo guardar el archivo (¿permisos en storage/studio?)', 500);
          $newTrack($safe, pathinfo($nm, PATHINFO_FILENAME), false);
        }
      }
      foreach ((array)($_POST['inbox'] ?? []) as $nm) {
        $nm = basename((string)$nm);
        if (!is_file(studio_inbox() . '/' . $nm) || !in_array(strtolower(pathinfo($nm, PATHINFO_EXTENSION)), STUDIO_EXTS, true)) studio_fail("No existe en la carpeta de entrada: $nm");
        $newTrack($nm, pathinfo($nm, PATHINFO_FILENAME), true);
      }
      if (!$added) studio_fail('No se recibió ningún audio');
      studio_update_project($pid, function (&$p) use ($proj) { $p['tracks'] = $proj['tracks']; });
      $job = studio_job_create($pid, 'ingest');
      if (!studio_job_spawn($job)) $GLOBALS['studio_inline_job'] = $job;
      studio_json(['added' => count($added), 'job' => $job]);
    }

    case 'track_update': {
      if (!$isPost) studio_fail('Método no permitido', 405);
      $tid = (string)($_POST['tid'] ?? '');
      studio_update_project($pid, function (&$p) use ($tid) {
        foreach ($p['tracks'] as &$t) {
          if ($t['id'] !== $tid) continue;
          if (isset($_POST['name'])) $t['name'] = mb_substr(trim((string)$_POST['name']), 0, 60) ?: $t['name'];
          if (isset($_POST['role']) && in_array($_POST['role'], ['voice', 'reference'], true)) $t['role'] = $_POST['role'];
          if (isset($_POST['gain_db'])) $t['gain_db'] = max(-20, min(20, (float)$_POST['gain_db']));
          if (isset($_POST['sync_to'])) {
            $to = (string)$_POST['sync_to'];
            $t['sync_to'] = ($to !== $tid && in_array($to, array_column($p['tracks'], 'id'), true)) ? $to : '';
          }
          if (isset($_POST['offset'])) { $t['offset'] = round(max(0, min(7200, (float)$_POST['offset'])), 3); $t['manual_offset'] = true; $t['sync_conf'] = null; }
          if (!empty($_POST['auto'])) $t['manual_offset'] = false;
          if (isset($_POST['no_segments'])) $t['no_segments'] = (bool)(int)$_POST['no_segments'];
        }
      });
      studio_json(['ok' => true]);
    }

    case 'track_remove': {
      if (!$isPost) studio_fail('Método no permitido', 405);
      if ($busy) studio_fail('Hay un proceso en marcha; espera a que termine.');
      $tid = (string)($_POST['tid'] ?? '');
      if (!preg_match('/^t\d+$/', $tid)) studio_fail('Pista no válida');
      studio_update_project($pid, function (&$p) use ($tid) {
        $p['tracks'] = array_values(array_filter($p['tracks'], fn($t) => $t['id'] !== $tid));
        $p['cuts']['deleted'] = array_values(array_filter($p['cuts']['deleted'], fn($k) => !str_starts_with($k, $tid . ':')));
      });
      foreach (['tracks' => ['wav', 'env', 'raw.wav', 'raw.env'], 'transcripts' => ['json']] as $sub => $exts) foreach ($exts as $x) @unlink(studio_pdir($pid, $sub) . "/$tid.$x");
      studio_json(['ok' => true]);
    }

    case 'music_set': {
      if (!$isPost) studio_fail('Método no permitido', 405);
      $kind = $_POST['kind'] ?? '';
      if (!in_array($kind, ['intro', 'outro'], true)) studio_fail('Tipo no válido');
      $dir = studio_pdir($pid, 'music');
      foreach (glob("$dir/$kind.*") ?: [] as $old) @unlink($old);
      $entry = null;
      if (!empty($_FILES['file'])) {
        if ($_FILES['file']['error'] !== UPLOAD_ERR_OK) studio_fail(studio_upload_error((int)$_FILES['file']['error']));
        $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, STUDIO_EXTS, true)) studio_fail("Formato no admitido: .$ext");
        move_uploaded_file($_FILES['file']['tmp_name'], "$dir/$kind.$ext");
        $entry = ['file' => "$kind.$ext", 'name' => basename($_FILES['file']['name'])];
      } elseif (!empty($_POST['inbox'])) {
        $nm = basename((string)$_POST['inbox']); $src = studio_inbox() . '/' . $nm;
        $ext = strtolower(pathinfo($nm, PATHINFO_EXTENSION));
        if (!is_file($src) || !in_array($ext, STUDIO_EXTS, true)) studio_fail('No existe en la carpeta de entrada');
        copy($src, "$dir/$kind.$ext");
        $entry = ['file' => "$kind.$ext", 'name' => $nm];
      }
      studio_update_project($pid, function (&$p) use ($kind, $entry) { if ($entry) $p['music'][$kind] = $entry; else unset($p['music'][$kind]); });
      studio_json(['ok' => true]);
    }

    case 'save_settings': {
      if (!$isPost) studio_fail('Método no permitido', 405);
      $in = json_decode((string)($_POST['data'] ?? '{}'), true) ?: [];
      $rules = ['max_pause' => [0, 5], 'lufs' => [-30, -8], 'intro_overlap' => [0, 10], 'outro_overlap' => [0, 10], 'music_gain' => [-30, 6]];
      studio_update_project($pid, function (&$p) use ($in, $rules) {
        foreach ($rules as $k => [$lo, $hi]) if (isset($in[$k])) $p['settings'][$k] = max($lo, min($hi, (float)$in[$k]));
        foreach (['denoise', 'compress', 'trim_edges'] as $k) if (isset($in[$k])) $p['settings'][$k] = $in[$k] ? 1 : 0;
        if (isset($in['title']) && trim($in['title']) !== '') $p['title'] = mb_substr(trim($in['title']), 0, 200);
      });
      $plan = studio_plan(studio_project($pid));
      studio_json(['plan' => ['cut' => $plan['cut'], 'out' => $plan['duration_out'], 'src' => $plan['duration_src'], 'stats' => $plan['stats']]]);
    }

    case 'run': {
      if (!$isPost) studio_fail('Método no permitido', 405);
      if ($busy) studio_fail('Ya hay un proceso en marcha en este proyecto.', 409);
      $type = (string)($_POST['type'] ?? '');
      $handlers = kp_apply_filters('studio_job_handlers', studio_core_handlers());
      if (!isset($handlers[$type])) studio_fail('Proceso desconocido');
      $ready = array_filter($proj['tracks'], fn($t) => !empty($t['ready']));
      if ($type === 'ingest' && !array_filter($proj['tracks'], fn($t) => !empty($t['src']) && empty($t['ready']))) studio_fail('No hay audios pendientes de preparar');
      if (in_array($type, ['sync', 'preview', 'transcribe', 'render'], true) && !$ready) studio_fail('Primero sube y prepara los audios');
      if ($type === 'transcribe') {
        if (!studio_voice_tracks($proj)) studio_fail('No hay pistas de voz que transcribir');
        $eng = studio_engine_ready();
        if (!$eng['ok']) studio_fail($eng['hint']);
      }
      $params = json_decode((string)($_POST['params'] ?? '{}'), true) ?: [];
      $job = studio_job_create($pid, $type, $params);
      if (!studio_job_spawn($job)) $GLOBALS['studio_inline_job'] = $job;
      studio_json(['job' => $job]);
    }

    case 'draft': {
      if (!$isPost) studio_fail('Método no permitido', 405);
      $mp3 = studio_pdir($pid, 'render') . '/episode.mp3';
      if (!is_file($mp3) || empty($proj['result'])) studio_fail('Primero exporta el episodio');
      $podcast = kp_one("SELECT * FROM podcasts WHERE id = ?", [$proj['podcast_id']]);
      $res = $proj['result'];
      $existing = $proj['episode_id'] ? kp_one("SELECT * FROM episodes WHERE id = ?", [$proj['episode_id']]) : null;
      $slug = $existing['slug'] ?? '';
      if (!$slug) {
        $base = kp_slugify($proj['title']) ?: 'episodio'; $slug = $base; $i = 2;
        while (kp_one("SELECT 1 FROM episodes WHERE podcast_id = ? AND slug = ?", [$podcast['id'], $slug])) $slug = $base . '-' . $i++;
      }
      $audioDir = __DIR__ . '/../../media/audio/' . $podcast['id']; $upDir = __DIR__ . '/../../media/uploads';
      foreach ([$audioDir, $upDir] as $d) if (!is_dir($d) && !@mkdir($d, 0775, true)) studio_fail("No se pudo crear $d", 500);
      $audioRel = 'media/audio/' . $podcast['id'] . "/$slug.mp3";
      if (!copy($mp3, __DIR__ . '/../../' . $audioRel)) studio_fail('No se pudo copiar el audio a media/', 500);
      $vttRel = '';
      if (is_file(studio_pdir($pid, 'render') . '/episode.vtt')) {
        $vttRel = "media/uploads/$slug.vtt";
        copy(studio_pdir($pid, 'render') . '/episode.vtt', __DIR__ . '/../../' . $vttRel);
      }
      $notes = trim((string)($_POST['notes'] ?? ''));
      if ($existing) {
        kp_exec("UPDATE episodes SET audio_url=?, audio_bytes=?, audio_mime='audio/mpeg', duration_secs=?, transcript_url=?, updated_at=? WHERE id=?",
          ['/' . $audioRel, filesize($mp3), (int)round($res['duration']), $vttRel ? '/' . $vttRel : null, gmdate('Y-m-d H:i:s'), $existing['id']]);
        $epId = (int)$existing['id'];
      } else {
        $now = gmdate('Y-m-d H:i:s');
        kp_exec("INSERT INTO episodes (podcast_id, guid, slug, title, notes_md, ep_type, audio_url, audio_bytes, audio_mime, duration_secs, transcript_url, status, created_by, created_at, updated_at)
                 VALUES (?,?,?,?,?, 'full', ?,?, 'audio/mpeg', ?,?, 'draft', ?,?,?)",
          [$podcast['id'], bin2hex(random_bytes(8)), $slug, $proj['title'], $notes, '/' . $audioRel, filesize($mp3), (int)round($res['duration']),
           $vttRel ? '/' . $vttRel : null, $studio_user['id'], $now, $now]);
        $epId = (int)kp_db()->lastInsertId();
      }
      studio_update_project($pid, function (&$p) use ($epId) { $p['episode_id'] = $epId; $p['status'] = 'drafted'; });
      studio_json(['episode_id' => $epId, 'url' => admin_url('new-episode') . '?id=' . $epId . '&podcast=' . urlencode($podcast['slug']), 'updated' => (bool)$existing]);
    }

    case 'free_space': {
      if (!$isPost) studio_fail('Método no permitido', 405);
      if ($busy) studio_fail('Hay un proceso en marcha; espera a que termine.');
      $freed = 0;
      foreach (['tmp', 'src'] as $sub) { $d = studio_pdir($pid, $sub); foreach (glob("$d/*") ?: [] as $f) { $freed += (int)@filesize($f); @unlink($f); } }
      foreach (glob(studio_pdir($pid, 'tracks') . '/*.wav') ?: [] as $f) { $freed += (int)@filesize($f); @unlink($f); }
      // Las pistas pierden su copia de trabajo: habrá que volver a prepararlas desde los originales
      studio_update_project($pid, function (&$p) { foreach ($p['tracks'] as &$t) { $t['ready'] = false; unset($t['src']); $t['freed'] = true; } });
      studio_json(['freed' => $freed]);
    }

    case 'delete': {
      if (!$isPost) studio_fail('Método no permitido', 405);
      if ($busy) studio_fail('Hay un proceso en marcha; espera a que termine.');
      kp_exec("DELETE FROM studio_projects WHERE id = ?", [$pid]);
      studio_rmtree(studio_pdir($pid));
      studio_json(['ok' => true]);
    }

    default:
      if ($st_do !== '') {
        // Otros plugins pueden atender sus propias acciones: add_action('studio_ajax_<do>', fn($proyecto))
        kp_do_action('studio_ajax_' . $st_do, $proj);
      }
      studio_fail('Acción desconocida', 404);
  }
}

// ── Vistas ──────────────────────────────────────────────────────────────────

$st_ajax_mode = isset($_GET['ajax']);
if ($st_id) {
  $proj = studio_project($st_id);
  if (!$proj || !studio_can_access($proj, $studio_user)) { echo '<div class="card">Proyecto no encontrado.</div>'; return; }
  require __DIR__ . '/view-editor.php';
} else {
  require __DIR__ . '/view-list.php';
}
