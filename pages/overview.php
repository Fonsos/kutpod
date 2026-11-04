<?php
// ============================================================================
// pages/overview.php — dashboard general · datos reales · empty states
// ============================================================================
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../version.php';

$podcasts = kp_podcasts();
$episodes = kp_episodes(true, null, 15);

$tot      = (int)array_sum(array_column($podcasts, 'downloads'));
$tot7     = (int)array_sum(array_column($podcasts, 'downloads7'));
$totalEps = (int)array_sum(array_column($podcasts, 'episodes'));

$drafts = 0;
try { $drafts = (int)(kp_one("SELECT count(*) AS c FROM episodes WHERE status = 'draft'")['c'] ?? 0); } catch (Throwable $e) {}

// Subs reales (ActivityPub + email premium) — 0 mientras no haya tabla poblada.
$subs = 0;
try { $subs = (int)(kp_one("SELECT count(*) AS c FROM ap_followers")['c'] ?? 0); } catch (Throwable $e) {}

// Top show por descargas últimos 7d (puede no existir si BD vacía).
$topShow = null;
if ($podcasts) {
  $ranked = $podcasts;
  usort($ranked, function($a, $b) {
      $d7 = ($b['downloads7'] ?? 0) <=> ($a['downloads7'] ?? 0);
      if ($d7 !== 0) return $d7;
      $dt = ($b['downloads'] ?? 0) <=> ($a['downloads'] ?? 0);
      if ($dt !== 0) return $dt;
      return ($b['episodes'] ?? 0) <=> ($a['episodes'] ?? 0);
  });
  $topShow = $ranked[0];
}

$has_podcasts = !empty($podcasts);
$has_episodes = !empty($episodes);

// Ritmo de publicación · medido sobre los últimos 7 episodios reales
$rhythm_days = null;
if (count($episodes) >= 2) {
  $ts = [];
  foreach (array_slice($episodes, 0, 7) as $e) {
    $t = strtotime($e['date_iso'] ?? '');
    if ($t) $ts[] = $t;
  }
  if (count($ts) >= 2) {
    $deltas = [];
    for ($i = 1; $i < count($ts); $i++) $deltas[] = ($ts[$i-1] - $ts[$i]) / 86400;
    $rhythm_days = array_sum($deltas) / count($deltas);
  }
}
$meta = 7;

// Comprobar si hay actualización disponible (datos cacheados, sin consultar GitHub)
$updateAvailable = null;
try {
  $cached = kp_setting('update_cache', '');
  if ($cached) {
    $updateData = json_decode($cached, true);
    // Solo mostrar si hay update Y la versión nueva es mayor que la instalada
    if ($updateData && !empty($updateData['available']) && !empty($updateData['new_version'])
        && version_compare($updateData['new_version'], KUTPOD_VERSION, '>')) {
      $updateAvailable = $updateData;
    }
  }
} catch (Throwable $e) {}
?>

<?php if ($updateAvailable): ?>
<div style="background:linear-gradient(135deg, var(--accent), color-mix(in srgb, var(--accent), #000 25%));border-radius:14px;padding:16px 24px;margin-bottom:20px;display:flex;align-items:center;justify-content:space-between;gap:16px">
  <div style="display:flex;align-items:center;gap:14px;color:#fff;min-width:0">
    <?= icon('refreshCw',18) ?>
    <div>
      <div style="font-size:14px;font-weight:600">Nueva versión disponible: v<?= e($updateAvailable['new_version'] ?? '') ?></div>
      <div style="font-size:12px;opacity:0.8;margin-top:2px">Estás en v<?= KUTPOD_VERSION ?> · <?= !empty($updateAvailable['published_at']) ? 'publicada ' . kp_relative_time($updateAvailable['published_at']) : '' ?></div>
    </div>
  </div>
  <a class="btn" href="/admin/update" style="background:rgba(255,255,255,0.2);color:#fff;border:1px solid rgba(255,255,255,0.3);white-space:nowrap;backdrop-filter:blur(4px)"><?= icon('download',13) ?> Ver actualización</a>
