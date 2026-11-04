<?php
// ============================================================================
// pages/alerts.php — Sistema de alertas y notificaciones con pestañas por categoría
// ============================================================================
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';

// Definición de las categorías y sus metadatos visuales
$categories = [
  'all' => [
    'key'         => 'all',
    'label'       => 'Todas',
    'singular'    => 'Todas las alertas',
    'icon'        => 'bell',
    'color'       => 'var(--accent)',
    'desc'        => 'Vista panorámica de todas las notificaciones del sistema',
    'empty_title' => 'Sin alertas en el sistema',
    'empty_desc'  => 'No hay notificaciones registradas por el momento.',
  ],
  'downloads' => [
    'key'         => 'downloads',
    'label'       => 'Descargas',
    'singular'    => 'Descargas',
    'icon'        => 'download',
    'color'       => '#0284c7',
    'desc'        => 'Sincronización de reglas y registros diarios de descargas auditadas',
    'empty_title' => 'Sin alertas de descargas',
    'empty_desc'  => 'Aquí aparecerán los informes de descargas auditadas por OP3.',
  ],
  'milestones' => [
    'key'         => 'milestones',
    'label'       => 'Hitos',
    'singular'    => 'Hitos',
    'icon'        => 'award',
    'color'       => '#f59e0b',
    'desc'        => 'Metas de audiencia superadas y récords de descargas alcanzadas',
    'empty_title' => 'Sin hitos alcanzados aún',
    'empty_desc'  => 'Cuando tus episodios alcancen metas de 100, 500, 1.000 o más descargas, aparecerán aquí para celebrar el logro.',
  ],
  'fediverse' => [
    'key'         => 'fediverse',
    'label'       => 'Fediverso',
    'singular'    => 'Fediverso',
    'icon'        => 'globe',
    'color'       => '#6366f1',
    'desc'        => 'Nuevos seguidores, interacciones, me gusta y publicaciones en ActivityPub',
    'empty_title' => 'Sin actividad del Fediverso',
    'empty_desc'  => 'Aquí aparecerán los nuevos seguidores, comentarios, me gusta y avisos de federación.',
  ],
  'system' => [
    'key'         => 'system',
    'label'       => 'Sistema',
    'singular'    => 'Sistema',
    'icon'        => 'settings',
    'color'       => '#10b981',
    'desc'        => 'Avisos de seguridad, 2FA, importaciones RSS, plugins y mantenimiento',
    'empty_title' => 'Sin avisos del sistema',
    'empty_desc'  => 'La plataforma y los servicios funcionan con normalidad sin avisos pendientes.',
  ],
];

// Helper para determinar la categoría de una fila
function kp_alert_category(array $a): string {
  $k = $a['kind'] ?? '';
  if ($k === 'milestones' || $k === 'hitos') return 'milestones';
  if ($k === 'downloads' || $k === 'youtube') {
    $title = mb_strtolower($a['title'] ?? '');
    if (str_contains($title, 'alcanzadas') || str_contains($title, 'descargas alcanzadas') || str_contains($title, 'hito') || str_contains($title, 'meta')) {
      return 'milestones';
    }
    return 'downloads';
  }
  if ($k === 'fediverse') return 'fediverse';
  if ($k === 'op3') {
    $title = mb_strtolower($a['title'] ?? '');
    if (str_contains($title, 'alcanzadas') || str_contains($title, 'descargas alcanzadas') || str_contains($title, 'hito') || str_contains($title, 'meta')) {
      return 'milestones';
    }
    return 'downloads';
  }
  return 'system';
}

// Pestaña activa
$active_tab = $_GET['tab'] ?? 'all';
if (!isset($categories[$active_tab])) {
  $active_tab = 'all';
}

