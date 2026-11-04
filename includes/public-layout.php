<?php 
require_once __DIR__ . '/../version.php'; 
require_once __DIR__ . '/helpers.php'; 
$inst = kp_instance(); 
$logo = kp_setting('instance_logo');
?>
<!doctype html>
<html lang="es" data-public="1" data-theme="<?= e(kp_setting('public_theme', 'light')) ?>">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<title><?= e($title ?? $inst['name']) ?></title>
<!-- Metadatos de Open Graph / Compartir -->
<meta property="og:type" content="<?= e($og_type ?? 'website') ?>"/>
<meta property="og:title" content="<?= e($og_title ?? $title ?? $inst['name']) ?>"/>
<meta property="og:description" content="<?= e($og_description ?? $inst['tagline'] ?? '') ?>"/>
<?php
$og_img_val = $og_image ?? $logo ?? '';
if ($og_img_val && !preg_match('#^https?://#i', $og_img_val)) {
    $domain = kp_handle_domain();
    $protocol = kp_get_protocol();
    if (strpos($og_img_val, '/') === 0) {
        $og_img_val = $protocol . '://' . $domain . $og_img_val;
    } else {
        $og_img_val = $protocol . '://' . $domain . '/' . $og_img_val;
    }
}
$current_protocol = kp_get_protocol();
$current_host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$current_uri = $_SERVER['REQUEST_URI'] ?? '/';
$canonical_url = $og_url ?? ($current_protocol . '://' . $current_host . strtok($current_uri, '?'));
?>
<?php if ($og_img_val): ?>
<meta property="og:image" content="<?= e($og_img_val) ?>"/>
<?php endif; ?>
<meta property="og:url" content="<?= e($og_url ?? ($current_protocol . '://' . $current_host . $current_uri)) ?>"/>

<!-- Metadatos de Twitter Cards -->
<meta name="twitter:card" content="summary_large_image"/>
<meta name="twitter:title" content="<?= e($og_title ?? $title ?? $inst['name']) ?>"/>
<meta name="twitter:description" content="<?= e($og_description ?? $inst['tagline'] ?? '') ?>"/>
<?php if ($og_img_val): ?>
<meta name="twitter:image" content="<?= e($og_img_val) ?>"/>
<?php endif; ?>

