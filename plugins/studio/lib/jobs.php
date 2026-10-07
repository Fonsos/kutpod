<?php
// ============================================================================
// KutPod · Estudio · trabajos en segundo plano (ingesta, sincronización, render…)
// ============================================================================
if (!defined('KUTPOD_VERSION')) { exit; }

function studio_job_get(int $id): ?array {
  studio_ensure_schema();
  return kp_one("SELECT * FROM studio_jobs WHERE id = ?", [$id]);
}

/** Trabajo activo (en cola o ejecutándose) del proyecto. Marca como caídos los que no dan señales. */
function studio_active_job(int $pid): ?array {
  studio_ensure_schema();
  kp_exec("UPDATE studio_jobs SET status='failed', message='El proceso dejó de responder' WHERE project_id=? AND status IN ('queued','running') AND updated_at < datetime('now','-30 minutes')", [$pid]);
  return kp_one("SELECT * FROM studio_jobs WHERE project_id=? AND status IN ('queued','running') ORDER BY id DESC LIMIT 1", [$pid]);
}

function studio_last_job(int $pid): ?array {
  return kp_one("SELECT * FROM studio_jobs WHERE project_id=? ORDER BY id DESC LIMIT 1", [$pid]);
}

function studio_job_create(int $pid, string $type, array $params = []): int {
  studio_ensure_schema();
  kp_exec("INSERT INTO studio_jobs (project_id, type, params_json) VALUES (?,?,?)", [$pid, $type, json_encode($params, JSON_UNESCAPED_UNICODE)]);
  return (int)kp_db()->lastInsertId();
}

function studio_php_bin(): string {
  if (in_array(PHP_SAPI, ['cli', 'cli-server'], true)) return PHP_BINARY;
  foreach ([PHP_BINDIR . '/php', '/usr/bin/php', '/usr/local/bin/php'] as $c) if (is_executable($c)) return $c;
  return 'php';
}

/** Lanza el trabajo en un proceso independiente. Devuelve false si no se puede (se ejecutará en línea). */
function studio_job_spawn(int $jobId): bool {
  if (!studio_exec_available()) return false;
  $log = studio_root() . '/worker.log';
  $cmd = 'nohup ' . escapeshellarg(studio_php_bin()) . ' ' . escapeshellarg(__DIR__ . '/../worker.php') . ' ' . $jobId
       . ' >> ' . escapeshellarg($log) . ' 2>&1 &';
  $p = @proc_open($cmd, [], $pipes);
  if (!is_resource($p)) return false;
  proc_close($p);
  return true;
}

/** Ejecuta un trabajo (en el worker o en línea). */
function studio_job_run(int $jobId): void {
  $job = studio_job_get($jobId);
  if (!$job || $job['status'] !== 'queued') return;
  @set_time_limit(0);
  @ini_set('memory_limit', '1024M');
  kp_exec("UPDATE studio_jobs SET status='running', updated_at=datetime('now') WHERE id=?", [$jobId]);
  $last = 0.0;
  $progress = function (float $f, string $msg = '') use ($jobId, &$last) {
    $now = microtime(true);
    if ($now - $last < 0.7 && $f < 1) return;
    $last = $now;
    if ($msg !== '') kp_exec("UPDATE studio_jobs SET progress=?, message=?, updated_at=datetime('now') WHERE id=?", [round($f, 3), $msg, $jobId]);
    else kp_exec("UPDATE studio_jobs SET progress=?, updated_at=datetime('now') WHERE id=?", [round($f, 3), $jobId]);
  };
  try {
    $handlers = kp_apply_filters('studio_job_handlers', studio_core_handlers());
    $fn = $handlers[$job['type']] ?? null;
    if (!$fn) throw new RuntimeException('Tipo de trabajo desconocido: ' . $job['type']);
    $res = $fn((int)$job['project_id'], json_decode($job['params_json'], true) ?: [], $progress);
    kp_exec("UPDATE studio_jobs SET status='done', progress=1, result_json=?, updated_at=datetime('now') WHERE id=?",
      [json_encode($res ?: new stdClass, JSON_UNESCAPED_UNICODE), $jobId]);
  } catch (Throwable $e) {
    kp_exec("UPDATE studio_jobs SET status='failed', message=?, updated_at=datetime('now') WHERE id=?", [$e->getMessage(), $jobId]);
    error_log('[studio] trabajo ' . $jobId . ' falló: ' . $e->getMessage());
  }
}

// ── Manejadores de los trabajos del núcleo ──────────────────────────────────