// Procesar acciones POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['_action'] ?? '';
  $post_tab = $_POST['tab'] ?? $active_tab;
  if (!isset($categories[$post_tab])) {
    $post_tab = 'all';
  }

  // 1. Marcar todas como leídas
  if ($action === 'mark_all_read') {
    try {
      kp_q("UPDATE alerts SET read_at = datetime('now') WHERE read_at IS NULL");
      setcookie('kp_flash', 'Todas las alertas han sido marcadas como leídas.', time() + 30, '/');
    } catch (Throwable $e) {}
    header('Location: /admin/alerts?tab=' . urlencode($post_tab));
    exit;
  }

  // 2. Marcar pestaña individual como leída
  if ($action === 'mark_tab_read') {
    try {
      if ($post_tab === 'downloads') {
        kp_q("UPDATE alerts SET read_at = datetime('now') WHERE read_at IS NULL AND (kind IN ('downloads', 'youtube') OR (kind = 'op3' AND title NOT LIKE '%alcanzadas%' AND title NOT LIKE '%meta%' AND title NOT LIKE '%hito%'))");
      } elseif ($post_tab === 'milestones') {
        kp_q("UPDATE alerts SET read_at = datetime('now') WHERE read_at IS NULL AND (kind IN ('milestones', 'hitos') OR ((kind = 'op3' OR kind = 'youtube') AND (title LIKE '%alcanzadas%' OR title LIKE '%meta%' OR title LIKE '%hito%')))");
      } elseif ($post_tab === 'fediverse') {
        kp_q("UPDATE alerts SET read_at = datetime('now') WHERE read_at IS NULL AND kind = 'fediverse'");
      } elseif ($post_tab === 'system') {
        kp_q("UPDATE alerts SET read_at = datetime('now') WHERE read_at IS NULL AND (kind NOT IN ('fediverse', 'downloads', 'milestones', 'hitos', 'youtube') AND NOT (kind = 'op3'))");
      } else {
        kp_q("UPDATE alerts SET read_at = datetime('now') WHERE read_at IS NULL");
      }
      setcookie('kp_flash', 'Alertas de ' . e($categories[$post_tab]['label']) . ' marcadas como leídas.', time() + 30, '/');
    } catch (Throwable $e) {}
    header('Location: /admin/alerts?tab=' . urlencode($post_tab));
    exit;
  }

  // 3. Marcar una alerta individual como leída
  if ($action === 'mark_one_read') {
    $id = (int)($_POST['alert_id'] ?? 0);
    if ($id > 0) {
      try {
        kp_q("UPDATE alerts SET read_at = datetime('now') WHERE id = ?", [$id]);
      } catch (Throwable $e) {}
    }
    header('Location: /admin/alerts?tab=' . urlencode($post_tab));
    exit;
  }

  // 4. Eliminar alerta individual
  if ($action === 'delete_alert') {
    $id = (int)($_POST['alert_id'] ?? 0);
    if ($id > 0) {
      try {
        kp_q("DELETE FROM alerts WHERE id = ?", [$id]);
        setcookie('kp_flash', 'Alerta eliminada.', time() + 30, '/');
      } catch (Throwable $e) {}
    }
    header('Location: /admin/alerts?tab=' . urlencode($post_tab));
    exit;
  }
}

// Estadísticas de totales y no leídos por categoría
$stats = [
  'all'        => ['total' => 0, 'unread' => 0],
  'downloads'  => ['total' => 0, 'unread' => 0],
  'milestones' => ['total' => 0, 'unread' => 0],
  'fediverse'  => ['total' => 0, 'unread' => 0],
  'system'     => ['total' => 0, 'unread' => 0],
];

