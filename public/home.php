<?php
// public/home.php
require_once __DIR__ . '/../includes/helpers.php';
$podcasts = kp_podcasts();
$episodes = kp_episodes();

// SEO: metadata para la página de inicio
$inst_seo = kp_instance();
$og_title = $inst_seo['name'] ?: $inst_seo['domain'];
$og_description = $inst_seo['tagline'] ?? 'Plataforma de podcasts';
$domain_seo = kp_handle_domain();
$protocol_seo = kp_get_protocol();

// Imagen OG: logo de la instancia → portada del primer podcast → vacío
$_home_logo = kp_setting('instance_logo');
if ($_home_logo) {
    $og_image = $_home_logo;
} elseif (!empty($podcasts) && !empty($podcasts[0]['cover'])) {
    $og_image = $podcasts[0]['cover'];
}

$og_jsonld = [
    '@context' => 'https://schema.org',
    '@type' => 'WebSite',
    'name' => $og_title,
    'url' => "{$protocol_seo}://{$domain_seo}/",
    'description' => $og_description,
];

$featured = null;
$featuredEp = null;
if (!empty($episodes)) {
  $featuredEp = $episodes[0];
  foreach ($podcasts as $p) {
    if ($p['id'] === $featuredEp['podcast']) {
      $featured = $p;
      break;
    }
  }
}
$latest = array_slice($episodes, 0, 6);

function pub_play_btn(array $ep, array $p, int $size = 14): string {
  $src = '/r/' . urlencode($p['id']) . '/' . urlencode($ep['id']) . '/audio.mp3';
  return '<button class="pub-ep-play" data-kp-play data-src="'.e($src).'" data-title="'.e($ep['title']).'" data-podcast="'.e($p['title']).'" data-color="'.e($p['color']).'" data-initial="'.e($p['initial']).'" data-cover="'.e($ep['cover'] ?: ($p['cover'] ?? '')).'">'.icon('play',$size).'</button>';
}

if (!$featured || !$featuredEp): ?>
<section class="pub-hero"><div class="pub-container" style="padding:80px 0;text-align:center">
  <h1 class="pub-h1" style="max-width:680px;margin:0 auto 16px">Tu instancia está lista.</h1>
  <p class="pub-lede" style="max-width:520px;margin:0 auto 28px">Todavía no hay episodios publicados. Cuando subas el primero aparecerá aquí.</p>
  <a class="pub-btn pub-btn-primary" href="/admin">Ir al Studio →</a>
