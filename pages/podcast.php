<?php
// pages/podcast.php — detalle de show con tabla de episodios
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';

$id = $_GET['id'] ?? '';
$p = kp_find_podcast($id) ?? kp_podcasts()[0];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (isset($_POST['action']) && $_POST['action'] === 'refederate') {
    $epId = (int)($_POST['episode_id'] ?? 0);
    if ($epId > 0 && kp_plugin_is_active('fediverse')) {
      require_once __DIR__ . '/../activitypub.php';
      try {
        kp_ap_publish_episode($epId, true);
        kp_alert('system', 'Re-envío solicitado', 'El episodio se ha encolado para re-envío al Fediverso.', '');
        header('Location: /admin/podcast?id=' . urlencode($p['id']) . '&refederated=1');
        exit;
      } catch (Throwable $e) {
        kp_alert('system', 'Error al re-enviar', $e->getMessage(), '');
      }
    }
  }
  if (isset($_POST['action']) && $_POST['action'] === 'defederate') {
    $epId = (int)($_POST['episode_id'] ?? 0);
    if ($epId > 0 && kp_plugin_is_active('fediverse')) {
      require_once __DIR__ . '/../activitypub.php';
      try {
        kp_ap_delete_episode($epId);
        kp_alert('system', 'Eliminación solicitada', 'El post de este episodio en el Fediverso se ha encolado para eliminación.', '');
        header('Location: /admin/podcast?id=' . urlencode($p['id']) . '&defederated=1');
        exit;
      } catch (Throwable $e) {
        kp_alert('system', 'Error al eliminar del Fediverso', $e->getMessage(), '');
      }
    }
  }
}

$eps = kp_episodes_of($p['id'], true, false);

$draftsCount = count(array_filter($eps, fn($e) => ($e['status'] ?? '') === 'draft'));
$subText = $draftsCount > 0 ? "$draftsCount borrador" . ($draftsCount > 1 ? 'es' : '') . " en cola" : '';

$seasons = array_unique(array_map(fn($e) => (int)($e['s'] ?? 1), $eps));
rsort($seasons);

$filterSeason = isset($_GET['s']) && $_GET['s'] !== '' ? (int)$_GET['s'] : null;
if ($filterSeason !== null) {
  $eps = array_values(array_filter($eps, fn($e) => (int)($e['s'] ?? 1) === $filterSeason));
}

$pageEp = max(1, (int)($_GET['p'] ?? 1));
$perPage = 10;
$totalEpsCount = count($eps);
$totalPages = max(1, ceil($totalEpsCount / $perPage));
$epsPage = array_slice($eps, ($pageEp - 1) * $perPage, $perPage);

$audioBytes = array_sum(array_column($eps, 'audio_bytes'));
$formattedBytes = '0 B';
if ($audioBytes > 0) {
  if ($audioBytes < 1048576) $formattedBytes = round($audioBytes / 1024, 1) . ' KB';
  elseif ($audioBytes < 1073741824) $formattedBytes = round($audioBytes / 1048576, 1) . ' MB';
  else $formattedBytes = round($audioBytes / 1073741824, 1) . ' GB';
}
?>
<?php if (isset($_GET['refederated']) && $_GET['refederated'] === '1'): ?>
  <div class="alert success" style="margin-bottom:var(--gap);display:flex;align-items:center;gap:8px;padding:12px;background:#10b98122;color:#10b981;border-radius:8px;border:1px solid #10b98144;font-size:14px">
    <?= icon('check', 16) ?> El episodio se ha encolado para re-envío al Fediverso.
  </div>
<?php endif; ?>
<?php if (isset($_GET['defederated']) && $_GET['defederated'] === '1'): ?>
  <div class="alert success" style="margin-bottom:var(--gap);display:flex;align-items:center;gap:8px;padding:12px;background:#10b98122;color:#10b981;border-radius:8px;border:1px solid #10b98144;font-size:14px">
    <?= icon('check', 16) ?> El post de este episodio en el Fediverso se ha encolado para eliminación.
  </div>
