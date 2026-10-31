<?php
require_once __DIR__ . '/../includes/helpers.php';
$ep = kp_find_episode($_GET['ep'] ?? $_GET['id'] ?? '', $_GET['show'] ?? '') ?? kp_episodes()[0];
$p = kp_podcast_or_placeholder($ep['podcast'] ?? '');

$title = $ep['title'] . ' - ' . $p['title'];
$og_title = $ep['title'];

// Get description: strip HTML and markdown formatting to get clean plain text
$og_desc_raw = $ep['notes_md'] ?? '';
$og_desc_raw = preg_replace('/\[(.+?)\]\((.+?)\)/', '$1', $og_desc_raw); // Links
$og_desc_raw = preg_replace('/\*\*(.+?)\*\*/', '$1', $og_desc_raw); // Bold
$og_desc_raw = preg_replace('/\*(.+?)\*/', '$1', $og_desc_raw); // Italic
$og_desc_raw = preg_replace('/`([^`]+)`/', '$1', $og_desc_raw); // Code
$og_desc_raw = preg_replace('/^#+\s+(.+)$/m', '$1', $og_desc_raw); // Headers
$og_desc_raw = preg_replace('/^>\s+(.+)$/m', '$1', $og_desc_raw); // Blockquotes
$og_desc_raw = preg_replace('/^- (.+)$/m', '$1', $og_desc_raw); // Lists
$og_desc_raw = strip_tags($og_desc_raw);
$og_description = mb_strimwidth(trim($og_desc_raw), 0, 200, '...');

$og_image = $ep['cover'] ?: ($p['cover'] ?? '');
$og_type = 'music.song';

// JSON-LD: PodcastEpisode para buscadores
$domain_seo = kp_handle_domain();
$protocol_seo = kp_get_protocol();
$dur_secs = (int)($ep['duration_secs'] ?? 0);
$dur_iso = 'PT' . ($dur_secs > 0 ? intdiv($dur_secs, 3600) . 'H' . intdiv($dur_secs % 3600, 60) . 'M' . ($dur_secs % 60) . 'S' : '0S');
$og_jsonld = [
    '@context' => 'https://schema.org',
    '@type' => 'PodcastEpisode',
    'name' => $ep['title'],
    'description' => $og_description,
    'url' => "{$protocol_seo}://{$domain_seo}/@" . rawurlencode($ep['podcast']) . '/' . rawurlencode($ep['id']),
    'datePublished' => $ep['date_iso'] ?? '',
    'timeRequired' => $dur_iso,
    'partOfSeries' => [
        '@type' => 'PodcastSeries',
        'name' => $p['title'],
        'url' => "{$protocol_seo}://{$domain_seo}/@" . rawurlencode($p['id']),
    ],
];
if (!empty($ep['audio_url'])) {
    $og_jsonld['associatedMedia'] = [
        '@type' => 'MediaObject',
        'contentUrl' => "{$protocol_seo}://{$domain_seo}/r/" . rawurlencode($p['id']) . '/' . rawurlencode($ep['id']) . '/audio.mp3',
    ];
}
if (!empty($og_image)) {
    $img_url = $og_image;
    if (!preg_match('#^https?://#i', $img_url)) {
        $img_url = "{$protocol_seo}://{$domain_seo}" . $img_url;
    }
    $og_jsonld['image'] = $img_url;
}
?>
<?php
$ep_cover = $ep['cover'] ?: ($p['cover'] ?? '');

// Load chapters if available
$chapters = [];
if (!empty($ep['chapters_url']) && file_exists(__DIR__ . '/..' . $ep['chapters_url'])) {
    $chapsData = json_decode(file_get_contents(__DIR__ . '/..' . $ep['chapters_url']), true);
    if (!empty($chapsData['chapters'])) {
        $chapters = $chapsData['chapters'];
    }
}
$fixed_notes = trim($p['fixed_notes'] ?? '');

require_once __DIR__ . '/../includes/platforms.php';

