<?php
// ============================================================================
// KutPod · front controller (router)
// ============================================================================
// Apache rewrite o `php -S localhost:8000 -t php/` enrutan todo aquí.
// Las páginas se reciben por ?page=overview|podcasts|podcast|new-podcast|...

// Si la BD no existe y no estamos en install → redirigir al instalador
$request_uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (!file_exists(__DIR__ . '/storage/kutpod.db') && !file_exists(__DIR__ . '/storage/install.lock')) {
    if ($request_uri === '/install' || $request_uri === '/cli/install.php') {
        require __DIR__ . '/cli/install.php';
        exit;
    }
    header('Location: /install');
    exit;
}

require_once __DIR__ . '/data.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/cache.php';

kp_csrf_ensure_session();



// Logout
if (($_GET['action'] ?? '') === 'logout') { kp_logout(); header('Location: /admin/login'); exit; }

$page = $_GET['page'] ?? 'overview';
// Los plugins activos pueden registrar páginas propias: slug => ['file' => ruta absoluta, 'title' => título]
$plugin_pages = kp_apply_filters('admin_pages', []);
$allowed = ['overview','alerts','podcasts','podcast','new-podcast','edit-podcast','new-episode','analytics','import','backups','fediverse','users','edit-user','api','pages','preferences','login','login-2fa','search','broadcast','queue','update','forgot','reset','recover','plugins'];
$allowed = array_merge($allowed, array_keys($plugin_pages));


// Gate: todo requiere sesión salvo páginas de acceso
if (!in_array($page, ['login', 'login-2fa', 'forgot', 'reset', 'recover'], true) && !kp_current_user()) { header('Location: /admin/login'); exit; }
$current_user = kp_current_user();

// CSRF: verificar token en TODOS los POST autenticados del panel admin
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !in_array($page, ['login', 'login-2fa', 'forgot', 'reset', 'recover'], true)) {
    kp_csrf_verify();
}
if (!in_array($page, $allowed, true)) $page = 'overview';
if ($page === 'fediverse' && !kp_plugin_is_active('fediverse')) {
    $page = 'overview';
}

$titles = [
  'overview'=>'Overview','alerts'=>'Alertas','podcasts'=>'Podcasts','podcast'=>'Podcast',
  'new-podcast'=>'Nuevo podcast','edit-podcast'=>'Editar podcast','new-episode'=>'Nuevo episodio',
  'analytics'=>'Estadísticas','import'=>'Importar RSS','backups'=>'Respaldos',
  'fediverse'=>'Fediverso','users'=>'Usuarios','edit-user'=>'Editar Usuario','api'=>'API · Kut Editor','pages'=>'Páginas','preferences'=>'Preferencias','login'=>'Entrar','login-2fa'=>'Verificación de 2FA','search'=>'Buscar',
  'broadcast'=>'Broadcast','queue'=>'Cola de procesos','update'=>'Actualizaciones',
  'forgot'=>'Recuperar contraseña','reset'=>'Restablecer contraseña','recover'=>'Usar código de respaldo'
];
foreach ($plugin_pages as $slug => $def) { $titles[$slug] = $def['title'] ?? $slug; }
$title = 'KutPod · ' . ($titles[$page] ?? 'Panel');
$page_file = $plugin_pages[$page]['file'] ?? (__DIR__ . "/pages/$page.php");

// Renderiza el body capturando la salida de la página correspondiente.
if (isset($_GET['ajax'])) {
    require $page_file;
    exit;
}

ob_start();
require $page_file;
$body = ob_get_clean();

require __DIR__ . '/includes/layout.php';