<link rel="canonical" href="<?= e($canonical_url) ?>"/>
<?php if (!empty($p['slug'])): ?>
<link rel="alternate" type="application/rss+xml" title="Feed RSS <?= e($p['title'] ?? '') ?>" href="/@<?= e($p['slug']) ?>/feed.xml"/>
<?php endif; ?>
<meta name="description" content="<?= e($og_description ?? $inst['tagline'] ?? '') ?>"/>
<meta name="robots" content="index, follow"/>
<?php if (!empty($og_jsonld)): ?>
<script type="application/ld+json"><?= json_encode($og_jsonld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?></script>
<?php endif; ?>

<link rel="preconnect" href="https://fonts.googleapis.com"/>
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin/>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="/assets/styles.css?v=<?= filemtime(__DIR__ . '/../assets/styles.css') ?>"/>
<link rel="stylesheet" href="/assets/public.css?v=<?= filemtime(__DIR__ . '/../assets/public.css') ?>"/>
<?php
$acc = kp_setting('public_accent', '#ff5f7e');
$acc_rgb = sscanf($acc, "#%02x%02x%02x");
if ($acc_rgb && count($acc_rgb) === 3) {
    $rgb_str = $acc_rgb[0].', '.$acc_rgb[1].', '.$acc_rgb[2];
    echo "<style>:root { --accent: {$acc}; --accent-soft: rgba({$rgb_str}, 0.14); --accent-glow: 0 0 0 1px rgba({$rgb_str}, 0.35), 0 18px 60px -20px rgba({$rgb_str}, 0.55); }</style>\n";
}
if ($logo) {
    echo '<link rel="icon" href="' . e($logo) . '">';
}
$masto_url = kp_setting('admin_mastodon_url');
if ($masto_url) {
    if (preg_match('/href=["\']([^"\']+)["\']/', $masto_url, $m)) {
        $masto_url = $m[1];
    }
    echo '<link rel="me" href="' . e($masto_url) . '">';
}
?>
</head>
<body>
<div class="pub-root">
  <input type="checkbox" id="pub-menu-toggle" class="pub-menu-toggle" hidden>
  <header class="pub-header">
    <div class="pub-container row" style="justify-content:space-between;align-items:center;height:72px">
      <a class="pub-brand row" href="/" style="gap:10px;color:var(--pub-text);text-decoration:none">
        <?php if ($logo): ?>
          <img src="<?= e($logo) ?>" style="width:36px;height:36px;border-radius:8px;object-fit:cover">
        <?php else: ?>
          <div class="pub-brand-mark"><?= e(strtoupper(substr($inst['domain'], 0, 1))) ?></div>
        <?php endif; ?>
        <div><div style="font-weight:700;font-size:16px;letter-spacing:-0.01em"><?= e($inst['name'] ?: $inst['domain']) ?></div><?php if ($inst['tagline'] || $inst['owner']): ?><div style="font-size:11px;color:var(--pub-muted);margin-top:1px"><?= e($inst['tagline'] ?: ('Podcasts de ' . $inst['owner'])) ?></div><?php endif; ?></div>
      </a>

      <label for="pub-menu-toggle" class="pub-menu-btn" aria-label="Menú">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon-open"><line x1="3" y1="12" x2="21" y2="12"></line><line x1="3" y1="6" x2="21" y2="6"></line><line x1="3" y1="18" x2="21" y2="18"></line></svg>
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon-close"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
      </label>

      <div class="pub-header-right-desktop row">
        <nav class="row" style="gap:4px">
          <a class="pub-nav-link <?= ($route ?? '')==='home'?'active':'' ?>" href="/">Inicio</a>
          <a class="pub-nav-link <?= ($route ?? '')==='shows'?'active':'' ?>" href="/shows">Shows</a>
          <?php foreach (($published_pages ?? []) as $pg): if (($pg['menu'] ?? 'header') !== 'header') continue; ?>
            <a class="pub-nav-link <?= (($route ?? '')==='page' && ($_GET['id']??'')===$pg['slug'])?'active':'' ?>" href="/p/<?= e($pg['slug']) ?>"><?= e($pg['title']) ?></a>
          <?php endforeach; ?>
        </nav>
        <div class="row pub-header-actions" style="gap:10px">
          <?php 
          if (!function_exists('kp_current_user')) {
              require_once __DIR__ . '/auth.php';
          }
          if (kp_current_user()): ?>
            <a class="pub-btn pub-btn-ghost" href="/admin">Studio →</a>
          <?php else: ?>
            <a class="pub-btn pub-btn-ghost" href="/admin" title="Panel de Administración">Admin</a>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </header>

  <div class="pub-header-right-mobile">
    <nav class="row" style="gap:4px">
      <a class="pub-nav-link <?= ($route ?? '')==='home'?'active':'' ?>" href="/">Inicio</a>
      <a class="pub-nav-link <?= ($route ?? '')==='shows'?'active':'' ?>" href="/shows">Shows</a>
      <?php foreach (($published_pages ?? []) as $pg): if (($pg['menu'] ?? 'header') !== 'header') continue; ?>
        <a class="pub-nav-link <?= (($route ?? '')==='page' && ($_GET['id']??'')===$pg['slug'])?'active':'' ?>" href="/p/<?= e($pg['slug']) ?>"><?= e($pg['title']) ?></a>
      <?php endforeach; ?>
    </nav>
    <div class="row pub-header-actions" style="gap:10px">
      <?php 
      if (!function_exists('kp_current_user')) {
          require_once __DIR__ . '/auth.php';
      }
      if (kp_current_user()): ?>
        <a class="pub-btn pub-btn-ghost" href="/admin">Studio →</a>
      <?php else: ?>
        <a class="pub-btn pub-btn-ghost" href="/admin" title="Panel de Administración">Admin</a>
      <?php endif; ?>
    </div>
  </div>

  <div class="pub-body"><?= $body ?></div>
  <footer class="pub-footer"><div class="pub-container">
    <div style="display:grid;grid-template-columns:1.2fr 1fr 1fr 1fr;gap:40px">
      <div>
        <div class="row" style="gap:10px;margin-bottom:14px">
          <?php if ($logo): ?>
            <img src="<?= e($logo) ?>" style="width:32px;height:32px;border-radius:6px;object-fit:cover">
          <?php else: ?>
            <div class="pub-brand-mark"><?= e(strtoupper(substr($inst['domain'], 0, 1))) ?></div>
          <?php endif; ?>
          <div style="font-weight:700;font-size:15px;align-self:center"><?= e($inst['name'] ?: $inst['domain']) ?></div>
        </div>
        <p style="font-size:13px;color:var(--pub-muted);line-height:1.6;max-width:320px"><?= e($inst['tagline'] ?: 'Publicado desde KutPod.') ?></p>
      </div>
      <div><div class="pub-foot-h">Shows</div>
        <?php foreach (array_slice(kp_podcasts(), 0, 4) as $p): ?>
          <a class="pub-foot-link" href="/@<?= e($p['id']) ?>"><?= e($p['title']) ?></a>
        <?php endforeach; ?>
      </div>
      <div><div class="pub-foot-h" style="visibility:hidden">Shows</div>
        <?php foreach (array_slice(kp_podcasts(), 4, 4) as $p): ?>
          <a class="pub-foot-link" href="/@<?= e($p['id']) ?>"><?= e($p['title']) ?></a>
        <?php endforeach; ?>
      </div>
      <div><div class="pub-foot-h" style="visibility:hidden">Shows</div>
        <?php foreach (array_slice(kp_podcasts(), 8, 4) as $p): ?>
          <a class="pub-foot-link" href="/@<?= e($p['id']) ?>"><?= e($p['title']) ?></a>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="row" style="justify-content:space-between;border-top:1px solid var(--pub-border);padding-top:24px;margin-top:40px;font-size:12px;color:var(--pub-muted)">
      <span>© <?= e($inst['year']) ?><?= $inst['owner'] ? ' ' . e($inst['owner']) : '' ?>. Hecho con KutPod. · <a href="/admin" style="color:inherit;text-decoration:none">Administración</a></span><span>v<?= defined('KUTPOD_VERSION') ? KUTPOD_VERSION : '0.4.2' ?></span>
    </div>
  </div></footer>
</div>
<script src="/assets/player.js?v=<?= filemtime(__DIR__ . '/../assets/player.js') ?>" defer></script>
<script>
  // Cerrar menú móvil al pulsar un enlace
  document.querySelectorAll('.pub-header-right-mobile .pub-nav-link').forEach(link => {
    link.addEventListener('click', () => {
      document.getElementById('pub-menu-toggle').checked = false;
    });
  });

  // Tooltip personalizado para fechas (compatible con Safari, Dia, Chromium y Firefox)
  (function() {
    const tooltip = document.createElement('div');
    tooltip.className = 'kp-custom-tooltip';
    document.body.appendChild(tooltip);

    const style = document.createElement('style');
    style.textContent = `
      [data-kp-date] {
        cursor: help;
      }
      .kp-custom-tooltip {
        position: fixed;
        background: rgba(15, 23, 42, 0.95);
        color: #f8fafc;
        padding: 6px 12px;
        border-radius: 8px;
        font-size: 12px;
        font-weight: 500;
        pointer-events: none;
        opacity: 0;
        transition: opacity 0.15s ease, transform 0.15s ease;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.25);
        z-index: 100000;
        white-space: nowrap;
        -webkit-backdrop-filter: blur(8px);
        backdrop-filter: blur(8px);
        border: 1px solid rgba(255, 255, 255, 0.12);
        will-change: transform, opacity;
      }
      .kp-custom-tooltip.visible {
        opacity: 1;
      }
    `;
    document.head.appendChild(style);

    function formatDate(iso) {
      if (!iso) return '';
      try {
        let dateStr = iso;
        if (/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/.test(iso)) {
          dateStr = iso.replace(' ', 'T') + 'Z';
        } else if (iso.includes(' ') && !iso.includes('Z') && !iso.includes('+')) {
          dateStr = iso.replace(' ', 'T') + 'Z';
        }
        const d = new Date(dateStr);
        if (!isNaN(d.getTime())) {
          return d.toLocaleDateString(undefined, { year: 'numeric', month: '2-digit', day: '2-digit' });
        }
      } catch (e) {}
      return '';
    }

    let activeEl = null;

    function showTooltip(el) {
      const iso = el.getAttribute('data-kp-date');
      const formatted = el.getAttribute('data-formatted-date') || formatDate(iso);
      if (!formatted) return;

      activeEl = el;
      el.removeAttribute('title');
      el.setAttribute('data-formatted-date', formatted);

      tooltip.textContent = formatted;
      const rect = el.getBoundingClientRect();

      tooltip.style.left = (rect.left + rect.width / 2) + 'px';
      if (rect.top < 45) {
        tooltip.style.top = (rect.bottom + 8) + 'px';
        tooltip.style.transform = 'translate(-50%, 0) scale(1)';
      } else {
        tooltip.style.top = (rect.top - 8) + 'px';
        tooltip.style.transform = 'translate(-50%, -100%) scale(1)';
      }
      tooltip.classList.add('visible');
    }

    function hideTooltip() {
      if (activeEl) {
        tooltip.classList.remove('visible');
        activeEl = null;
      }
    }

    // Delegación de eventos para mayor rendimiento y soporte en todos los navegadores
    document.addEventListener('mouseover', (e) => {
      const el = e.target.closest('[data-kp-date]');
      if (el) {
        showTooltip(el);
      } else if (activeEl && !e.target.closest('.kp-custom-tooltip')) {
        hideTooltip();
      }
    });

    document.addEventListener('mouseout', (e) => {
      const el = e.target.closest('[data-kp-date]');
      if (el && (!e.relatedTarget || !el.contains(e.relatedTarget))) {
        hideTooltip();
      }
    });

    // Ocultar si se hace scroll en el contenedor principal (.pub-root) o en window
    const pubRoot = document.querySelector('.pub-root');
    if (pubRoot) {
      pubRoot.addEventListener('scroll', hideTooltip, { passive: true });
    }
    window.addEventListener('scroll', hideTooltip, { passive: true });

    document.addEventListener('touchstart', hideTooltip, { passive: true });
  })();
</script>
</body>
</html>
