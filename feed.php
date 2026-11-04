<?php
// ============================================================================
// KutPod · generador de feed RSS 2.0 · podcasting 2.0
// ============================================================================
// URL pública: /@{slug}/feed.xml  (rewrite a feed.php?slug=...)
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/cache.php';
require_once __DIR__ . '/data.php';

kp_check_scheduled();

$slug = strtolower(preg_replace('/[^a-z0-9\-]/i', '', $_GET['slug'] ?? ''));

// 1. Buscar por slug actual
$p = kp_one("SELECT * FROM podcasts WHERE LOWER(slug) = ? AND hidden = 0", [$slug]);
if ($p) {
    // Si vinieron por la ruta vieja /feed/slug.xml, redireccionar con 301 a /@slug/feed.xml
    if (preg_match('#^/feed/#i', parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH))) {
        $query = $_SERVER['QUERY_STRING'] ?? '';
        header('Location: /@' . $p['slug'] . '/feed.xml' . ($query !== '' ? '?' . $query : ''), true, 301);
        exit;
    }
} else {
    // 2. Buscar por slug de redirección
    $p = kp_one("SELECT * FROM podcasts WHERE feed_redirect_slug IS NOT NULL AND feed_redirect_slug != '' AND LOWER(feed_redirect_slug) = ? AND hidden = 0", [$slug]);
    if ($p) {
        $query = $_SERVER['QUERY_STRING'] ?? '';
        header('Location: /@' . $p['slug'] . '/feed.xml' . ($query !== '' ? '?' . $query : ''), true, 301);
        exit;
    }
}

// 3. Si no existe, redirigir o mostrar 404 / página con podcasts activos
if (!$p) {
    if (preg_match('#^/feed/#i', parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH))) {
        header('Location: /feed/' . rawurlencode($slug), true, 302);
        exit;
    }
    http_response_code(404);
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    if (str_contains($accept, 'text/html')) {
        $active_podcasts = kp_podcasts();
        $title = 'Feed no especificado';
        ob_start();
        ?>
        <div class="pub-container" style="padding:60px 24px 80px; max-width:860px; margin:0 auto;">
          <div style="text-align:center; margin-bottom:44px;">
            <div style="font-size:48px; margin-bottom:16px; color:var(--pub-muted); line-height:1;"><?= icon('rss', 48) ?></div>
            <h1 style="font-size:28px; font-weight:700; margin-bottom:12px; letter-spacing:-0.02em;">Feed RSS no especificado</h1>
            <p style="font-size:15px; color:var(--pub-muted); line-height:1.6; max-width:560px; margin:0 auto;">
              Esta plataforma aloja múltiples podcasts independientes. Cada programa cuenta con su propio canal RSS para suscribirte en tu reproductor favorito:
            </p>
          </div>
          <?php if (!empty($active_podcasts)): ?>
            <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(260px, 1fr)); gap:20px;">
              <?php foreach ($active_podcasts as $show): ?>
                <div class="pub-card" style="display:flex; flex-direction:column; padding:20px; border:1px solid var(--pub-border); border-radius:14px; background:var(--pub-surface); color:var(--pub-text);">
                  <div style="display:flex; align-items:center; gap:14px; margin-bottom:14px;">
                    <?php if (!empty($show['cover'])): ?>
                      <img src="<?= e($show['cover']) ?>" alt="" style="width:56px; height:56px; border-radius:10px; object-fit:cover; flex-shrink:0;">
                    <?php else: ?>
                      <div style="width:56px; height:56px; border-radius:10px; background:linear-gradient(135deg,<?= e($show['color']) ?>,<?= e($show['color']) ?>aa); display:flex; align-items:center; justify-content:center; color:#fff; font-weight:800; font-size:20px; flex-shrink:0;">
                        <?= e($show['initial']) ?>
                      </div>
                    <?php endif; ?>
                    <div style="min-width:0; flex:1;">
                      <div style="font-weight:600; font-size:16px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; letter-spacing:-0.01em; color:var(--pub-text);"><?= e($show['title']) ?></div>
                      <div style="font-size:12.5px; color:var(--pub-muted); margin-top:2px;"><?= (int)$show['episodes'] ?> episodios · <?= e($show['cat']) ?></div>
                    </div>
                  </div>
                  <div style="margin-top:auto; display:flex; gap:10px; padding-top:10px; border-top:1px solid var(--pub-border);">
                    <a href="/@<?= e($show['id']) ?>" class="pub-btn pub-btn-ghost" style="flex:1; justify-content:center; font-size:13px; text-decoration:none; color:var(--pub-text);">Ver show</a>
                    <a href="/@<?= e($show['id']) ?>/feed.xml" class="pub-btn pub-btn-primary" style="font-size:13px; padding:6px 14px; display:inline-flex; align-items:center; gap:6px; text-decoration:none;" target="_blank"><?= icon('rss', 13) ?> Feed RSS</a>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <div style="text-align:center;">
              <a href="/shows" class="pub-btn pub-btn-primary" style="display:inline-flex; align-items:center; gap:8px;">Ver catálogo de podcasts</a>
            </div>
          <?php endif; ?>
        </div>
        <?php
        $body = ob_get_clean();
        require_once __DIR__ . '/includes/public-layout.php';
        exit;
    }
    header('Content-Type: text/plain; charset=utf-8');
    exit('Feed no encontrado');
}

