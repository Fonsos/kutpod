<?php
// pages/login-2fa.php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

kp_csrf_ensure_session();
$uid = $_SESSION['pending_2fa_user_id'] ?? null;
if (!$uid) {
    header('Location: /admin/login');
    exit;
}

$u = kp_one("SELECT * FROM users WHERE id = ?", [$uid]);
if (!$u || empty($u['totp_secret'])) {
    header('Location: /admin/login');
    exit;
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = trim($_POST['code'] ?? '');
    
    // Verify TOTP code
    if (kp_totp_verify($u['totp_secret'], $code)) {
        unset($_SESSION['pending_2fa_user_id']);
        
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 120);
        $token = bin2hex(random_bytes(32));
        $expires = gmdate('Y-m-d H:i:s', time() + 60*60*24*30); // 30 días
        
        kp_exec("INSERT INTO sessions (token,user_id,ip,user_agent,expires_at) VALUES (?,?,?,?,?)",
          [$token, $u['id'], $ip, substr($ua, 0, 240), $expires]);
        kp_db()->prepare("UPDATE users SET last_login = datetime('now') WHERE id = ?")->execute([$u['id']]);

        $secure = kp_get_protocol() === 'https';
        setcookie('kp_sess', $token, ['expires' => time()+60*60*24*30, 'path'=>'/', 'httponly'=>true, 'secure'=>$secure, 'samesite'=>'Lax']);
        kp_alert('security', 'Inicio de sesión (2FA)', "{$u['name']} accedió tras validar 2FA desde $ip.", '/admin', ['actor_name' => $u['name']]);
        
        header('Location: /admin');
        exit;
    } else {
        $error = 'Código de verificación incorrecto o expirado.';
    }
}
?>
<div style="min-height:calc(100vh - 120px);display:grid;place-items:center;padding:40px 16px;box-sizing:border-box">
  <form method="POST" class="card card-lg" style="width:100%;max-width:380px;padding:36px;box-sizing:border-box">
    <?= kp_csrf_field() ?>
    <div style="text-align:center;margin-bottom:24px">
      <div style="width:48px;height:48px;border-radius:12px;background:var(--accent);color:white;display:grid;place-items:center;font-weight:800;font-size:22px;margin:0 auto 12px">
        <?= icon('shield', 22) ?>
      </div>
      <h1 class="page-title" style="font-size:20px;margin:0">Verificación de 2FA</h1>
      <p class="page-sub" style="margin-top:6px;font-size:12.5px">Introduce el código de 6 dígitos de tu aplicación de autenticación.</p>
    </div>
    <?php if ($error): ?><div class="error" style="margin-bottom:14px;padding:10px 12px;background:rgba(248,113,113,.1);border-radius:8px"><?= e($error) ?></div><?php endif; ?>
    <div class="field">
      <label class="label">Código de Seguridad</label>
      <input class="input tabular" type="text" name="code" pattern="[0-9]{6}" inputmode="numeric" placeholder="Ej: 123456" required autofocus style="text-align:center;font-size:20px;letter-spacing:4px">
    </div>
    <button class="btn btn-primary" type="submit" style="width:100%;margin-top:20px;justify-content:center">Verificar y Entrar</button>
    <div style="text-align:center;margin-top:16px;font-size:13px">
      <a href="/admin/login" style="color:var(--text-3);text-decoration:none">Cancelar y volver</a>
    </div>
  </form>
</div>
