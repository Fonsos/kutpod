<?php
// ============================================================================
// KutPod · instalador
// ============================================================================
// Uso:
//   CLI:  php php/cli/install.php
//   WEB:  abrir https://www.tupodcast.com/cli/install.php  (se autodesactiva tras instalar)
//
// Crea: BD SQLite, esquema, tablas OP3, usuario owner inicial, .env con
// APP_KEY y marcador install.lock para evitar reejecución.
// ============================================================================

$ROOT = dirname(__DIR__);
$DB   = $ROOT . '/storage/kutpod.db';
$LOCK = $ROOT . '/storage/install.lock';
$ENV  = $ROOT . '/.env';
$cli  = php_sapi_name() === 'cli';

function out(string $msg, string $tag = 'info'): void {
  global $cli;
  if ($cli) { echo "[" . str_pad($tag, 5) . "] $msg\n"; return; }
  $color = ['info'=>'#666','ok'=>'#0a7','err'=>'#c33','warn'=>'#c80'][$tag] ?? '#333';
  echo "<div style='font-family:ui-monospace,monospace;font-size:13px;padding:4px 12px;color:$color'>[$tag] " . htmlspecialchars($msg) . "</div>";
}

function ask(string $q, string $default = ''): string {
  global $cli;
  if (!$cli) return $_POST[$q] ?? $default;
  echo "  $q" . ($default ? " [$default]" : '') . ": ";
  $a = trim(fgets(STDIN));
  return $a !== '' ? $a : $default;
}

