<?php
// ============================================================================
// KutPod · Estudio · núcleo: rutas, esquema, proyectos, ajustes y procesos
// ============================================================================
if (!defined('KUTPOD_VERSION')) { exit; }

require_once __DIR__ . '/../../../includes/helpers.php';
require_once __DIR__ . '/../../../includes/db.php';

const STUDIO_SR = 44100;   // frecuencia de las copias de trabajo (mono, 16 bit)

// ── Ajustes ─────────────────────────────────────────────────────────────────

function studio_defaults(): array {
  return [
    'engine'        => 'api',                       // api | faster-whisper
    'api_url'       => 'https://api.openai.com/v1',
    'api_key'       => '',
    'api_model'     => 'whisper-1',
    'fw_model'      => 'large-v3',
    'fw_device'     => 'auto',
    'python'        => 'python3',
    'language'      => 'es',
    'prompt'        => 'Eeeh, mmm, o sea, eh... Pues, eeeh, vale. Mmm, ehh, bueno.',
    'filler_words'  => 'o sea, en plan, ¿vale?, ¿sabes?, ¿no?',
    'auto_fillers'  => '1',                         // marcar sonidos de relleno para corte
    'pad_ms'        => '15',                        // margen alrededor de cada corte
    'max_pause'     => '1.2',                       // s; 0 = no acortar pausas
    'trim_edges'    => '1',
    'denoise'       => '1',
    'compress'      => '1',
    'lufs'          => '-16',
    'channels'      => '2',
    'mp3_kbps'      => '128',
    'intro_overlap' => '0',
    'outro_overlap' => '0',
    'music_gain'    => '-3',                        // dB sobre las voces
  ];
}

function studio_opt(string $k) {
  $d = studio_defaults();
  $v = kp_setting('studio_' . $k, null);
  return ($v === null || $v === '') ? ($d[$k] ?? '') : $v;
}

// ── Rutas ───────────────────────────────────────────────────────────────────

function studio_root(): string {
  $d = realpath(__DIR__ . '/../../../storage') . '/studio';
  if (!is_dir($d)) @mkdir($d, 0775, true);
  return $d;
}
function studio_pdir(int $id, string $sub = ''): string {
  $d = studio_root() . '/p' . $id . ($sub !== '' ? '/' . $sub : '');
  if (!is_dir($d)) @mkdir($d, 0775, true);
  return $d;
}
function studio_inbox(): string {
  $d = studio_root() . '/inbox';
  if (!is_dir($d)) @mkdir($d, 0775, true);
  return $d;
}
function studio_library(int $podcastId): string {
  $d = studio_root() . '/library/' . $podcastId;
  if (!is_dir($d)) @mkdir($d, 0775, true);
  return $d;
}
function studio_safe_name(string $n): string {
  $n = preg_replace('/[^A-Za-z0-9._-]+/', '_', basename($n));
  return trim($n, '._') !== '' ? $n : 'audio';
}

// ── Esquema ─────────────────────────────────────────────────────────────────

function studio_ensure_schema(): void {
  static $done = false;
  if ($done) return;
  $pdo = kp_db();
  $pdo->exec("CREATE TABLE IF NOT EXISTS studio_projects (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    podcast_id    INTEGER NOT NULL REFERENCES podcasts(id) ON DELETE CASCADE,
    title         TEXT NOT NULL,
    status        TEXT NOT NULL DEFAULT 'new',
    tracks_json   TEXT NOT NULL DEFAULT '[]',
    music_json    TEXT NOT NULL DEFAULT '{}',
    settings_json TEXT NOT NULL DEFAULT '{}',
    cuts_json     TEXT NOT NULL DEFAULT '{}',
    result_json   TEXT NOT NULL DEFAULT '{}',
    episode_id    INTEGER,
    created_by    INTEGER,
    created_at    TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at    TEXT NOT NULL DEFAULT (datetime('now'))
  )");
  $pdo->exec("CREATE TABLE IF NOT EXISTS studio_jobs (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    project_id  INTEGER NOT NULL REFERENCES studio_projects(id) ON DELETE CASCADE,
    type        TEXT NOT NULL,
    status      TEXT NOT NULL DEFAULT 'queued',
    progress    REAL NOT NULL DEFAULT 0,
    message     TEXT,
    params_json TEXT NOT NULL DEFAULT '{}',
    result_json TEXT,
    created_at  TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at  TEXT NOT NULL DEFAULT (datetime('now'))
  )");
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_studio_jobs_p ON studio_jobs(project_id, id DESC)");
  $done = true;
}

