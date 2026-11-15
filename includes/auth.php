<?php
// ============================================================================
// KutPod · autenticación + sesiones + roles
// ============================================================================
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

function kp_login(string $email, string $password): ?array {
  $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
  $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 120);

  // Rate limiting: máximo 5 intentos por IP en 15 minutos
  if (kp_login_rate_limited($ip)) {
    kp_alert('security', 'Bloqueo por intentos excesivos', "IP $ip bloqueada tras demasiados intentos de acceso fallidos.", '', ['actor_name' => $email]);
    return null;
  }

  $u = kp_one("SELECT * FROM users WHERE email = ?", [strtolower(trim($email))]);
  if (!$u || !password_verify($password, $u['password_hash'])) {
    kp_login_record_failure($ip);
    kp_alert('security', 'Login fallido', "Intento de acceso con email '$email' desde $ip.", '', ['actor_name' => $email]);
    return null;
  }

  if (!empty($u['totp_secret'])) {
    return ['2fa_pending' => true, 'user_id' => $u['id']];
  }

  $token = bin2hex(random_bytes(32));
  $expires = gmdate('Y-m-d H:i:s', time() + 60*60*24*30); // 30 días
  kp_exec("INSERT INTO sessions (token,user_id,ip,user_agent,expires_at) VALUES (?,?,?,?,?)",
    [$token, $u['id'], $ip, substr($ua, 0, 240), $expires]);
  kp_db()->prepare("UPDATE users SET last_login = datetime('now') WHERE id = ?")->execute([$u['id']]);

  $secure = kp_get_protocol() === 'https';
  setcookie('kp_sess', $token, ['expires' => time()+60*60*24*30, 'path'=>'/', 'httponly'=>true, 'secure'=>$secure, 'samesite'=>'Lax']);
  kp_alert('security', 'Inicio de sesión', "{$u['name']} accedió al panel desde $ip.", '/admin', ['actor_name' => $u['name']]);
  unset($u['password_hash']);
  return $u;
}

function kp_logout(): void {
  $tok = $_COOKIE['kp_sess'] ?? null;
  $u = kp_current_user();
  if ($tok) kp_db()->prepare("DELETE FROM sessions WHERE token = ?")->execute([$tok]);
  $secure = kp_get_protocol() === 'https';
  setcookie('kp_sess', '', ['expires'=>time()-3600, 'path'=>'/', 'httponly'=>true, 'secure'=>$secure, 'samesite'=>'Lax']);
  if ($u) kp_alert('security', 'Cierre de sesión', "{$u['name']} cerró sesión.", '', ['actor_name' => $u['name']]);
}