// Episodios adyacentes para navegación Anterior / Siguiente
$allShowEps = kp_episodes_of($p['id']);
$curEpIdx = -1;
foreach ($allShowEps as $idx => $item) {
    if ((string)$item['id'] === (string)$ep['id']) {
        $curEpIdx = $idx;
        break;
    }
}
$prevEp = ($curEpIdx !== -1 && isset($allShowEps[$curEpIdx + 1])) ? $allShowEps[$curEpIdx + 1] : null;
$nextEp = ($curEpIdx > 0 && isset($allShowEps[$curEpIdx - 1])) ? $allShowEps[$curEpIdx - 1] : null;

// Plataformas de escucha y enlaces de comunidad/apoyo
$broadcast_links = !empty($p['broadcast_links']) ? json_decode($p['broadcast_links'], true) : [];
$visible_platforms = array_filter($broadcast_links, fn($c) => !empty($c['visible']) && !empty($c['link']));
$listen_platforms = array_filter($visible_platforms, fn($plat) => ($plat['type'] ?? '') === 'podcasting');
$funding_platforms = array_filter($visible_platforms, fn($plat) => ($plat['type'] ?? '') === 'funding');
$social_platforms = array_filter($visible_platforms, fn($plat) => ($plat['type'] ?? '') === 'social');

// Participantes
$persons = !empty($ep['persons_json']) ? json_decode($ep['persons_json'], true) : [];
if (!is_array($persons)) {
    $persons = [];
}

// Configuración del Plugin Barra Lateral de Episodios
$sbPluginActive = kp_plugin_is_active('episode_sidebar');
$sbConfig = function_exists('kp_episode_sidebar_get_config') ? kp_episode_sidebar_get_config() : [
    'mode'                  => 'no_chapters',
    'show_platforms'        => true,
    'title_platforms'       => 'Escúchalo en',
    'show_rss'              => true,
    'show_navigation'       => true,
    'title_navigation'      => 'Navegación',
    'show_persons'          => true,
    'title_persons'         => 'Participantes',
    'show_meta'             => true,
    'title_meta'            => 'Detalles del episodio',
    'show_download'         => true,
    'show_transcript'       => true,
    'show_support'          => true,
    'title_support'         => 'Apoyo y Comunidad',
    'show_fediverse_likes'  => true,
    'title_fediverse_likes' => 'Me gusta en el Fediverso',
    'show_embed'            => true,
    'title_embed'           => 'Incrustar reproductor',
];

// Likes del Fediverso (ActivityPub)
if (!function_exists('kp_ap_get_episode_likes')) {
    require_once __DIR__ . '/../activitypub.php';
}
$epLookup = !empty($ep['db_id']) ? (int)$ep['db_id'] : ($ep['id'] ?? '');
$fediverse_likes = function_exists('kp_ap_get_episode_likes') ? kp_ap_get_episode_likes($epLookup) : [];
$likes_count = count($fediverse_likes);

$hasSidebarWidgets = $sbPluginActive && (
    (!empty($sbConfig['show_platforms']) && (!empty($listen_platforms) || !empty($sbConfig['show_rss']))) ||
    (!empty($sbConfig['show_navigation']) && ($prevEp || $nextEp)) ||
    (!empty($sbConfig['show_persons']) && !empty($persons)) ||
    !empty($sbConfig['show_meta']) ||
    (!empty($sbConfig['show_support']) && (!empty($funding_platforms) || !empty($social_platforms))) ||
    (!empty($sbConfig['show_fediverse_likes']) && ($p['federate'] ?? 1)) ||
    !empty($sbConfig['show_embed'])
);

// Determinar si la barra lateral alternativa debe renderizarse
$showSidebarAlternate = $sbPluginActive && $hasSidebarWidgets && (empty($chapters) || $sbConfig['mode'] === 'always');