// ── Proyectos ───────────────────────────────────────────────────────────────

function studio_project_defaults(): array {
  return [
    'intro_overlap' => (float)studio_opt('intro_overlap'),
    'outro_overlap' => (float)studio_opt('outro_overlap'),
    'music_gain'    => (float)studio_opt('music_gain'),
    'max_pause'     => (float)studio_opt('max_pause'),
    'denoise'       => (int)studio_opt('denoise'),
    'compress'      => (int)studio_opt('compress'),
    'lufs'          => (float)studio_opt('lufs'),
    'trim_edges'    => (int)studio_opt('trim_edges'),
  ];
}

function studio_project(int $id): ?array {
  studio_ensure_schema();
  $r = kp_one("SELECT * FROM studio_projects WHERE id = ?", [$id]);
  if (!$r) return null;
  $r['tracks']   = json_decode($r['tracks_json'], true) ?: [];
  $r['music']    = json_decode($r['music_json'], true) ?: [];
  $r['settings'] = array_merge(studio_project_defaults(), json_decode($r['settings_json'], true) ?: []);
  $r['cuts']     = json_decode($r['cuts_json'], true) ?: [];
  $r['cuts']    += ['deleted' => []];
  $r['result']   = json_decode($r['result_json'], true) ?: [];
  return $r;
}

function studio_save_project(array $p): void {
  kp_exec("UPDATE studio_projects SET title=?, status=?, tracks_json=?, music_json=?, settings_json=?, cuts_json=?, result_json=?, episode_id=?, updated_at=datetime('now') WHERE id=?", [
    $p['title'], $p['status'],
    json_encode($p['tracks'], JSON_UNESCAPED_UNICODE),
    json_encode($p['music'] ?: new stdClass, JSON_UNESCAPED_UNICODE),
    json_encode($p['settings'], JSON_UNESCAPED_UNICODE),
    json_encode($p['cuts'], JSON_UNESCAPED_UNICODE),
    json_encode($p['result'] ?: new stdClass, JSON_UNESCAPED_UNICODE),
    $p['episode_id'] ?? null, $p['id'],
  ]);
}

/** Relee, aplica $fn al proyecto y lo guarda (evita pisar cambios de otros procesos). */
function studio_update_project(int $id, callable $fn): array {
  $p = studio_project($id);
  if (!$p) throw new RuntimeException('Proyecto no encontrado');
  $fn($p);
  studio_save_project($p);
  return $p;
}

function studio_can_access(array $proj, ?array $user = null): bool {
  return kp_can_edit_podcast((int)$proj['podcast_id'], $user);
}

function studio_find_track(array $proj, string $tid): ?array {
  foreach ($proj['tracks'] as $t) if ($t['id'] === $tid) return $t;
  return null;
}

function studio_voice_tracks(array $proj): array {
  return array_values(array_filter($proj['tracks'], fn($t) => ($t['role'] ?? 'voice') === 'voice' && !empty($t['ready'])));
}

// ── Binarios y procesos ─────────────────────────────────────────────────────