</div>
<?php endif; ?>

<div class="page-head">
  <div>
    <h1 class="page-title">Overview</h1>
    <p class="page-sub">
      <?= $has_podcasts ? count($podcasts) . ' show' . (count($podcasts)>1?'s':'') . ' activos' : 'Sin podcasts todavía' ?>
      · <?= $totalEps ?> episodio<?= $totalEps===1?'':'s' ?>
      · <?= $drafts ?> borrador<?= $drafts===1?'':'es' ?>
    </p>
  </div>
</div>

<div class="stat-grid">
  <?= stat_card('Descargas · 7d', fmt_num($tot7), 'download', ['sub'=>$tot7 ? 'OP3 nativo' : 'Aún sin tráfico']) ?>
  <?= stat_card('Descargas totales', fmt_num($tot), 'chart', ['sub'=>$tot ? 'desde el primer ep' : '—']) ?>
  <?= stat_card('Episodios publicados', (string)$totalEps, 'headphones', ['sub'=>$drafts ? "$drafts borrador" . ($drafts>1?'es':'') . ' en cola' : 'Sin borradores']) ?>
  <?= stat_card('Seguidores Fediverso', fmt_num($subs), 'rss', ['sub'=>'ActivityPub']) ?>
</div>

<?php if (!$has_podcasts): ?>
  <div class="card card-lg" style="padding:60px 40px;margin-top:24px;text-align:center">
    <div style="display:inline-grid;place-items:center;width:64px;height:64px;border-radius:16px;background:var(--accent-soft);color:var(--accent);margin-bottom:18px"><?= icon('mic',28) ?></div>
    <h2 style="font-size:22px;letter-spacing:-0.01em;margin-bottom:8px">Tu instancia está limpia.</h2>
    <p class="page-sub" style="max-width:480px;margin:0 auto 24px">Crea tu primer podcast o importa un feed RSS existente. Cuando publiques episodios verás las estadísticas reales aquí.</p>
    <div class="row" style="justify-content:center;gap:10px">
      <a class="btn btn-primary" href="/admin/new-podcast"><?= icon('plus',13) ?> Crear primer podcast</a>
      <a class="btn" href="/admin/import"><?= icon('rss',13) ?> Importar RSS</a>
    </div>
  </div>

<?php else: ?>

