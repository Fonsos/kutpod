<?php
// ============================================================================
// KutPod · robots.txt y sitemap.xml dinámicos
// ============================================================================
// .htaccess / nginx rewrite → robots-sitemap.php?type=robots|sitemap

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/cache.php';
require_once __DIR__ . '/data.php';

$type = $_GET['type'] ?? 'robots';

// ── robots.txt ──────────────────────────────────────────────────────────────
if ($type === 'robots') {
    header('Content-Type: text/plain; charset=utf-8');
    $domain = kp_handle_domain();
    $protocol = kp_get_protocol();
    echo "User-agent: *\n";
    echo "Allow: /\n";
    echo "\n";
    echo "# Bloquear panel admin y rutas internas\n";
    echo "Disallow: /admin\n";
    echo "Disallow: /admin/\n";
    echo "Disallow: /api/\n";
    echo "Disallow: /cli/\n";
    echo "Disallow: /storage/\n";
    echo "Disallow: /includes/\n";
    echo "Disallow: /pages/\n";
    echo "\n";
    echo "Sitemap: {$protocol}://{$domain}/sitemap.xml\n";
    exit;
}

// ── sitemap.xml ─────────────────────────────────────────────────────────────
if ($type === 'sitemap') {
    $cache_key = 'sitemap_xml';
    $cached = kp_cache_get($cache_key);
    if ($cached) {
        header('Content-Type: application/xml; charset=utf-8');
        echo $cached;
        exit;
    }

    $domain = kp_handle_domain();
    $protocol = kp_get_protocol();
    $base = "{$protocol}://{$domain}";

    $urls = [];

    // Home
    $urls[] = ['loc' => $base . '/', 'priority' => '1.0', 'changefreq' => 'daily'];

    // Shows listing
    $urls[] = ['loc' => $base . '/shows', 'priority' => '0.8', 'changefreq' => 'weekly'];

    // Each podcast (show)
    $podcasts = kp_podcasts();
    foreach ($podcasts as $p) {
        $urls[] = [
            'loc' => $base . '/@' . rawurlencode($p['id']),
            'priority' => '0.8',
            'changefreq' => 'weekly',
        ];
    }

    // Each episode
    $episodes = kp_episodes();
    foreach ($episodes as $ep) {
        $urls[] = [
            'loc' => $base . '/@' . rawurlencode($ep['podcast']) . '/' . rawurlencode($ep['id']),
            'priority' => '0.6',
            'changefreq' => 'monthly',
        ];
    }

    // Published static pages
    foreach (glob(__DIR__ . '/storage/pages/*.json') ?: [] as $f) {
        $pg = json_decode(file_get_contents($f), true);
        if ($pg && !empty($pg['published'])) {
            $urls[] = [
                'loc' => $base . '/p/' . rawurlencode($pg['slug']),
                'priority' => '0.5',
                'changefreq' => 'monthly',
            ];
        }
    }

    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    foreach ($urls as $u) {
        $xml .= "  <url>\n";
        $xml .= "    <loc>" . htmlspecialchars($u['loc'], ENT_XML1, 'UTF-8') . "</loc>\n";
        if (!empty($u['changefreq'])) {
            $xml .= "    <changefreq>" . $u['changefreq'] . "</changefreq>\n";
        }
        if (!empty($u['priority'])) {
            $xml .= "    <priority>" . $u['priority'] . "</priority>\n";
        }
        $xml .= "  </url>\n";
    }
    $xml .= "</urlset>\n";

    kp_cache_set($cache_key, $xml, 3600); // 1 hour TTL

    header('Content-Type: application/xml; charset=utf-8');
    echo $xml;
    exit;
}

http_response_code(404);
exit;