<?php endif; ?>

<div class="page-head">
  <div class="row" style="gap:16px;align-items:flex-start">
    <a class="cover-link" href="/@<?= e($p['id']) ?>" target="_blank" title="Ver <?= e($p['title']) ?> en la web">
      <?php if ($p['cover']): ?>
        <img class="cover" src="<?= e($p['cover']) ?>" style="width:64px;height:64px;border-radius:14px;object-fit:cover" alt="<?= e($p['title']) ?>">
      <?php else: ?>
        <div class="cover" style="width:64px;height:64px;border-radius:14px;background:linear-gradient(135deg,<?= e($p['color']) ?>,<?= e($p['color']) ?>99);color:white;font-weight:800;font-size:22px"><?= e($p['initial']) ?></div>
      <?php endif; ?>
    </a>
    <div>
      <h1 class="page-title">
        <a href="/@<?= e($p['id']) ?>" target="_blank" title="Ver <?= e($p['title']) ?> en la web"><?= e($p['title']) ?></a>
        <?php if (($p['parental'] ?? 'clean') === 'explicit'): ?>
          <span style="display:inline-block;background:var(--border);color:var(--text-2);border-radius:4px;font-size:12px;padding:2px 6px;margin-left:6px;vertical-align:middle;line-height:1;font-weight:800">E</span>
        <?php endif; ?>
      </h1>
      <p class="page-sub"><?= (int)$p['episodes'] ?> episodios · <?= fmt_num($p['downloads']) ?> descargas · <?= e($p['cat']) ?></p>
    </div>
  </div>
  <div class="row">
    <?php if (!empty($p['op3']) && ($op3_url = kp_op3_show_url($p))): ?>
      <a class="btn" href="<?= e($op3_url) ?>" target="_blank"><?= icon('chart',13) ?> OP3</a>
    <?php else: ?>
      <button class="btn" disabled style="opacity:0.5;cursor:not-allowed" title="OP3 no está activo para este show"><?= icon('chart',13) ?> OP3</button>
    <?php endif; ?>
    <a class="btn" href="/@<?= e($p['id']) ?>/feed.xml" target="_blank" download="feed.xml"><?= icon('rss',13) ?> Exportar RSS</a>
    <a class="btn" href="/admin/broadcast?podcast=<?= e($p['id']) ?>"><?= icon('globe',13) ?> Broadcast</a>
    <a class="btn" href="/admin/edit-podcast?id=<?= e($p['id']) ?>" style="color:var(--accent);border-color:var(--accent)"><?= icon('edit',13) ?> Editar</a>
    <a class="btn btn-primary" href="/admin/new-episode?podcast=<?= e($p['id']) ?>"><?= icon('plus',13) ?> Nuevo episodio</a>
  </div>
</div>

<div class="stat-grid">
  <?= stat_card('Episodios', (string)$p['episodes'], 'headphones', $subText ? ['sub'=>$subText] : []) ?>
  <?= stat_card('Almacenamiento', $formattedBytes, 'layers', ['sub'=>'solo audios']) ?>
  <?= stat_card('Descargas · 7d', fmt_num($p['downloads7']), 'download', ['delta'=>['dir'=>$p['growth']>=0?'up':'down','val'=>($p['growth']>=0?'+':'').$p['growth'].'%']]) ?>
  <?= stat_card('Oyentes únicos', fmt_num($p['unique_listeners'] ?? 0), 'users', ['sub' => 'estimado por OP3']) ?>
</div>