<!-- GRÁFICO PRINCIPAL A TODO LO ANCHO -->
<div class="card" style="margin-top:var(--gap)">
  <div class="card-head">
    <?php 
      $period = (int)($_GET['period'] ?? 30);
      if (!in_array($period, [7, 30, 365])) $period = 30;
    ?>
    <div class="card-title">Descargas por show — últimos <?= $period==365 ? '12 meses' : $period.' días' ?></div>
    <div class="seg">
      <button class="<?= $period==7?'active':'' ?>" onclick="location.href='?period=7'">7d</button>
      <button class="<?= $period==30?'active':'' ?>" onclick="location.href='?period=30'">30d</button>
      <button class="<?= $period==365?'active':'' ?>" onclick="location.href='?period=365'">12m</button>
    </div>
  </div>
  <?php
    require_once __DIR__ . '/../includes/op3-stats.php';
    $W = 1000; $H = 220;
    $all_zero = !$tot7;

    $max_val = 1;
    $all_series = [];
    $podcast_period_totals = [];
    $days = [];
    for ($i=$period-1; $i>=0; $i--) $days[] = date('Y-m-d', strtotime("-$i days"));
    
    $raw_series = kp_op3_all_podcasts_series($period);
    $series_by_pod = [];
    foreach ($raw_series as $row) {
      $series_by_pod[$row['podcast_id']][$row['date']] = (int)$row['downloads'];
    }

    foreach ($podcasts as $p) {
      $dict = $series_by_pod[$p['db_id']] ?? [];
      $p_tot = array_sum($dict);
      $podcast_period_totals[$p['db_id']] = $p_tot;

      $series_pts = [];
      foreach ($days as $d) {
        $val = $dict[$d] ?? 0;
        if ($val > $max_val) $max_val = $val;
        $series_pts[] = ['d'=>$d, 'v'=>$val];
      }
      $all_series[] = ['p' => $p, 'pts' => $series_pts];
    }

    // Serie de YouTube si el plugin está activo
    $has_yt_data = false;
    if (kp_plugin_is_active('youtube_stats') && function_exists('kp_youtube_get_daily_series')) {
      $yt_daily = kp_youtube_get_daily_series($days);
      if (array_sum($yt_daily) > 0) {
        $has_yt_data = true;
        $yt_pts = [];
        foreach ($days as $d) {
          $val = $yt_daily[$d] ?? 0;
          if ($val > $max_val) $max_val = $val;
          $yt_pts[] = ['d' => $d, 'v' => $val];
        }
        $all_series[] = [
          'p' => [
            'id'         => 'youtube',
            'title'      => 'YouTube',
            'color'      => '#ef4444',
            'is_youtube' => true,
          ],
          'pts' => $yt_pts
        ];
      }
    }

    echo '<svg viewBox="0 0 '.$W.' '.$H.'" preserveAspectRatio="none" style="width:100%;height:220px;overflow:visible">';
    foreach ([0.25, 0.5, 0.75] as $g) echo '<line x1="0" x2="'.$W.'" y1="'.($H*$g).'" y2="'.($H*$g).'" stroke="var(--border)" stroke-dasharray="2 4"/>';
    
    if ($all_zero && !$has_yt_data) {
      echo '<text x="'.($W/2).'" y="'.($H/2).'" text-anchor="middle" fill="var(--text-3)" font-size="13" font-family="Inter,system-ui">Esperando datos…</text>';
    } else {
      foreach ($all_series as $s) {
        $pts = [];
        $circles = [];
        $is_yt = !empty($s['p']['is_youtube']);
        $unit_str = $is_yt ? 'vistas' : 'descargas';
        $prefix_str = $is_yt ? '▶️ ' : '';
        foreach ($s['pts'] as $k => $pt) {
          $x = ($k / max(1, $period - 1)) * $W;
          $y = $H - (($pt['v'] / $max_val) * $H * 0.9);
          $pts[] = $x . ',' . $y;
          if ($pt['v'] > 0) {
            $d_fmt = date('d M, Y', strtotime($pt['d']));
            $tip_html = '<div style="display:flex;align-items:center;gap:6px"><span style="width:8px;height:8px;border-radius:50%;background:'.e($s['p']['color']).'"></span><strong style="color:var(--text)">'.$prefix_str.e($s['p']['title']).'</strong></div><div style="font-size:11px;color:var(--text-3);margin-top:2px">'.e($d_fmt).'</div><div style="font-size:13px;font-weight:600;margin-top:4px;color:var(--text)">'.number_format($pt['v']).' '.$unit_str.'</div>';

            $circles[] = '<g class="chart-point-hit" data-tooltip-html="'.htmlspecialchars($tip_html, ENT_QUOTES, 'UTF-8').'">'
              . '<circle cx="'.$x.'" cy="'.$y.'" r="10" fill="transparent" />'
              . '<circle cx="'.$x.'" cy="'.$y.'" r="4" fill="'.e($s['p']['color']).'" stroke="var(--surface)" stroke-width="2" class="chart-point-dot" style="transition:all 0.15s ease" />'
              . '</g>';
          }
        }
        $sw = $is_yt ? '2.5' : '2';
        echo '<polyline points="'.implode(' ', $pts).'" fill="none" stroke="'.e($s['p']['color']).'" stroke-width="'.$sw.'" opacity="0.9"/>';
        echo implode('', $circles);
      }
    }
    echo '</svg>';
  ?>
  <div class="row" style="gap:18px;flex-wrap:wrap;margin-top:14px">
    <?php foreach ($podcasts as $p): ?>
      <div class="row" style="gap:6px;font-size:12px"><span style="width:10px;height:10px;border-radius:3px;background:<?= e($p['color']) ?>"></span><?= e($p['title']) ?></div>
    <?php endforeach; ?>
    <?php if ($has_yt_data): ?>
      <div class="row" style="gap:6px;font-size:12px;font-weight:600;color:#ef4444"><span style="width:10px;height:10px;border-radius:3px;background:#ef4444"></span>▶️ YouTube (vistas)</div>
    <?php endif; ?>
  </div>