try {
  $raw_stats = kp_q("
    SELECT 
      CASE 
        WHEN kind IN ('milestones', 'hitos') OR ((kind = 'op3' OR kind = 'youtube') AND (title LIKE '%alcanzadas%' OR title LIKE '%meta%' OR title LIKE '%hito%')) THEN 'milestones'
        WHEN kind IN ('downloads', 'youtube') OR (kind = 'op3' AND title NOT LIKE '%alcanzadas%') THEN 'downloads'
        WHEN kind = 'fediverse' THEN 'fediverse'
        ELSE 'system'
      END as cat,
      COUNT(*) as total,
      SUM(CASE WHEN read_at IS NULL THEN 1 ELSE 0 END) as unread
    FROM alerts
    GROUP BY cat
  ") ?: [];

  foreach ($raw_stats as $rs) {
    $c = $rs['cat'];
    $t = (int)$rs['total'];
    $u = (int)$rs['unread'];
    if (isset($stats[$c])) {
      $stats[$c]['total'] = $t;
      $stats[$c]['unread'] = $u;
    }
    $stats['all']['total'] += $t;
    $stats['all']['unread'] += $u;
  }
} catch (Throwable $e) {}

// Obtener alertas de la pestaña activa con paginación
$perPage = 40;
$totalInTab = $stats[$active_tab]['total'] ?? 0;
$totalPages = max(1, (int)ceil($totalInTab / $perPage));
$page = max(1, min($totalPages, (int)($_GET['p'] ?? 1)));
$offset = ($page - 1) * $perPage;

$alerts = [];
try {
  if ($active_tab === 'downloads') {
    $whereClause = "(kind IN ('downloads', 'youtube') OR (kind = 'op3' AND title NOT LIKE '%alcanzadas%' AND title NOT LIKE '%meta%' AND title NOT LIKE '%hito%'))";
  } elseif ($active_tab === 'milestones') {
    $whereClause = "(kind IN ('milestones', 'hitos') OR ((kind = 'op3' OR kind = 'youtube') AND (title LIKE '%alcanzadas%' OR title LIKE '%meta%' OR title LIKE '%hito%')))";
  } elseif ($active_tab === 'fediverse') {
    $whereClause = "kind = 'fediverse'";
  } elseif ($active_tab === 'system') {
    $whereClause = "(kind NOT IN ('fediverse', 'downloads', 'milestones', 'hitos', 'youtube') AND NOT (kind = 'op3'))";
  } else {
    $whereClause = "1=1";
  }

  $alerts = kp_q("
    SELECT id, kind, title, body, link, actor_url, action_type, actor_avatar, actor_name, created_at, read_at
    FROM alerts
    WHERE $whereClause
    ORDER BY created_at DESC
    LIMIT ? OFFSET ?
  ", [$perPage, $offset]) ?: [];
} catch (Throwable $e) {}

$unread_in_active_tab = $stats[$active_tab]['unread'] ?? 0;
$total_unread_all = $stats['all']['unread'] ?? 0;
?>

<style>
  /* Alertas: Diseño visual mejorado y responsive */
  .alerts-kpi-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 14px;
    margin-bottom: 24px;
  }
  @media (max-width: 960px) {
    .alerts-kpi-grid { grid-template-columns: repeat(2, 1fr); }
  }
  @media (max-width: 540px) {
    .alerts-kpi-grid { grid-template-columns: 1fr; }
  }

  .alert-kpi-card {
    display: block;
    text-decoration: none;
    color: inherit;
    padding: 18px 20px;
    border-radius: var(--radius);
    background: var(--surface);
    border: 1px solid var(--border);
    transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
    position: relative;
    overflow: hidden;
  }
  .alert-kpi-card:hover {
    transform: translateY(-2px);
    border-color: var(--border-strong);
    box-shadow: 0 8px 20px -6px rgba(0,0,0,0.25);
  }
  .alert-kpi-card.is-active {
    border-color: var(--card-accent, var(--accent));
    box-shadow: 0 0 0 1px var(--card-accent, var(--accent)), 0 6px 18px -4px rgba(0,0,0,0.2);
    background: var(--surface-2);
  }
  .alert-kpi-card::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 3px;
    background: var(--card-accent, var(--border));
    opacity: 0.85;
  }

  .alert-kpi-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 12px;
  }
  .alert-kpi-icon {
    width: 32px;
    height: 32px;
    border-radius: 8px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: var(--card-accent-soft, rgba(255,255,255,0.06));
    color: var(--card-accent, inherit);
  }
  .alert-kpi-label {
    font-size: 11.5px;
    font-weight: 700;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    color: var(--text-3);
  }
  .alert-kpi-num {
    font-size: 30px;
    font-weight: 700;
    line-height: 1.1;
    color: var(--text);
    margin-bottom: 8px;
    letter-spacing: -0.02em;
  }
  .alert-kpi-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    font-size: 12px;
    color: var(--text-3);
  }
  .alert-unread-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 3px 8px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 600;
    background: rgba(239, 68, 68, 0.15);
    color: #ef4444;
  }
  .alert-clean-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 3px 8px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 500;
    background: rgba(16, 185, 129, 0.12);
    color: #10b981;
  }

  /* Barra de pestañas */
  .alerts-tabs-nav {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 6px;
    background: var(--surface-2);
    border-radius: var(--radius);
    border: 1px solid var(--border);
    margin-bottom: 24px;
    overflow-x: auto;
    scrollbar-width: none;
  }
  .alerts-tabs-nav::-webkit-scrollbar { display: none; }
  .alerts-tab-link {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 9px 16px;
    border-radius: var(--radius-sm);
    border: 1px solid transparent;
    background: transparent;
    color: var(--text-2);
    font-size: 13.5px;
    font-weight: 500;
    text-decoration: none;
    transition: all 0.18s ease;
    white-space: nowrap;
    user-select: none;
  }
  .alerts-tab-link:hover {
    color: var(--text);
    background: rgba(255, 255, 255, 0.04);
  }
  [data-theme="light"] .alerts-tab-link:hover {
    background: rgba(0, 0, 0, 0.04);
  }
  .alerts-tab-link.active {
    background: var(--surface);
    color: var(--text);
    border-color: var(--border-strong);
    box-shadow: 0 4px 14px -4px rgba(0, 0, 0, 0.35);
    font-weight: 600;
  }
  .alerts-tab-link .tab-counter {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 1px 7px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 700;
    background: var(--surface-3);
    color: var(--text-2);
  }
  .alerts-tab-link.active .tab-counter {
    background: var(--accent-soft);
    color: var(--accent);
  }
  .alerts-tab-link .tab-counter.has-unread {
    background: #ef444422;
    color: #ef4444;
  }

  /* Filas de alertas */
  .alert-card-item {
    display: flex;
    gap: 16px;
    padding: 16px 18px;
    border-radius: 12px;
    background: var(--surface);
    border: 1px solid var(--border);
    transition: all 0.18s ease;
    align-items: flex-start;
    position: relative;
  }
  .alert-card-item:hover {
    border-color: var(--border-strong);
    background: var(--surface-2);
  }
  .alert-card-item.is-unread {
    border-left: 3.5px solid var(--alert-cat-color, var(--accent));
    background: linear-gradient(to right, var(--alert-cat-tint, rgba(255,255,255,0.02)), transparent 25%), var(--surface);
  }
  .alert-card-avatar {
    width: 40px;
    height: 40px;
    border-radius: 10px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    background: var(--alert-cat-soft, rgba(255,255,255,0.06));
    color: var(--alert-cat-color, inherit);
  }
  .alert-card-content {
    flex: 1;
    min-width: 0;
  }
  .alert-card-meta {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 4px;
    flex-wrap: wrap;
  }
  .alert-badge-tag {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 2px 7px;
    border-radius: 6px;
    font-size: 11px;
    font-weight: 600;
    letter-spacing: 0.02em;
    background: var(--alert-cat-soft, rgba(255,255,255,0.06));
    color: var(--alert-cat-color, inherit);
  }
  .alert-badge-unread-dot {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: #ef4444;
    display: inline-block;
  }
  .alert-card-title {
    font-size: 14.5px;
    font-weight: 600;
    color: var(--text);
    line-height: 1.35;
    margin-bottom: 4px;
  }
  .alert-card-body {
    font-size: 13px;
    color: var(--text-2);
    line-height: 1.5;
    margin-bottom: 6px;
    word-break: break-word;
  }
  .alert-card-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
  }
  .alert-action-btn {
    background: none;
    border: 1px solid var(--border);
    padding: 5px 9px;
    border-radius: 6px;
    color: var(--text-3);
    cursor: pointer;
    font-size: 12px;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    transition: all 0.15s ease;
  }
  .alert-action-btn:hover {
    color: var(--text);
    border-color: var(--border-strong);
    background: var(--surface-3);
  }
  .alert-action-btn.delete:hover {
    color: #ef4444;
    border-color: #ef444444;
    background: #ef444415;
  }
  .alert-action-btn.check:hover {
    color: #10b981;
    border-color: #10b98144;
    background: #10b98115;
  }