// Determinar si los participantes se muestran en la barra lateral
$personsInSidebar = $showSidebarAlternate && !empty($sbConfig['show_persons']) && !empty($persons);
?>
<section class="pub-section"><div class="pub-container pub-ep-view-container">
  <div class="pub-ep-detail-grid">
    <!-- Fila 1 - Izquierda: Cover con Reflejo -->
    <div class="pub-ep-grid-hero-cover">
      <div class="pub-ep-cover-reflection-wrap">
        <?php if ($ep_cover): ?>
          <img src="<?= e($ep_cover) ?>" alt="<?= e($ep['title']) ?>" class="pub-ep-cover-main">
          <div class="pub-ep-cover-reflection" aria-hidden="true">
            <img src="<?= e($ep_cover) ?>" alt="" class="pub-ep-cover-reflected-img">
            <div class="pub-ep-cover-reflection-fade"></div>
          </div>
        <?php else: ?>
          <div class="pub-ep-cover-main pub-ep-cover-placeholder" style="background:linear-gradient(135deg,<?= e($p['color']) ?>,<?= e($p['color']) ?>88);">
            <span style="color:rgba(255,255,255,0.35);font-weight:800;font-size:60px;letter-spacing:-0.04em"><?= e($p['initial']) ?></span>
          </div>
          <div class="pub-ep-cover-reflection" aria-hidden="true">
            <div class="pub-ep-cover-reflected-img pub-ep-cover-placeholder" style="background:linear-gradient(135deg,<?= e($p['color']) ?>,<?= e($p['color']) ?>88);">
              <span style="color:rgba(255,255,255,0.35);font-weight:800;font-size:60px;letter-spacing:-0.04em"><?= e($p['initial']) ?></span>
            </div>
            <div class="pub-ep-cover-reflection-fade"></div>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Fila 1 - Derecha: Título, Player y Compartir -->
    <div class="pub-ep-grid-hero-info">
      <a href="/@<?= e($p['id']) ?>" class="pub-ep-detail-show-tag" style="color:<?= e($p['color']) ?>">
        <?= e($p['title']) ?>
      </a>

      <h1 class="pub-ep-detail-title">
        <?= e($ep['title']) ?>
        <?php if (($p['parental'] ?? 'clean') === 'explicit'): ?>
          <span class="pub-badge-e" title="Contenido explícito" style="vertical-align:middle;margin-left:8px">E</span>
        <?php endif; ?>
        <?php if ((int)kp_get_podcast_meta($p, 'premium_enabled', 0) || (int)kp_get_episode_meta($ep, 'premium_enabled', 0)): ?>
          <span class="pub-badge-prem" title="Contenido premium" style="vertical-align:middle;margin-left:4px">$</span>
        <?php endif; ?>
      </h1>

      <div class="pub-ep-detail-control-bar">
        <div class="pub-ep-detail-play-row">
          <?php
          ob_start();
          $play_label = 'Reproducir' . (!empty($ep['duration']) ? ' · ' . e($ep['duration']) : '');
          ?>
          <button class="pub-ep-play-pill" style="background:<?= e($p['color']) ?>;color:#fff" aria-label="Reproducir <?= e($ep['title']) ?>"
                  data-kp-play data-src="/r/<?= urlencode($p['id']) ?>/<?= urlencode($ep['id']) ?>/audio.mp3"
                  data-title="<?= e($ep['title']) ?>" data-podcast="<?= e($p['title']) ?>"
                  data-color="<?= e($p['color']) ?>" data-initial="<?= e($p['initial']) ?>"
                  data-cover="<?= e($ep['cover'] ?: ($p['cover'] ?? '')) ?>">
            <?= icon('play', 13) ?>
            <span><?= $play_label ?></span>
          </button>
          <?php
          $play_control = ob_get_clean();
          echo kp_apply_filters('public_episode_play_control', $play_control, $ep, $p);
          ?>
          
          <div class="pub-ep-detail-meta">
            <?php
            $epMetaParts = [];
            if (!empty($ep['n']) && (int)$ep['n'] > 0) {
              $epMetaParts[] = '<span>Episodio ' . (int)$ep['n'] . '</span>';
            }
            if (!empty($ep['s']) && (int)$ep['s'] > 0) {
              $epMetaParts[] = '<span>Temporada ' . (int)$ep['s'] . '</span>';
            }
            if (!empty($ep['date_iso'])) {
              $epMetaParts[] = '<span data-kp-date="' . e($ep['date_iso']) . '">Publicado ' . e(kp_relative_time($ep['date_iso'])) . '</span>';
            }
            echo implode('<span class="pub-ep-meta-sep" aria-hidden="true">·</span>', $epMetaParts);
            ?>
          </div>
        </div>

        <button type="button" class="pub-ep-share-btn" onclick="kpShareEpisode(this)" data-title="<?= e($ep['title']) ?>">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"></path><polyline points="16 6 12 2 8 6"></polyline><line x1="12" y1="2" x2="12" y2="15"></line></svg>
          <span class="pub-ep-share-label">Compartir</span>
        </button>
      </div>

      <?php if (!empty($ep['transcript_url'])): ?>
        <div style="margin-top:20px;display:flex;align-items:center;gap:12px;padding:10px 14px;background:var(--pub-surface);border:1px solid var(--pub-border);border-radius:10px;width:fit-content">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--pub-muted)" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
          <div style="font-size:13px">
            <span style="font-weight:600;color:var(--pub-text)">Transcripción:</span>
            <a href="<?= e($ep['transcript_url']) ?>" target="_blank" style="color:<?= e($p['color']) ?>;text-decoration:none;margin-left:4px" onmouseover="this.style.textDecoration='underline'" onmouseout="this.style.textDecoration='none'">Ver o descargar</a>
          </div>
        </div>
      <?php endif; ?>
    </div>

    <!-- Fila 2 - Izquierda: Columna Lateral Unificada (Capítulos y/o Widgets) -->
    <?php if (!empty($chapters) || $showSidebarAlternate): ?>
      <div class="pub-ep-grid-sidebar">
        <!-- Bloque de Capítulos si existen -->
        <?php if (!empty($chapters)): ?>
          <div class="pub-ep-side-block pub-ep-chapters-block">
            <h3 class="pub-ep-subheading" style="margin-bottom:20px">Capítulos del episodio</h3>
            <div class="pub-ep-chapters-clean-list">
              <?php foreach ($chapters as $ch): 
                  $sec = (int)($ch['startTime'] ?? 0);
                  $fmt = sprintf('%d:%02d', floor($sec / 60), $sec % 60);
                  $ch_img = !empty($ch['img']) ? $ch['img'] : ($p['cover'] ?? '');
              ?>
                <button class="pub-ep-chapter-row" data-time="<?= $sec ?>"
                        data-src="/r/<?= urlencode($p['id']) ?>/<?= urlencode($ep['id']) ?>/audio.mp3"
                        data-title="<?= e($ep['title']) ?>" data-podcast="<?= e($p['title']) ?>"
                        data-color="<?= e($p['color']) ?>" data-initial="<?= e($p['initial']) ?>"
                        data-cover="<?= e($ep['cover'] ?: ($p['cover'] ?? '')) ?>"
                        aria-label="Saltar al minuto <?= $fmt ?>">
                  <span class="pub-ep-chapter-time"><?= $fmt ?></span>
                  <span class="pub-ep-chapter-title"><?= e($ch['title']) ?></span>
                  <?php if (!empty($ch_img)): ?>
                    <img class="pub-ep-chapter-thumb" src="<?= e($ch_img) ?>" alt="">
                  <?php endif; ?>
                </button>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>

        <!-- Widgets del Plugin si están activos en el orden configurado -->
        <?php if ($showSidebarAlternate): ?>
          <?php
            $orderedWidgets = $sbConfig['widgets_order'] ?? ['platforms', 'navigation', 'persons', 'meta', 'support', 'fediverse_likes', 'embed'];
            foreach ($orderedWidgets as $widgetKey):
              switch ($widgetKey):
                case 'platforms':
                  if (!empty($sbConfig['show_platforms']) && (!empty($listen_platforms) || !empty($sbConfig['show_rss']))): ?>
                    <!-- Plataformas de Escucha -->
                    <div class="pub-ep-side-block">
                      <h3 class="pub-ep-side-heading"><?= e($sbConfig['title_platforms'] ?: 'Escúchalo en') ?></h3>
                      <div class="pub-ep-side-platforms-icons" style="--podcast-color:<?= e($p['color']) ?>;">
                        <?php foreach ($listen_platforms as $slug => $plat):
                            $platData = \KutPod\KpPlatforms::DATA['podcasting'][$slug] ?? null;
                            $platLabel = $platData['label'] ?? ucfirst($slug);
                        ?>
                          <a href="<?= e($plat['link']) ?>" target="_blank" rel="noopener" class="pub-ep-side-platform-icon" title="<?= e($platLabel) ?>" aria-label="<?= e($platLabel) ?>">
                            <?= get_public_platform_svg('podcasting', $slug) ?>
                          </a>
                        <?php endforeach; ?>
                        <?php if (!empty($sbConfig['show_rss'])): ?>
                          <a href="/@<?= e($p['id']) ?>/feed.xml" target="_blank" rel="noopener" class="pub-ep-side-platform-icon" title="Feed RSS" aria-label="Feed RSS">
                            <?= icon('rss', 26) ?>
                          </a>
                        <?php endif; ?>
                      </div>
                    </div>
                  <?php endif;
                  break;

                case 'navigation':
                  if (!empty($sbConfig['show_navigation']) && ($prevEp || $nextEp)): ?>
                    <!-- Navegación entre episodios -->
                    <div class="pub-ep-side-block">
                      <h3 class="pub-ep-side-heading"><?= e($sbConfig['title_navigation'] ?: 'Navegación') ?></h3>
                      <div class="pub-ep-side-nav-group">
                        <?php if ($nextEp): ?>
                          <a href="/@<?= e($p['id']) ?>/<?= e($nextEp['id']) ?>" class="pub-ep-side-nav-card" title="Siguiente episodio: <?= e($nextEp['title']) ?>">
                            <div class="pub-ep-side-nav-meta">
                              <span class="pub-ep-side-nav-arrow"><?= icon('arrowUp', 12) ?></span>
                              <span>Siguiente episodio</span>
                            </div>
                            <div class="pub-ep-side-nav-title"><?= e($nextEp['title']) ?></div>
                          </a>
                        <?php endif; ?>
                        <?php if ($prevEp): ?>
                          <a href="/@<?= e($p['id']) ?>/<?= e($prevEp['id']) ?>" class="pub-ep-side-nav-card" title="Episodio anterior: <?= e($prevEp['title']) ?>">
                            <div class="pub-ep-side-nav-meta">
                              <span class="pub-ep-side-nav-arrow"><?= icon('arrowDown', 12) ?></span>
                              <span>Episodio anterior</span>
                            </div>
                            <div class="pub-ep-side-nav-title"><?= e($prevEp['title']) ?></div>
                          </a>
                        <?php endif; ?>
                      </div>
                    </div>
                  <?php endif;
                  break;

                case 'persons':
                  if ($personsInSidebar): ?>
                    <!-- Participantes del episodio -->
                    <div class="pub-ep-side-block">
                      <h3 class="pub-ep-side-heading"><?= e($sbConfig['title_persons'] ?: 'Participantes') ?></h3>
                      <div class="pub-ep-side-persons-list">
                        <?php foreach ($persons as $person): ?>
                          <div class="pub-ep-side-person-item">
                            <div class="pub-ep-side-person-avatar">
                              <?php if (!empty($person['avatar'])): ?>
                                <img src="<?= e($person['avatar']) ?>" alt="<?= e($person['name'] ?? '') ?>">
                              <?php else: ?>
                                <span><?= e(mb_substr($person['name'] ?? '?', 0, 1)) ?></span>
                              <?php endif; ?>
                            </div>
                            <div class="pub-ep-side-person-body">
                              <div class="pub-ep-side-person-name">
                                <?php if (!empty($person['url'])): ?>
                                  <a href="<?= e($person['url']) ?>" target="_blank" rel="noopener"><?= e($person['name']) ?></a>
                                <?php else: ?>
                                  <?= e($person['name']) ?>
                                <?php endif; ?>
                              </div>
                              <?php if (!empty($person['role'])): ?>
                                <span class="pub-ep-side-person-role"><?= e($person['role']) ?></span>
                              <?php endif; ?>
                            </div>
                          </div>
                        <?php endforeach; ?>
                      </div>
                    </div>
                  <?php endif;
                  break;

                case 'meta':
                  if (!empty($sbConfig['show_meta'])): ?>
                    <!-- Ficha técnica y Descarga -->
                    <div class="pub-ep-side-block">
                      <h3 class="pub-ep-side-heading"><?= e($sbConfig['title_meta'] ?: 'Detalles del episodio') ?></h3>
                      <div class="pub-ep-side-meta-card">
                        <div class="pub-ep-side-meta-row">
                          <span class="pub-ep-side-meta-k">Duración</span>
                          <span class="pub-ep-side-meta-v"><?= e($ep['duration'] ?: kp_fmt_duration((int)($ep['duration_secs'] ?? 0))) ?></span>
                        </div>
                        <?php if (!empty($ep['audio_bytes'])): ?>
                          <div class="pub-ep-side-meta-row">
                            <span class="pub-ep-side-meta-k">Tamaño</span>
                            <span class="pub-ep-side-meta-v"><?= human_bytes($ep['audio_bytes']) ?></span>
                          </div>
                        <?php endif; ?>
                        <?php if (!empty($ep['type']) && $ep['type'] !== 'full'): ?>
                          <div class="pub-ep-side-meta-row">
                            <span class="pub-ep-side-meta-k">Tipo</span>
                            <span class="pub-ep-side-meta-v"><?= ucfirst(e($ep['type'])) ?></span>
                          </div>
                        <?php endif; ?>
                        <?php if (!empty($ep['date_iso'])): ?>
                          <div class="pub-ep-side-meta-row">
                            <span class="pub-ep-side-meta-k">Publicado</span>
                            <span class="pub-ep-side-meta-v" data-kp-date="<?= e($ep['date_iso']) ?>"><?= e(kp_relative_time($ep['date_iso'])) ?></span>
                          </div>
                        <?php endif; ?>
                      </div>

                      <?php if (!empty($sbConfig['show_download']) || (!empty($sbConfig['show_transcript']) && !empty($ep['transcript_url']))): ?>
                        <div class="pub-ep-side-actions">
                          <?php if (!empty($sbConfig['show_download'])): ?>
                            <a href="/r/<?= urlencode($p['id']) ?>/<?= urlencode($ep['id']) ?>/audio.mp3" download class="pub-ep-side-dl-btn" title="Descargar archivo MP3">
                              <?= icon('download', 14) ?>
                              <span>Descargar MP3</span>
                            </a>
                          <?php endif; ?>
                          <?php if (!empty($sbConfig['show_transcript']) && !empty($ep['transcript_url'])): ?>
                            <a href="<?= e($ep['transcript_url']) ?>" target="_blank" class="pub-ep-side-transcript-btn" title="Ver o descargar transcripción">
                              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                              <span>Ver transcripción</span>
                            </a>
                          <?php endif; ?>
                        </div>
                      <?php endif; ?>
                    </div>
                  <?php endif;
                  break;

                case 'support':
                  if (!empty($sbConfig['show_support']) && (!empty($funding_platforms) || !empty($social_platforms))): ?>
                    <!-- Apoyo y Comunidad -->
                    <div class="pub-ep-side-block">
                      <h3 class="pub-ep-side-heading"><?= e($sbConfig['title_support'] ?: 'Apoyo y Comunidad') ?></h3>
                      <div class="pub-ep-side-links-group">
                        <?php foreach ($funding_platforms as $slug => $plat):
                            $platData = \KutPod\KpPlatforms::DATA['funding'][$slug] ?? null;
                            $fLabel = $platData['label'] ?? ucfirst($slug);
                        ?>
                          <a href="<?= e($plat['link']) ?>" target="_blank" rel="noopener" class="pub-ep-side-plat-badge pub-ep-side-badge-support" style="--badge-accent:<?= e($p['color']) ?>">
                            <span class="pub-ep-side-plat-icon"><?= get_public_platform_svg('funding', $slug) ?></span>
                            <span class="pub-ep-side-plat-label">Apoyar en <?= e($fLabel) ?></span>
                          </a>
                        <?php endforeach; ?>
                        <?php foreach ($social_platforms as $slug => $plat):
                            $platData = \KutPod\KpPlatforms::DATA['social'][$slug] ?? null;
                            $sLabel = !empty($plat['account_id']) ? $plat['account_id'] : ($platData ? $platData['label'] : ucfirst($slug));
                        ?>
                          <a href="<?= e($plat['link']) ?>" target="_blank" rel="noopener" class="pub-ep-side-plat-badge">
                            <span class="pub-ep-side-plat-icon"><?= get_public_platform_svg('social', $slug) ?></span>
                            <span class="pub-ep-side-plat-label"><?= e($sLabel) ?></span>
                          </a>
                        <?php endforeach; ?>
                      </div>
                    </div>
                  <?php endif;
                  break;

                case 'fediverse_likes':
                  if (!empty($sbConfig['show_fediverse_likes']) && ($p['federate'] ?? 1)): ?>
                    <!-- Likes del Fediverso (ActivityPub) -->
                    <div class="pub-ep-side-block">
                      <h3 class="pub-ep-side-heading" style="display:flex;align-items:center;justify-content:space-between">
                        <span><?= e($sbConfig['title_fediverse_likes'] ?: 'Me gusta en el Fediverso') ?></span>
                        <span class="pub-ep-side-likes-badge" title="<?= $likes_count ?> likes recibidos">
                          <?= icon('heart', 12) ?>
                          <span><?= $likes_count ?></span>
                        </span>
                      </h3>
                      <div class="pub-ep-side-likes-card">
                        <?php if ($likes_count > 0): ?>
                          <div class="pub-ep-side-likes-summary">
                            <span><strong><?= $likes_count ?></strong> <?= $likes_count === 1 ? 'persona le ha dado me gusta' : 'personas le han dado me gusta' ?></span>
                          </div>
                          <div class="pub-ep-side-likes-avatars">
                            <?php foreach ($fediverse_likes as $like): ?>
                              <a href="<?= e($like['actor_url']) ?>" target="_blank" rel="noopener" class="pub-ep-side-like-avatar" title="<?= e($like['actor_name'] ?: $like['actor_handle']) ?> (@<?= e($like['actor_handle']) ?>)">
                                <?php if (!empty($like['actor_avatar'])): ?>
                                  <img src="<?= e($like['actor_avatar']) ?>" alt="<?= e($like['actor_name'] ?: $like['actor_handle']) ?>" loading="lazy">
                                <?php else: ?>
                                  <span class="pub-ep-side-like-initial"><?= e(mb_substr($like['actor_name'] ?: $like['actor_handle'] ?: '?', 0, 1)) ?></span>
                                <?php endif; ?>
                              </a>
                            <?php endforeach; ?>
                          </div>
                        <?php else: ?>
                          <div class="pub-ep-side-likes-empty">
                            <span class="pub-ep-side-likes-empty-icon"><?= icon('heart', 16) ?></span>
                            <p>Aún no hay me gusta desde el Fediverso. Interactúa desde Mastodon con la nota de este episodio para aparecer aquí.</p>
                          </div>
                        <?php endif; ?>
                      </div>
                    </div>
                  <?php endif;
                  break;

                  case 'embed':
                  if (!empty($sbConfig['show_embed'])):
                    $podcastSlug = $p['id'] ?? ($ep['podcast'] ?? '');
                    $episodeSlug = $ep['id'] ?? '';
                    $embedUrl = kp_base_url() . '/embed/@' . rawurlencode($podcastSlug) . '/' . rawurlencode($episodeSlug);
                    $iframeCode = '<iframe src="' . e($embedUrl) . '" width="100%" height="160" frameborder="0" scrolling="no" allow="encrypted-media; fullscreen"></iframe>';
                  ?>
                    <!-- Incrustar reproductor -->
                    <div class="pub-ep-side-block">
                      <h3 class="pub-ep-side-heading"><?= e($sbConfig['title_embed'] ?: 'Incrustar reproductor') ?></h3>
                      <div class="pub-ep-side-embed-card">
                        <p class="pub-ep-side-embed-desc">Copia el código para incluir este reproductor en tu sitio web o blog:</p>
                        <div class="pub-ep-side-embed-box">
                          <input type="text" readonly class="pub-ep-side-embed-input" value="<?= e($iframeCode) ?>" id="kpEmbedCode">
                          <button type="button" class="pub-ep-side-embed-copy-btn" onclick="kpCopyEmbedCode(this)" title="Copiar código HTML">
                            <?= icon('copy', 13) ?>
                            <span class="kp-copy-text">Copiar</span>
                          </button>
                        </div>
                        <div class="pub-ep-side-embed-links">
                          <a href="<?= e($embedUrl) ?>" target="_blank" rel="noopener" class="pub-ep-side-embed-preview">
                            <?= icon('externalLink', 12) ?> Vista previa del reproductor
                          </a>
                        </div>
                      </div>
                    </div>
                  <?php endif;
                  break;
              endswitch;
            endforeach;
          ?>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <!-- Fila 2 - Derecha: Descripción y Notas del Podcast -->
    <div class="pub-ep-grid-body">
      <!-- Sección Descripción -->
      <div class="pub-ep-detail-desc-block">
        <h3 class="pub-ep-subheading">Descripción</h3>
        <div class="pub-prose pub-ep-clean-prose">
          <?php
            $md = $ep['notes_md'] ?? '';
            if (!$md) {
              echo '<p>Sin notas del episodio.</p>';
            } else {
              echo kp_md_to_html($md);
            }
          ?>
        </div>
      </div>

      <!-- Sección Notas del Podcast -->
      <?php if ($fixed_notes): ?>
      <div class="pub-ep-detail-notes-block">
        <h3 class="pub-ep-subheading">Notas del podcast</h3>
        <div class="pub-prose pub-ep-clean-prose" style="--podcast-color: <?= e($p['color']) ?>;">
          <?= kp_md_to_html($fixed_notes) ?>
        </div>
      </div>
      <?php endif; ?>
    </div>

    <!-- Fila 3 - Ancho Completo: Participantes (solo si NO se están mostrando ya en la barra lateral) -->
    <?php if (!$personsInSidebar && !empty($persons)): ?>
    <div class="pub-ep-grid-persons">
      <h3 class="pub-ep-subheading">En este episodio participó:</h3>
      <div class="pub-ep-persons-grid">
        <?php foreach ($persons as $person): ?>
          <div class="pub-ep-person-card">
            <div class="pub-ep-person-avatar">
              <?php if (!empty($person['avatar'])): ?>
                <img src="<?= e($person['avatar']) ?>" alt="<?= e($person['name']) ?>">
              <?php else: ?>
                <span><?= e(mb_substr($person['name'], 0, 1)) ?></span>
              <?php endif; ?>
            </div>
            <div class="pub-ep-person-info">
              <div class="pub-ep-person-head">
                <span class="pub-ep-person-name">
                  <?php if (!empty($person['url'])): ?>
                    <a href="<?= e($person['url']) ?>" target="_blank"><?= e($person['name']) ?></a>
                  <?php else: ?>
                    <?= e($person['name']) ?>
                  <?php endif; ?>
                </span>
                <span class="pub-ep-person-role"><?= e($person['role']) ?></span>
              </div>
              <?php if (!empty($person['bio'])): ?>
                <p class="pub-ep-person-bio"><?= e($person['bio']) ?></p>
              <?php endif; ?>
              <?php if (!empty($person['url'])): ?>
                <a href="<?= e($person['url']) ?>" target="_blank" class="pub-ep-person-link" style="color:<?= e($p['color']) ?>">
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path></svg>
                  Visitar enlace
                </a>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div></section>

