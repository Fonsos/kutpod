<?php
// ============================================================================
// KutPod · sitio público (www.tupodcast.com) — router
// ============================================================================
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
require_once __DIR__ . '/includes/cache.php';

// Redirección para /@slug/feed (sin .xml) a /@slug/feed.xml
if (preg_match('#^/@([a-z0-9\-]+)/feed/?$#i', parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), $m)) {
  $slug = strtolower($m[1]);
  $query = $_SERVER['QUERY_STRING'] ?? '';
  header('Location: /@' . $slug . '/feed.xml' . ($query !== '' ? '?' . $query : ''), true, 301);
  exit;
}

// Redirección o error 410 para /feed/slug y /feed/slug.xml
if (preg_match('#^/feed/([a-z0-9\-]+)(?:\.xml)?$#i', parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), $m)) {
  $requested_slug = strtolower($m[1]);
  
  // Buscar en la base de datos
  require_once __DIR__ . '/includes/db.php';
  $p = kp_one("SELECT slug FROM podcasts WHERE (LOWER(slug) = ? OR (feed_redirect_slug IS NOT NULL AND feed_redirect_slug != '' AND LOWER(feed_redirect_slug) = ?)) AND hidden = 0 LIMIT 1", [$requested_slug, $requested_slug]);
  
  if ($p) {
    $query = $_SERVER['QUERY_STRING'] ?? '';
    header('Location: /@' . $p['slug'] . '/feed.xml' . ($query !== '' ? '?' . $query : ''), true, 301);
    exit;
  } else {
    http_response_code(410);
    $title = 'Feed no disponible';
    $body = '<div class="pub-container" style="padding:100px 24px; text-align:center; max-width:600px; margin:0 auto;">
        <div style="font-size:64px; margin-bottom:24px; color:var(--text-3);">' . icon('rss', 64) . '</div>
        <h1 style="font-size:28px; margin-bottom:16px; font-weight:700;">Este feed ya no está activo</h1>
        <p style="font-size:16px; color:var(--text-3); line-height:1.6; margin-bottom:32px;">El podcast que estás buscando no existe o ha sido eliminado de esta plataforma. Esta dirección de feed ya no está en funcionamiento.</p>
        <a href="/shows" class="btn btn-primary" style="display:inline-flex; align-items:center; gap:8px;">' . icon('globe', 16) . ' Explorar otros podcasts</a>
    </div>';
    require __DIR__ . '/includes/public-layout.php';
    exit;
  }
}

// Fallback de enrutamiento para Nginx antiguos que no tienen el rewrite de feeds nuevo
if (preg_match('#^/@([a-z0-9\-]+)/feed\.xml#i', parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), $m)) {
  $_GET['slug'] = $m[1];
  require __DIR__ . '/feed.php';
  exit;
}

// Fallback de enrutamiento para ActivityPub
if (preg_match('#^/users/([a-z0-9\-]+)(?:/(inbox|outbox|followers|notes/[^/]+))?/?$#i', parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), $m)) {
  $_GET['slug'] = $m[1];
  $_GET['ap'] = $m[2] ?? 'actor';
  require __DIR__ . '/activitypub.php';
  exit;
}

// Enrutamiento directo para reproductor incrustado /embed
if (preg_match('#^/embed(?:/@([a-z0-9\-]+)/([a-z0-9\-]+)|/([a-z0-9\-]+)/([a-z0-9\-]+)|/([a-z0-9\-]+))?/?$#i', parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), $m)) {
  $_GET['r'] = 'embed';
  if (!empty($m[1]) && !empty($m[2])) {
    $_GET['show'] = $m[1];
    $_GET['ep'] = $m[2];
  } elseif (!empty($m[3]) && !empty($m[4])) {
    $_GET['show'] = $m[3];
    $_GET['ep'] = $m[4];
  } elseif (!empty($m[5])) {
    if (is_numeric($m[5])) {
      $_GET['id'] = $m[5];
    } else {
      $_GET['ep'] = $m[5];
    }
  }
  require __DIR__ . '/public/embed.php';
  exit;
}

if (($_GET['r'] ?? '') === 'embed') {
  require __DIR__ . '/public/embed.php';
  exit;
}

// Fallback para /episodes si el servidor no procesa .htaccess
if (preg_match('#^/episodes/?$#i', parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH))) {
  $_GET['r'] = 'episodes';
}

// Redirección 301 Permanente para URLs antiguas con /episodes/
if (preg_match('#^/@([a-z0-9\-]+)/episodes/([a-z0-9\-]+)#i', parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), $m)) {
  header("Location: /@{$m[1]}/{$m[2]}", true, 301);
  exit;
}

$route = $_GET['r'] ?? 'home';
$allowed = ['home','shows','episodes','show','episode','about','page'];
if (!in_array($route, $allowed, true)) $route = 'home';

$is_logged_in = isset($_COOKIE['kp_sess']);
$has_token = !empty($_GET['token']);
$use_cache = (!$is_logged_in && !$has_token);

$cache_key = 'site_' . $route . '_' . md5(json_encode($_GET));
$cached_html = $use_cache ? kp_cache_get($cache_key) : null;

if ($cached_html) {
    echo $cached_html;
    exit;
}

// Lista de páginas publicadas, expuesta a la nav (header/footer)
$published_pages = [];
foreach (glob(__DIR__ . '/storage/pages/*.json') ?: [] as $f) {
  $j = json_decode(file_get_contents($f), true);
  if ($j && !empty($j['published'])) $published_pages[] = ['slug'=>$j['slug'], 'title'=>$j['title'], 'menu'=>$j['menu'] ?? 'header'];
}

ob_start();
require __DIR__ . "/public/$route.php";
$body = ob_get_clean();

ob_start();
require __DIR__ . '/includes/public-layout.php';
$final_html = ob_get_clean();

if ($use_cache) {
    kp_cache_set($cache_key, $final_html, 300); // 5 minutes TTL
}
echo $final_html;