// Gancho para interceptar la petición de feed RSS (ej: verificar tokens)
kp_do_action('feed_rss_init', $p);

$now_utc = gmdate('Y-m-d H:i:s');
$latest_ts = kp_db_strtotime($p['updated_at'] ?? $p['created_at']);
$latest_ep = kp_one("SELECT MAX(COALESCE(published_at, publish_at, created_at)) as max_date FROM episodes WHERE podcast_id = ? AND hidden = 0 AND (status = 'published' OR (status = 'scheduled' AND COALESCE(publish_at, created_at) <= ?))", [$p['id'], $now_utc]);
if (!empty($latest_ep['max_date'])) {
    $ep_ts = kp_db_strtotime($latest_ep['max_date']);
    if ($ep_ts > $latest_ts) $latest_ts = $ep_ts;
}

function send_rss_response(string $xml, int $last_modified_ts): void {
    $etag = '"' . md5($xml) . '"';
    $last_modified_str = gmdate('D, d M Y H:i:s \G\M\T', $last_modified_ts);

    header('Content-Type: application/rss+xml; charset=utf-8');
    header('ETag: ' . $etag);
    header('Last-Modified: ' . $last_modified_str);
    header('Cache-Control: public, max-age=300');
    header('Content-Length: ' . strlen($xml));

    $if_none_match = trim($_SERVER['HTTP_IF_NONE_MATCH'] ?? '');
    $if_modified_since = trim($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '');

    if ($if_none_match === $etag || ($if_modified_since !== '' && strtotime($if_modified_since) >= $last_modified_ts)) {
        http_response_code(304);
        exit;
    }

    echo $xml;
    exit;
}

$tokenStr = trim($_GET['token'] ?? '');
$cache_key = 'rss_feed_' . $p['id'] . '_' . md5($tokenStr);
$cached_xml = kp_cache_get($cache_key);
if ($cached_xml) {
    send_rss_response($cached_xml, $latest_ts);
}

function xml_sanitize(?string $str): string {
    if ($str === null) return '';
    // Remove invalid XML control characters
    return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $str);
}

$base = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'www.tupodcast.com');
// Tracker nativo · cada enclosure pasa por /r/{show}/{ep}/audio.ext
// Si op3 está OFF en el podcast, el tracker hace 302 directo sin loggear.
$tracker = $base . '/r/' . rawurlencode($p['slug']) . '/';
$guid = !empty($p['guid']) ? $p['guid'] : kp_deterministic_podcast_guid(kp_canonical_feed_url($p['slug']));
if (!empty($p['op3'])) {
    $tracker = 'https://op3.dev/e,pg=' . $guid . '/' . preg_replace('#^https?://#', '', $tracker);
}

