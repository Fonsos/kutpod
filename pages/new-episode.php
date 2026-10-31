<?php
// pages/new-episode.php — formulario completo de episodio
require_once __DIR__ . '/../includes/helpers.php';

require_once __DIR__ . '/../includes/ffmpeg.php';
$has_ffmpeg = kp_has_ffmpeg();
$ffmpeg_cmd = kp_get_ffmpeg_cmd('ffmpeg') ?: 'ffmpeg';
$ffprobe_cmd = kp_get_ffmpeg_cmd('ffprobe') ?: 'ffprobe';

$pid = $_GET['podcast'] ?? '';
$ep_id = $_GET['id'] ?? null; // NOTE: This is actually the SLUG from the URL

$p = kp_find_podcast($pid) ?? kp_podcasts()[0];

// Fetch numeric podcast ID
$real_podcast_id = kp_one("SELECT id FROM podcasts WHERE slug = ?", [$p['id']])['id'] ?? null;

$ep = null;
if ($ep_id && $real_podcast_id) {
    $ep = kp_one("SELECT * FROM episodes WHERE slug = ? AND podcast_id = ?", [$ep_id, $real_podcast_id]);
}

// Calculate default season and episode number
$default_season = 1;
$default_number = 1;
if (!$ep && $real_podcast_id) {
    $last_ep = kp_one("SELECT season, number FROM episodes WHERE podcast_id = ? ORDER BY season DESC, number DESC LIMIT 1", [$real_podcast_id]);
    if ($last_ep) {
        $default_season = (int)$last_ep['season'];
        $default_number = (int)$last_ep['number'] + 1;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (isset($_POST['action']) && $_POST['action'] === 'delete' && $ep) {

    // 1b. Lanzar gancho de borrado de episodio (ej: borrar discusiones en foros)
    try {
      kp_do_action('episode_deleted', $ep);
    } catch(Throwable $e) {}

    // 2. Borrar de la base de datos
    $pdo = kp_db();
    $deletedTitle = $ep['title'];
    $pdo->prepare("DELETE FROM episodes WHERE id = ?")->execute([$ep['id']]);
    
    // Auto-renumber subsequent episodes in the same season
    $pdo->prepare("UPDATE episodes SET number = number - 1 WHERE podcast_id = ? AND season = ? AND number > ?")->execute([$ep['podcast_id'], $ep['season'], $ep['number']]);
    
    kp_alert('system', 'Episodio eliminado', "El episodio '$deletedTitle' fue eliminado de {$p['title']}.", '/admin/podcast/' . urlencode($p['id']));
    setcookie('kp_flash', 'Episodio eliminado', time() + 30, '/');
    header('Location: /admin/podcast/' . urlencode($p['id'])); exit;
  }

  $pdo = kp_db();
  $title = $_POST['title'] ?? 'Sin título';
  $custom_slug = $_POST['custom_slug'] ?? '';
  $slug = $custom_slug ? kp_slugify($custom_slug) : kp_slugify(preg_replace('/#\w+/u', '', $title));
  $notes_md = $_POST['notes_md'] ?? '';
  $season = (int)($_POST['season'] ?? $default_season);
  $number = (int)($_POST['number'] ?? $default_number);
  $ep_type = $_POST['ep_type'] ?? 'full';
  $status = $_POST['status'] ?? 'draft';
  $is_podcast_premium = (int)kp_get_podcast_meta($p, 'premium_enabled', 0);
  $premium = (!$is_podcast_premium && isset($_POST['premium'])) ? 1 : 0;

  $publish_at = null;
  if (!empty($_POST['publish_at'])) {
      $ts = strtotime($_POST['publish_at']);
      if ($ts) {
          $publish_at = gmdate('Y-m-d H:i:s', $ts);
      }
  }

  // Handle transcript upload
  $transcript_url = $ep['transcript_url'] ?? '';
  if (!empty($_FILES['transcript']) && $_FILES['transcript']['error'] === UPLOAD_ERR_OK) {
      $ext = strtolower(pathinfo($_FILES['transcript']['name'], PATHINFO_EXTENSION) ?: 'srt');
      $dest = __DIR__ . '/../media/uploads/' . $slug . '.' . $ext;
      @mkdir(dirname($dest), 0775, true);
      if (move_uploaded_file($_FILES['transcript']['tmp_name'], $dest)) {
          $transcript_url = '/media/uploads/' . $slug . '.' . $ext;
      }
  }

  // Handle manual chapters upload
  $chapters_url = $ep['chapters_url'] ?? '';
  if (!empty($_FILES['chapters']) && $_FILES['chapters']['error'] === UPLOAD_ERR_OK) {
      $ext = strtolower(pathinfo($_FILES['chapters']['name'], PATHINFO_EXTENSION) ?: 'json');
      $dest = __DIR__ . '/../media/chapters/' . $p['id'] . '/ep_' . $slug . '.' . $ext;
      @mkdir(dirname($dest), 0775, true);
      if (move_uploaded_file($_FILES['chapters']['tmp_name'], $dest)) {
          $chapters_url = '/media/chapters/' . $p['id'] . '/ep_' . $slug . '.' . $ext;
      }
  }

  // Same for audio
  $audio_url = $ep['audio_url'] ?? '';
  $audio_bytes = $ep['audio_bytes'] ?? 0;
  $duration_secs = $ep['duration_secs'] ?? 0;
  $client_duration = (int)($_POST['client_duration'] ?? 0);

  if (!empty($_FILES['audio']) && $_FILES['audio']['error'] === UPLOAD_ERR_OK) {
      $ext = strtolower(pathinfo($_FILES['audio']['name'], PATHINFO_EXTENSION) ?: 'mp3');
      $dest = __DIR__ . '/../media/audio/' . $p['id'] . '/' . $slug . '.' . $ext;
      @mkdir(dirname($dest), 0775, true);
      if (move_uploaded_file($_FILES['audio']['tmp_name'], $dest)) {
          // Si el archivo es webm, mp4, ogg, o m4a (grabación directa del navegador), convertirlo a mp3 para compatibilidad
          if (in_array($ext, ['webm', 'mp4', 'ogg', 'm4a']) && $has_ffmpeg) {
              $mp3Dest = __DIR__ . '/../media/audio/' . $p['id'] . '/' . $slug . '.mp3';
              // Convertir a MP3 a 128kbps (estándar para voz)
              exec($ffmpeg_cmd . ' -y -i ' . escapeshellarg($dest) . ' -b:a 128k ' . escapeshellarg($mp3Dest) . ' 2>&1', $out, $ret);
              if ($ret === 0) {
                  unlink($dest); // borrar el archivo original
                  $dest = $mp3Dest;
                  $ext = 'mp3';
              }
          }
          
          $audio_url = '/media/audio/' . $p['id'] . '/' . $slug . '.' . $ext;
          $audio_bytes = filesize($dest);
          
          // Client duration fallback
          if ($client_duration > 0 && empty($duration_secs)) {
              $duration_secs = $client_duration;
          }

          // FFprobe metadata & chapters extraction
          if ($has_ffmpeg) {
              $probeJson = @shell_exec($ffprobe_cmd . ' -v quiet -print_format json -show_format -show_chapters ' . escapeshellarg($dest));
              if ($probeJson) {
              $probe = json_decode($probeJson, true);
              if (!empty($probe['format']['duration'])) {
                  $duration_secs = (int)round((float)$probe['format']['duration']);
              }
              // Extract chapters if present
              if (!empty($probe['chapters'])) {
                  $chaps = [];
                  foreach ($probe['chapters'] as $c) {
                      $chaps[] = [
                          'startTime' => (int)round((float)($c['start_time'] ?? 0)),
                          'title' => $c['tags']['title'] ?? 'Chapter'
                      ];
                  }
                  if (count($chaps) > 0) {
                      $chapData = [
                          'version' => '1.2.0',
                          'chapters' => $chaps
                      ];
                      $chapDest = __DIR__ . '/../media/chapters/' . $p['id'] . '/ep_' . $slug . '.json';
                      @mkdir(dirname($chapDest), 0775, true);
                      file_put_contents($chapDest, json_encode($chapData, JSON_UNESCAPED_UNICODE));
                      $chapters_url = '/media/chapters/' . $p['id'] . '/ep_' . $slug . '.json';
                  }
              }
              }
          }
      }
  }

  // Handle cover upload
  $cover_url = $ep['cover'] ?? '';
  if (!empty($_FILES['cover']) && $_FILES['cover']['error'] === UPLOAD_ERR_OK) {
      $ext = strtolower(pathinfo($_FILES['cover']['name'], PATHINFO_EXTENSION) ?: 'jpg');
      $dest = __DIR__ . '/../media/covers/' . $p['id'] . '/ep_' . $slug . '.' . $ext;
      @mkdir(dirname($dest), 0775, true);
      if (move_uploaded_file($_FILES['cover']['tmp_name'], $dest)) {
          $cover_url = '/media/covers/' . $p['id'] . '/ep_' . $slug . '.' . $ext;
      }
  }

  $persons_input = $_POST['_persons_sel'] ?? [];
  $persons_json = null;
  if (!empty($persons_input)) {
      $persons_list = [];
      foreach ($persons_input as $u_id) {
          $u_id = (int)$u_id;
          $role = $_POST['person_role_' . $u_id] ?? 'guest';
          $u_data = kp_one("SELECT name, avatar, url, bio FROM users WHERE id = ?", [$u_id]);
          if ($u_data) {
              $persons_list[] = [
                  'id' => $u_id,
                  'name' => $u_data['name'],
                  'avatar' => $u_data['avatar'] ?? '',
                  'url' => $u_data['url'] ?? '',
                  'bio' => $u_data['bio'] ?? '',
                  'role' => $role
              ];
          }
      }
      $persons_json = json_encode($persons_list, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  }

  $location_name = trim($_POST['location_name'] ?? $_POST['ep_location'] ?? '');
  $lat = null;
  $lon = null;
  if (!empty($_POST['ep_latlon'])) {
      $parts = explode(',', $_POST['ep_latlon']);
      if (count($parts) === 2 && is_numeric(trim($parts[0])) && is_numeric(trim($parts[1]))) {
          $lat = (float)trim($parts[0]);
          $lon = (float)trim($parts[1]);
      }
  }

  $was_published = false;
  if ($ep) {
      $published_at = $ep['published_at'] ?? null;
      if ($status === 'published' && empty($published_at)) {
          $published_at = $publish_at ?: gmdate('Y-m-d H:i:s');
      }
      $pdo->prepare("UPDATE episodes SET title=?, slug=?, notes_md=?, season=?, number=?, ep_type=?, status=?, transcript_url=?, chapters_url=?, audio_url=?, audio_bytes=?, duration_secs=?, cover=?, publish_at=?, published_at=?, persons_json=?, premium=?, location_name=?, lat=?, lon=?, updated_at=datetime('now') WHERE id=?")
          ->execute([$title, $slug, $notes_md, $season, $number, $ep_type, $status, $transcript_url, $chapters_url, $audio_url, $audio_bytes, $duration_secs, $cover_url, $publish_at, $published_at, $persons_json, $premium, $location_name, $lat, $lon, $ep['id']]);
      $episode_id = $ep['id'];
      $was_published = $ep['status'] === 'published';
  } else {
      $published_at = ($status === 'published') ? ($publish_at ?: gmdate('Y-m-d H:i:s')) : null;
      $pdo->prepare("INSERT INTO episodes (podcast_id, guid, title, slug, notes_md, season, number, ep_type, status, transcript_url, chapters_url, audio_url, audio_bytes, duration_secs, cover, publish_at, published_at, persons_json, premium, location_name, lat, lon) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
          ->execute([$real_podcast_id, bin2hex(random_bytes(8)), $title, $slug, $notes_md, $season, $number, $ep_type, $status, $transcript_url, $chapters_url, $audio_url, $audio_bytes, $duration_secs, $cover_url, $publish_at, $published_at, $persons_json, $premium, $location_name, $lat, $lon]);
      $episode_id = (int)$pdo->lastInsertId();
  }

  // Lanzar gancho para que los plugins procesen el guardado del episodio
  kp_do_action('episode_saved', (int)$episode_id, $_POST);

  if ($status === 'published' && !$was_published) {
      $domain = kp_setting('instance_domain', $_SERVER['HTTP_HOST'] ?? 'localhost');
      $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
      $feed_url = kp_canonical_feed_url($p['id'] ?? '');
      $ep_url = $protocol . $domain . '/@' . ($p['id'] ?? '') . '/' . $slug;
      kp_notify_websub($feed_url);

      kp_alert('system', 'Episodio publicado', "El episodio '$title' se ha publicado en la red.", $ep_url);

      // Lanzar gancho de publicación de episodio (ej: auto-postear en foros)
      try {
          kp_do_action('episode_published', (int)$episode_id, (int)$real_podcast_id, $title, $notes_md, $ep_url);
      } catch (Throwable $e) {}
  }

  require_once __DIR__ . '/../includes/cache.php';
  kp_cache_clear();

  setcookie('kp_flash', 'Episodio guardado exitosamente', time() + 30, '/');
  header('Location: /admin/podcast/' . urlencode($p['id'])); exit;
}

$sections = [
  's2-1' => 'Archivo & metadatos',
  's2-2' => 'Notas (Markdown)',
  's2-3' => 'Premium & ubicación',
  's2-4' => 'Transcripción & capítulos',
  's2-5' => 'Avanzados',
];
?>
<form method="POST" enctype="multipart/form-data" class="form-episode">
  <?= kp_csrf_field() ?>
  <input type="hidden" name="client_duration" id="client_duration" value="0">
  <div class="page-head">
    <div>
      <h1 class="page-title"><?= $ep ? 'Editar episodio' : 'Nuevo episodio' ?></h1>
      <p class="page-sub">Para <strong><?= e($p['title']) ?></strong> · @<?= e($p['id']) ?>@<?= e(kp_handle_domain()) ?></p>
    </div>
  </div>

  <div class="grid-12" style="gap:24px">
    <div class="span-9 col" style="gap:24px">

      <!-- 2.1 Archivo & metadatos -->
      <section id="s2-1" class="card card-lg">
        <header class="section-head"><h2>Archivo de audio &amp; metadatos</h2></header>
        <div class="tab-strip" role="tablist">
          <button type="button" class="tab active" data-source="upload"><?= icon('upload',13) ?> Subir archivo</button>
          <button type="button" class="tab" data-source="record"><?= icon('mic',13) ?> Grabar</button>
          <button type="button" class="tab" data-source="url"><?= icon('globe',13) ?> Desde URL</button>
        </div>
        <div class="source-panel" data-panel="upload">
          <label for="audio" style="display:block;border:2px dashed var(--border);border-radius:14px;padding:36px;text-align:center;cursor:pointer">
            <?= icon('upload',32) ?>
            <div id="audio-filename" style="margin-top:12px;font-weight:500">Arrastra tu MP3/WAV aquí</div>
            <div class="help" style="margin-top:4px">o haz click · máx. 500MB · también vía Kut Editor / Hindenburg Pro / API</div>
            <input id="audio" type="file" name="audio" accept="audio/mpeg,audio/mp3,audio/wav,audio/x-m4a" hidden>
          </label>
          <div id="audio-preview-container" hidden style="margin-top:14px;background:var(--surface-2);border-radius:10px;padding:12px;border:1px solid var(--border)">
            <audio id="audio-preview" controls style="width:100%;height:40px;border-radius:8px"></audio>
            <button type="button" class="btn btn-ghost" onclick="document.getElementById('audio').value=''; document.getElementById('audio').dispatchEvent(new Event('change'));" style="margin-top:8px;color:var(--red);padding:4px 8px"><?= icon('x',13) ?> Descartar audio</button>
          </div>
        </div>
        <div class="source-panel" data-panel="record" hidden>
          <div class="recorder" style="display:flex;flex-direction:column;align-items:center;justify-content:center;padding:26px 20px;gap:12px;background:var(--surface-2);border-radius:14px;border:1px solid var(--border);text-align:center">
            <div class="recorder-time tabular" style="font-size:26px;font-family:ui-monospace,monospace;font-weight:600;color:var(--text);letter-spacing:.05em">00:00</div>
            <div id="rec-status-label" style="font-size:13px;color:var(--text-3)">Pulsa para iniciar la grabación</div>
            
            <div style="display:flex;align-items:center;justify-content:center;gap:16px;margin:4px 0">
              <button type="button" class="rec-btn-circle record-btn" id="record-btn-trigger" title="Iniciar grabación">
                <svg id="recIconMic" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3Z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="22"/></svg>
                <svg id="recIconStop" width="24" height="24" viewBox="0 0 24 24" fill="currentColor" style="display:none"><rect x="5" y="5" width="14" height="14" rx="2"/></svg>
              </button>
            </div>
            
            <div class="recorder-meter" style="width:100%;max-width:340px;height:6px;background:var(--surface-3);border-radius:999px;overflow:hidden">
              <span style="display:block;width:0%;height:100%;background:var(--accent);transition:width 120ms"></span>
            </div>
          </div>
        </div>
        <div class="source-panel" data-panel="url" hidden>
          <div class="field"><label class="label">URL del audio</label><input class="input" type="url" name="audio_url" placeholder="https://cdn.ejemplo.com/ep.mp3"></div>
        </div>

        <div class="grid-12" style="margin-top:18px">
          <div class="field span-4 col">
            <label class="label">Portada del episodio</label>
            <label for="ep_cover" id="ep_cover_label" style="display:block;aspect-ratio:1;border-radius:12px;background:var(--surface-2);border:1px dashed var(--border);display:grid;place-items:center;color:var(--text-3);cursor:pointer;background-size:cover;background-position:center;<?= !empty($ep['cover']) ? 'background-image:url('.e($ep['cover']).');' : '' ?>">
              <?= empty($ep['cover']) ? icon('image',28) : '' ?>
            </label>
            <input id="ep_cover" type="file" name="cover" accept="image/png,image/jpeg,image/gif,image/webp" hidden>
            <div class="help" style="margin-top:6px">Hereda la del show si la dejas vacía.</div>
            <button id="ep_cover-cancel" type="button" class="btn btn-ghost" style="margin-top:8px;font-size:12px;display:none;color:var(--red);align-self:flex-start;"><?= icon('x',12) ?> Cancelar selección</button>
          </div>
          <div class="col span-8" style="display:flex;flex-direction:column;gap:22px">
            <div class="field">
              <label class="label">Permalink</label>
              <div style="display:flex;align-items:center;background:var(--surface-2);border:1px solid var(--border);border-radius:8px;padding:0 12px;overflow:hidden;width:100%">
                <span class="muted" style="font-size:14px;white-space:nowrap;user-select:none"><?= (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . e(kp_setting('instance_domain', $_SERVER['HTTP_HOST'] ?? '')) ?>/@<?= e($p['slug'] ?? $p['id']) ?>/</span>
                <input type="text" id="permalink_input" name="custom_slug" class="input" value="<?= e($ep['slug'] ?? '') ?>" style="border:none;background:transparent;padding-left:4px;width:100%;font-weight:600;box-shadow:none;outline:none" placeholder="titulo-del-episodio">
                <button type="button" class="btn btn-ghost" id="btn-copy-url" style="border:none;padding:8px;margin-left:4px;cursor:pointer;display:flex;align-items:center;background:transparent" title="Copiar URL completa">
                  <?= icon('copy', 14) ?>
                </button>
              </div>
            </div>
            <div class="field">
              <label class="label">Título <span class="req">*</span></label>
              <input class="input" name="title" required placeholder="Ej: Entrevista a John Doe" value="<?= e($ep['title'] ?? '') ?>" style="font-size:16px;font-weight:500" oninput="if(!manualSlug) { const s = slugify(this.value); document.getElementById('permalink_input').value = s; const emb = document.getElementById('embed_slug_input'); if(emb) emb.value = s; }">
            </div>
            <div class="field">
              <div class="grid-12">
                <div class="field span-2"><div class="label">Temporada</div><input type="number" name="season" class="input" value="<?= e($ep['season'] ?? $default_season) ?>"></div>
                <div class="field span-2"><div class="label">Episodio</div><input type="number" name="number" class="input" value="<?= e($ep['number'] ?? $default_number) ?>"></div>
                <div class="field span-3"><div class="label">Tipo</div>
                  <select class="select" name="ep_type">
                    <option value="full" <?= ($ep['ep_type']??'')==='full'?'selected':'' ?>>Full (Completo)</option>
                    <option value="trailer" <?= ($ep['ep_type']??'')==='trailer'?'selected':'' ?>>Trailer</option>
                    <option value="bonus" <?= ($ep['ep_type']??'')==='bonus'?'selected':'' ?>>Bonus (Extra)</option>
                  </select>
                </div>
                <div class="field span-5"><label class="label">Publicación</label><input class="input" type="datetime-local" id="publish_at_input" name="publish_at" value="<?= e(isset($ep['publish_at']) && $ep['publish_at'] ? date('Y-m-d\TH:i', kp_db_strtotime($ep['publish_at'])) : '') ?>"></div>
              </div>
            </div>
            <div class="field">
              <label class="label">Personas (Host, Invitados)</label>
              <div style="font-size:12px;color:var(--text-3);margin-bottom:6px">Selecciona quién participa en este episodio. Se añadirán etiquetas <code>&lt;podcast:person&gt;</code> al RSS.</div>
              <?php
              $all_users = kp_q("SELECT id, name, avatar FROM users ORDER BY name");
              $ep_persons = !empty($ep['persons_json']) ? json_decode($ep['persons_json'], true) : [];
              $ep_person_ids = [];
              foreach ($ep_persons as $p_j) {
                  $ep_person_ids[$p_j['id']] = $p_j['role'] ?? 'guest';
              }
              if (!$ep && isset($current_user['id'])) {
                  $ep_person_ids[$current_user['id']] = 'host';
              }
              ?>
              <div style="display:flex;flex-wrap:wrap;gap:12px;background:var(--surface-2);padding:12px;border-radius:10px;border:1px solid var(--border);max-height:200px;overflow-y:auto">
                <?php foreach ($all_users as $u_row): 
                  $is_sel = isset($ep_person_ids[$u_row['id']]);
                  $role = $is_sel ? $ep_person_ids[$u_row['id']] : 'guest';
                ?>
                  <label style="display:flex;align-items:center;gap:8px;background:var(--surface);padding:6px 12px;border-radius:20px;border:1px solid <?= $is_sel ? 'var(--accent)' : 'var(--border)' ?>;cursor:pointer;user-select:none">
                    <input type="checkbox" name="_persons_sel[]" value="<?= $u_row['id'] ?>" onchange="this.parentNode.style.borderColor = this.checked ? 'var(--accent)' : 'var(--border)'; this.nextElementSibling.nextElementSibling.nextElementSibling.disabled = !this.checked;" <?= $is_sel ? 'checked' : '' ?> hidden>
                    <div style="width:24px;height:24px;border-radius:50%;overflow:hidden;background:var(--border);display:flex;align-items:center;justify-content:center;color:var(--text-3);font-size:10px;font-weight:bold">
                      <?php if($u_row['avatar']): ?><img src="<?= e($u_row['avatar']) ?>" style="width:100%;height:100%;object-fit:cover"><?php else: ?><?= substr($u_row['name'], 0, 1) ?><?php endif; ?>
                    </div>
                    <span style="font-size:13px;font-weight:500"><?= e($u_row['name']) ?></span>
                    <select name="person_role_<?= $u_row['id'] ?>" style="font-size:11px;padding:2px;border-radius:4px;border:none;background:var(--surface-2);cursor:pointer" <?= $is_sel ? '' : 'disabled' ?>>
                      <option value="host" <?= $role === 'host' ? 'selected' : '' ?>>Host</option>
                      <option value="guest" <?= $role === 'guest' ? 'selected' : '' ?>>Guest</option>
                    </select>
                  </label>
                <?php endforeach; ?>
              </div>
            </div>
            <div class="field">
              <label class="label">Reproductor embebido</label>
              <div style="display:flex;align-items:center;background:var(--surface-2);border:1px solid var(--border);border-radius:8px;padding:0 12px;overflow:hidden;width:100%">
                <span class="muted" style="font-size:14px;white-space:nowrap;user-select:none"><?= (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . e(kp_setting('instance_domain', $_SERVER['HTTP_HOST'] ?? '')) ?>/embed/@<?= e($p['slug'] ?? $p['id']) ?>/</span>
                <input type="text" id="embed_slug_input" readonly class="input" value="<?= e($ep['slug'] ?? '') ?>" style="border:none;background:transparent;padding-left:4px;width:100%;font-weight:600;box-shadow:none;outline:none;cursor:default" placeholder="titulo-del-episodio">
                <button type="button" class="btn btn-ghost" id="btn-copy-embed" style="border:none;padding:8px;margin-left:4px;cursor:pointer;display:flex;align-items:center;background:transparent" title="Copiar código iframe del reproductor embebido">
                  <?= icon('copy', 14) ?>
                </button>
              </div>
              <div class="help" style="margin-top:6px">Copia el código iframe para incrustar el reproductor en páginas web externas o blogs.</div>
            </div>
          </div>
        </div>
      </section>

      <!-- 2.2 Notas Markdown -->
      <section id="s2-2" class="card card-lg">
        <header class="section-head">
          <h2>Notas del episodio</h2>
          <div class="tab-strip md-tabs">
            <button type="button" class="tab active" data-md="edit">Editar</button>
            <button type="button" class="tab" data-md="preview">Vista previa</button>
            <button type="button" class="tab" data-md="split">Dividida</button>
          </div>
        </header>
        <div class="md-toolbar">
          <button type="button" data-md-cmd="bold"><strong>B</strong></button>
          <button type="button" data-md-cmd="italic"><em>I</em></button>
          <button type="button" data-md-cmd="h2">H2</button>
          <button type="button" data-md-cmd="link">🔗</button>
          <button type="button" data-md-cmd="list">• lista</button>
          <button type="button" data-md-cmd="quote">❝</button>
          <button type="button" data-md-cmd="code">{ }</button>
        </div>
        <div class="md-editor">
          <textarea class="textarea md-source" name="notes_md" style="min-height:280px" placeholder="Escribe aquí las notas del episodio. Puedes usar Markdown." oninput="updatePreview(this.value)"><?= e($ep['notes_md'] ?? '') ?></textarea>
          <div class="md-preview prose" hidden></div>
        </div>
        <div class="md-foot">
          <span class="help"><span class="md-count">0</span> caracteres · Soporta Markdown estándar + tablas + footnotes.</span>
        </div>
      </section>

      <!-- 2.3 Premium & ubicación -->
      <section id="s2-3" class="card card-lg">
        <header class="section-head"><h2>Premium &amp; ubicación</h2></header>
        <?php $is_podcast_premium = (int)kp_get_podcast_meta($p, 'premium_enabled', 0); ?>
        <label class="toggle-row">
          <span><strong>Episodio Premium</strong><br>
            <span class="help">
              <?php if ($is_podcast_premium): ?>
                El podcast completo está marcado como Premium. Todos los episodios son premium.
              <?php else: ?>
                Solo accesible para suscriptores.
              <?php endif; ?>
            </span>
          </span>
          <label class="toggle">
            <input type="checkbox" name="premium" value="1" <?= ($is_podcast_premium || ($ep && (int)$ep['premium'])) ? 'checked' : '' ?> <?= $is_podcast_premium ? 'disabled' : '' ?>>
            <span class="toggle-track"></span>
          </label>
        </label>
        <div class="field-row" style="margin-top:14px; border-top:1px solid var(--border); padding-top:14px">
          <div class="field">
            <label class="label">Precio de suscripción mensual del podcast (USD)</label>
            <input class="input tabular" type="number" min="0" step="0.01" name="premium_price" value="<?= e(kp_get_podcast_meta($p, 'premium_price', 5.00)) ?>">
            <span class="help" style="margin-top:4px; display:inline-block">Precio mensual estándar.</span>
          </div>
          <?php $premium_price_year = (float)kp_get_podcast_meta($p, 'premium_price_year', 0.00); ?>
          <div class="field">
            <label class="label">Precio de suscripción anual del podcast (USD)</label>
            <input class="input tabular" type="number" min="0" step="0.01" name="premium_price_year" value="<?= $premium_price_year > 0 ? e($premium_price_year) : '' ?>" placeholder="Ej: 50.00">
            <span class="help" style="margin-top:4px; display:inline-block">Precio anual opcional (con descuento).</span>
          </div>
        </div>
        <div class="field-row" style="margin-top:-6px; margin-bottom:6px">
          <div class="field">
            <span class="help" style="color:var(--text-muted)">Los oyentes pagan esta cuota para acceder a todos los episodios premium de este podcast. Puedes modificarla aquí o en los ajustes del show.</span>
          </div>
        </div>
        <div class="field-row" style="margin-top:14px">
          <div class="field"><label class="label">Ubicación del episodio</label><input class="input" name="ep_location" value="<?= e($ep['location_name'] ?? '') ?>" placeholder="Caracas, Venezuela"></div>
          <div class="field"><label class="label">Lat, Lon</label><input class="input tabular" name="ep_latlon" value="<?= (!empty($ep['lat']) && !empty($ep['lon'])) ? e($ep['lat'] . ', ' . $ep['lon']) : '' ?>" placeholder="10.4806, -66.9036"></div>
        </div>
      </section>

      <!-- 2.4 Transcripción & capítulos -->
      <section id="s2-4" class="card card-lg">
        <header class="section-head"><h2>Transcripción &amp; capítulos</h2></header>
        <div class="field-row">
          <div class="field"><label class="label">Transcripción (.srt / .vtt)</label>
            <label for="transcript" id="transcript-label" class="btn" style="width:100%;cursor:pointer"><?= icon('file',13) ?> Subir archivo</label>
            <input id="transcript" type="file" name="transcript" accept=".srt,.vtt,text/vtt" hidden>
            <div class="help" style="margin-top:6px">Se publica vía <code>&lt;podcast:transcript&gt;</code> en el feed.</div>
          </div>
          <div class="field"><label class="label">Capítulos (.json · podcasting 2.0)</label>
            <label for="chapters" id="chapters-label" class="btn" style="width:100%;cursor:pointer"><?= icon('file',13) ?> Subir capítulos</label>
            <input id="chapters" type="file" name="chapters" accept="application/json,.json" hidden>
            <div class="help" style="margin-top:6px">Spec: <a href="https://podcasting2.org/podcast-namespace/tags/chapters" target="_blank" style="color:var(--accent);text-decoration:none">podcasting2.org</a></div>
          </div>
        </div>
      </section>

      <!-- 2.5 Avanzados -->
      <section id="s2-5" class="card card-lg">
        <header class="section-head"><h2>Avanzados</h2></header>
        <div class="field"><label class="label">Custom tags (XML)</label><textarea class="textarea" name="custom_tags" rows="3" placeholder="&lt;podcast:funding url=...&gt;" style="font-family:ui-monospace,monospace;font-size:12.5px"></textarea></div>
        <?php kp_do_action('episode_form_advanced', $ep); ?>
        <label class="toggle-row" style="margin-top:14px"><span><strong>Ocultar episodio</strong><br><span class="help">No mostrar este episodio en el feed público ni en el sitio web</span></span><label class="toggle"><input type="checkbox" name="hidden" <?= ($e['hidden']??0)?'checked':'' ?>><span class="toggle-track"></span></label></label>
      </section>

    </div>
    
    <aside class="span-3 form-toc" style="position:sticky; top:90px; align-self:start">
      <?php foreach ($sections as $a => $l): ?>
        <a href="#<?= $a ?>" class="form-toc-item"><?= e($l) ?></a>
      <?php endforeach; ?>
      
      <div style="margin-top:32px;display:flex;flex-direction:column;gap:10px">
        <button class="btn btn-primary" type="submit" name="status" value="published" style="width:100%;justify-content:center;padding:10px"><?= icon('upload',13) ?> <?= ($ep && ($ep['status'] ?? '') === 'published') ? 'Guardar Cambios' : 'Publicar' ?></button>
        <button class="btn" type="submit" name="status" value="scheduled" style="width:100%;justify-content:center"><?= icon('check',13) ?> Programar</button>
        <button class="btn" type="submit" name="status" value="draft" style="width:100%;justify-content:center"><?= icon('file',13) ?> <?= ($ep['status'] ?? '') === 'published' ? 'Despublicar (A borrador)' : 'Guardar Borrador' ?></button>
        <?php if ($ep && ($ep['status'] ?? '') === 'published'): ?>
          <a class="btn" href="/@<?= e($p['slug'] ?? $p['id']) ?>/<?= e($ep['slug'] ?? $ep['id']) ?>" target="_blank" rel="noopener" style="width:100%;justify-content:center"><?= icon('externalLink',13) ?> Ir al Episodio</a>
        <?php endif; ?>
        <a class="btn" href="/admin/podcast?id=<?= e($p['id']) ?>" style="width:100%;justify-content:center">Cancelar</a>
        <?php if ($ep): ?>
          <button class="btn btn-danger" style="color:var(--red,#ef4444);border:1px solid var(--red,#ef4444);width:100%;justify-content:center" type="submit" name="action" value="delete" onclick="return confirm('¿Estás seguro de que quieres eliminar este episodio permanentemente?')"><?= icon('x',13) ?> Eliminar</button>
        <?php endif; ?>
      </div>
    </aside>

  </div>
</form>

<script>
const hasFFmpegServer = <?= $has_ffmpeg ? 'true' : 'false' ?>;

// Slug en vivo para el permalink
let manualSlug = <?= $ep ? 'true' : 'false' ?>;

function slugify(s) {
  return (s||'').toString().replace(/#\w+/g, '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g,'')
    .replace(/[^a-z0-9\s-]/g,'').trim().replace(/\s+/g,'-').replace(/-+/g,'-') || 'titulo-del-episodio';
}

document.getElementById('permalink_input')?.addEventListener('input', function() {
  manualSlug = true;
  this.value = slugify(this.value);
  const emb = document.getElementById('embed_slug_input');
  if (emb) emb.value = this.value;
});

// Comportamiento para imitar Chromium en Firefox: inicializar selector de fecha/hora al hacer clic
document.getElementById('publish_at_input')?.addEventListener('focus', function() {
  if (!this.value) {
    const d = new Date();
    d.setMinutes(d.getMinutes() - d.getTimezoneOffset());
    this.value = d.toISOString().slice(0, 16);
  }
});

// Tabs de fuente de audio
document.querySelectorAll('.tab-strip:not(.md-tabs) .tab').forEach(t => t.addEventListener('click', () => {
  const strip = t.parentElement;
  strip.querySelectorAll('.tab').forEach(x => x.classList.remove('active'));
  t.classList.add('active');
  document.querySelectorAll('.source-panel').forEach(p => p.hidden = p.dataset.panel !== t.dataset.source);
}));

// UI Feedback para subida de archivos
document.getElementById('audio')?.addEventListener('change', function() {
  const file = this.files[0];
  const preview = document.getElementById('audio-preview');
  const previewContainer = document.getElementById('audio-preview-container');
  if (file) {
    document.getElementById('audio-filename').innerHTML = '<span style="color:var(--accent)">' + file.name + '</span>';
    
    const url = URL.createObjectURL(file);
    if (preview) {
      preview.src = url;
      previewContainer.hidden = false;
    }

    // Detect duration on client side
    const tempAudio = new Audio(url);
    tempAudio.addEventListener('loadedmetadata', () => {
      const d = tempAudio.duration;
      if (d && d !== Infinity && !isNaN(d)) {
        document.getElementById('client_duration').value = Math.round(d);
      }
    });
  } else {
    document.getElementById('audio-filename').innerHTML = 'Arrastra tu MP3/WAV aquí';
    if (preview) {
      previewContainer.hidden = true;
      preview.src = '';
    }
  }
});

// Drag and drop for audio
const audioLabel = document.querySelector('label[for="audio"]');
if (audioLabel) {
  ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(ev => {
    audioLabel.addEventListener(ev, e => {
      e.preventDefault();
      e.stopPropagation();
    });
  });
  ['dragenter', 'dragover'].forEach(ev => audioLabel.addEventListener(ev, () => audioLabel.style.borderColor = 'var(--accent)'));
  ['dragleave', 'drop'].forEach(ev => audioLabel.addEventListener(ev, () => audioLabel.style.borderColor = 'var(--border)'));
  audioLabel.addEventListener('drop', e => {
    const dt = e.dataTransfer;
    const files = dt.files;
    if (files.length) {
      document.getElementById('audio').files = files;
      document.getElementById('audio').dispatchEvent(new Event('change'));
    }
  });
}
const epCoverInput = document.getElementById('ep_cover');
const epCoverLabel = document.getElementById('ep_cover_label');
const epCoverCancel = document.getElementById('ep_cover-cancel');
let initialEpCoverBgImage = '';
let initialEpCoverBg = '';
let initialEpCoverBorder = '';
let initialEpCoverHTML = '';
if (epCoverLabel) {
  initialEpCoverBgImage = epCoverLabel.style.backgroundImage;
  initialEpCoverBg = epCoverLabel.style.background;
  initialEpCoverBorder = epCoverLabel.style.border;
  initialEpCoverHTML = epCoverLabel.innerHTML;
}

epCoverInput?.addEventListener('change', function() {
  if(this.files[0]){
    const r=new FileReader();
    r.onload=e=>{
      epCoverLabel.style.backgroundImage='url('+e.target.result+')';
      epCoverLabel.innerHTML='';
      if(epCoverCancel) epCoverCancel.style.display = 'inline-flex';
    };
    r.readAsDataURL(this.files[0]);
  } else {
    resetEpCover();
  }
});

function resetEpCover() {
  if (epCoverInput) epCoverInput.value = '';
  if (epCoverLabel) {
    epCoverLabel.style.background = initialEpCoverBg;
    epCoverLabel.style.backgroundImage = initialEpCoverBgImage;
    epCoverLabel.style.border = initialEpCoverBorder;
    epCoverLabel.innerHTML = initialEpCoverHTML;
  }
  if (epCoverCancel) epCoverCancel.style.display = 'none';
}
epCoverCancel?.addEventListener('click', resetEpCover);
document.getElementById('transcript')?.addEventListener('change', function() {
  document.getElementById('transcript-label').innerHTML = this.files[0] ? this.files[0].name : '<?= icon('file',13) ?> Subir archivo';
});
document.getElementById('chapters')?.addEventListener('change', function() {
  document.getElementById('chapters-label').innerHTML = this.files[0] ? this.files[0].name : '<?= icon('file',13) ?> Subir capítulos';
});

// Editor Markdown — toolbar + preview
const recordBtn = document.querySelector('.record-btn');
const meterSpan = document.querySelector('.recorder-meter span');
const timeSpan = document.querySelector('.recorder-time');
let mediaRecorder = null;
let audioChunks = [];
let audioContext = null;
let analyser = null;
let stream = null;
let rafId = null;
let recordStart = 0;
let timerInt = null;

if (recordBtn) {
  recordBtn.addEventListener('click', async () => {
    if (mediaRecorder && mediaRecorder.state === 'recording') {
      mediaRecorder.stop();
      stream.getTracks().forEach(t => t.stop());
      if (audioContext) { audioContext.close(); audioContext = null; }
      cancelAnimationFrame(rafId);
      clearInterval(timerInt);
      meterSpan.style.width = '0%';
      recordBtn.classList.remove('recording');
      const micIcon = document.getElementById('recIconMic');
      const stopIcon = document.getElementById('recIconStop');
      const statusLbl = document.getElementById('rec-status-label');
      if (micIcon) micIcon.style.display = 'block';
      if (stopIcon) stopIcon.style.display = 'none';
      if (statusLbl) statusLbl.textContent = 'Pulsa para iniciar la grabación';
      recordBtn.setAttribute('title', 'Iniciar grabación');
      return;
    }

    try {
      stream = await navigator.mediaDevices.getUserMedia({ audio: true });
      audioContext = new (window.AudioContext || window.webkitAudioContext)();
      const source = audioContext.createMediaStreamSource(stream);
      analyser = audioContext.createAnalyser();
      analyser.fftSize = 256;
      source.connect(analyser);
      
      const dataArray = new Uint8Array(analyser.frequencyBinCount);
      const drawMeter = () => {
        if (!audioContext) return;
        analyser.getByteFrequencyData(dataArray);
        let sum = 0;
        for (let i = 0; i < dataArray.length; i++) sum += dataArray[i];
        const avg = sum / dataArray.length;
        const percent = Math.min(100, (avg / 128) * 100);
        meterSpan.style.width = percent + '%';
        rafId = requestAnimationFrame(drawMeter);
      };
      drawMeter();

      mediaRecorder = new MediaRecorder(stream);
      audioChunks = [];
      mediaRecorder.ondataavailable = e => { if (e.data.size > 0) audioChunks.push(e.data); };
      mediaRecorder.onstop = async () => {
        const recordedDuration = Math.round((Date.now() - recordStart) / 1000);
        document.getElementById('client_duration').value = recordedDuration;
        
        const mimeType = mediaRecorder.mimeType || 'audio/webm';
        let ext = 'webm';
        if (mimeType.includes('mp4')) ext = 'mp4';
        else if (mimeType.includes('ogg')) ext = 'ogg';

        const blob = new Blob(audioChunks, { type: mimeType });
        let finalFile;

        if (!hasFFmpegServer) {
            // Client-side FFmpeg processing using WebAssembly
            if (typeof SharedArrayBuffer === 'undefined') {
                // SharedArrayBuffer is required for @ffmpeg/core 0.12+ 
                // We skip conversion silently to avoid the error alert on Shared Hosting
                finalFile = new File([blob], 'grabacion-' + Date.now() + '.' + ext, { type: mimeType });
            } else {
                document.getElementById('audio-filename').innerHTML = '<span style="color:var(--accent)">Procesando audio (WebAssembly)... no cierres la página.</span>';
                document.querySelector('.tab-strip .tab[data-source="upload"]').click();
                recordBtn.disabled = true;

                try {
                    // Importar dinámicamente como módulos ES (ESM) para evitar errores con UMD en el navegador
                    const ffmpegModule = await import('https://unpkg.com/@ffmpeg/ffmpeg@0.12.6/dist/esm/index.js');
                    const utilModule = await import('https://unpkg.com/@ffmpeg/util@0.12.1/dist/esm/index.js');
                    
                    const { FFmpeg } = ffmpegModule;
                    const { fetchFile } = utilModule;
                    const ffmpeg = new FFmpeg();
                    
                    await ffmpeg.load({
                        coreURL: 'https://unpkg.com/@ffmpeg/core@0.12.6/dist/umd/ffmpeg-core.js',
                        wasmURL: 'https://unpkg.com/@ffmpeg/core@0.12.6/dist/umd/ffmpeg-core.wasm',
                    });
                    
                    await ffmpeg.writeFile('input.' + ext, await fetchFile(blob));
                    await ffmpeg.exec(['-i', 'input.' + ext, '-b:a', '128k', 'output.mp3']);
                    const data = await ffmpeg.readFile('output.mp3');
                    
                    finalFile = new File([data.buffer], 'grabacion-' + Date.now() + '.mp3', { type: 'audio/mp3' });
                } catch (err) {
                    console.error("Error en FFmpeg wasm", err);
                    finalFile = new File([blob], 'grabacion-' + Date.now() + '.' + ext, { type: mimeType });
                }
                recordBtn.disabled = false;
            }
        } else {
            finalFile = new File([blob], 'grabacion-' + Date.now() + '.' + ext, { type: mimeType });
        }

        const dataTransfer = new DataTransfer();
        dataTransfer.items.add(finalFile);
        const fileInput = document.getElementById('audio');
        fileInput.files = dataTransfer.files;
        fileInput.dispatchEvent(new Event('change'));
        document.querySelector('.tab-strip .tab[data-source="upload"]').click();
      };
      
      mediaRecorder.start(200);
      
      recordBtn.classList.add('recording');
      const micIcon = document.getElementById('recIconMic');
      const stopIcon = document.getElementById('recIconStop');
      const statusLbl = document.getElementById('rec-status-label');
      if (micIcon) micIcon.style.display = 'none';
      if (stopIcon) stopIcon.style.display = 'block';
      if (statusLbl) statusLbl.textContent = 'Grabando... pulsa para detener';
      recordBtn.setAttribute('title', 'Detener grabación');
      
      recordStart = Date.now();
      timerInt = setInterval(() => {
        const s = Math.floor((Date.now() - recordStart) / 1000);
        const mins = String(Math.floor(s / 60)).padStart(2, '0');
        const secs = String(s % 60).padStart(2, '0');
        timeSpan.textContent = `${mins}:${secs}`;
      }, 1000);
      
    } catch(err) {
      alert('Error al acceder al micrófono: ' + err.message);
    }
  });
}

// Editor Markdown — toolbar + preview
const src = document.querySelector('.md-source');
const prev = document.querySelector('.md-preview');
const count = document.querySelector('.md-count');
function mdRender(md) {
  if (!md) return '<p class="muted">Aún no has escrito nada.</p>';
  let h = md;
  h = h.replace(/^### (.+)$/gm,'<h3>$1</h3>')
       .replace(/^## (.+)$/gm,'<h2>$1</h2>')
       .replace(/^# (.+)$/gm,'<h1>$1</h1>')
       .replace(/\*\*(.+?)\*\*/g,'<strong>$1</strong>')
       .replace(/\*(.+?)\*/g,'<em>$1</em>')
       .replace(/`([^`]+)`/g,'<code>$1</code>')
       .replace(/\[(.+?)\]\((.+?)\)/g,'<a href="$2" target="_blank">$1</a>')
       .replace(/^&gt; (.+)$/gm,'<blockquote>$1</blockquote>')
       .replace(/^- (.+)$/gm,'<li>$1</li>')
       .replace(/(<li>.*<\/li>)/s,'<ul>$1</ul>')
       .replace(/\n\n+/g,'</p><p>');
  return '<p>'+h+'</p>';
}
function refreshPreview() {
  prev.innerHTML = mdRender(src.value);
  count.textContent = src.value.length;
}
src && src.addEventListener('input', refreshPreview);

document.querySelectorAll('.md-tabs .tab').forEach(t => t.addEventListener('click', () => {
  document.querySelectorAll('.md-tabs .tab').forEach(x => x.classList.remove('active'));
  t.classList.add('active');
  const mode = t.dataset.md;
  src.hidden = mode === 'preview';
  prev.hidden = mode === 'edit';
  document.querySelector('.md-editor').classList.toggle('split', mode === 'split');
  if (mode !== 'edit') refreshPreview();
}));

document.querySelectorAll('[data-md-cmd]').forEach(b => b.addEventListener('click', () => {
  const wraps = { bold:['**','**'], italic:['*','*'], code:['`','`'] };
  const lines = { h2:'## ', list:'- ', quote:'> ' };
  const sel = src.value.substring(src.selectionStart, src.selectionEnd);
  const c = b.dataset.mdCmd;
  let insert;
  if (wraps[c]) insert = wraps[c][0] + (sel||'texto') + wraps[c][1];
  else if (lines[c]) insert = lines[c] + (sel||'');
  else if (c === 'link') insert = `[${sel||'texto'}](https://)`;
  const start = src.selectionStart;
  src.value = src.value.slice(0,start) + insert + src.value.slice(src.selectionEnd);
  src.focus();
  src.setSelectionRange(start + insert.length, start + insert.length);
  refreshPreview();
}));

const copyBtn = document.getElementById('btn-copy-url');
if (copyBtn) {
  copyBtn.addEventListener('click', function(e) {
    e.preventDefault();
    const domain = "<?= (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . e(kp_setting('instance_domain', $_SERVER['HTTP_HOST'] ?? '')) ?>";
    const podcast = "/@<?= e($p['slug'] ?? $p['id']) ?>/";
    const slug = document.getElementById('permalink_input').value || 'titulo-del-episodio';
    const fullUrl = domain + podcast + slug;
    navigator.clipboard.writeText(fullUrl).then(() => {
      const originalHTML = copyBtn.innerHTML;
      copyBtn.innerHTML = '<?= icon('check', 14) ?>';
      setTimeout(() => { copyBtn.innerHTML = originalHTML; }, 2000);
    }).catch(err => {
      alert('Error al copiar la URL: ' + err);
    });
  });
}

const copyEmbedBtn = document.getElementById('btn-copy-embed');
if (copyEmbedBtn) {
  copyEmbedBtn.addEventListener('click', function(e) {
    e.preventDefault();
    const domain = "<?= (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . e(kp_setting('instance_domain', $_SERVER['HTTP_HOST'] ?? '')) ?>";
    const podcast = "/embed/@<?= e($p['slug'] ?? $p['id']) ?>/";
    const slug = document.getElementById('embed_slug_input')?.value || document.getElementById('permalink_input')?.value || 'titulo-del-episodio';
    const fullEmbedUrl = domain + podcast + slug;
    const iframeCode = '<iframe src="' + fullEmbedUrl + '" width="100%" height="160" frameborder="0" scrolling="no" allow="encrypted-media; fullscreen"></iframe>';
    navigator.clipboard.writeText(iframeCode).then(() => {
      const originalHTML = copyEmbedBtn.innerHTML;
      copyEmbedBtn.innerHTML = '<?= icon('check', 14) ?>';
      setTimeout(() => { copyEmbedBtn.innerHTML = originalHTML; }, 2000);
    }).catch(err => {
      alert('Error al copiar el código iframe: ' + err);
    });
  });
}
</script>