</style>

<div class="page-head">
  <div>
    <h1 class="page-title">Alertas</h1>
    <p class="page-sub">
      <?= $total_unread_all ? "<strong>$total_unread_all</strong> sin leer · " : '' ?>Centro de notificaciones de descargas, hitos, federación y sistema
    </p>
  </div>
  <div class="row" style="gap:8px">
    <?php if ($total_unread_all > 0): ?>
      <form method="POST" style="display:inline">
        <?= kp_csrf_field() ?>
        <input type="hidden" name="_action" value="mark_all_read"/>
        <input type="hidden" name="tab" value="<?= e($active_tab) ?>"/>
        <button class="btn btn-primary" type="submit" style="display:inline-flex;align-items:center;gap:6px">
          <?= icon('check', 13) ?> Marcar todas leídas (<?= $total_unread_all ?>)
        </button>
      </form>
    <?php endif; ?>
  </div>
</div>

<!-- TARJETAS DE VISTAZO RÁPIDO (KPIs) -->
<div class="alerts-kpi-grid">
  <!-- 1. Descargas -->
  <a href="?tab=downloads" class="alert-kpi-card <?= $active_tab === 'downloads' ? 'is-active' : '' ?>" style="--card-accent:#0284c7;--card-accent-soft:rgba(2,132,199,0.12)">
    <div class="alert-kpi-head">
      <span class="alert-kpi-label">Descargas</span>
      <span class="alert-kpi-icon"><?= icon('download', 16) ?></span>
    </div>
    <div class="alert-kpi-num tabular"><?= number_format($stats['downloads']['total']) ?></div>
    <div class="alert-kpi-footer">
      <span>Registros OP3</span>
      <?php if ($stats['downloads']['unread'] > 0): ?>
        <span class="alert-unread-badge"><span class="alert-badge-unread-dot"></span> <?= $stats['downloads']['unread'] ?> sin leer</span>
      <?php else: ?>
        <span class="alert-clean-badge"><?= icon('check', 11) ?> Al día</span>
      <?php endif; ?>
    </div>
  </a>

  <!-- 2. Hitos -->
  <a href="?tab=milestones" class="alert-kpi-card <?= $active_tab === 'milestones' ? 'is-active' : '' ?>" style="--card-accent:#f59e0b;--card-accent-soft:rgba(245,158,11,0.12)">
    <div class="alert-kpi-head">
      <span class="alert-kpi-label">Hitos &amp; Metas</span>
      <span class="alert-kpi-icon"><?= icon('award', 16) ?></span>
    </div>
    <div class="alert-kpi-num tabular"><?= number_format($stats['milestones']['total']) ?></div>
    <div class="alert-kpi-footer">
      <span>Récords de audiencia</span>
      <?php if ($stats['milestones']['unread'] > 0): ?>
        <span class="alert-unread-badge"><span class="alert-badge-unread-dot"></span> <?= $stats['milestones']['unread'] ?> sin leer</span>
      <?php else: ?>
        <span class="alert-clean-badge"><?= icon('check', 11) ?> Al día</span>
      <?php endif; ?>
    </div>
  </a>

  <!-- 3. Fediverso -->
  <a href="?tab=fediverse" class="alert-kpi-card <?= $active_tab === 'fediverse' ? 'is-active' : '' ?>" style="--card-accent:#6366f1;--card-accent-soft:rgba(99,102,241,0.12)">
    <div class="alert-kpi-head">
      <span class="alert-kpi-label">El Fediverso</span>
      <span class="alert-kpi-icon"><?= icon('globe', 16) ?></span>
    </div>
    <div class="alert-kpi-num tabular"><?= number_format($stats['fediverse']['total']) ?></div>
    <div class="alert-kpi-footer">
      <span>Actividad ActivityPub</span>
      <?php if ($stats['fediverse']['unread'] > 0): ?>
        <span class="alert-unread-badge"><span class="alert-badge-unread-dot"></span> <?= $stats['fediverse']['unread'] ?> sin leer</span>
      <?php else: ?>
        <span class="alert-clean-badge"><?= icon('check', 11) ?> Al día</span>
      <?php endif; ?>
    </div>
  </a>

  <!-- 4. Sistema -->
  <a href="?tab=system" class="alert-kpi-card <?= $active_tab === 'system' ? 'is-active' : '' ?>" style="--card-accent:#10b981;--card-accent-soft:rgba(16,185,129,0.12)">
    <div class="alert-kpi-head">
      <span class="alert-kpi-label">El Sistema</span>
      <span class="alert-kpi-icon"><?= icon('settings', 16) ?></span>
    </div>
    <div class="alert-kpi-num tabular"><?= number_format($stats['system']['total']) ?></div>
    <div class="alert-kpi-footer">
      <span>Avisos de plataforma</span>
      <?php if ($stats['system']['unread'] > 0): ?>
        <span class="alert-unread-badge"><span class="alert-badge-unread-dot"></span> <?= $stats['system']['unread'] ?> sin leer</span>
      <?php else: ?>
        <span class="alert-clean-badge"><?= icon('check', 11) ?> Al día</span>
      <?php endif; ?>
    </div>
  </a>