</div>

<!-- TARJETAS EN 3 COLUMNAS COMPACTAS -->
<div class="overview-three-col" style="margin-top:var(--gap)">
  <!-- COLUMNA 1: Tus podcasts (Paginado a Top 4) -->
  <div class="card kp-paginated-card" data-page-size="4" style="display:flex;flex-direction:column;height:100%">
    <div class="card-head">
      <div class="card-title">Tus podcasts</div>
      <a class="btn btn-ghost" href="/admin/podcasts">Ver todos →</a>
    </div>
    <div class="kp-paginated-items list" style="flex:1">
      <?php
        $top_episodes_map = kp_op3_most_downloaded_episodes_by_podcast();
        foreach ($podcasts as $p):
      ?>
        <a class="row" style="padding:12px 8px;border-top:1px solid var(--border);text-decoration:none;color:var(--text)" href="/admin/podcast/<?= e($p['id']) ?>">
          <?php if ($p['cover']): ?>
            <img class="cover" src="<?= e($p['cover']) ?>" style="width:40px;height:40px;border-radius:10px;object-fit:cover" alt="">
          <?php else: ?>
            <div class="cover" style="width:40px;height:40px;border-radius:10px;background:linear-gradient(135deg,<?= e($p['color']) ?>,<?= e($p['color']) ?>99);color:white;font-weight:700"><?= e($p['initial']) ?></div>
          <?php endif; ?>
          <div style="flex:1;min-width:0">
            <div style="font-size:13.5px;font-weight:500">
              <?= e($p['title']) ?>
              <?php if (($p['parental'] ?? 'clean') === 'explicit'): ?>
                <span style="display:inline-block;background:var(--border);color:var(--text-2);border-radius:4px;font-size:10px;padding:2px 4px;margin-left:4px;vertical-align:middle;line-height:1;font-weight:800">E</span>
              <?php endif; ?>
            </div>
            <div style="font-size:11.5px;color:var(--text-3)"><?= (int)$p['episodes'] ?> eps · <?= e($p['cat']) ?></div>
            <?php
              $top_ep = $top_episodes_map[$p['db_id']] ?? null;
              if ($top_ep && $top_ep['downloads'] > 0):
            ?>
              <div style="font-size:11px;color:var(--text-3);margin-top:3px;display:flex;align-items:center;gap:4px">
                <span style="color:var(--accent);display:inline-flex"><?= icon('star', 10) ?></span>
                <span style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:260px;" title="Top: <?= e($top_ep['title']) ?> (<?= fmt_num((int)$top_ep['downloads']) ?> descargas)">
                  Top: <strong style="color:var(--text-2)"><?= e($top_ep['title']) ?></strong> (<?= fmt_num((int)$top_ep['downloads']) ?> desc.)
                </span>
              </div>
            <?php endif; ?>
          </div>
          <div class="tabular" style="text-align:right">
            <div style="font-weight:600"><?= fmt_num((int)$p['downloads7']) ?></div>
            <div style="font-size:10.5px;color:var(--text-3);margin-top:2px">desc. 7d</div>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- COLUMNA 2: Distribución por show (Donut chart: Top 4 + Otros shows) -->
  <?php
    $donut_total = array_sum($podcast_period_totals);
    $donut_items = [];
    foreach ($podcasts as $p) {
      $p_dl = $podcast_period_totals[$p['db_id']] ?? 0;
      $donut_items[] = [
        'title' => $p['title'],
        'color' => $p['color'],
        'downloads' => $p_dl
      ];
    }
    usort($donut_items, function($a, $b) {
      return $b['downloads'] <=> $a['downloads'];
    });

    $C = 2 * M_PI * 56;
    $slices = [];
    $other_dl = 0;
    foreach ($donut_items as $idx => $di) {
      if ($donut_total > 0 && $di['downloads'] > 0) {
        if ($idx < 4) { // Top 4 shows
          $slices[] = [
            'title' => $di['title'],
            'color' => $di['color'],
            'downloads' => $di['downloads'],
            'pct' => round(($di['downloads'] / $donut_total) * 100, 1)
          ];
        } else {
          $other_dl += $di['downloads'];
        }
      }
    }
    if ($other_dl > 0) {
      $slices[] = [
        'title' => 'Otros shows',
        'color' => '#8c8c95',
        'downloads' => $other_dl,
        'pct' => round(($other_dl / $donut_total) * 100, 1)
      ];
    }
  ?>
  <div class="card" style="display:flex;flex-direction:column;height:100%">
    <div class="card-head" style="margin-bottom:12px">
      <div class="card-title">Distribución por show</div>
      <span style="font-size:11px;color:var(--text-3);background:var(--surface-2);border:1px solid var(--border);padding:2px 8px;border-radius:12px"><?= $period==365 ? '12 meses' : $period.' días' ?></span>
    </div>

    <div style="position:relative;display:flex;justify-content:center;align-items:center;padding:12px 0;flex:1">
      <svg viewBox="0 0 160 160" style="width:160px;height:160px;overflow:visible">
        <!-- Fondo base del donut -->
        <circle cx="80" cy="80" r="56" fill="none" stroke="var(--surface-2)" stroke-width="18" />
        <?php if ($donut_total <= 0 || empty($slices)): ?>
          <text x="80" y="78" text-anchor="middle" font-size="12" font-weight="600" fill="var(--text-3)">Sin descargas</text>
          <text x="80" y="94" text-anchor="middle" font-size="10" fill="var(--text-3)">en el período</text>
        <?php else:
          $acc = 0;
          foreach ($slices as $sIdx => $sl):
            $dash = ($sl['pct'] / 100) * $C;
            $visible_dash = count($slices) > 1 ? max(1, $dash - 1.5) : $dash;
            $gap = $C - $visible_dash;
            $offset = -$acc;
            $acc += $dash;
            $tip_donut = '<div style="display:flex;align-items:center;gap:6px"><span style="width:8px;height:8px;border-radius:50%;background:'.e($sl['color']).'"></span><strong style="color:var(--text)">'.e($sl['title']).'</strong></div><div style="font-size:13px;font-weight:600;margin-top:4px;color:var(--text)">'.number_format($sl['downloads']).' descargas ('.$sl['pct'].'%)</div>';
        ?>
          <circle cx="80" cy="80" r="56" fill="none"
            stroke="<?= e($sl['color']) ?>"
            stroke-width="18"
            stroke-dasharray="<?= sprintf('%.2f %.2f', $visible_dash, $gap) ?>"
            stroke-dashoffset="<?= sprintf('%.2f', $offset) ?>"
            transform="rotate(-90 80 80)"
            class="donut-slice"
            data-donut-id="<?= $sIdx ?>"
            data-tooltip-html="<?= htmlspecialchars($tip_donut, ENT_QUOTES, 'UTF-8') ?>"
          />
        <?php endforeach; ?>
          <!-- Total en el centro -->
          <text x="80" y="77" text-anchor="middle" font-size="18" font-weight="700" fill="var(--text)" style="letter-spacing:-0.02em"><?= fmt_num($donut_total) ?></text>
          <text x="80" y="93" text-anchor="middle" font-size="10.5" fill="var(--text-3)">descargas</text>
        <?php endif; ?>
      </svg>
    </div>

    <?php if (!empty($slices)): ?>
      <div class="col" style="gap:6px;margin-top:auto;border-top:1px solid var(--border);padding-top:12px">
        <?php foreach ($slices as $sIdx => $sl):
          $tip_legend = '<div style="display:flex;align-items:center;gap:6px"><span style="width:8px;height:8px;border-radius:50%;background:'.e($sl['color']).'"></span><strong style="color:var(--text)">'.e($sl['title']).'</strong></div><div style="font-size:13px;font-weight:600;margin-top:4px;color:var(--text)">'.number_format($sl['downloads']).' descargas ('.$sl['pct'].'%)</div>';
        ?>
          <div class="row donut-legend-row" data-donut-id="<?= $sIdx ?>" data-tooltip-html="<?= htmlspecialchars($tip_legend, ENT_QUOTES, 'UTF-8') ?>" style="justify-content:space-between;padding:6px 8px;border-radius:6px;font-size:12px;cursor:pointer">
            <div class="row" style="gap:8px;min-width:0">
              <span style="width:8px;height:8px;border-radius:50%;background:<?= e($sl['color']) ?>;flex-shrink:0"></span>
              <span style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:var(--text-2);font-weight:500"><?= e($sl['title']) ?></span>
            </div>
            <div class="tabular row" style="gap:8px;flex-shrink:0">
              <strong style="color:var(--text)"><?= $sl['pct'] ?>%</strong>
              <span style="color:var(--text-3);font-size:11px"><?= fmt_num($sl['downloads']) ?></span>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- COLUMNA 3: Ritmo de publicación + Top show · 7d apilados -->
  <div class="col" style="gap:var(--gap);display:flex;flex-direction:column;height:100%">
    <!-- Ritmo de publicación -->
    <div class="card" style="flex:1;display:flex;flex-direction:column;justify-content:space-between">
      <div>
        <div class="card-head"><div class="card-title">Ritmo de publicación</div></div>
        <?php if ($rhythm_days === null): ?>
          <div class="page-sub" style="padding:20px 0;text-align:center">Necesitas al menos 2 episodios publicados para medir el ritmo.</div>
        <?php else: ?>
          <div class="tabular" style="font-size:30px;font-weight:600;letter-spacing:-0.02em">
            <?= number_format($rhythm_days, 1) ?><span style="font-size:13.5px;color:var(--text-3);font-weight:400;margin-left:6px">días entre eps</span>
          </div>
          <div class="delta <?= $rhythm_days <= $meta ? 'up' : 'down' ?>" style="margin-top:6px">
            <?= icon($rhythm_days <= $meta ? 'arrowDown' : 'arrowUp',12) ?>
            <?= number_format(abs($meta - $rhythm_days), 1) ?>d vs meta de <?= $meta ?>d
          </div>
        <?php endif; ?>
      </div>
      <?php if ($rhythm_days !== null): ?>
        <div class="row" style="justify-content:space-between;margin-top:14px;font-size:11px;color:var(--text-3)"><span>últimos episodios</span><span><?= e($episodes[0]['date'] ?? '') ?></span></div>
      <?php endif; ?>
    </div>

    <!-- Top show · 7d -->
    <?php if ($topShow): ?>
    <div class="card" style="flex:1;display:flex;flex-direction:column;justify-content:space-between">
      <div>
        <div class="card-head"><div class="card-title">Top show · 7d</div></div>
        <div class="row" style="gap:14px;align-items:flex-start">
          <?php if ($topShow['cover']): ?>
            <img class="cover" src="<?= e($topShow['cover']) ?>" style="width:64px;height:64px;border-radius:12px;object-fit:cover;flex-shrink:0" alt="">
          <?php else: ?>
            <div class="cover" style="width:64px;height:64px;border-radius:12px;background:linear-gradient(135deg,<?= e($topShow['color']) ?>,<?= e($topShow['color']) ?>99);color:white;font-weight:700;font-size:18px;flex-shrink:0"><?= e($topShow['initial']) ?></div>
          <?php endif; ?>
          <div style="flex:1;min-width:0">
            <div style="font-size:15px;font-weight:600">
              <?= e($topShow['title']) ?>
              <?php if (($topShow['parental'] ?? 'clean') === 'explicit'): ?>
                <span style="display:inline-block;background:var(--border);color:var(--text-2);border-radius:4px;font-size:10px;padding:2px 4px;margin-left:4px;vertical-align:middle;line-height:1;font-weight:800">E</span>
              <?php endif; ?>
            </div>
            <div style="font-size:12px;color:var(--text-3);margin-top:2px"><?= (int)$topShow['episodes'] ?> eps · <?= e($topShow['cat']) ?></div>
            <div class="row" style="gap:16px;margin-top:12px">
              <div><div style="font-size:11px;color:var(--text-3)">7d</div><div class="tabular" style="font-weight:600"><?= fmt_num((int)$topShow['downloads7']) ?></div></div>
              <div><div style="font-size:11px;color:var(--text-3)">Total</div><div class="tabular" style="font-weight:600"><?= fmt_num((int)$topShow['downloads']) ?></div></div>
            </div>
          </div>
        </div>
      </div>
      <a class="btn" style="margin-top:14px;width:100%;justify-content:center" href="/admin/podcast/<?= e($topShow['id']) ?>">Abrir show →</a>
    </div>
    <?php endif; ?>
  </div>