</div></section>
<?php return; endif; ?>
<?php
$featured_premium = false;
$user_has_access = true;
$tokenStr = '';
if (kp_plugin_is_active('premium')) {
  $featured_premium = (int)kp_get_podcast_meta($featured, 'premium_enabled', 0) || (int)kp_get_episode_meta($featuredEp, 'premium_enabled', 0);
  if ($featured_premium) {
    $user_has_access = false;
    if (!function_exists('kp_current_user')) {
      require_once __DIR__ . '/../includes/auth.php';
    }
    $u = kp_current_user();
    if ($u && in_array($u['role'], ['owner', 'admin'], true)) {
      $user_has_access = true;
    } else {
      $tokenStr = trim($_GET['token'] ?? '');
      if (!empty($tokenStr)) {
        $token = kp_one("SELECT * FROM premium_tokens WHERE token = ? AND podcast_id = ? AND status = 'active'", [$tokenStr, $featured['db_id']]);
        if ($token) {
          $user_has_access = true;
        }
      }
    }
  }
}
?>
<section class="pub-hero"><div class="pub-container">
  <div style="display:grid;grid-template-columns:1.2fr 1fr;gap:48px;align-items:center">
    <div>
      <div class="pub-eyebrow">Último episodio · <?= e($featured['title']) ?></div>
      <h1 class="pub-h1"><?= e($featuredEp['title']) ?></h1>
      <p class="pub-lede"><?= e(mb_strimwidth(strip_tags($featuredEp['notes_md'] ?? ''), 0, 150, '...')) ?></p>
      <div class="row" style="gap:12px;margin-top:28px">
        <?php if ($featured_premium && !$user_has_access): ?>
          <a class="pub-btn pub-btn-primary" style="background: <?= e($featured['color']) ?>; border-color: <?= e($featured['color']) ?>; color: #fff; text-decoration: none; display: inline-flex; align-items: center; gap: 6px;" href="/@<?= urlencode($featured['id']) ?>/<?= urlencode($featuredEp['id']) ?>">
            <?= icon('lock',13) ?> Premium · <?= e($featuredEp['duration']) ?>
          </a>
        <?php else: ?>
          <?php 
            $play_src = "/r/" . urlencode($featured['id']) . "/" . urlencode($featuredEp['id']) . "/audio.mp3";
            if (!empty($tokenStr)) {
              $play_src .= "?token=" . urlencode($tokenStr);
            }
          ?>
          <button class="pub-btn pub-btn-primary" style="background: <?= e($featured['color']) ?>; border-color: <?= e($featured['color']) ?>; color: #fff;" data-kp-play data-src="<?= e($play_src) ?>" data-title="<?= e($featuredEp['title']) ?>" data-podcast="<?= e($featured['title']) ?>" data-color="<?= e($featured['color']) ?>" data-initial="<?= e($featured['initial']) ?>" data-cover="<?= e($featuredEp['cover'] ?: ($featured['cover'] ?? '')) ?>">
            <?= icon('play',13) ?> Reproducir · <?= e($featuredEp['duration']) ?>
          </button>
        <?php endif; ?>
        <a class="pub-btn pub-btn-ghost" href="/@<?= e($featured['id']) ?>/<?= e($featuredEp['id']) ?><?= !empty($tokenStr) ? '?token=' . urlencode($tokenStr) : '' ?>">Ver notas →</a>
      </div>
      <div class="row" style="gap:10px;margin-top:32px;font-size:12.5px;color:var(--pub-muted);align-items:center">
        <?php if (($featuredEp['type'] ?? '') === 'trailer'): ?>
          <span class="badge-promo">Trailer</span><span>·</span>
        <?php elseif (($featuredEp['type'] ?? '') === 'bonus'): ?>
          <span class="badge-extra">Bonus</span><span>·</span>
        <?php else: ?>
          <?php if ((int)($featuredEp['s'] ?? 1) > 0): ?>
            <span>Temporada <?= (int)$featuredEp['s'] ?></span><span>·</span>
          <?php endif; ?>
          <span>Episodio <?= (int)$featuredEp['n'] ?></span><span>·</span>
        <?php endif; ?>
        <span data-kp-date="<?= e($featuredEp['date_iso']) ?>">Publicado <?= e(kp_relative_time($featuredEp['date_iso'])) ?></span>
      </div>
    </div>
    <?php if ($featured['cover']): ?>
      <img class="pub-cover" style="width:100%;aspect-ratio:1;border-radius:24px;object-fit:cover" src="<?= e($featured['cover']) ?>" alt="">
    <?php else: ?>
      <div class="pub-cover" style="background:linear-gradient(135deg,<?= e($featured['color']) ?>,<?= e($featured['color']) ?>88)">
        <div style="position:absolute;inset:0;padding:32px;display:flex;flex-direction:column;justify-content:space-between;color:#fff">
          <div style="font-size:48px;font-weight:800;letter-spacing:-0.04em"><?= e($featured['initial']) ?></div>
          <div><div style="font-size:12px;opacity:0.85;text-transform:uppercase;letter-spacing:0.1em;font-weight:600;margin-bottom:6px">Podcast</div><div style="font-size:22px;font-weight:700;letter-spacing:-0.02em">
            <?= e($featured['title']) ?>
            <?php if (($featured['parental'] ?? 'clean') === 'explicit'): ?>
              <span style="display:inline-block;background:var(--border);color:var(--text-2);border-radius:4px;font-size:12px;padding:2px 6px;margin-left:6px;vertical-align:middle;line-height:1;font-weight:800">E</span>
            <?php endif; ?>
            <?php if ((int)kp_get_podcast_meta($featured, 'premium_enabled', 0)): ?>
              <span style="display:inline-block;background:#10b981;color:#fff;border-radius:4px;font-size:12px;padding:2px 6px;margin-left:6px;vertical-align:middle;line-height:1;font-weight:800">$</span>
            <?php endif; ?>
          </div></div>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div></section>

