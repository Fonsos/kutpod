<?php
// pages/preferences.php — preferencias de instancia + OP3
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/op3-stats.php';

// POST · guardar campos de instancia en tabla settings
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_form'] ?? '') === 'appearance') {
  $set = kp_db()->prepare("INSERT INTO settings (k,v) VALUES (?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v, updated_at=datetime('now')");
  
  foreach (['admin_theme', 'admin_accent', 'public_theme', 'public_accent'] as $k) {
      if (!empty($_POST[$k])) {
          $set->execute([$k, trim($_POST[$k])]);
      }
  }

  // Remove old cookie if it exists to clean up
  if (isset($_COOKIE['kp_theme'])) {
      setcookie('kp_theme', '', time() - 3600, '/');
  }

  setcookie('kp_flash', 'Apariencia actualizada', time() + 30, '/');
  header('Location: /admin/preferences'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_form'] ?? '') === 'instance') {
  $set = kp_db()->prepare("INSERT INTO settings (k,v) VALUES (?,?)
                           ON CONFLICT(k) DO UPDATE SET v=excluded.v, updated_at=datetime('now')");
  foreach (['instance_name','instance_domain','instance_tagline','owner_name','timezone'] as $k) {
    if (isset($_POST[$k])) $set->execute([$k, trim($_POST[$k])]);
  }
  
  if (!empty($_FILES['instance_logo']['tmp_name'])) {
    $ext = strtolower(pathinfo($_FILES['instance_logo']['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg', 'ico'], true)) $ext = 'png';
    $name_file = 'instance_logo_' . uniqid() . '.' . $ext;
    $dest = __DIR__ . '/../media/' . $name_file;
    if (move_uploaded_file($_FILES['instance_logo']['tmp_name'], $dest)) {
      $set->execute(['instance_logo', '/media/' . $name_file]);
    }
  }

  setcookie('kp_flash', 'Preferencias guardadas', time() + 30, '/');
  header('Location: /admin/preferences'); exit;
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_form'] ?? '') === 'smtp') {
  $set = kp_db()->prepare("INSERT INTO settings (k,v) VALUES (?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v, updated_at=datetime('now')");
  foreach (['smtp_host','smtp_port','smtp_user','smtp_pass','smtp_crypto','from_email'] as $k) {
    if (isset($_POST[$k])) $set->execute([$k, trim($_POST[$k])]);
  }

  $test_smtp = !empty($_POST['test_smtp']);
  if ($test_smtp) {
    require_once __DIR__ . '/../includes/mail.php';
    $to = $current_user['email'] ?? '';
    if ($to) {
      $subject = "Prueba de conexión SMTP - KutPod";
      $body = "¡Hola!\n\nEste es un mensaje de prueba para verificar que la configuración del servidor de correo en tu instancia de KutPod es correcta.\n\nFecha y hora: " . date('Y-m-d H:i:s') . "\n\nSaludos,\nEl equipo de KutPod";
      
      $ok = kp_send_mail($to, $subject, $body);
      if ($ok) {
        header('Location: /admin/preferences?smtp_test=success&to=' . urlencode($to) . '#correo'); exit;
      } else {
        header('Location: /admin/preferences?smtp_test=error#correo'); exit;
      }
    } else {
      header('Location: /admin/preferences?smtp_test=no_email#correo'); exit;
    }
  } else {
    setcookie('kp_flash', 'Configuración de correo guardada', time() + 30, '/');
    header('Location: /admin/preferences#correo'); exit;
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_form'] ?? '') === 'update_token') {
  $set = kp_db()->prepare("INSERT INTO settings (k,v) VALUES (?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v, updated_at=datetime('now')");
  $token = trim($_POST['update_github_token'] ?? '');
  $set->execute(['update_github_token', $token]);
  setcookie('kp_flash', $token ? 'Token de GitHub guardado' : 'Token de GitHub eliminado', time() + 30, '/');
  header('Location: /admin/preferences#update-token'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_form'] ?? '') === 'cache_settings') {
  $set = kp_db()->prepare("INSERT INTO settings (k,v) VALUES (?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v, updated_at=datetime('now')");
  $enable = !empty($_POST['enable_cache']) ? '1' : '0';
  $set->execute(['enable_cache', $enable]);
  setcookie('kp_flash', 'Preferencias de caché guardadas', time() + 30, '/');
  header('Location: /admin/preferences#mantenimiento'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_form'] ?? '') === 'cache_clear') {
  require_once __DIR__ . '/../includes/cache.php';
  kp_cache_clear();
  setcookie('kp_flash', 'Caché vaciada correctamente', time() + 30, '/');
  header('Location: /admin/preferences#mantenimiento'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_form'] ?? '') === 'db_cleanup') {
  require_once __DIR__ . '/../cli/cleanup.php';
  setcookie('kp_flash', 'Base de datos limpiada correctamente', time() + 30, '/');
  header('Location: /admin/preferences#mantenimiento'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_form'] ?? '') === 'queue_clear') {
  kp_db()->exec("DELETE FROM ap_delivery WHERE status='delivered'");
  setcookie('kp_flash', 'Procesos completados eliminados', time() + 30, '/');
  header('Location: /admin/preferences#mantenimiento'); exit;
}


$lastSync = kp_op3_last_sync();
$rulesCount = kp_op3_rules_count();
$inst = kp_instance();
?>
<div class="page-head">
  <div><h1 class="page-title">Preferencias</h1>
  <p class="page-sub">Instancia, apariencia, mantenimiento y API</p></div>
</div>


<div class="pref-layout">
  <nav style="position:sticky;top:90px;display:flex;flex-direction:column;gap:8px">
    <a href="#apariencia" class="pub-nav-link" style="padding:8px 12px;border-radius:8px;text-decoration:none;color:var(--text-2);font-weight:500">Apariencia</a>
    <a href="#instancia" class="pub-nav-link" style="padding:8px 12px;border-radius:8px;text-decoration:none;color:var(--text-2);font-weight:500">Instancia</a>
    <a href="#correo" class="pub-nav-link" style="padding:8px 12px;border-radius:8px;text-decoration:none;color:var(--text-2);font-weight:500">Correo Electrónico</a>
    <a href="#api" class="pub-nav-link" style="padding:8px 12px;border-radius:8px;text-decoration:none;color:var(--text-2);font-weight:500">API</a>
    <a href="#update-token" class="pub-nav-link" style="padding:8px 12px;border-radius:8px;text-decoration:none;color:var(--text-2);font-weight:500">Actualizaciones</a>
    <a href="#ffmpeg" class="pub-nav-link" style="padding:8px 12px;border-radius:8px;text-decoration:none;color:var(--text-2);font-weight:500">Dependencias (FFmpeg)</a>
    <a href="#op3" class="pub-nav-link" style="padding:8px 12px;border-radius:8px;text-decoration:none;color:var(--text-2);font-weight:500">OP3 (Métricas)</a>
    <a href="#mantenimiento" class="pub-nav-link" style="padding:8px 12px;border-radius:8px;text-decoration:none;color:var(--text-2);font-weight:500">Mantenimiento</a>
    <a href="#migracion" class="pub-nav-link" style="padding:8px 12px;border-radius:8px;text-decoration:none;color:var(--text-2);font-weight:500">Migración URL</a>
    <style>
      nav a.pub-nav-link:hover { background: var(--surface-2); color: var(--text); }
      nav a.pub-nav-link.active { background: var(--accent-soft); color: var(--accent); font-weight: 600 !important; }
      .pref-layout { display:grid; grid-template-columns:220px 1fr; gap:32px; align-items:start; }
      @media (max-width: 920px) {
        .pref-layout { grid-template-columns:1fr; }
        .pref-layout nav { position:relative !important; top:0 !important; flex-direction:row; overflow-x:auto; padding-bottom:8px; }
        .pref-layout nav a { white-space:nowrap; }
      }
    </style>
  </nav>

  <div class="grid-12" style="align-content:start;gap:18px">

  <form id="apariencia" class="card span-12" method="POST">
    <?= kp_csrf_field() ?>
    <input type="hidden" name="_form" value="appearance">
    <div class="card-title" style="margin-bottom:14px">Apariencia del Dashboard (Admin)</div>
    <div class="field-row">
      <div class="field">
        <div class="label">Tema de la interfaz</div>
        <select class="select" name="admin_theme">
          <?php $adm_th = kp_setting('admin_theme', 'dark'); ?>
          <option value="dark" <?= $adm_th === 'dark' ? 'selected' : '' ?>>Oscuro</option>
          <option value="light" <?= $adm_th === 'light' ? 'selected' : '' ?>>Claro</option>
        </select>
      </div>
      <div class="field">
        <div class="label">Color de acento</div>
        <select class="select" name="admin_accent">
          <?php $adm_acc = kp_setting('admin_accent', '#ff5f7e'); ?>
          <option value="#ff5f7e" <?= $adm_acc === '#ff5f7e' ? 'selected' : '' ?>>Rosa KutPod</option>
          <option value="#f97316" <?= $adm_acc === '#f97316' ? 'selected' : '' ?>>Naranja</option>
          <option value="#10b981" <?= $adm_acc === '#10b981' ? 'selected' : '' ?>>Verde</option>
          <option value="#3b82f6" <?= $adm_acc === '#3b82f6' ? 'selected' : '' ?>>Azul</option>
          <option value="#8b5cf6" <?= $adm_acc === '#8b5cf6' ? 'selected' : '' ?>>Violeta</option>
        </select>
      </div>
    </div>

    <div class="card-title" style="margin-bottom:14px;margin-top:24px;border-top:1px solid var(--border);padding-top:24px">Apariencia del Sitio Público (Home)</div>
    <div class="field-row">
      <div class="field">
        <div class="label">Tema del sitio</div>
        <select class="select" name="public_theme">
          <?php $pub_th = kp_setting('public_theme', 'light'); ?>
          <option value="light" <?= $pub_th === 'light' ? 'selected' : '' ?>>Claro</option>
          <option value="dark" <?= $pub_th === 'dark' ? 'selected' : '' ?>>Oscuro</option>
        </select>
      </div>
      <div class="field">
        <div class="label">Color de acento</div>
        <select class="select" name="public_accent">
          <?php $pub_acc = kp_setting('public_accent', '#ff5f7e'); ?>
          <option value="#ff5f7e" <?= $pub_acc === '#ff5f7e' ? 'selected' : '' ?>>Rosa KutPod</option>
          <option value="#f97316" <?= $pub_acc === '#f97316' ? 'selected' : '' ?>>Naranja</option>
          <option value="#10b981" <?= $pub_acc === '#10b981' ? 'selected' : '' ?>>Verde</option>
          <option value="#3b82f6" <?= $pub_acc === '#3b82f6' ? 'selected' : '' ?>>Azul</option>
          <option value="#8b5cf6" <?= $pub_acc === '#8b5cf6' ? 'selected' : '' ?>>Violeta</option>
        </select>
      </div>
    </div>

    <button class="btn btn-primary" type="submit" style="margin-top:20px">Aplicar apariencia</button>
  </form>

  <form id="instancia" class="card span-12" method="POST" enctype="multipart/form-data">
    <?= kp_csrf_field() ?>
    <input type="hidden" name="_form" value="instance">
    <div class="card-title" style="margin-bottom:14px">Instancia</div>
    <div class="field"><div class="label">Nombre de la instancia</div><input class="input" name="instance_name" value="<?= e($inst['name']) ?>"></div>
    <div class="field" style="margin-top:12px"><div class="label">Propietario ("Podcasts de...")</div><input class="input" name="owner_name" value="<?= e($inst['owner']) ?>" placeholder="Ernesto"></div>
    <div class="field" style="margin-top:12px"><div class="label">Dominio</div><input class="input" name="instance_domain" value="<?= e($inst['domain']) ?>"></div>
    <div class="field" style="margin-top:12px"><div class="label">Tagline (opcional)</div><input class="input" name="instance_tagline" value="<?= e($inst['tagline']) ?>" placeholder="Tres podcasts, un feed propio."></div>
    
    <div class="field" style="margin-top:12px">
      <div class="label">Zona horaria del servidor</div>
      <select class="select" name="timezone">
        <option value="">Por defecto del servidor (<?= e(date_default_timezone_get()) ?>)</option>
        <?php foreach (timezone_identifiers_list() as $tz): ?>
          <option value="<?= e($tz) ?>" <?= kp_setting('timezone') === $tz ? 'selected' : '' ?>><?= e($tz) ?></option>
        <?php endforeach; ?>
      </select>
      <div class="help" style="margin-top:4px">La hora actual según esta zona es: <strong><?= e(date('Y-m-d H:i:s')) ?></strong>. Útil para que la programación de episodios sea exacta.</div>
    </div>

    <div class="field" style="margin-top:12px">
      <div class="label">Logotipo / Favicon (opcional)</div>
      <div style="display:flex;align-items:center;gap:10px">
        <input class="input" type="file" name="instance_logo" id="instance_logo" accept="image/*" style="flex:1">
        <button id="instance_logo_cancel" type="button" class="btn btn-ghost" style="display:none;color:var(--red);padding:4px 8px;font-size:12px"><?= icon('x',12) ?> Cancelar</button>
      </div>
      <?php $logo = kp_setting('instance_logo'); if ($logo): ?>
        <div style="margin-top:6px"><img src="<?= e($logo) ?>" style="height:32px;border-radius:4px"></div>
      <?php endif; ?>
    </div>
    <button class="btn btn-primary" type="submit" style="margin-top:14px">Guardar</button>
  </form>


  <form id="correo" class="card span-12" method="POST">
    <?= kp_csrf_field() ?>
    <input type="hidden" name="_form" value="smtp">
    <?php if (isset($_GET['smtp_test'])): ?>
      <?php if ($_GET['smtp_test'] === 'success'): ?>
        <div class="card" style="padding:14px;margin-bottom:16px;border:1px solid rgba(16,185,129,0.3);background:rgba(16,185,129,0.05);color:var(--text);box-shadow:none">
          <div class="row" style="gap:10px;align-items:center">
            <div style="color:#10b981;display:grid;place-items:center"><?= icon('check',16) ?></div>
            <div style="font-size:13px;font-weight:500">¡Conexión SMTP exitosa! El correo de prueba se envió correctamente a <strong><?= e($_GET['to'] ?? '') ?></strong>.</div>
          </div>
        </div>
      <?php elseif ($_GET['smtp_test'] === 'error'): ?>
        <div class="card" style="padding:14px;margin-bottom:16px;border:1px solid rgba(239,68,68,0.3);background:rgba(239,68,68,0.05);color:var(--text);box-shadow:none">
          <div class="row" style="gap:10px;align-items:flex-start">
            <div style="color:#ef4444;margin-top:2px;display:grid;place-items:center"><?= icon('x',16) ?></div>
            <div class="col" style="gap:4px">
              <div style="font-size:13px;font-weight:600;color:#ef4444">Error al conectar con el servidor SMTP</div>
              <div style="font-size:12.5px;color:var(--text-3);line-height:1.4">No se pudo enviar el correo de prueba. Verifica las credenciales, el host, el puerto y el tipo de cifrado. Revisa los logs de error del servidor para ver el reporte de socket.</div>
            </div>
          </div>
        </div>
      <?php elseif ($_GET['smtp_test'] === 'no_email'): ?>
        <div class="card" style="padding:14px;margin-bottom:16px;border:1px solid rgba(245,158,11,0.3);background:rgba(245,158,11,0.05);color:var(--text);box-shadow:none">
          <div class="row" style="gap:10px;align-items:center">
            <div style="color:#f59e0b;display:grid;place-items:center"><?= icon('bell',16) ?></div>
            <div style="font-size:13px;font-weight:500">Error: No se encontró un correo electrónico configurado para tu usuario administrador.</div>
          </div>
        </div>
      <?php endif; ?>
    <?php endif; ?>
    <div class="card-title" style="margin-bottom:14px">Correo Electrónico (SMTP)</div>
    <div style="font-size:12.5px;color:var(--text-3);margin-bottom:12px">Configura un servidor SMTP para asegurar la entrega de correos del sistema (como los enlaces de recuperación de contraseñas). Si se deja en blanco, se usará la función nativa del sistema.</div>
    <div class="field-row">
      <div class="field">
        <div class="label">Host SMTP</div>
        <input class="input" name="smtp_host" value="<?= e(kp_setting('smtp_host', '')) ?>" placeholder="smtp.tuservidor.com">
      </div>
      <div class="field" style="flex:0.5">
        <div class="label">Puerto</div>
        <input class="input" type="number" name="smtp_port" value="<?= e(kp_setting('smtp_port', '465')) ?>" placeholder="465">
      </div>
    </div>
    <div class="field-row" style="margin-top:12px">
      <div class="field">
        <div class="label">Usuario SMTP</div>
        <input class="input" name="smtp_user" value="<?= e(kp_setting('smtp_user', '')) ?>" placeholder="usuario@tuservidor.com">
      </div>
      <div class="field">
        <div class="label">Contraseña SMTP</div>
        <input class="input" type="password" name="smtp_pass" value="<?= e(kp_setting('smtp_pass', '')) ?>" placeholder="••••••••">
      </div>
    </div>
    <div class="field-row" style="margin-top:12px">
      <div class="field">
        <div class="label">Cifrado</div>
        <select class="select" name="smtp_crypto">
          <?php $crypto = kp_setting('smtp_crypto', 'ssl'); ?>
          <option value="ssl" <?= $crypto==='ssl'?'selected':'' ?>>SSL/TLS (Recomendado)</option>
          <option value="tls" <?= $crypto==='tls'?'selected':'' ?>>STARTTLS</option>
          <option value="" <?= $crypto===''?'selected':'' ?>>Ninguno (Poco seguro)</option>
        </select>
      </div>
      <div class="field">
        <div class="label">Correo del Remitente (From)</div>
        <input class="input" type="email" name="from_email" value="<?= e(kp_setting('from_email', '')) ?>" placeholder="noreply@tuservidor.com">
      </div>
    </div>
    <div class="row" style="gap:10px;margin-top:14px">
      <button class="btn btn-primary" type="submit">Guardar configuración SMTP</button>
      <button class="btn" type="submit" name="test_smtp" value="1">Probar envío de correo</button>
    </div>
  </form>

  <div id="api" class="card span-12">
    <div class="card-head">
      <div>
        <div class="card-title">API y Contraseñas de Aplicación</div>
        <div style="font-size:12.5px;color:var(--text-3);margin-top:4px">
          Gestión de credenciales para múltiples instancias de KutEditor o aplicaciones externas.
        </div>
      </div>
    </div>
    <div style="font-size:13.5px;line-height:1.5;margin-top:14px">
      <p>KutPod admite múltiples <strong>Tokens de Aplicación</strong> para que puedas conectar diferentes dispositivos o instancias de KutEditor de forma independiente, con credenciales seguras que no se ven afectadas por la autenticación en dos pasos (2FA).</p>
      <div style="margin-top:16px">
        <a class="btn btn-primary" href="<?= admin_url('api') ?>" style="display:inline-flex;align-items:center;gap:6px;text-decoration:none">
          <?= icon('share', 13) ?> Ir al Gestor de Tokens de API
        </a>
      </div>
    </div>
  </div>

  <form class="card span-12" method="POST" id="update-token">
    <?= kp_csrf_field() ?>
    <input type="hidden" name="_form" value="update_token">
    <div class="card-title" style="margin-bottom:14px"><?= icon('refreshCw',16) ?> Actualizaciones (GitHub · Opcional para desarrollo)</div>
    <div style="font-size:12.5px;color:var(--text-3);margin-bottom:12px">Por defecto, KutPod se actualiza automáticamente a través de los servidores de KutStudio sin requerir tokens. Si eres desarrollador y deseas descargar directamente desde tu repositorio privado de GitHub, puedes configurar un token de acceso personal (PAT) aquí con permiso <code>Contents: Read</code>.</div>
    <div class="field">
      <div class="label">Personal Access Token (PAT) <span style="font-weight:normal;color:var(--text-3);font-size:11px">(dejar vacío para usar KutStudio Cloud)</span></div>
      <input class="input" name="update_github_token" value="<?= e(kp_setting('update_github_token', '')) ?>" placeholder="github_pat_xxxxxxxxxxxxxxxxx" style="font-family:ui-monospace,monospace">
    </div>
    <div class="row" style="gap:10px;margin-top:14px">
      <button class="btn btn-primary" type="submit">Guardar token</button>
      <a class="btn" href="/admin/update"><?= icon('search',13) ?> Ir a Actualizaciones</a>
    </div>
  </form>

  <div id="ffmpeg" class="card span-12">
    <div class="card-head">
      <div>
        <div class="card-title">Dependencias (FFmpeg)</div>
        <div style="font-size:12.5px;color:var(--text-3);margin-top:4px">
          KutPod requiere FFmpeg para extraer la duración de los audios y procesar imágenes.
        </div>
      </div>
      <?php
      require_once __DIR__ . '/../includes/ffmpeg.php';
      $has_ffmpeg = kp_has_ffmpeg();
      ?>
      <span class="pill"><?= $has_ffmpeg ? 'Instalado' : 'No instalado' ?></span>
    </div>

    <div class="row" style="gap:10px;margin-top:14px;flex-wrap:wrap">
      <button class="btn <?= $has_ffmpeg ? 'btn-ghost' : 'btn-primary' ?>" id="ffmpeg-install-btn" type="button" onclick="kpInstallFFmpeg(this)">
        <?= icon($has_ffmpeg ? 'refresh' : 'download', 13) ?> <?= $has_ffmpeg ? 'Reinstalar FFmpeg' : 'Instalar FFmpeg localmente' ?>
      </button>
      <div id="ffmpeg-status" style="font-size:12.5px;color:var(--text-3);align-self:center"></div>
    </div>

    <div style="font-size:12px;color:var(--text-3);margin-top:14px;line-height:1.55">
      Esto descargará los binarios estáticos de <a href="https://github.com/ffbinaries/ffbinaries-prebuilt" target="_blank" style="color:var(--accent)">ffbinaries</a> (formato ZIP para servidores Linux amd64) y los guardará en la carpeta <code>bin/</code> usando las extensiones nativas de PHP. Si estás en Windows o ARM, instálalo manualmente.
    </div>
  </div>


  <div id="op3" class="card span-12">
    <div class="card-head">
      <div>
        <div class="card-title">OP3 · Tracker nativo de descargas</div>
        <div style="font-size:12.5px;color:var(--text-3);margin-top:4px">
          Cálculo de descargas IAB v2 corriendo en tu servidor. No depende de op3.dev.
        </div>
      </div>
      <span class="pill"><?= kp_op3_tables_exist() ? 'Activo' : 'Pendiente de primer worker' ?></span>
    </div>

    <div class="grid-12" style="margin-top:14px;gap:12px">
      <div class="card span-4" style="background:var(--surface-2);padding:14px">
        <div style="font-size:11px;color:var(--text-3);text-transform:uppercase;letter-spacing:0.06em">Endpoint</div>
        <div style="font-family:ui-monospace,monospace;font-size:13px;margin-top:6px">/r/{show}/{ep}/audio.mp3</div>
      </div>
      <div class="card span-4" style="background:var(--surface-2);padding:14px">
        <div style="font-size:11px;color:var(--text-3);text-transform:uppercase;letter-spacing:0.06em">Reglas anti-bot</div>
        <div class="tabular" style="font-size:22px;font-weight:600;margin-top:2px"><?= number_format($rulesCount) ?></div>
        <div style="font-size:11px;color:var(--text-3)">cargadas en BD</div>
      </div>
      <div class="card span-4" style="background:var(--surface-2);padding:14px">
        <div style="font-size:11px;color:var(--text-3);text-transform:uppercase;letter-spacing:0.06em">Última sincronización</div>
        <div style="font-size:13px;margin-top:6px"><?= $lastSync ? e(date('d M Y · H:i', strtotime($lastSync['updated_at']))) : '—' ?></div>
        <?php if ($lastSync && isset($lastSync['result'])): ?>
          <div style="font-size:11px;color:var(--text-3);margin-top:2px">
            <?= (int)($lastSync['result']['hashes'] ?? 0) ?> hashes · <?= (int)($lastSync['result']['patterns'] ?? 0) ?> patrones
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="row" style="gap:10px;margin-top:14px;flex-wrap:wrap">
      <button class="btn btn-primary" id="op3-sync-btn" type="button" onclick="kpOp3Sync(this)">
        <?= icon('refresh',13) ?> Sincronizar reglas desde OP3
      </button>
      <a class="btn" href="/storage/OP3-README.md" target="_blank">
        <?= icon('file',13) ?> Ver documentación
      </a>
      <div id="op3-sync-status" style="font-size:12.5px;color:var(--text-3);align-self:center"></div>
    </div>

    <div style="font-size:12px;color:var(--text-3);margin-top:14px;line-height:1.55">
      Descarga la lista actual de patrones bot-IP desde el repo público de
      <a href="https://github.com/skymethod/op3" target="_blank" style="color:var(--accent)">skymethod/op3</a>
      y reemplaza las reglas locales. Se recomienda sincronizar semanalmente.
    </div>
  </div>

  <div id="mantenimiento" class="card span-12">
    <div class="card-head">
      <div>
        <div class="card-title">Mantenimiento</div>
        <div style="font-size:12.5px;color:var(--text-3);margin-top:4px">
          Gestión de caché, limpieza de base de datos y procesos.
        </div>
      </div>
      <span class="pill"><?= kp_setting('enable_cache', false) ? 'Caché Activada' : 'Caché Desactivada' ?></span>
    </div>

    <form method="POST" style="margin-top:14px;display:flex;align-items:center;gap:12px">
      <?= kp_csrf_field() ?>
      <input type="hidden" name="_form" value="cache_settings">
      <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
        <input type="checkbox" name="enable_cache" value="1" <?= kp_setting('enable_cache', false) ? 'checked' : '' ?>>
        <span style="font-size:13px">Habilitar caché local (almacenada en cache.db)</span>
      </label>
      <button class="btn btn-primary" type="submit" style="padding:4px 10px;font-size:12px">Guardar</button>
    </form>

    <div class="grid-12" style="margin-top:24px;border-top:1px solid var(--border);padding-top:24px;gap:16px">
      <form class="card span-4" method="POST" style="background:var(--surface-2);padding:16px">
        <?= kp_csrf_field() ?>
        <input type="hidden" name="_form" value="cache_clear">
        <div style="font-weight:600;font-size:13.5px;margin-bottom:6px">Caché del Sistema</div>
        <div style="font-size:12px;color:var(--text-3);margin-bottom:14px;line-height:1.5">Borra todos los elementos temporales de la caché inmediatamente.</div>
        <button class="btn" type="submit" style="background:#dc2626;color:white;border-color:#dc2626;font-weight:600;width:100%" onclick="return confirm('¿Seguro que deseas vaciar toda la caché actual?')"><?= icon('trash', 13) ?> Limpiar Caché</button>
      </form>

      <form class="card span-4" method="POST" style="background:var(--surface-2);padding:16px">
        <?= kp_csrf_field() ?>
        <input type="hidden" name="_form" value="db_cleanup">
        <div style="font-weight:600;font-size:13.5px;margin-bottom:6px">Base de Datos</div>
        <div style="font-size:12px;color:var(--text-3);margin-bottom:14px;line-height:1.5">Limpia el historial antiguo de notificaciones, sesiones y entregas.</div>
        <button class="btn" type="submit" style="background:#f59e0b;color:white;border-color:#f59e0b;font-weight:600;width:100%" onclick="return confirm('¿Limpiar historial de DB? Esta acción no se puede deshacer.')"><?= icon('trash', 13) ?> Limpiar DB</button>
      </form>

      <form class="card span-4" method="POST" style="background:var(--surface-2);padding:16px">
        <?= kp_csrf_field() ?>
        <input type="hidden" name="_form" value="queue_clear">
        <div style="font-weight:600;font-size:13.5px;margin-bottom:6px">Cola de Procesos</div>
        <div style="font-size:12px;color:var(--text-3);margin-bottom:14px;line-height:1.5">Borra el historial de procesos de entrega del Fediverso que ya fueron completados.</div>
        <button class="btn" type="submit" style="font-weight:600;width:100%" onclick="return confirm('¿Limpiar procesos entregados?');"><?= icon('trash', 13) ?> Limpiar Procesos</button>
      </form>
    </div>
  </div>

  <div id="migracion" class="card span-12">
    <div class="card-head">
      <div>
        <div class="card-title">Migrar URL base</div>
        <div style="font-size:12.5px;color:var(--text-3);margin-top:4px">
          Reescribe todas las URLs absolutas en la base de datos. Útil para mover de un dominio de pruebas al definitivo, o tras importar feeds de otro host.
        </div>
      </div>
    </div>

    <div class="grid-12" style="margin-top:14px;gap:12px">
      <div class="field span-6">
        <label class="label">URL actual (origen)</label>
        <input class="input" id="rw_from" placeholder="https://test.tupodcast.com" style="font-family:ui-monospace,monospace">
        <div class="help" style="margin-top:4px">Sin barra final. Ej: <code>https://import.tupodcast.com</code></div>
      </div>
      <div class="field span-6">
        <label class="label">URL nueva (destino)</label>
        <input class="input" id="rw_to" placeholder="https://www.tupodcast.com" style="font-family:ui-monospace,monospace">
        <div class="help" style="margin-top:4px">Sin barra final. Ej: <code>https://www.tupodcast.com</code></div>
      </div>
    </div>

    <div class="row" style="gap:10px;margin-top:14px;flex-wrap:wrap">
      <button class="btn" type="button" onclick="kpRewriteBaseUrl(true)">
        <?= icon('search',13) ?> Simular (dry-run)
      </button>
      <button class="btn btn-primary" type="button" onclick="kpRewriteBaseUrl(false)">
        <?= icon('arrowRight',13) ?> Reescribir ahora
      </button>
      <div id="rw_status" style="font-size:12.5px;color:var(--text-3);align-self:center"></div>
    </div>

    <pre id="rw_report" style="display:none;background:var(--surface-2);padding:14px;border-radius:8px;margin-top:14px;font-size:12px;overflow:auto;max-height:300px;font-family:ui-monospace,monospace;white-space:pre-wrap;color:var(--text)"></pre>

    <div style="font-size:12px;color:var(--text-3);margin-top:14px;line-height:1.55">
      <strong style="color:var(--text-2)">Qué se reescribe:</strong> portadas y banners de podcasts ·
      audio_url, cover, transcripciones, capítulos y notas de episodios · bloques de páginas estáticas ·
      custom tags XML del RSS. <strong style="color:var(--text-2)">No se toca:</strong> contraseñas, tokens,
      contadores ni datos de OP3 (los hashes son irreversibles).
      Recomendado: <em>simular</em> primero para ver qué cambia.
    </div>
  </div>
  </div>
</div>

<script>
const logoInput = document.getElementById('instance_logo');
const logoCancel = document.getElementById('instance_logo_cancel');

logoInput?.addEventListener('change', function() {
  if (this.files[0]) {
    if (logoCancel) logoCancel.style.display = 'inline-flex';
  } else {
    if (logoCancel) logoCancel.style.display = 'none';
  }
});

logoCancel?.addEventListener('click', function() {
  if (logoInput) logoInput.value = '';
  this.style.display = 'none';
});
function kpInstallFFmpeg(btn){
  const status = document.getElementById('ffmpeg-status');
  btn.disabled = true; status.textContent = 'Descargando y extrayendo FFmpeg... (Puede tomar varios minutos)';
  fetch('/install-ffmpeg.php', {method:'POST'})
    .then(r => r.json())
    .then(j => {
      if (j.error) { status.textContent = '❌ ' + j.error; btn.disabled = false; }
      else { status.textContent = '✓ ' + j.message; setTimeout(()=>location.reload(), 2000); }
    })
    .catch(e => { status.textContent = '❌ Error de red o timeout al descargar'; btn.disabled = false; });
}

function kpOp3Sync(btn){
  const status = document.getElementById('op3-sync-status');
  btn.disabled = true; status.textContent = 'Descargando bots.ts…';
  fetch('/op3-sync.php', {method:'POST'})
    .then(r => r.json())
    .then(j => {
      if (j.error) { status.textContent = '❌ ' + j.error; }
      else if (j.starting) { status.textContent = '✓ Sincronización iniciada en background'; }
      else { status.textContent = `✓ ${j.hashes||0} hashes + ${j.patterns||0} patrones actualizados`; setTimeout(()=>location.reload(), 1200); }
    })
    .catch(e => status.textContent = '❌ ' + e.message)
    .finally(() => btn.disabled = false);
}

function kpRewriteBaseUrl(dry){
  const from = document.getElementById('rw_from').value.trim();
  const to   = document.getElementById('rw_to').value.trim();
  const status = document.getElementById('rw_status');
  const report = document.getElementById('rw_report');
  if (!from || !to) { status.textContent = '❌ Rellena ambos campos'; return; }
  if (!dry && !confirm(`¿Reescribir TODO el contenido de ${from} → ${to}? Esto es irreversible.`)) return;
  status.textContent = dry ? 'Simulando…' : 'Reescribiendo…';
  report.style.display = 'none';
  const body = new URLSearchParams({from, to});
  if (dry) body.set('dry', '1');
  fetch('/rewrite-base-url.php', {method:'POST', body})
    .then(r => r.json())
    .then(j => {
      if (j.error) { status.textContent = '❌ ' + j.error; return; }
      if (j.noop) { status.textContent = 'Las URLs son idénticas, nada que hacer'; return; }
      const changes = j.changes || {};
      const lines = Object.entries(changes).map(([k,v]) => `  ${k}: ${v}`).join('\n');
      status.textContent = dry
        ? `✓ Simulación · ${j.total||0} filas se modificarían`
        : `✓ Migración completada · ${j.total||0} filas actualizadas`;
      report.textContent = `${j.from}  →  ${j.to}\n\nCambios:\n${lines || '  (ninguno)'}`;
      report.style.display = 'block';
    })
    .catch(e => status.textContent = '❌ ' + e.message);
}
</script>
