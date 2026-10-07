<?php
// ============================================================================
// KutPod · router para el servidor web integrado de PHP (php -S)
// Equivale a las reglas de .htaccess para quien no usa Apache/Nginx.
// Uso:  php -S 127.0.0.1:8080 -t <carpeta-de-kutpod> router.php
// ============================================================================
$root = rtrim($_SERVER['DOCUMENT_ROOT'], '/');
$uri  = rawurldecode((string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$uri  = preg_replace('#/+#', '/', $uri);

function kp_forbid(): bool { http_response_code(403); header('Content-Type: text/plain; charset=utf-8'); echo '403 Forbidden'; return true; }

/** Ejecuta un script PHP de la app como si Apache lo hubiera reescrito. */
function kp_run(string $root, string $file, array $get = []): bool {
  foreach ($get as $k => $v) $_GET[$k] = $v;
  $_REQUEST = $_GET + $_POST;
  $abs = "$root/$file";
  $_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = '/' . $file;
  $_SERVER['SCRIPT_FILENAME'] = $abs;
  chdir(dirname($abs));
  require $abs;
  return true;
}

// Nunca servir: salida del árbol, ficheros ocultos (salvo .well-known), bases de datos, secretos, instaladores
if (str_contains($uri, '..') || str_contains($uri, "\0")) return kp_forbid();
if (preg_match('#/\.(?!well-known/)#', $uri)) return kp_forbid();
if (preg_match('#\.(db|db-wal|db-shm|sqlite|sql|env|lock|log|ini|sh|md|py|bak)$#i', $uri)) return kp_forbid();
if (preg_match('#^/(pages|includes|api/handlers|public|plugins|installer|bin|Documentation)/#', $uri)) return kp_forbid();
if (preg_match('#^/cli/(?!install\.php$)#', $uri)) return kp_forbid();
if (preg_match('#^/storage/(?!media/)#', $uri)) return kp_forbid();

// Instalador
if ($uri === '/install' || $uri === '/install/') return kp_run($root, 'cli/install.php');

// Tracker OP3 · /r/{show}/{ep}/audio.mp3
if (preg_match('#^/r/([^/]+)/([^/]+)(?:/.*)?$#', $uri, $m)) return kp_run($root, 'r.php', ['show' => $m[1], 'ep' => $m[2]]);

// SEO
if ($uri === '/robots.txt')  return kp_run($root, 'robots-sitemap.php', ['type' => 'robots']);
if ($uri === '/sitemap.xml') return kp_run($root, 'robots-sitemap.php', ['type' => 'sitemap']);

// Feeds RSS
if (preg_match('#^/feed/([a-zA-Z0-9\-]+)\.xml$#', $uri, $m)) return kp_run($root, 'feed.php', ['slug' => $m[1]]);
if ($uri === '/feed.xml') return kp_run($root, 'feed.php', ['slug' => 'all']);

// API REST
if (preg_match('#^/api/(.*)$#', $uri, $m)) return kp_run($root, 'api/index.php', ['route' => $m[1]]);

// ActivityPub
if ($uri === '/.well-known/webfinger') return kp_run($root, 'activitypub.php', ['ap' => 'webfinger']);
if (preg_match('#^/users/([a-zA-Z0-9\-]+)/?$#', $uri, $m)) return kp_run($root, 'activitypub.php', ['ap' => 'actor', 'slug' => $m[1]]);
if (preg_match('#^/users/([a-zA-Z0-9\-]+)/(inbox|outbox|followers|notes/[^/]+)/?$#', $uri, $m)) return kp_run($root, 'activitypub.php', ['ap' => $m[2], 'slug' => $m[1]]);

// Panel de administración
if (preg_match('#^/admin/?$#', $uri)) return kp_run($root, 'index.php');
if (preg_match('#^/admin/login-2fa/?$#', $uri)) return kp_run($root, 'index.php', ['page' => 'login-2fa']);
if (preg_match('#^/admin/([a-z0-9\-]+)/?$#', $uri, $m)) return kp_run($root, 'index.php', ['page' => $m[1]]);
if (preg_match('#^/admin/podcast/([a-zA-Z0-9\-]+)/?$#', $uri, $m)) return kp_run($root, 'index.php', ['page' => 'podcast', 'id' => $m[1]]);
if (preg_match('#^/admin/podcast/([a-zA-Z0-9\-]+)/edit/?$#', $uri, $m)) return kp_run($root, 'index.php', ['page' => 'edit-podcast', 'id' => $m[1]]);

// Sitio público
if (preg_match('#^/@([a-zA-Z0-9\-]+)/?$#', $uri, $m)) return kp_run($root, 'site.php', ['r' => 'show', 'id' => $m[1]]);
if (preg_match('#^/@([a-zA-Z0-9\-]+)/([a-zA-Z0-9\-]+)/?$#', $uri, $m)) return kp_run($root, 'site.php', ['r' => 'episode', 'show' => $m[1], 'ep' => $m[2]]);
if (preg_match('#^/shows/?$#', $uri)) return kp_run($root, 'site.php', ['r' => 'shows']);
if (preg_match('#^/episodes/?$#', $uri)) return kp_run($root, 'site.php', ['r' => 'episodes']);
if (preg_match('#^/p/([a-zA-Z0-9\-]+)/?$#', $uri, $m)) return kp_run($root, 'site.php', ['r' => 'page', 'id' => $m[1]]);
if ($uri === '/') return kp_run($root, 'site.php');

// Ficheros reales (assets, media, scripts PHP sueltos)
if (is_file($root . $uri)) return false;

// Cualquier otra cosa → home pública
return kp_run($root, 'site.php', ['r' => 'home']);