function studio_core_handlers(): array {
  return [
    'ingest'     => 'studio_job_ingest',
    'sync'       => 'studio_job_sync',
    'preview'    => 'studio_job_preview',
    'transcribe' => 'studio_job_transcribe',
    'render'     => 'studio_job_render',
  ];
}

function studio_job_ingest(int $pid, array $params, callable $progress): array {
  $proj = studio_project($pid);
  $pending = array_values(array_filter($proj['tracks'], fn($t) => empty($t['ready']) && !empty($t['src'])));
  if (!$pending) return ['tracks' => 0];
  foreach ($pending as $i => $t) {
    $progress($i / count($pending), 'Preparando «' . $t['name'] . '»…');
    $src = !empty($t['src_inbox']) ? studio_inbox() . '/' . basename($t['src']) : studio_pdir($pid, 'src') . '/' . basename($t['src']);
    try {
      $r = studio_ingest_track($pid, $t['id'], $src, fn($f) => $progress(($i + $f) / count($pending), 'Preparando «' . $t['name'] . '»…'));
    } catch (Throwable $e) {
      studio_update_project($pid, function (&$p) use ($t, $e) {
        foreach ($p['tracks'] as &$x) if ($x['id'] === $t['id']) $x['error'] = $e->getMessage();
      });
      throw $e;
    }
    studio_update_project($pid, function (&$p) use ($t, $r, $src) {
      foreach ($p['tracks'] as &$x) if ($x['id'] === $t['id']) {
        $x['ready'] = true; $x['duration'] = round($r['duration'], 2); unset($x['error']);
        if (empty($x['src_inbox'])) { @unlink($src); unset($x['src']); }
      }
      $p['status'] = 'ingested';
    });
  }
  return ['tracks' => count($pending)];
}

function studio_job_sync(int $pid, array $params, callable $progress): array {
  $proj = studio_project($pid);
  $tracks = array_values(array_filter($proj['tracks'], fn($t) => !empty($t['ready'])));
  if (count($tracks) < 1) throw new RuntimeException('Primero prepara las pistas de audio');
  $byId = []; foreach ($tracks as $t) $byId[$t['id']] = $t;
  $anchor = $tracks[0]['id'];
  $abs = [$anchor => 0.0]; $conf = [$anchor => null]; $info = [];
  $envs = [];
  $env = function ($tid) use (&$envs, $pid) { return $envs[$tid] ??= studio_env_load($pid, $tid); };

  // Resuelve cada pista contra su "sync_to" (por defecto, el ancla); respeta las pistas con desfase manual.
  // Si la referencia tiene fallos (pierde audio / mete silencio), la pista se recorta para seguirla.
  $pending = array_keys($byId); unset($pending[array_search($anchor, $pending)]);
  $guard = 0; $n = 0; $total = max(1, count($pending));
  while ($pending && $guard++ < 20) {
    foreach ($pending as $k => $tid) {
      $t = $byId[$tid];
      $to = $t['sync_to'] ?? $anchor;
      if (!isset($byId[$to]) || $to === $tid) $to = $anchor;
      if (!isset($abs[$to])) continue;                      // su referencia aún no está resuelta
      $progress($n / $total * 0.7, 'Sincronizando «' . $t['name'] . '»…');
      if (!empty($t['manual_offset'])) {
        studio_unbake_track($pid, $tid);
        $abs[$tid] = (float)$t['offset']; $conf[$tid] = null; $info[$tid] = ['segments' => [], 'breaks' => []];
        $dur = studio_wav_info(studio_track_paths($pid, $tid)['wav']); $info[$tid]['duration'] = $dur['bytes'] / 2 / $dur['sr'];
        $info[$tid]['ncc'] = round(studio_ncc100($env($to), studio_env_load($pid, $tid), 0, 1e9, (float)$t['offset'] - $abs[$to]), 3);
      } else {
        $P = studio_track_paths($pid, $tid);
        $rawEnv = studio_env_read($P['src_env']);
        $rawInfo = studio_wav_info($P['src_wav']);
        $rawDur = $rawInfo['bytes'] / 2 / $rawInfo['sr'];
        $r = studio_xcorr($env($to), $rawEnv);              // pista[t] ≈ referencia[t+offset]
        $seg = empty($t['no_segments'])
          ? studio_segment_sync($env($to), $rawEnv, $r['offset'], $rawDur)
          : ['segments' => [['s' => $r['offset'], 'e' => $r['offset'] + $rawDur, 'off' => $r['offset']]], 'breaks' => []];
        if (count($seg['segments']) > 1) {
          $dur = studio_bake_track($pid, $tid, $seg['segments']);
        } else {
          studio_unbake_track($pid, $tid);
          $dur = $rawDur;
        }
        unset($envs[$tid]);
        // Calidad real del resultado: correlación entre la referencia y la pista ya alineada
        $ncc = round(studio_ncc100($env($to), studio_env_load($pid, $tid), 0, 1e9, $seg['segments'][0]['s']), 3);
        $abs[$tid] = $abs[$to] + $seg['segments'][0]['s'];
        $conf[$tid] = $r['confidence'];
        $info[$tid] = ['segments' => count($seg['segments']) > 1 ? $seg['segments'] : [], 'breaks' => $seg['breaks'], 'duration' => $dur, 'ncc' => $ncc];
      }
      unset($pending[$k]); $n++;
    }
  }
  foreach ($pending as $tid) { $abs[$tid] = 0.0; $conf[$tid] = 0.0; $info[$tid] = ['segments' => [], 'breaks' => []]; }
  $min = min($abs);
  $retranscribe = [];
  studio_update_project($pid, function (&$p) use ($abs, $conf, $min, $info, &$retranscribe, $pid) {
    foreach ($p['tracks'] as &$t) {
      $id = $t['id'];
      if (!isset($abs[$id])) continue;
      $t['offset'] = round($abs[$id] - $min + 0.0, 3);
      $t['sync_conf'] = $conf[$id];
      $t['sync_ncc'] = $info[$id]['ncc'] ?? null;
      $sig = md5(json_encode($info[$id]['segments'] ?? []));
      $t['breaks'] = $info[$id]['breaks'] ?? [];
      if (isset($info[$id]['duration'])) $t['duration'] = round($info[$id]['duration'], 2);
      // Si el audio de la pista cambió (recortes nuevos o distintos), su transcripción ya no vale
      if (($t['segments_sig'] ?? md5('[]')) !== $sig) {
        if (!empty($t['transcribed'])) {
          $t['transcribed'] = false; $retranscribe[] = $t['name'];
          @unlink(studio_transcript_path($pid, $id));
          $p['cuts']['deleted'] = array_values(array_filter($p['cuts']['deleted'], fn($k) => !str_starts_with($k, $id . ':')));
        }
        $t['segments_sig'] = $sig;
      }
    }
    $p['status'] = 'synced';
  });
  $proj = studio_project($pid);
  $progress(0.72, 'Creando vista previa…');
  studio_build_preview($pid, $proj, fn($f) => $progress(0.72 + 0.28 * $f, 'Creando vista previa…'));
  return ['anchor' => $anchor, 'retranscribe' => $retranscribe];
}