function kp_current_user(): ?array {
  static $cache = false;
  if ($cache !== false) return $cache;
  $tok = $_COOKIE['kp_sess'] ?? null;
  if (!$tok) return $cache = null;
  $row = kp_one("SELECT u.id,u.email,u.name,u.role,u.avatar
                 FROM sessions s JOIN users u ON u.id = s.user_id
                 WHERE s.token = ? AND s.expires_at > datetime('now')", [$tok]);
  return $cache = $row;
}

function kp_require_auth(): array {
  $u = kp_current_user();
  if (!$u) { header('Location: /admin/login'); exit; }
  return $u;
}

function kp_require_role(string ...$roles): array {
  $u = kp_require_auth();
  if (!in_array($u['role'], $roles, true)) {
    http_response_code(403);
    exit('403 · Acceso denegado');
  }
  return $u;
}

function kp_can_edit_podcast(int $podcastId, ?array $user = null): bool {
  $u = $user ?? kp_current_user();
  if (!$u) return false;
  if (in_array($u['role'], ['owner','admin'], true)) return true;
  $row = kp_one("SELECT 1 FROM podcast_users WHERE podcast_id = ? AND user_id = ?", [$podcastId, $u['id']]);
  return (bool) $row;
}

// ── CSRF ────────────────────────────────────────────────────────────

function kp_csrf_ensure_session(): void {
  if (session_status() === PHP_SESSION_NONE) {
    session_start();
  }
}

/** Genera u obtiene el token CSRF de la sesión actual. */
function kp_csrf_token(): string {
  kp_csrf_ensure_session();
  if (empty($_SESSION['_csrf_token'])) {
    $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
  }
  return $_SESSION['_csrf_token'];
}

/** Genera un campo hidden HTML con el token CSRF. */
function kp_csrf_field(): string {
  return '<input type="hidden" name="_csrf" value="' . kp_csrf_token() . '">';
}

/** Verifica el token CSRF del POST actual. Aborta con 403 si falla. */
function kp_csrf_verify(): void {
  kp_csrf_ensure_session();
  $token = $_POST['_csrf'] ?? '';
  if ($token === '' || empty($_SESSION['_csrf_token']) || !hash_equals($_SESSION['_csrf_token'], $token)) {
    http_response_code(403);
    exit('403 · Token CSRF inválido. Recarga la página e intenta de nuevo.');
  }
}

// ── Rate Limiting ───────────────────────────────────────────────────

function kp_login_ensure_table(): void {
  static $done = false;
  if ($done) return;
  try {
    kp_db()->exec("CREATE TABLE IF NOT EXISTS login_attempts (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      ip TEXT NOT NULL,
      attempted_at INTEGER NOT NULL
    )");
    kp_db()->exec("CREATE INDEX IF NOT EXISTS idx_login_attempts_ip ON login_attempts(ip, attempted_at)");
  } catch (Throwable $e) {}
  $done = true;
}

/** Registra un intento de login fallido para la IP dada. */
function kp_login_record_failure(string $ip): void {
  kp_login_ensure_table();
  try {
    kp_db()->prepare("INSERT INTO login_attempts (ip, attempted_at) VALUES (?, ?)")
           ->execute([$ip, time()]);
    // Limpiar entradas antiguas (> 1 hora)
    kp_db()->prepare("DELETE FROM login_attempts WHERE attempted_at < ?")->execute([time() - 3600]);
  } catch (Throwable $e) {}
}

/** Comprueba si la IP ha excedido el límite de intentos (5 en 15 min). */
function kp_login_rate_limited(string $ip, int $maxAttempts = 5, int $windowSecs = 900): bool {
  kp_login_ensure_table();
  try {
    $cutoff = time() - $windowSecs;
    $row = kp_one("SELECT COUNT(*) as cnt FROM login_attempts WHERE ip = ? AND attempted_at > ?", [$ip, $cutoff]);
    return ($row && (int)$row['cnt'] >= $maxAttempts);
  } catch (Throwable $e) {
    return false;
  }
}

// ── TOTP / 2FA Helpers ──────────────────────────────────────────────

function kp_totp_base32_decode(string $base32): string {
  $base32Chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  $base32CharsFlipped = array_flip(str_split($base32Chars));
  $base32 = strtoupper($base32);
  $base32 = preg_replace('/[^A-Z2-7]/', '', $base32);
  $len = strlen($base32);
  $n = 0;
  $j = 0;
  $binary = '';
  for ($i = 0; $i < $len; $i++) {
    $n = ($n << 5) | $base32CharsFlipped[$base32[$i]];
    $j += 5;
    if ($j >= 8) {
      $j -= 8;
      $binary .= chr(($n >> $j) & 0xFF);
    }
  }
  return $binary;
}

function kp_totp_generate(string $secret, ?int $time = null): string {
  if ($time === null) {
    $time = time();
  }
  $key = kp_totp_base32_decode($secret);
  $timeStep = 30;
  $timeCode = (int)floor($time / $timeStep);
  $timePacked = pack('N*', 0) . pack('N*', $timeCode);
  $hmac = hash_hmac('sha1', $timePacked, $key, true);
  $offset = ord($hmac[19]) & 0x0F;
  $value = unpack('N', substr($hmac, $offset, 4))[1] & 0x7FFFFFFF;
  $code = $value % 1000000;
  return str_pad((string)$code, 6, '0', STR_PAD_LEFT);
}

function kp_totp_verify(string $secret, string $code, int $window = 1): bool {
  $time = time();
  for ($i = -$window; $i <= $window; $i++) {
    if (hash_equals(kp_totp_generate($secret, $time + $i * 30), $code)) {
      return true;
    }
  }
  return false;
}

function kp_totp_generate_secret(int $length = 16): string {
  $base32Chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  $secret = '';
  for ($i = 0; $i < $length; $i++) {
    $secret .= $base32Chars[random_int(0, 31)];
  }
  return $secret;
}