// ---------------------------------------------------------------------------
// Si NO es CLI y no hay POST, muestra formulario web
// ---------------------------------------------------------------------------
if (!$cli && $_SERVER['REQUEST_METHOD'] !== 'POST') {
  if (file_exists($LOCK)) {
    http_response_code(403);
    exit('<h1>KutPod ya está instalado.</h1><p>Borra <code>storage/install.lock</code> para reinstalar.</p>');
  }
  ?>
  <!doctype html><html lang="es"><head><meta charset="utf-8"><title>Instalar KutPod</title>
  <style>body{font-family:system-ui;max-width:520px;margin:60px auto;padding:0 24px;color:#222}
  h1{font-size:28px;letter-spacing:-0.02em}label{display:block;margin:14px 0 4px;font-size:13px;font-weight:600}
  input{width:100%;padding:10px 12px;border:1px solid #ddd;border-radius:8px;font-size:14px;box-sizing:border-box}
  button{margin-top:20px;padding:12px 20px;background:#5b8def;color:white;border:0;border-radius:8px;font-weight:600;cursor:pointer;font-size:14px}
  small{color:#888}</style></head><body>
  <h1>Instalar KutPod</h1>
  <p>Configura tu instancia. Esto se ejecuta una sola vez.</p>
  <form method="POST">
    <label>Nombre de la instancia</label><input name="instance" value="KutPod" required>
    <label>Dominio</label><input name="domain" value="<?= htmlspecialchars($_SERVER['HTTP_HOST'] ?? 'www.tupodcast.com') ?>" required>
    <label>Tu nombre</label><input name="name" required>
    <label>Email de admin</label><input name="email" type="email" required>
    <label>Contraseña <small>(mín. 8 caracteres)</small></label><input name="password" type="password" minlength="8" required>
    <!-- Sin podcast demo: el instalador deja la BD lista para datos reales. -->
    <button type="submit">Instalar</button>
  </form></body></html>
  <?php
  exit;
}

// ---------------------------------------------------------------------------
// Ejecución
// ---------------------------------------------------------------------------
if (!$cli) echo "<!doctype html><body style='background:#fafafa;padding:40px;font-family:system-ui'><h1>Instalando KutPod…</h1>";

if (file_exists($LOCK)) { out('Ya instalado. Borra storage/install.lock para reinstalar.', 'err'); exit(1); }

// Validar entorno
if (!extension_loaded('pdo_sqlite')) { out('Falta extensión php-sqlite3 / pdo_sqlite', 'err'); exit(1); }
out('PHP ' . PHP_VERSION . ' · pdo_sqlite OK', 'ok');

foreach (['storage', 'storage/uploads', 'storage/pages', 'storage/geoip', 'storage/backups', 'media', 'media/audio', 'media/covers'] as $d) {
  $p = $ROOT . '/' . $d;
  if (!is_dir($p)) { @mkdir($p, 0775, true); out("creado $d/", 'ok'); }
  if (!is_writable($p)) { out("$d/ no es escribible (chmod 775)", 'warn'); }
}

// Datos
$instance = $cli ? ask('Nombre de la instancia', 'KutPod') : ($_POST['instance'] ?? 'KutPod');
$domain   = $cli ? ask('Dominio', 'www.tupodcast.com')               : ($_POST['domain']   ?? 'www.tupodcast.com');
$name     = $cli ? ask('Tu nombre')                          : ($_POST['name']     ?? '');
$email    = $cli ? ask('Email de admin')                     : ($_POST['email']    ?? '');
$password = $cli ? ask('Contraseña (mín. 8)')                : ($_POST['password'] ?? '');
if (!$name || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
  out('Datos inválidos. Email válido y contraseña ≥ 8 caracteres.', 'err'); exit(1);
}

// BD
try {
  $pdo = new PDO('sqlite:' . $DB);
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $pdo->exec('PRAGMA foreign_keys = ON');
  $pdo->exec('PRAGMA journal_mode = WAL');
  out("BD creada en $DB", 'ok');
} catch (Throwable $e) { out('No se pudo abrir SQLite: ' . $e->getMessage(), 'err'); exit(1); }

// Schema base
$schema = file_get_contents($ROOT . '/storage/schema.sql');
// Sentencia a sentencia: schema.sql incluye índices sobre tablas OP3 que solo crea kp_op3_ensure_schema()
// (más abajo); con un único exec() la instalación se detenía en el primero. Los que fallan se reintentan después.
$pendientes = [];
foreach (preg_split('/;[ \t]*\r?\n/', $schema) as $stmt) {
  $stmt = trim($stmt);
  if ($stmt === '') continue;
  try { $pdo->exec($stmt); } catch (Throwable $e) { $pendientes[] = $stmt; }
}
out('Esquema base aplicado · ' . substr_count($schema, 'CREATE TABLE') . ' tablas', 'ok');

// Schema OP3 (idempotente, lo hace el tracker)
require_once $ROOT . '/includes/op3-tracker.php';
kp_op3_ensure_schema();
out('Tablas OP3 listas (hits, downloads, stats_daily, geo, apps, botip_rules)', 'ok');

foreach ($pendientes as $stmt) {
  try { $pdo->exec($stmt); }
  catch (Throwable $e) { out('No se pudo aplicar una sentencia del esquema: ' . $e->getMessage(), 'err'); exit(1); }
}

// Usuario owner
$hash = password_hash($password, PASSWORD_BCRYPT);
$pdo->prepare("INSERT INTO users (email,name,password_hash,role) VALUES (?,?,?,'owner')")
    ->execute([$email, $name, $hash]);
$uid = (int)$pdo->lastInsertId();
out("Usuario owner #$uid creado · $email", 'ok');

// .env con APP_KEY + dominio + instancia
$appKey = bin2hex(random_bytes(32));
$env = "APP_NAME=\"$instance\"\nAPP_DOMAIN=$domain\nAPP_KEY=$appKey\nAPP_DB=storage/kutpod.db\nAPP_ENV=production\n";
file_put_contents($ENV, $env);
@chmod($ENV, 0600);
out('.env escrito · APP_KEY generada (32 bytes)', 'ok');

// settings · nombre/dominio en BD para que Preferencias los lea
$pdo->exec("CREATE TABLE IF NOT EXISTS settings (k TEXT PRIMARY KEY, v TEXT, updated_at TEXT DEFAULT (datetime('now')))");
$set = $pdo->prepare("INSERT INTO settings (k,v) VALUES (?,?)
                      ON CONFLICT(k) DO UPDATE SET v=excluded.v, updated_at=datetime('now')");
$set->execute(['instance_name', $instance]);
$set->execute(['instance_domain', $domain]);
$set->execute(['owner_name', $name]);
$set->execute(['owner_email', $email]);
$set->execute(['installed_at', date('c')]);
out('settings cargadas', 'ok');

// Seed bot rules (offline, ~5 patrones). Después sincroniza desde el panel.
$count = (int)$pdo->query("SELECT count(*) FROM op3_botip_rules")->fetchColumn();
out("Reglas anti-bot: $count cargadas · sincroniza desde Preferencias para la lista completa", 'ok');

// Sin páginas ni podcasts demo · BD queda lista para datos reales

// Lock
file_put_contents($LOCK, date('c') . " · instalado por $email\n");
@chmod($LOCK, 0644);
out('install.lock escrito · instalador desactivado', 'ok');

// Resumen final
if (!$cli) {
  echo "<div style='background:#0a7;color:white;padding:20px 24px;border-radius:12px;margin-top:24px;font-family:system-ui'>
        <h2 style='margin:0 0 8px'>✓ KutPod instalado</h2>
        <p style='margin:0 0 16px'>Inicia sesión con <strong>$email</strong> · contraseña la que pusiste arriba.</p>
        <a href='/admin' style='display:inline-block;padding:10px 16px;background:white;color:#0a7;border-radius:8px;text-decoration:none;font-weight:600'>Ir al Studio →</a>
        <a href='/' style='display:inline-block;padding:10px 16px;color:white;border:1px solid rgba(255,255,255,0.4);border-radius:8px;text-decoration:none;font-weight:600;margin-left:8px'>Ver sitio público</a>
        </div>
        <details style='margin-top:24px;font-family:system-ui;font-size:13px;color:#666'>
        <summary>Próximos pasos</summary>
        <ol>
          <li>Configura el cron: <code>*/15 * * * * php " . realpath($ROOT . '/cli/op3-worker.php') . "</code></li>
          <li>Entra a <strong>Preferencias → OP3</strong> y haz click en <em>Sincronizar reglas</em> para descargar la lista completa de bot-IPs.</li>
          <li>(Opcional) Descarga GeoLite2-City.mmdb a <code>storage/geoip/</code> si no estás detrás de Cloudflare.</li>
          <li>Borra o protege <code>cli/install.php</code> para mayor seguridad.</li>
        </ol>
        </details></body>";
} else {
  echo "\n";
  out("Instalado. Inicia sesión en https://$domain con $email", 'ok');
  out("Siguiente paso: cron OP3 → */15 * * * * php $ROOT/cli/op3-worker.php", 'info');
  out("Y sincroniza reglas anti-bot desde Preferencias → OP3.", 'info');
}