<script>
function kpShareEpisode(btn) {
  const url = window.location.href;
  const title = btn.getAttribute('data-title') || document.title;
  const label = btn.querySelector('.pub-ep-share-label');
  if (navigator.share) {
    navigator.share({ title: title, url: url }).catch(() => {});
  } else if (navigator.clipboard) {
    navigator.clipboard.writeText(url).then(() => {
      if (label) {
        const orig = label.textContent;
        label.textContent = '¡Enlace copiado!';
        setTimeout(() => { label.textContent = orig; }, 2000);
      }
    }).catch(() => {});
  }
}

function kpCopyEmbedCode(btn) {
  const input = document.getElementById('kpEmbedCode');
  if (!input) return;
  input.select();
  input.setSelectionRange(0, 99999);
  
  const textEl = btn.querySelector('.kp-copy-text');
  const copyAction = () => {
    if (textEl) {
      const orig = textEl.textContent;
      textEl.textContent = '¡Copiado! ✓';
      btn.style.color = 'var(--accent, #10b981)';
      setTimeout(() => {
        textEl.textContent = orig;
        btn.style.color = '';
      }, 2000);
    }
  };

  if (navigator.clipboard) {
    navigator.clipboard.writeText(input.value).then(copyAction).catch(() => {
      document.execCommand('copy');
      copyAction();
    });
  } else {
    document.execCommand('copy');
    copyAction();
  }
}
</script>
