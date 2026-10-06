<?php
// ============================================================================
// KutPod · layout admin (sidebar + topbar + footer) · rutas /admin/*
// ============================================================================
// Variables esperadas: $page (slug actual), $title (string), $body (HTML).

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../version.php';

$is_podcast_section = in_array($page, ['podcasts','new-podcast','edit-podcast','new-episode','podcast','import']);

$unread_alerts = 0;
try {
  $unread_alerts = (int)(kp_one("SELECT count(*) c FROM alerts WHERE read_at IS NULL")['c'] ?? 0);
} catch (Throwable $e) {}

// Removed admin_url (moved to helpers.php)
?><!doctype html>
<html lang="es" data-theme="<?= e(kp_setting('admin_theme', 'dark')) ?>">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<title><?= e($title ?? 'KutPod Studio') ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com"/>
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin/>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="/assets/styles.css?v=<?= filemtime(__DIR__ . '/../assets/styles.css') ?>">
<?php
$acc = kp_setting('admin_accent', '#ff5f7e');
$acc_rgb = sscanf($acc, "#%02x%02x%02x");
if ($acc_rgb && count($acc_rgb) === 3) {
    $rgb_str = $acc_rgb[0].', '.$acc_rgb[1].', '.$acc_rgb[2];
    echo "<style>:root { --accent: {$acc}; --accent-soft: rgba({$rgb_str}, 0.14); --accent-glow: 0 0 0 1px rgba({$rgb_str}, 0.35), 0 18px 60px -20px rgba({$rgb_str}, 0.55); }</style>\n";
}
$logo = kp_setting('instance_logo');
if ($logo) {
    echo '<link rel="icon" href="' . e($logo) . '">';
}
?>
</head>
<body>
<?php
$is_auth_page = in_array($page, ['login', 'login-2fa', 'forgot', 'reset', 'recover'], true);
?>
<div class="app <?= $is_auth_page ? 'app-login' : '' ?>">
  <?php if (!$is_auth_page): ?>
  <aside class="sidebar">
    <div class="brand" style="justify-content:space-between">
      <div style="display:flex;align-items:center;gap:12px">
        <?php if ($logo): ?>
          <img src="<?= e($logo) ?>" style="width:24px;height:24px;border-radius:6px;object-fit:cover">
        <?php else: ?>
          <div class="brand-mark">K</div>
        <?php endif; ?>
        <div><div class="brand-name">KutPod</div><div class="brand-sub">Studio · v<?= KUTPOD_VERSION ?></div></div>
      </div>
      <a href="/" target="_blank" rel="noopener" class="btn btn-ghost" style="padding:4px 8px;font-size:11px">Ver Sitio</a>
    </div>
    <div class="nav-section">
      <a class="nav-item <?= $page==='overview'?'active':'' ?>" href="<?= admin_url() ?>"><?= icon('grid') ?> Resumen</a>
      <a class="nav-item <?= $page==='alerts'?'active':'' ?>" href="<?= admin_url('alerts') ?>">
        <?= icon('bell') ?> Alertas
        <?php if ($unread_alerts): ?>
          <span class="badge" style="<?= $page==='alerts' ? '' : 'background:var(--accent);color:var(--bg-1)' ?>"><?= $unread_alerts ?></span>
        <?php endif; ?>
      </a>
    </div>
    <div class="nav-section">
      <div class="nav-label">Podcasts</div>
      <a class="nav-item <?= in_array($page,['podcasts','podcast'])?'active':'' ?>" href="<?= admin_url('podcasts') ?>"><?= icon('mic') ?> Todos los Podcasts</a>
      <a class="nav-item <?= $page==='new-podcast'?'active':'' ?>" href="<?= admin_url('new-podcast') ?>"><?= icon('plus') ?> Nuevo Podcast</a>
      <a class="nav-item <?= $page==='import'?'active':'' ?>" href="<?= admin_url('import') ?>"><?= icon('rss') ?> Importar RSS</a>
    </div>
    <?php ob_start(); kp_do_action('admin_nav', $page); $plugin_nav = ob_get_clean(); if (trim($plugin_nav) !== ''): ?>
    <div class="nav-section">
      <div class="nav-label">Producción</div>
      <?= $plugin_nav ?>
    </div>
    <?php endif; ?>
    <div class="nav-section">
      <div class="nav-label">Red</div>
      <a class="nav-item <?= $page==='analytics'?'active':'' ?>" href="<?= admin_url('analytics') ?>"><?= icon('chart') ?> Estadísticas</a>
      <?php if (kp_plugin_is_active('fediverse')): ?>
        <a class="nav-item <?= $page==='fediverse'?'active':'' ?>" href="<?= admin_url('fediverse') ?>"><?= icon('globe') ?> Fediverso</a>
      <?php endif; ?>
      <a class="nav-item <?= $page==='pages'?'active':'' ?>" href="<?= admin_url('pages') ?>"><?= icon('file') ?> Páginas</a>
    </div>
    <div class="nav-section">
      <div class="nav-label">Administración</div>
      <a class="nav-item <?= $page==='update'?'active':'' ?>" href="<?= admin_url('update') ?>"><?= icon('refreshCw') ?> Actualizaciones</a>
      <a class="nav-item <?= $page==='queue'?'active':'' ?>" href="<?= admin_url('queue') ?>"><?= icon('layers') ?> Cola de procesos</a>
      <a class="nav-item <?= $page==='backups'?'active':'' ?>" href="<?= admin_url('backups') ?>"><?= icon('layers') ?> Respaldos</a>
      <a class="nav-item <?= $page==='preferences'?'active':'' ?>" href="<?= admin_url('preferences') ?>"><?= icon('settings') ?> Preferencias</a>
      <a class="nav-item <?= $page==='plugins'?'active':'' ?>" href="<?= admin_url('plugins') ?>"><?= icon('layers') ?> Plugins</a>
      <a class="nav-item <?= $page==='users'?'active':'' ?>" href="<?= admin_url('users') ?>"><?= icon('users') ?> Usuarios</a>
    </div>
    <div class="nav-section">
      <div class="nav-label">Otros</div>
      <a class="nav-item <?= $page==='api'?'active':'' ?>" href="<?= admin_url('api') ?>"><?= icon('share') ?> API</a>
    </div>
    <div class="sidebar-footer">
      <a href="<?= admin_url('users') ?>" style="display:flex; align-items:center; gap:10px; flex:1; min-width:0; text-decoration:none; color:inherit;">
        <?php if (!empty($current_user['avatar'])): ?>
          <img src="<?= e($current_user['avatar']) ?>" class="avatar" style="object-fit:cover" alt="">
        <?php else: ?>
          <div class="avatar"><?= e(strtoupper(substr($current_user['name'] ?? 'EA',0,2))) ?></div>
        <?php endif; ?>
        <div style="min-width:0;flex:1">
          <div style="font-size:13px;font-weight:500"><?= e($current_user['name'] ?? 'Owner') ?></div>
          <div style="font-size:11px;color:var(--text-3)"><?= e(ucfirst($current_user['role'] ?? 'owner')) ?></div>
        </div>
      </a>
      <a class="kebab" href="/admin?action=logout" title="Cerrar sesión"><?= icon('logOut') ?></a>
    </div>
  </aside>
  <?php endif; ?>

  <main class="main">
    <?php if (!$is_auth_page): ?>
    <div class="topbar">
      <div class="crumbs">
        <a class="btn btn-ghost" style="padding:4px 8px;font-size:13px" href="<?= admin_url() ?>">KutPod</a>
        <?= icon('chevronRight',14) ?>
        <span class="crumb-current"><?= e($title ?? 'Página') ?></span>
      </div>
      <form class="search" action="<?= admin_url('search') ?>" method="GET">
        <label><?= icon('search',14) ?><input name="q" placeholder="Buscar podcasts, episodios, invitados…" value="<?= e($_GET['q'] ?? '') ?>"/></label>
      </form>
    </div>
    <?php endif; ?>
    <div class="page"><?= $body ?></div>
  </main>
</div>
<script src="/assets/app.js?v=<?= filemtime(__DIR__ . '/../assets/app.js') ?>" defer></script>
</body>
</html>