function studio_job_preview(int $pid, array $params, callable $progress): array {
  studio_build_preview($pid, studio_project($pid), fn($f) => $progress($f, 'Creando vista previa…'));
  return [];
}

function studio_job_transcribe(int $pid, array $params, callable $progress): array {
  $proj = studio_project($pid);
  $voices = studio_voice_tracks($proj);
  if (!$voices) throw new RuntimeException('No hay pistas de voz listas para transcribir');
  $only = $params['tracks'] ?? null;
  $n = count($voices); $i = 0;
  foreach ($voices as $t) {
    if (is_array($only) && !in_array($t['id'], $only, true)) { $i++; continue; }
    $progress($i / $n, 'Transcribiendo «' . $t['name'] . '»…');
    studio_transcribe_track($pid, $t, fn($f) => $progress(($i + $f) / $n, 'Transcribiendo «' . $t['name'] . '»…'));
    studio_mark_fillers($pid, $t['id']);
    $i++;
  }
  return [];
}

/** Tras transcribir una pista: marca para corte sus sonidos de relleno (y descarta ediciones previas de esa pista). */
function studio_mark_fillers(int $pid, string $tid): void {
  $words = array_filter(studio_words(studio_project($pid)), fn($w) => $w['tid'] === $tid);
  $auto = in_array(studio_opt('auto_fillers'), ['1', 1, true], true);
  studio_update_project($pid, function (&$p) use ($tid, $words, $auto) {
    $keep = array_values(array_filter($p['cuts']['deleted'], fn($k) => !str_starts_with($k, $tid . ':')));
    if ($auto) foreach ($words as $w) if ($w['kind'] === 'f') $keep[] = $w['k'];
    $p['cuts']['deleted'] = $keep;
    foreach ($p['tracks'] as &$x) if ($x['id'] === $tid) $x['transcribed'] = true;
    $p['status'] = 'transcribed';
  });
}

function studio_job_render(int $pid, array $params, callable $progress): array {
  $res = studio_render($pid, $progress);
  studio_update_project($pid, function (&$p) use ($res) { $p['result'] = $res; $p['status'] = 'rendered'; });
  return $res;
}