function studio_bin(string $name): string {
  static $cache = [];
  if (isset($cache[$name])) return $cache[$name];
  foreach ([__DIR__ . '/../../../bin/' . $name, __DIR__ . '/../../../bin/' . $name . '.exe'] as $local) {
    if (is_file($local) && is_executable($local)) return $cache[$name] = $local;
  }
  return $cache[$name] = $name;
}

function studio_exec_available(): bool {
  if (!function_exists('proc_open')) return false;
  $dis = array_map('trim', explode(',', (string)ini_get('disable_functions')));
  return !in_array('proc_open', $dis, true);
}

/**
 * Ejecuta un comando (array, sin shell). Devuelve [código, stdout, stderr].
 * $onLine recibe cada línea de stdout (útil con -progress pipe:1).
 */
function studio_run(array $cmd, ?callable $onLine = null, ?string $cwd = null, bool $captureOut = true): array {
  $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
  if (!is_resource($proc)) return [-1, '', 'No se pudo lanzar ' . $cmd[0]];
  stream_set_blocking($pipes[1], false);
  stream_set_blocking($pipes[2], false);
  $out = ''; $err = ''; $buf = '';
  while (true) {
    $r = [$pipes[1], $pipes[2]]; $w = $e = null;
    if (feof($pipes[1]) && feof($pipes[2])) break;
    if (stream_select($r, $w, $e, 1) === false) break;
    foreach ($r as $s) {
      $chunk = fread($s, 65536);
      if ($chunk === '' || $chunk === false) continue;
      if ($s === $pipes[1]) {
        if ($captureOut) $out .= $chunk;
        if ($onLine) {
          $buf .= $chunk;
          while (($nl = strpos($buf, "\n")) !== false) {
            $onLine(rtrim(substr($buf, 0, $nl)));
            $buf = substr($buf, $nl + 1);
          }
        }
      } else {
        $err .= $chunk;
        if (strlen($err) > 262144) $err = substr($err, -131072);   // acota el log
      }
    }
  }
  fclose($pipes[1]); fclose($pipes[2]);
  $code = proc_close($proc);
  return [$code, $out, $err];
}

/**
 * Lanza ffmpeg. $totalSecs permite calcular el progreso (0-1) para $onProgress.
 * Devuelve [código, stderr].
 */
function studio_ffmpeg(array $args, ?float $totalSecs = null, ?callable $onProgress = null, ?string $cwd = null, string $loglevel = 'error'): array {
  $cmd = array_merge([studio_bin('ffmpeg'), '-hide_banner', '-nostdin', '-y', '-v', $loglevel, '-nostats', '-progress', 'pipe:1'], $args);
  [$code, , $err] = studio_run($cmd, function ($line) use ($totalSecs, $onProgress) {
    if ($onProgress && $totalSecs && str_starts_with($line, 'out_time_us=')) {
      $us = (float)substr($line, 12);
      if ($us > 0) $onProgress(min(1.0, $us / 1e6 / $totalSecs));
    }
  }, $cwd, false);
  return [$code, $err];
}

function studio_probe(string $file): ?array {
  [$code, $out] = studio_run([studio_bin('ffprobe'), '-v', 'quiet', '-print_format', 'json', '-show_format', '-show_streams', $file]);
  if ($code !== 0) return null;
  $j = json_decode($out, true);
  if (!$j) return null;
  $dur = (float)($j['format']['duration'] ?? 0);
  $a = null;
  foreach ($j['streams'] ?? [] as $s) if (($s['codec_type'] ?? '') === 'audio') { $a = $s; break; }
  if (!$a) return null;
  return ['duration' => $dur, 'sample_rate' => (int)($a['sample_rate'] ?? 0), 'channels' => (int)($a['channels'] ?? 0), 'codec' => $a['codec_name'] ?? ''];
}

function studio_fmt_time(float $s): string {
  $s = max(0, (int)round($s));
  return $s >= 3600 ? sprintf('%d:%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60) : sprintf('%d:%02d', intdiv($s, 60), $s % 60);
}