<section class="pub-section"><div class="pub-container">
  <div class="row" style="justify-content:space-between;align-items:baseline;margin-bottom:28px">
    <h2 class="pub-h2">Últimos episodios</h2>
    <a class="pub-link" href="/episodes">Ver todos →</a>
  </div>
  <div class="pub-eps-grid">
    <?php foreach ($latest as $ep): $p = kp_podcast_or_placeholder($ep['podcast'] ?? ''); ?>
      <a class="pub-ep-card" href="/@<?= e($p['id']) ?>/<?= e($ep['id']) ?>" style="text-decoration:none;color:inherit">
        <?php if ($p['cover']): ?>
          <img class="pub-ep-cover" style="object-fit:cover" src="<?= e($p['cover']) ?>" alt="">
          <?= pub_play_btn($ep, $p) ?>
        <?php else: ?>
          <div class="pub-ep-cover" style="background:linear-gradient(135deg,<?= e($p['color']) ?>,<?= e($p['color']) ?>99)">
            <span style="color:#fff;font-weight:700;font-size:22px"><?= e($p['initial']) ?></span>
            <?= pub_play_btn($ep, $p) ?>
          </div>
        <?php endif; ?>
        <div style="flex:1;min-width:0">
          <div class="pub-tag" style="color:<?= e($p['color']) ?>;border-color:<?= e($p['color']) ?>55"><?= e($p['title']) ?></div>
          <h3 class="pub-ep-title" style="display:flex;align-items:center;flex-wrap:wrap;gap:4px">
            <?= e($ep['title']) ?>
            <?php if (($p['parental'] ?? 'clean') === 'explicit'): ?>
              <span style="display:inline-block;background:var(--border);color:var(--text-2);border-radius:4px;font-size:9px;padding:1px 3px;line-height:1;font-weight:800">E</span>
            <?php endif; ?>
            <?php if ((int)kp_get_podcast_meta($p, 'premium_enabled', 0) || (int)kp_get_episode_meta($ep, 'premium_enabled', 0)): ?>
              <span style="display:inline-block;background:#10b981;color:#fff;border-radius:4px;font-size:9px;padding:1px 3px;line-height:1;font-weight:800">$</span>
            <?php endif; ?>
          </h3>
          <div style="font-size:12.5px;color:var(--pub-muted);margin-top:6px"><?= e($ep['duration']) ?> · <span data-kp-date="<?= e($ep['date_iso']) ?>">Publicado <?= e(kp_relative_time($ep['date_iso'])) ?></span></div>
        </div>
      </a>
    <?php endforeach; ?>
  </div>
</div></section>

<section class="pub-section pub-section-alt"><div class="pub-container">
  <div class="row" style="justify-content:space-between;align-items:baseline;margin-bottom:28px">
    <h2 class="pub-h2">Mis shows</h2>
    <a class="pub-link" href="/shows">Catálogo completo →</a>
  </div>
  <div class="pub-shows-grid">
    <?php foreach ($podcasts as $p): ?>
      <a class="pub-show-card" href="/@<?= e($p['id']) ?>" style="text-decoration:none;color:inherit">
        <?php if ($p['cover']): ?>
          <img class="pub-show-cover" style="width:100%;aspect-ratio:1;border-radius:14px;object-fit:cover" src="<?= e($p['cover']) ?>" alt="">
        <?php else: ?>
          <div class="pub-show-cover" style="background:linear-gradient(135deg,<?= e($p['color']) ?>,<?= e($p['color']) ?>88)">
            <span style="color:#fff;font-weight:800;font-size:38px;letter-spacing:-0.04em"><?= e($p['initial']) ?></span>
          </div>
        <?php endif; ?>
        <div style="margin-top:14px">
          <h3 style="font-size:16px;font-weight:600;letter-spacing:-0.01em">
            <?= e($p['title']) ?>
            <?php if (($p['parental'] ?? 'clean') === 'explicit'): ?>
              <span style="display:inline-block;background:var(--border);color:var(--text-2);border-radius:4px;font-size:10px;padding:2px 4px;margin-left:4px;vertical-align:middle;line-height:1;font-weight:800">E</span>
            <?php endif; ?>
            <?php if ((int)kp_get_podcast_meta($p, 'premium_enabled', 0)): ?>
              <span style="display:inline-block;background:#10b981;color:#fff;border-radius:4px;font-size:10px;padding:2px 4px;margin-left:4px;vertical-align:middle;line-height:1;font-weight:800">$</span>
            <?php endif; ?>
          </h3>
          <div style="font-size:12.5px;color:var(--pub-muted);margin-top:4px"><?= (int)$p['episodes'] ?> episodios · <?= e($p['cat']) ?></div>
        </div>
      </a>
    <?php endforeach; ?>
  </div>
</div></section>