</div>

<div class="card" style="margin-top:var(--gap)">
  <div class="card-head">
    <div class="card-title">Episodios recientes</div>
    <a class="btn btn-ghost" href="/admin/podcasts">Ver más →</a>
  </div>
  <?php if (!$has_episodes): ?>
    <div class="page-sub" style="padding:40px;text-align:center">Aún no has publicado episodios.
      <?php if ($has_podcasts): ?>
        <a class="btn" style="margin-top:14px" href="/admin/new-episode?podcast=<?= e($podcasts[0]['id']) ?>">Subir el primero →</a>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="list">
      <div style="overflow-x:auto;">
        <div style="min-width: 700px;">
          <?= ep_header() ?>
          <?php foreach (array_slice($episodes, 0, 8) as $ep) echo ep_row($ep); ?>
        </div>
      </div>
    </div>
  <?php endif; ?>
</div>

<script>
(function initOverviewInteractions() {
  // 1. Tooltip universal para puntos de gráfico y rebanadas de donut
  let tooltip = document.querySelector('.kp-universal-tooltip');
  if (!tooltip) {
    tooltip = document.createElement('div');
    tooltip.className = 'kp-universal-tooltip';
    document.body.appendChild(tooltip);
  }

  let activeEl = null;

  function updatePos(x, y) {
    tooltip.style.left = `${x}px`;
    tooltip.style.top = `${y}px`;
  }

  document.addEventListener('mouseover', (e) => {
    const item = e.target.closest('[data-tooltip], [data-tooltip-html]');
    if (!item) {
      if (activeEl) {
        tooltip.classList.remove('visible');
        activeEl = null;
      }
      return;
    }

    activeEl = item;
    if (item.hasAttribute('data-tooltip-html')) {
      tooltip.innerHTML = item.getAttribute('data-tooltip-html');
    } else {
      tooltip.textContent = item.getAttribute('data-tooltip') || '';
    }
    tooltip.classList.add('visible');
    updatePos(e.clientX, e.clientY);
  });

  document.addEventListener('mousemove', (e) => {
    if (activeEl) {
      updatePos(e.clientX, e.clientY);
    }
  });

  document.addEventListener('mouseout', (e) => {
    if (activeEl && !e.relatedTarget?.closest('[data-tooltip], [data-tooltip-html]')) {
      tooltip.classList.remove('visible');
      activeEl = null;
    }
  });

  // 2. Sincronización hover entre rebanadas Donut y filas de leyenda
  const slices = document.querySelectorAll('.donut-slice');
  const legendRows = document.querySelectorAll('.donut-legend-row');

  function setSliceActive(id, active) {
    slices.forEach(s => {
      if (s.getAttribute('data-donut-id') === id) {
        s.classList.toggle('is-active', active);
      } else if (active) {
        s.style.opacity = '0.35';
      } else {
        s.style.opacity = '';
      }
    });
    legendRows.forEach(r => {
      if (r.getAttribute('data-donut-id') === id) {
        r.classList.toggle('is-active', active);
      }
    });
  }

  slices.forEach(s => {
    const id = s.getAttribute('data-donut-id');
    s.addEventListener('mouseenter', () => setSliceActive(id, true));
    s.addEventListener('mouseleave', () => setSliceActive(id, false));
  });

  legendRows.forEach(r => {
    const id = r.getAttribute('data-donut-id');
    r.addEventListener('mouseenter', () => setSliceActive(id, true));
    r.addEventListener('mouseleave', () => setSliceActive(id, false));
  });

  // 3. Paginador para la tarjeta de Tus Podcasts
  document.querySelectorAll('.kp-paginated-card').forEach(card => {
    const pageSize = parseInt(card.dataset.pageSize || '4', 10);
    const container = card.querySelector('.kp-paginated-items');
    if (!container) return;

    const items = Array.from(container.children);
    const total = items.length;
    if (total <= pageSize) return;

    let currentPage = 1;
    let showAll = false;
    const totalPages = Math.ceil(total / pageSize);

    const footer = document.createElement('div');
    footer.className = 'kp-paginated-footer';
    footer.innerHTML = `
      <span class="kp-pager-info">Mostrando 1–${pageSize} de ${total}</span>
      <div class="kp-pager-controls">
        <button type="button" class="kp-pager-toggle-btn">Ver todos (${total})</button>
        <button type="button" class="kp-pager-btn kp-pager-prev" title="Página anterior" disabled>‹</button>
        <span class="kp-pager-pages" style="font-size:11.5px;color:var(--text-2);padding:0 4px">1 / ${totalPages}</span>
        <button type="button" class="kp-pager-btn kp-pager-next" title="Página siguiente">›</button>
      </div>
    `;
    card.appendChild(footer);

    const infoEl = footer.querySelector('.kp-pager-info');
    const toggleBtn = footer.querySelector('.kp-pager-toggle-btn');
    const prevBtn = footer.querySelector('.kp-pager-prev');
    const nextBtn = footer.querySelector('.kp-pager-next');
    const pagesSpan = footer.querySelector('.kp-pager-pages');

    function updateView() {
      const parentGrid = card.closest('.overview-three-col');
      if (showAll) {
        if (parentGrid) parentGrid.classList.add('is-expanded');
        items.forEach(el => el.style.display = '');
        infoEl.textContent = `Mostrando todos (${total})`;
        toggleBtn.textContent = 'Ver top ' + pageSize;
        prevBtn.style.display = 'none';
        nextBtn.style.display = 'none';
        pagesSpan.style.display = 'none';
      } else {
        if (parentGrid) parentGrid.classList.remove('is-expanded');
        prevBtn.style.display = '';
        nextBtn.style.display = '';
        pagesSpan.style.display = '';
        toggleBtn.textContent = `Ver todos (${total})`;

        const start = (currentPage - 1) * pageSize;
        const end = Math.min(start + pageSize, total);

        items.forEach((el, idx) => {
          el.style.display = (idx >= start && idx < end) ? '' : 'none';
        });

        infoEl.textContent = `Mostrando ${start + 1}–${end} de ${total}`;
        pagesSpan.textContent = `${currentPage} / ${totalPages}`;
        prevBtn.disabled = currentPage <= 1;
        nextBtn.disabled = currentPage >= totalPages;
      }
    }

    toggleBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      showAll = !showAll;
      if (!showAll) currentPage = 1;
      updateView();
    });

    prevBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      if (currentPage > 1) {
        currentPage--;
        updateView();
      }
    });

    nextBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      if (currentPage < totalPages) {
        currentPage++;
        updateView();
      }
    });

    updateView();
  });
})();
</script>

<?php endif; ?>