$order = ($p['type'] ?? 'episodic') === 'serial' ? 'ASC' : 'DESC';
$now_utc = gmdate('Y-m-d H:i:s');
$episodes = kp_q("SELECT * FROM episodes
                  WHERE podcast_id = ? 
                    AND (status = 'published' OR (status = 'scheduled' AND COALESCE(publish_at, created_at) <= ?))
                    AND hidden = 0
                  ORDER BY COALESCE(publish_at, created_at) $order", [$p['id'], $now_utc]);

// Filtrar los episodios antes de renderizarlos (ej: ocultar premium si no tiene acceso)
$episodes = kp_apply_filters('feed_rss_episodes', $episodes, $p);


ob_start();
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<rss version="2.0"
     xmlns:atom="http://www.w3.org/2005/Atom"
     xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd"
     xmlns:content="http://purl.org/rss/1.0/modules/content/"
     xmlns:podcast="https://podcastindex.org/namespace/1.0"
     xmlns:dc="http://purl.org/dc/elements/1.1/">
<channel>
  <title><?= e($p['title']) ?></title>
  <link><?= e($base) ?>/@<?= e($p['slug']) ?></link>
  <?php
    $self_url = kp_canonical_feed_url($p['slug']);
    if (!empty($tokenStr)) {
        $self_url .= (strpos($self_url, '?') !== false ? '&' : '?') . 'token=' . urlencode($tokenStr);
    }
  ?>
  <atom:link href="<?= e($self_url) ?>" rel="self" type="application/rss+xml"/>
  <generator>KutPod (https://kutpod.com)</generator>
  <?php if (!empty($guid)): ?>
  <podcast:guid><?= e($guid) ?></podcast:guid>
  <?php endif; ?>
  <podcast:medium>podcast</podcast:medium>
  <language><?= e($p['language']) ?></language>
  <description><?= e($p['description'] ?? '') ?></description>
  <itunes:summary><?= e($p['description'] ?? '') ?></itunes:summary>
  <itunes:author><?= e(!empty($p['author']) ? $p['author'] : ($p['publisher'] ?? '')) ?></itunes:author>
  <?php if (!empty($p['publisher'])): ?>
  <dc:publisher><?= e($p['publisher']) ?></dc:publisher>
  <podcast:publisher><?= e($p['publisher']) ?></podcast:publisher>
  <podcast:person role="publisher" group="editorial"><?= e($p['publisher']) ?></podcast:person>
  <?php endif; ?>
  <?php if (!empty($p['author'])): ?>
  <dc:creator><?= e($p['author']) ?></dc:creator>
  <podcast:person role="host"><?= e($p['author']) ?></podcast:person>
  <?php endif; ?>
  <itunes:type><?= e($p['type']) ?></itunes:type>
  <itunes:explicit><?= $p['parental'] === 'explicit' ? 'true' : 'false' ?></itunes:explicit>
  <?php if (!empty($p['category'])): ?>
    <?php if (!empty($p['subcategory'])): ?>
  <itunes:category text="<?= e($p['category']) ?>">
    <itunes:category text="<?= e($p['subcategory']) ?>"/>
  </itunes:category>
    <?php else: ?>
  <itunes:category text="<?= e($p['category']) ?>"/>
    <?php endif; ?>
  <?php endif; ?>
  <?php if (!$p['remove_email'] && !empty($p['owner_email'])): ?>
  <itunes:owner>
    <itunes:name><?= e(!empty($p['author']) ? $p['author'] : (!empty($p['publisher']) ? $p['publisher'] : $p['title'])) ?></itunes:name>
    <itunes:email><?= e($p['owner_email']) ?></itunes:email>
  </itunes:owner>
  <?php endif; ?>
  <?php if ($p['cover']): ?><itunes:image href="<?= e($base . $p['cover']) ?>"/><?php endif; ?>
  <copyright><?= e($p['copyright'] ?? '') ?></copyright>
  <?php
    $lic = !empty($p['license']) ? trim($p['license']) : '';
    $lic_url = null;
    $lic_map = [
        'all-rights-reserved' => null,
        'cc-by-4.0' => 'https://creativecommons.org/licenses/by/4.0/',
        'cc-by-sa-4.0' => 'https://creativecommons.org/licenses/by-sa/4.0/',
        'cc-by-nc-4.0' => 'https://creativecommons.org/licenses/by-nc/4.0/',
        'cc-by-nc-sa-4.0' => 'https://creativecommons.org/licenses/by-nc-sa/4.0/',
        'cc-by-nd-4.0' => 'https://creativecommons.org/licenses/by-nd/4.0/',
        'cc-by-nc-nd-4.0' => 'https://creativecommons.org/licenses/by-nc-nd/4.0/',
        'cc0-1.0' => 'https://creativecommons.org/publicdomain/zero/1.0/',
    ];

    if ($lic !== '' && array_key_exists($lic, $lic_map)) {
        $lic_url = $lic_map[$lic];
    } elseif ($lic === '' || $lic === 'custom') {
        $c_lower = strtolower($p['copyright'] ?? '');
        if (strpos($c_lower, 'cc-by-nc-nd') !== false) {
            $lic = 'cc-by-nc-nd-4.0';
            $lic_url = 'https://creativecommons.org/licenses/by-nc-nd/4.0/';
        } elseif (strpos($c_lower, 'cc-by-nc-sa') !== false) {
            $lic = 'cc-by-nc-sa-4.0';
            $lic_url = 'https://creativecommons.org/licenses/by-nc-sa/4.0/';
        } elseif (strpos($c_lower, 'cc-by-nc') !== false) {
            $lic = 'cc-by-nc-4.0';
            $lic_url = 'https://creativecommons.org/licenses/by-nc/4.0/';
        } elseif (strpos($c_lower, 'cc-by-nd') !== false) {
            $lic = 'cc-by-nd-4.0';
            $lic_url = 'https://creativecommons.org/licenses/by-nd/4.0/';
        } elseif (strpos($c_lower, 'cc-by-sa') !== false) {
            $lic = 'cc-by-sa-4.0';
            $lic_url = 'https://creativecommons.org/licenses/by-sa/4.0/';
        } elseif (strpos($c_lower, 'cc-by') !== false) {
            $lic = 'cc-by-4.0';
            $lic_url = 'https://creativecommons.org/licenses/by/4.0/';
        } elseif (strpos($c_lower, 'cc0') !== false || strpos($c_lower, 'public domain') !== false) {
            $lic = 'cc0-1.0';
            $lic_url = 'https://creativecommons.org/publicdomain/zero/1.0/';
        } else {
            $lic = 'all-rights-reserved';
        }
    }
  ?>
  <podcast:license<?= $lic_url ? ' url="' . e($lic_url) . '"' : '' ?>><?= e($lic) ?></podcast:license>
  <?php
    if (!empty($p['complete'])) {
        echo "  <podcast:updateFrequency complete=\"true\">Completed</podcast:updateFrequency>\n";
    } else {
        $freq_label = 'Weekly';
        $rrule = 'FREQ=WEEKLY';
        $manual_freq = !empty($p['update_frequency']) ? strtolower(trim($p['update_frequency'])) : '';

        if ($manual_freq === 'daily' || $manual_freq === 'diario') {
            $freq_label = 'Daily';
            $rrule = 'FREQ=DAILY';
        } elseif ($manual_freq === 'weekly' || $manual_freq === 'semanal') {
            $freq_label = 'Weekly';
            $rrule = 'FREQ=WEEKLY';
        } elseif ($manual_freq === 'biweekly' || $manual_freq === 'quincenal') {
            $freq_label = 'Biweekly';
            $rrule = 'FREQ=WEEKLY;INTERVAL=2';
        } elseif ($manual_freq === 'monthly' || $manual_freq === 'mensual') {
            $freq_label = 'Monthly';
            $rrule = 'FREQ=MONTHLY';
        } else {
            if (count($episodes) >= 2) {
                $dates = [];
                foreach (array_slice($episodes, 0, 5) as $ep_item) {
                    $ts = kp_db_strtotime($ep_item['published_at'] ?? ($ep_item['publish_at'] ?? $ep_item['created_at']));
                    if ($ts > 0) $dates[] = $ts;
                }
                if (count($dates) >= 2) {
                    $diffs = [];
                    for ($i = 0; $i < count($dates) - 1; $i++) {
                        $diffs[] = abs($dates[$i] - $dates[$i+1]);
                    }
                    $avg_days = (array_sum($diffs) / count($diffs)) / 86400;
                    if ($avg_days <= 1.5) {
                        $freq_label = 'Daily';
                        $rrule = 'FREQ=DAILY';
                    } elseif ($avg_days <= 4) {
                        $freq_label = 'Twice weekly';
                        $rrule = 'FREQ=WEEKLY;INTERVAL=1;BYDAY=TU,TH';
                    } elseif ($avg_days <= 9) {
                        $freq_label = 'Weekly';
                        $rrule = 'FREQ=WEEKLY';
                    } elseif ($avg_days <= 18) {
                        $freq_label = 'Biweekly';
                        $rrule = 'FREQ=WEEKLY;INTERVAL=2';
                    } else {
                        $freq_label = 'Monthly';
                        $rrule = 'FREQ=MONTHLY';
                    }
                }
            }
        }
        echo "  <podcast:updateFrequency rrule=\"" . e($rrule) . "\">" . e($freq_label) . "</podcast:updateFrequency>\n";
    }
  ?>
  <?php 
    $trailers = array_filter($episodes, fn($ep) => ($ep['ep_type'] ?? '') === 'trailer' && !empty($ep['audio_url']));
    foreach ($trailers as $tr): 
      $tr_url = $tracker . rawurlencode($tr['slug']) . '/' . basename($tr['audio_url']);
      $tr_url = kp_apply_filters('feed_rss_enclosure_url', $tr_url, $tr, $p);
      $tr_pub = date(DATE_RSS, kp_db_strtotime($tr['published_at'] ?? ($tr['publish_at'] ?? ($tr['created_at'] ?? null))));
  ?>
  <podcast:trailer url="<?= e($tr_url) ?>" pubdate="<?= e($tr_pub) ?>" length="<?= (int)$tr['audio_bytes'] ?>" type="<?= e($tr['audio_mime'] ?? 'audio/mpeg') ?>"<?= (!empty($tr['season']) && (int)$tr['season'] > 0) ? ' season="' . (int)$tr['season'] . '"' : '' ?>><?= e(xml_sanitize($tr['title'])) ?></podcast:trailer>
  <?php endforeach; ?>
  <?php if (!empty($p['ownership_txt'])): ?>
  <podcast:txt purpose="verify"><?= e($p['ownership_txt']) ?></podcast:txt>
  <?php endif; ?>
  <?php if ($p['fediverse_handle']): ?>
  <podcast:social platform="activitypub" url="<?= e($base) ?>/@<?= e($p['fediverse_handle']) ?>" priority="1"/>
  <?php endif; ?>
  <?php if (!empty($p['location_name'])): ?>
  <podcast:location<?= !empty($p['osm_id']) ? ' osmid="' . e($p['osm_id']) . '"' : '' ?><?= (!empty($p['lat']) && !empty($p['lon'])) ? ' geo="geo:' . e($p['lat']) . ',' . e($p['lon']) . '"' : '' ?>><?= e($p['location_name']) ?></podcast:location>
  <?php endif; ?>
  <podcast:locked<?= (!$p['remove_email'] && !empty($p['owner_email'])) ? ' owner="' . e($p['owner_email']) . '"' : '' ?>><?= $p['locked'] ? 'yes' : 'no' ?></podcast:locked>
  <?php if (!empty($p['complete'])): ?>
  <itunes:complete>yes</itunes:complete>
  <podcast:complete>yes</podcast:complete>
  <?php endif; ?>
  <?php 
    require_once __DIR__ . '/includes/platforms.php';
    $broadcast_links = !empty($p['broadcast_links']) ? json_decode($p['broadcast_links'], true) : [];
    foreach ($broadcast_links as $slug => $plat) {
        if (empty($plat['visible']) || empty($plat['link'])) continue;
        if ($plat['type'] === 'funding') {
            $label = \KutPod\KpPlatforms::DATA['funding'][$slug]['label'] ?? $slug;
            echo "  <podcast:funding url=\"" . e($plat['link']) . "\">" . e($label) . "</podcast:funding>\n";
        } else {
            echo "  <podcast:social platform=\"" . e($slug) . "\" url=\"" . e($plat['link']) . "\"/>\n";
        }
    }
  ?>
  <?php kp_do_action('feed_rss_channel', $p); ?>
  <?php
    // SEC-01: Sanitizar custom_tags para prevenir inyección XML
    $channel_custom = $p['custom_tags'] ?? '';
    if ($channel_custom !== '') {
        // Eliminar scripts, event handlers y contenido peligroso
        $channel_custom = preg_replace('/<\s*script[^>]*>.*?<\s*\/\s*script\s*>/is', '', $channel_custom);
        $channel_custom = preg_replace('/\bon\w+\s*=\s*["\'][^"\']*["\']/i', '', $channel_custom);
    }
  ?>
  <?= $channel_custom ?>

  <?php foreach ($episodes as $e): 
    $itunes_title = preg_replace('/^(?:episodio|episode|ep\.?|cap[ií]tulo|cap\.?)\s*#?\d+\s*[:-]?\s*/i', '', $e['title']);
    $itunes_title = preg_replace('/^#\d+\s*[:-]?\s*/', '', $itunes_title);
    $itunes_title = trim($itunes_title);
    if ($itunes_title === '') $itunes_title = $e['title'];
  ?>
  <item>
    <title><?= e(xml_sanitize($e['title'])) ?></title>
    <itunes:title><?= e(xml_sanitize($itunes_title)) ?></itunes:title>
    <guid isPermaLink="false"><?= e(xml_sanitize($e['guid'])) ?></guid>
    <link><?= e($base) ?>/@<?= e($p['slug']) ?>/<?= e($e['slug']) ?></link>
    <pubDate><?= e(date(DATE_RSS, kp_db_strtotime($e['published_at'] ?? ($e['publish_at'] ?? ($e['created_at'] ?? null))))) ?></pubDate>
    <description><?= e(xml_sanitize(strip_tags(kp_md_to_html($e['notes_md'] ?? '')))) ?></description>
    <itunes:summary><?= e(xml_sanitize(strip_tags(kp_md_to_html($e['notes_md'] ?? '')))) ?></itunes:summary>
    <?php
      $ep_content = kp_md_to_html($e['notes_md'] ?? '');
      $ep_fixed = trim($p['fixed_notes'] ?? '');
      if ($ep_fixed) {
        $ep_content .= "\n\n<hr>\n\n" . kp_md_to_html($ep_fixed);
      }
      $ep_content = xml_sanitize($ep_content);
    ?>
    <content:encoded><![CDATA[<?= str_replace(']]>', ']]]]><![CDATA[>', $ep_content) ?>]]></content:encoded>
    <?php if ($e['audio_url']): 
      $enc_url = $tracker . rawurlencode($e['slug']) . '/' . basename($e['audio_url']);
      $enc_url = kp_apply_filters('feed_rss_enclosure_url', $enc_url, $e, $p);
    ?>
    <enclosure url="<?= e($enc_url) ?>"
               length="<?= (int)$e['audio_bytes'] ?>"
               type="<?= e($e['audio_mime'] ?? 'audio/mpeg') ?>"/>
    <?php endif; ?>
    <itunes:duration><?= (int)$e['duration_secs'] ?></itunes:duration>
    <itunes:episodeType><?= e($e['ep_type']) ?></itunes:episodeType>
    <?php if (!empty($e['season']) && (int)$e['season'] > 0): ?>
    <itunes:season><?= (int)$e['season'] ?></itunes:season>
    <podcast:season><?= (int)$e['season'] ?></podcast:season>
    <?php endif; ?>
    <?php if (!empty($e['number']) && (int)$e['number'] > 0 && $e['ep_type'] === 'full'): ?>
    <itunes:episode><?= (int)$e['number'] ?></itunes:episode>
    <podcast:episode><?= (int)$e['number'] ?></podcast:episode>
    <?php endif; ?>
    <itunes:explicit><?= $e['parental'] === 'explicit' ? 'true' : 'false' ?></itunes:explicit>
    <?php 
      $item_cover = $e['cover'] ?: ($p['cover'] ?? '');
      if ($item_cover): 
    ?>
    <itunes:image href="<?= e($base . $item_cover) ?>"/>
    <?php endif; ?>
    <?php if (!empty($e['guest']) || !empty($p['author']) || !empty($p['publisher'])): ?>
    <itunes:author><?= e(!empty($e['guest']) ? $e['guest'] : (!empty($p['author']) ? $p['author'] : $p['publisher'])) ?></itunes:author>
    <?php endif; ?>
    <?php if (!empty($e['transcript_url'])): 
      $tr_type = 'application/x-subrip';
      if (str_ends_with(strtolower($e['transcript_url']), '.vtt')) {
          $tr_type = 'text/vtt';
      } elseif (str_ends_with(strtolower($e['transcript_url']), '.json')) {
          $tr_type = 'application/json';
      }
    ?>
    <podcast:transcript url="<?= e($base . $e['transcript_url']) ?>" type="<?= $tr_type ?>"/>
    <?php endif; ?>
    <?php if (!empty($e['chapters_url'])): ?>
    <podcast:chapters url="<?= e($base . $e['chapters_url']) ?>" type="application/json+chapters"/>
    <?php endif; ?>
    <?php if (!empty($e['location_name'])): ?>
    <podcast:location<?= (!empty($e['lat']) && !empty($e['lon'])) ? ' geo="geo:' . e($e['lat']) . ',' . e($e['lon']) . '"' : '' ?>><?= e($e['location_name']) ?></podcast:location>
    <?php endif; ?>
    <?php 
      if (!empty($e['persons_json'])) {
        $persons = json_decode($e['persons_json'], true);
        if (is_array($persons)) {
          foreach ($persons as $p_j) {
            $r = $p_j['role'] ?? 'guest';
            $img = !empty($p_j['avatar']) ? ' img="' . e($base . $p_j['avatar']) . '"' : '';
            $href = !empty($p_j['url']) ? ' href="' . e($p_j['url']) . '"' : '';
            echo "    <podcast:person role=\"" . e($r) . "\"{$img}{$href}>" . e($p_j['name']) . "</podcast:person>\n";
          }
        }
      }
    ?>
    <?php if (!empty($p['federate']) && !empty($p['fediverse_handle'])): 
      $ap_host = parse_url($base, PHP_URL_HOST);
      $ap_uri = $base . '/@' . $p['slug'] . '/' . $e['slug'];
      $ap_account = '@' . $p['fediverse_handle'] . '@' . $ap_host;
    ?>
    <podcast:socialInteract priority="1" protocol="activitypub" uri="<?= e($ap_uri) ?>" accountId="<?= e($ap_account) ?>" accountUrl="<?= e($base . '/@' . $p['fediverse_handle']) ?>"/>
    <?php endif; ?>
    <?php kp_do_action('feed_rss_item', $e, $p); ?>
    <?php
      $item_custom = $e['custom_tags'] ?? '';
      if ($item_custom !== '') {
          $item_custom = preg_replace('/<\s*script[^>]*>.*?<\s*\/\s*script\s*>/is', '', $item_custom);
          $item_custom = preg_replace('/\bon\w+\s*=\s*["\'][^"\']*["\']/i', '', $item_custom);
      }
    ?>
    <?= $item_custom ?>
  </item>
  <?php endforeach; ?>
</channel>
</rss>
<?php
$final_xml = ob_get_clean();
$final_xml = kp_apply_filters('feed_rss_xml', $final_xml, $p);
kp_cache_set($cache_key, $final_xml, 300); // 5 minutes TTL
send_rss_response($final_xml, $latest_ts);