</div>

<!-- NAVEGACIÓN DE PESTAÑAS (Estilo Estadísticas) -->
<div class="alerts-tabs-nav" role="tablist">
  <?php foreach ($categories as $ckey => $cdata):
    $is_active = $active_tab === $ckey;
    $c_unread = $stats[$ckey]['unread'] ?? 0;
    $c_total = $stats[$ckey]['total'] ?? 0;
  ?>
    <a href="?tab=<?= urlencode($ckey) ?>" class="alerts-tab-link <?= $is_active ? 'active' : '' ?>" role="tab" aria-selected="<?= $is_active ? 'true' : 'false' ?>">
      <?= icon($cdata['icon'], 16) ?>
      <span><?= e($cdata['label']) ?></span>
      <span class="tab-counter <?= $c_unread > 0 ? 'has-unread' : '' ?>" title="<?= $c_unread ? "$c_unread sin leer de $c_total" : "$c_total en total" ?>">
        <?= $c_total ?><?= $c_unread ? " ($c_unread)" : '' ?>
      </span>
    </a>
  <?php endforeach; ?>
</div>

<!-- CONTENEDOR PRINCIPAL DE ALERTAS DE LA PESTAÑA ACTIVA -->
<div class="card card-lg" style="padding: 24px;">
  <div class="row" style="justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:12px;align-items:center;border-bottom:1px solid var(--border);padding-bottom:16px">
    <div>
      <div style="display:flex;align-items:center;gap:10px">
        <h2 style="margin:0;font-size:18px"><?= e($categories[$active_tab]['singular']) ?></h2>
        <?php if ($unread_in_active_tab > 0): ?>
          <span class="alert-unread-badge" style="font-size:11.5px">
            <span class="alert-badge-unread-dot"></span> <?= $unread_in_active_tab ?> sin leer
          </span>
        <?php else: ?>
          <span class="alert-clean-badge" style="font-size:11.5px">
            <?= icon('check', 12) ?> Al día
          </span>
        <?php endif; ?>
      </div>
      <p class="muted" style="margin:4px 0 0;font-size:13px">
        <?= e($categories[$active_tab]['desc']) ?> · Mostrando <?= count($alerts) ?> de <?= $totalInTab ?>
      </p>
    </div>

    <!-- Botón para marcar esta pestaña individual como leída -->
    <div>
      <?php if ($unread_in_active_tab > 0): ?>
        <form method="POST" style="display:inline">
          <?= kp_csrf_field() ?>
          <input type="hidden" name="_action" value="mark_tab_read"/>
          <input type="hidden" name="tab" value="<?= e($active_tab) ?>"/>
          <button class="btn" type="submit" style="display:inline-flex;align-items:center;gap:6px;font-size:12.5px">
            <?= icon('check', 13) ?> Marcar <?= $active_tab === 'all' ? 'todas' : 'esta pestaña' ?> como leída (<?= $unread_in_active_tab ?>)
          </button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <?php if (empty($alerts)): ?>
    <div style="text-align:center;padding:56px 20px">
      <div style="font-size:42px;margin-bottom:12px;opacity:.35;display:inline-flex;color:var(--text-3)">
        <?= icon($categories[$active_tab]['icon'], 42) ?>
      </div>
      <h3 style="margin:0 0 8px;font-size:17px"><?= e($categories[$active_tab]['empty_title']) ?></h3>
      <p class="muted" style="margin:0 auto;max-width:480px;font-size:13.5px;line-height:1.5">
        <?= e($categories[$active_tab]['empty_desc']) ?>
      </p>
    </div>
  <?php else: ?>

    <div class="alert-list" style="display:flex;flex-direction:column;gap:10px">
      <?php foreach ($alerts as $a):
        $cat = kp_alert_category($a);
        $catMeta = $categories[$cat] ?? $categories['system'];
        $is_unread = empty($a['read_at']);
        
        // Colores específicos de categoría
        $catColor = $catMeta['color'];
        $catSoft = match($cat) {
          'downloads'  => 'rgba(2, 132, 199, 0.15)',
          'milestones' => 'rgba(245, 158, 11, 0.18)',
          'fediverse'  => 'rgba(99, 102, 241, 0.16)',
          default      => 'rgba(16, 185, 129, 0.15)',
        };
        $catTint = match($cat) {
          'downloads'  => 'rgba(2, 132, 199, 0.05)',
          'milestones' => 'rgba(245, 158, 11, 0.06)',
          'fediverse'  => 'rgba(99, 102, 241, 0.05)',
          default      => 'rgba(16, 185, 129, 0.05)',
        };
      ?>
        <div class="alert-card-item <?= $is_unread ? 'is-unread' : '' ?>"
             style="--alert-cat-color:<?= $catColor ?>;--alert-cat-soft:<?= $catSoft ?>;--alert-cat-tint:<?= $catTint ?>">
          
          <!-- Avatar o icono representativo -->
          <?php if (!empty($a['actor_avatar'])): ?>
            <div style="width:40px;height:40px;border-radius:10px;background:var(--bg-3) url('<?= e($a['actor_avatar']) ?>') center/cover;flex-shrink:0;box-shadow:0 0 0 1px var(--border);"></div>
          <?php else: ?>
            <div class="alert-card-avatar">
              <?= icon($catMeta['icon'], 18) ?>
            </div>
          <?php endif; ?>

          <!-- Contenido de la alerta -->
          <div class="alert-card-content">
            <div class="alert-card-meta">
              <span class="alert-badge-tag">
                <?= icon($catMeta['icon'], 12) ?>
                <?= e($catMeta['singular']) ?>
              </span>

              <?php if ($is_unread): ?>
                <span class="alert-badge-unread-dot" title="No leída"></span>
              <?php endif; ?>

              <?php if (!empty($a['action_type'])):
                $at = $a['action_type'];
                $atLabel = match($at) {
                  'Follow'   => '👤 Seguidor',
                  'Like'     => '💖 Me gusta',
                  'Announce' => '🔁 Retoot',
                  'Reply'    => '💬 Comentario',
                  'YouTube'  => '▶️ YouTube',
                  'Hito'     => '🏆 Hito',
                  default    => '⚡ ' . $at,
                };
              ?>
                <span style="font-size:11.5px;color:<?= $at === 'YouTube' ? '#ef4444' : ($at === 'Hito' ? '#f59e0b' : 'var(--text-3)') ?>;font-weight:600"><?= e($atLabel) ?></span>
              <?php endif; ?>

              <span class="muted" style="margin-left:auto;font-size:12px;white-space:nowrap" title="<?= e($a['created_at']) ?>">
                <?= e(kp_relative_time($a['created_at'])) ?>
              </span>
            </div>

            <!-- Título -->
            <div class="alert-card-title">
              <?= e($a['title']) ?>
            </div>

            <!-- Actor name (si es del fediverso o usuario) -->
            <?php if (!empty($a['actor_name'])): ?>
              <div style="font-size:13px;font-weight:500;margin-bottom:4px">
                <?php if (!empty($a['actor_url'])): ?>
                  <a href="<?= e($a['actor_url']) ?>" target="_blank" rel="noopener noreferrer" style="color:var(--accent);text-decoration:none;" title="Ver perfil en el Fediverso">
                    <?= e($a['actor_name']) ?>
                  </a>
                  <span class="muted" style="font-size:11.5px;opacity:0.75">&lt;<?= e($a['actor_url']) ?>&gt;</span>
                <?php else: ?>
                  <span style="color:var(--text-2)"><?= e($a['actor_name']) ?></span>
                <?php endif; ?>
              </div>
            <?php endif; ?>

            <!-- Cuerpo del mensaje -->
            <?php if (!empty($a['body'])): ?>
              <div class="alert-card-body">
                <?= e($a['body']) ?>
              </div>
            <?php endif; ?>

            <!-- Pie de la alerta: Enlace de acción y botones individuales -->
            <div class="alert-card-footer">
              <div>
                <?php if (!empty($a['link']) && $a['link'] !== '/admin/alerts'):
                  $lnk = $a['link'];
                  if (preg_match('~/users/([^/]+)/notes/([^/?#]+)~', $lnk, $m)) {
                    $cleanEp = preg_replace('/-\d+$/', '', $m[2]);
                    $lnk = "/@" . $m[1] . "/" . $cleanEp;
                  } elseif (preg_match('~/users/([^/?#]+)/?$~', $lnk, $m)) {
                    $lnk = "/@" . $m[1];
                  }

                  $linkLabel = 'Ver detalle →';
                  $actionType = $a['action_type'] ?? '';

                  if (str_contains($lnk, 'new-episode') || str_contains($lnk, '/notes/')) {
                    $linkLabel = 'Ver episodio →';
                  } elseif (str_contains($lnk, 'tab=analytics') || str_contains($lnk, '/admin/analytics')) {
                    $linkLabel = 'Ver estadísticas →';
                  } elseif (str_contains($lnk, '/admin/podcast/')) {
                    $linkLabel = 'Ver podcast →';
                  } elseif (str_contains($lnk, '/admin/import')) {
                    $linkLabel = 'Ver importación →';
                  } elseif (str_contains($lnk, 'flarum') || str_contains($lnk, '/d/')) {
                    $linkLabel = 'Ver en foro →';
                  } elseif ($actionType === 'Like' || $actionType === 'Announce') {
                    $linkLabel = 'Ver publicación →';
                  } elseif ($actionType === 'Reply') {
                    $linkLabel = 'Ver comentario →';
                  } elseif ($actionType === 'Follow' || (!empty($a['actor_url']) && $lnk === $a['actor_url'])) {
                    $linkLabel = 'Ver perfil →';
                  } elseif (str_starts_with(strtolower($lnk), 'http')) {
                    $linkLabel = 'Ver enlace →';
                  }

                  $is_external = str_starts_with(strtolower($lnk), 'http');
                ?>
                  <a href="<?= e($lnk) ?>" <?= $is_external ? 'target="_blank" rel="noopener noreferrer"' : '' ?>
                     style="font-size:12.5px;font-weight:600;color:<?= $catColor ?>;display:inline-flex;align-items:center;gap:4px;text-decoration:none">
                    <?= $linkLabel ?>
                  </a>
                <?php endif; ?>
              </div>

              <!-- Botones individuales (Marcar leída y Eliminar) -->
              <div class="row" style="gap:6px">
                <?php if ($is_unread): ?>
                  <form method="POST" style="margin:0;padding:0">
                    <?= kp_csrf_field() ?>
                    <input type="hidden" name="_action" value="mark_one_read"/>
                    <input type="hidden" name="tab" value="<?= e($active_tab) ?>"/>
                    <input type="hidden" name="alert_id" value="<?= (int)$a['id'] ?>"/>
                    <button type="submit" class="alert-action-btn check" title="Marcar como leída">
                      <?= icon('check', 12) ?> <span>Marcar leída</span>
                    </button>
                  </form>
                <?php endif; ?>

                <form method="POST" style="margin:0;padding:0" onsubmit="return confirm('¿Eliminar esta alerta?')">
                  <?= kp_csrf_field() ?>
                  <input type="hidden" name="_action" value="delete_alert"/>
                  <input type="hidden" name="tab" value="<?= e($active_tab) ?>"/>
                  <input type="hidden" name="alert_id" value="<?= (int)$a['id'] ?>"/>
                  <button type="submit" class="alert-action-btn delete" title="Eliminar alerta">
                    <?= icon('trash', 12) ?>
                  </button>
                </form>
              </div>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <!-- Paginación -->
    <?php if ($totalPages > 1): ?>
      <div class="row" style="justify-content:space-between;margin-top:24px;padding-top:16px;border-top:1px solid var(--border);align-items:center">
        <div>
          <?php if ($page > 1): ?>
            <a href="?tab=<?= urlencode($active_tab) ?>&p=<?= $page - 1 ?>" class="btn" style="font-size:13px">« Anterior</a>
          <?php else: ?>
            <button class="btn" disabled style="opacity:0.5;cursor:not-allowed;font-size:13px">« Anterior</button>
          <?php endif; ?>
        </div>
        <div class="muted tabular" style="font-size:13px">
          Página <?= $page ?> de <?= $totalPages ?> (Total: <?= $totalInTab ?>)
        </div>
        <div>
          <?php if ($page < $totalPages): ?>
            <a href="?tab=<?= urlencode($active_tab) ?>&p=<?= $page + 1 ?>" class="btn" style="font-size:13px">Siguiente »</a>
          <?php else: ?>
            <button class="btn" disabled style="opacity:0.5;cursor:not-allowed;font-size:13px">Siguiente »</button>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>

  <?php endif; ?>
</div>