<div class="card" style="margin-top:var(--gap)">
  <div class="card-head">
    <div class="card-title">Episodios — <?= count($eps) ?></div>
    <div class="row" style="gap:12px">
      <?php if (count($seasons) > 1): ?>
      <select class="select" style="font-size:12px;padding:4px 10px;height:auto;min-height:0" onchange="window.location.href='/admin/podcast?id=<?= e($p['id']) ?>'+(this.value?'&s='+this.value:'')">
        <option value="">Todas las temporadas</option>
        <?php foreach ($seasons as $seasonNum): ?>
          <option value="<?= $seasonNum ?>" <?= $filterSeason === $seasonNum ? 'selected' : '' ?>>Temporada <?= $seasonNum ?></option>
        <?php endforeach; ?>
      </select>
      <?php endif; ?>
      <a class="btn btn-primary" href="/admin/new-episode?podcast=<?= e($p['id']) ?>"><?= icon('plus',13) ?> Nuevo episodio</a>
    </div>
  </div>
  <div class="list">
    <div style="overflow-x:auto;">
      <div style="min-width: 700px;">
        <?= ep_header(true) ?>
        <?php foreach ($epsPage as $ep) echo ep_row($ep, true); ?>
      </div>
    </div>
    <?php if ($totalPages > 1): ?>
    <?php $sParam = $filterSeason ? '&s=' . $filterSeason : ''; ?>
    <div class="row" style="padding:16px;justify-content:center;gap:4px;border-top:1px solid var(--border)">
      <?php if ($pageEp > 1): ?>
        <a class="btn" style="padding:4px 10px;font-size:12px" href="/admin/podcast?id=<?= e($p['id']) ?><?= $sParam ?>&p=1">Principio</a>
      <?php endif; ?>

      <?php
        $start = max(1, $pageEp - 1);
        $end = min($totalPages, max(3, $pageEp + 1));
        if ($pageEp === $totalPages) {
          $start = max(1, $totalPages - 2);
        }

        if ($start > 3) {
            echo '<a class="btn" style="padding:4px 10px;font-size:12px" href="/admin/podcast?id='.e($p['id']).$sParam.'&p=1">1</a>';
            echo '<a class="btn" style="padding:4px 10px;font-size:12px" href="/admin/podcast?id='.e($p['id']).$sParam.'&p=2">2</a>';
            echo '<span style="color:var(--text-3);padding:0 4px">...</span>';
        } elseif ($start > 1) {
            for ($i = 1; $i < $start; $i++) {
                echo '<a class="btn" style="padding:4px 10px;font-size:12px" href="/admin/podcast?id='.e($p['id']).$sParam.'&p='.$i.'">'.$i.'</a>';
            }
        }

        for ($i = $start; $i <= $end; $i++) {
            $active = $i === $pageEp ? ' btn-primary' : '';
            echo '<a class="btn'.$active.'" style="padding:4px 10px;font-size:12px" href="/admin/podcast?id='.e($p['id']).$sParam.'&p='.$i.'">'.$i.'</a>';
        }

        if ($end < $totalPages - 2) {
            echo '<span style="color:var(--text-3);padding:0 4px">...</span>';
            echo '<a class="btn" style="padding:4px 10px;font-size:12px" href="/admin/podcast?id='.e($p['id']).$sParam.'&p='.($totalPages - 1).'">'.($totalPages - 1).'</a>';
            echo '<a class="btn" style="padding:4px 10px;font-size:12px" href="/admin/podcast?id='.e($p['id']).$sParam.'&p='.$totalPages.'">'.$totalPages.'</a>';
        } elseif ($end < $totalPages) {
            for ($i = $end + 1; $i <= $totalPages; $i++) {
                echo '<a class="btn" style="padding:4px 10px;font-size:12px" href="/admin/podcast?id='.e($p['id']).$sParam.'&p='.$i.'">'.$i.'</a>';
            }
        }
      ?>

      <?php if ($pageEp < $totalPages): ?>
        <a class="btn" style="padding:4px 10px;font-size:12px" href="/admin/podcast?id=<?= e($p['id']) ?><?= $sParam ?>&p=<?= $totalPages ?>">Último</a>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</div>
