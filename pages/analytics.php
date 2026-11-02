<?php
// ============================================================================
// pages/analytics.php — Estadísticas globales (OP3)
// ============================================================================
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/op3-stats.php';

// Nielsen DMA code → city name lookup
$DMA_NAMES = [
  200=>'New York',201=>'New York',202=>'New York',
  500=>'Portland, OR',501=>'New York',502=>'Binghamton',503=>'Macon',
  504=>'Philadelphia',505=>'Detroit',506=>'Boston',507=>'Savannah',
  508=>'Pittsburgh',509=>'Ft. Wayne',510=>'Cleveland',511=>'Washington DC',
  512=>'Baltimore',513=>'Flint-Saginaw',514=>'Buffalo',515=>'Cincinnati',
  516=>'Erie',517=>'Charlotte',518=>'Greensboro',519=>'Charleston, SC',
  520=>'Augusta',521=>'Providence',522=>'Columbus, GA',523=>'Burlington',
  524=>'Atlanta',525=>'Albany, GA',526=>'Utica',527=>'Indianapolis',
  528=>'Miami',529=>'Louisville',530=>'Tallahassee',531=>'Tri-Cities, TN-VA',
  532=>'Albany-Schenectady',533=>'Hartford',534=>'Orlando',535=>'Columbus, OH',
  536=>'Youngstown',537=>'Bangor',538=>'Rochester, NY',539=>'Tampa-St. Pete',
  540=>'Traverse City',541=>'Lexington',542=>'Dayton',543=>'Springfield, MA',
  544=>'Norfolk',545=>'Greenville-Spartanburg',546=>'Columbia, SC',
  547=>'Toledo',548=>'West Palm Beach',549=>'Watertown',550=>'Wilmington',
  551=>'Lansing',552=>'Presque Isle',553=>'Marquette',554=>'Wheeling',
  555=>'Syracuse',556=>'Richmond',557=>'Knoxville',558=>'Lima',559=>'Bluefield',
  560=>'Raleigh-Durham',561=>'Jacksonville',563=>'Grand Rapids',
  564=>'Charleston, WV',565=>'Elmira',566=>'Harrisburg',567=>'Greenvl-New Bern',
  569=>'Harrisonburg',570=>'Florence-Myrtle Beach',571=>'Ft. Myers',
  573=>'Roanoke',574=>'Johnstown-Altoona',575=>'Chattanooga',576=>'Salisbury',
  577=>'Wilkes-Barre',581=>'Terre Haute',582=>'Lafayette, IN',
  583=>'Alpena',584=>'Charlottesville',588=>'South Bend',592=>'Gainesville',
  596=>'Zanesville',597=>'Parkersburg',598=>'Clarksburg',600=>'Corpus Christi',
  602=>'Chicago',603=>'Joplin',604=>'Columbia-Jefferson City',605=>'Topeka',
  606=>'Dothan',609=>'St. Louis',610=>'Rockford',611=>'Rochester-Mason City',
  612=>'Shreveport',613=>'Minneapolis-St. Paul',616=>'Kansas City',
  617=>'Milwaukee',618=>'Houston',619=>'Springfield, MO',620=>'Tuscaloosa',
  622=>'New Orleans',623=>'Dallas-Ft. Worth',624=>'Sioux City',625=>'Waco-Temple',
  626=>'Victoria',627=>'Wichita Falls',628=>'Monroe-El Dorado',630=>'Birmingham',
  631=>'Ottumwa-Kirksville',632=>'Paducah',633=>'Odessa-Midland',
  634=>'Amarillo',635=>'Austin',636=>'Harlingen-Weslaco',637=>'Cedar Rapids',
  638=>'St. Joseph',639=>'Jackson, TN',640=>'Memphis',641=>'San Antonio',
  642=>'Lafayette, LA',643=>'Lake Charles',644=>'Alexandria',
  647=>'Greenwood-Greenville',648=>'Champaign',649=>'Evansville',
  650=>'Oklahoma City',651=>'Lubbock',652=>'Omaha',656=>'Panama City',
  657=>'Sherman-Ada',658=>'Green Bay',659=>'Nashville',
  661=>'San Angelo',662=>'Abilene-Sweetwater',669=>'Madison',
  670=>'Ft. Smith-Fayetteville',671=>'Tulsa',673=>'Columbus-Tupelo',
  675=>'Peoria-Bloomington',676=>'Duluth-Superior',678=>'Wichita-Hutchinson',
  679=>'Des Moines',682=>'Davenport',686=>'Mobile-Pensacola',
  687=>'Minot-Bismarck',691=>'Huntsville-Decatur',692=>'Beaumont-Port Arthur',
  693=>'Little Rock-Pine Bluff',698=>'Montgomery',702=>'La Crosse-Eau Claire',
  705=>'Wausau-Rhinelander',709=>'Tyler-Longview',710=>'Hattiesburg-Laurel',
  711=>'Meridian',716=>'Baton Rouge',717=>'Quincy-Hannibal',
  718=>'Jackson, MS',722=>'Lincoln-Hastings',724=>'Fargo-Valley City',
  725=>'Sioux Falls',734=>'Jonesboro',736=>'Bowling Green',
  737=>'Knoxville',740=>'North Platte',743=>'Anchorage',
  744=>'Honolulu',745=>'Fairbanks',746=>'Biloxi-Gulfport',
  747=>'Juneau',749=>'Laredo',751=>'Denver',752=>'Colorado Springs',
  753=>'Phoenix',754=>'Butte-Bozeman',755=>'Great Falls',756=>'Billings',
  757=>'Boise',758=>'Idaho Falls-Pocatello',759=>'Cheyenne-Scottsbluff',
  760=>'Twin Falls',762=>'Missoula',764=>'Rapid City',
  765=>'El Paso',766=>'Helena',767=>'Casper-Riverton',770=>'Salt Lake City',
  771=>'Yuma-El Centro',773=>'Grand Junction',789=>'Tucson',790=>'Albuquerque',
  798=>'Glendive',800=>'Bakersfield',801=>'Eugene',802=>'Eureka',
  803=>'Los Angeles',804=>'Palm Springs',807=>'San Francisco',
  810=>'Yakima-Pasco',811=>'Reno',813=>'Medford-Klamath Falls',
  819=>'Seattle-Tacoma',820=>'Portland, OR',821=>'Bend, OR',
  825=>'San Diego',828=>'Monterey-Salinas',839=>'Las Vegas',
  855=>'Santa Barbara',862=>'Sacramento',866=>'Fresno-Visalia',
  868=>'Chico-Redding',881=>'Spokane',
];

function dma_name(string $code, array $map): string {
  return $map[(int)$code] ?? $code;
}

function kp_country_name(string $code): string {
  static $es_names = [
    'ES' => 'España', 'US' => 'Estados Unidos', 'MX' => 'México', 'CO' => 'Colombia',
    'AR' => 'Argentina', 'CL' => 'Chile', 'PE' => 'Perú', 'VE' => 'Venezuela',
    'EC' => 'Ecuador', 'GT' => 'Guatemala', 'CU' => 'Cuba', 'BO' => 'Bolivia',
    'DO' => 'República Dominicana', 'HN' => 'Honduras', 'PY' => 'Paraguay',
    'SV' => 'El Salvador', 'NI' => 'Nicaragua', 'CR' => 'Costa Rica', 'PA' => 'Panamá',
    'UY' => 'Uruguay', 'PR' => 'Puerto Rico', 'BR' => 'Brasil', 'CA' => 'Canadá',
    'DE' => 'Alemania', 'FR' => 'Francia', 'GB' => 'Reino Unido', 'IT' => 'Italia',
    'PT' => 'Portugal', 'RU' => 'Rusia', 'JP' => 'Japón', 'CN' => 'China',
    'IN' => 'India', 'AU' => 'Australia', 'NL' => 'Países Bajos', 'BE' => 'Bélgica',
    'CH' => 'Suiza', 'SE' => 'Suecia', 'NO' => 'Noruega', 'DK' => 'Dinamarca',
    'FI' => 'Finlandia', 'PL' => 'Polonia', 'AT' => 'Austria', 'IE' => 'Irlanda',
    'NZ' => 'Nueva Zelanda', 'ZA' => 'Sudáfrica', 'KR' => 'Corea del Sur'
  ];
  $c = strtoupper(trim($code));
  return $es_names[$c] ?? $c;
}

function kp_country_flag(string $code): string {
  $c = strtoupper(trim($code));
  if (strlen($c) !== 2 || !ctype_alpha($c)) return '🌐';
  $chars = str_split($c);
  return mb_chr(127397 + ord($chars[0]), 'UTF-8') . mb_chr(127397 + ord($chars[1]), 'UTF-8');
}

function kp_stat_bar_row(string $label, int $count, int $total, ?string $badge = null, ?string $link = null, ?string $sub = null): string {
  $pct = $total > 0 ? round(($count / $total) * 100, 1) : 0;
  $width = min(100, max(2, $pct));
  $formatted_count = number_format($count);
  $tip_text = htmlspecialchars("{$label}: {$formatted_count} descargas ({$pct}%)", ENT_QUOTES, 'UTF-8');
  
  $label_html = $link 
    ? '<a href="' . e($link) . '" style="color:inherit;text-decoration:none" class="stat-bar-link" onmouseover="this.style.textDecoration=\'underline\'" onmouseout="this.style.textDecoration=\'none\'">' . e($label) . '</a>'
    : '<span>' . e($label) . '</span>';
    
  $badge_html = $badge ? '<span class="stat-row-badge">' . $badge . '</span>' : '';
  $sub_html = $sub ? '<div class="stat-bar-sub">' . e($sub) . '</div>' : '';

  return '
  <div class="stat-bar-item" data-tooltip="' . $tip_text . '" data-label="' . e($label) . '" data-count="' . $formatted_count . '" data-pct="' . $pct . '%">
    <div class="stat-bar-header">
      <div class="stat-bar-title-wrap">
        ' . $badge_html . '
        ' . $label_html . '
      </div>
      <div class="stat-bar-numbers tabular">
        <span class="stat-count">' . $formatted_count . '</span>
        <span class="stat-pct">(' . $pct . '%)</span>
      </div>
    </div>
    ' . $sub_html . '
    <div class="stat-bar-track">
      <div class="stat-bar-fill" style="width:' . $width . '%"></div>
    </div>
  </div>';
}

$range = $_GET['range'] ?? '30d';
$valid_ranges = ['7d'=>7, '30d'=>30, 'mtd'=>(int)date('j'), 'all'=>3650];
$days = $valid_ranges[$range] ?? 30;

$active_tab = $_GET['tab'] ?? 'downloads';
if (!in_array($active_tab, ['downloads', 'location', 'tech'], true)) {
  $active_tab = 'downloads';
}

$cache_key = 'analytics_' . $range;
$cached_data = kp_cache_get($cache_key);

if ($cached_data) {
    extract($cached_data);
} else {
    $podcasts = kp_q("SELECT id, slug, title FROM podcasts ORDER BY title");
    $has_op3 = kp_op3_tables_exist();
    $last_sync = kp_op3_last_sync();

    // Totales globales
    $tot = 0; $tot_period = 0; $unique_listeners = 0;
    $series = []; // dia => downloads
    foreach ($podcasts as $p) {
      $st = kp_op3_podcast_totals((int)$p['id'], 9999);
      $stp = kp_op3_podcast_totals((int)$p['id'], $days);
      $tot += $st['downloads']; $tot_period += $stp['downloads'];
      $unique_listeners += $stp['listeners'];
      foreach (kp_op3_podcast_series((int)$p['id'], $days) as $row) {
        $d = $row['date'] ?? null; $v = (int)($row['downloads'] ?? 0);
        if ($d) $series[$d] = ($series[$d] ?? 0) + $v;
      }
    }
    ksort($series);
    $max_dl = max(array_merge([1], $series));

    // Top apps + países + nuevas métricas agregadas
    $apps_agg = []; $countries_agg = [];
    $metros_agg = []; $latam_agg = []; $eu_agg = []; $as_agg = [];
    $devices_agg = []; $browsers_agg = [];

    foreach ($podcasts as $p) {
      foreach (kp_op3_top_apps((int)$p['id'], $days, 20) as $a) {
        $k = $a['app_name'] ?? 'Otros'; $apps_agg[$k] = ($apps_agg[$k] ?? 0) + (int)($a['downloads'] ?? 0);
      }
      foreach (kp_op3_top_countries((int)$p['id'], $days, 250) as $c) {
        $k = $c['country'] ?? 'XX'; $countries_agg[$k] = ($countries_agg[$k] ?? 0) + (int)($c['downloads'] ?? 0);
      }
      foreach (kp_op3_top_metros((int)$p['id'], $days, 20) as $m) {
        $k = $m['metro_code'] ?? 'Unknown'; $metros_agg[$k] = ($metros_agg[$k] ?? 0) + (int)($m['downloads'] ?? 0);
      }
      foreach (kp_op3_top_regions((int)$p['id'], 'SA', $days, 20) as $r) {
        $k = $r['region_name'] ?? 'Unknown'; $latam_agg[$k] = ($latam_agg[$k] ?? 0) + (int)($r['downloads'] ?? 0);
      }
      foreach (kp_op3_top_regions((int)$p['id'], 'EU', $days, 20) as $r) {
        $k = $r['region_name'] ?? 'Unknown'; $eu_agg[$k] = ($eu_agg[$k] ?? 0) + (int)($r['downloads'] ?? 0);
      }
      foreach (kp_op3_top_regions((int)$p['id'], 'AS', $days, 20) as $r) {
        $k = $r['region_name'] ?? 'Unknown'; $as_agg[$k] = ($as_agg[$k] ?? 0) + (int)($r['downloads'] ?? 0);
      }
      foreach (kp_op3_top_devices((int)$p['id'], $days, 20) as $d) {
        $k = $d['name'] ?? 'Unknown'; $devices_agg[$k] = ($devices_agg[$k] ?? 0) + (int)($d['downloads'] ?? 0);
      }
      foreach (kp_op3_top_browsers((int)$p['id'], $days, 20) as $b) {
        $k = $b['name'] ?? 'Unknown'; $browsers_agg[$k] = ($browsers_agg[$k] ?? 0) + (int)($b['downloads'] ?? 0);
      }
    }
    arsort($apps_agg); arsort($countries_agg);
    arsort($metros_agg); arsort($latam_agg); arsort($eu_agg); arsort($as_agg);
    arsort($devices_agg); arsort($browsers_agg);

    $apps_total = max(1, array_sum($apps_agg));
    $countries_total = max(1, array_sum($countries_agg));
    $metros_total = max(1, array_sum($metros_agg));
    $latam_total = max(1, array_sum($latam_agg));
    $eu_total = max(1, array_sum($eu_agg));
    $as_total = max(1, array_sum($as_agg));
    $devices_total = max(1, array_sum($devices_agg));
    $browsers_total = max(1, array_sum($browsers_agg));

    $cached_data = compact(
        'podcasts', 'has_op3', 'last_sync',
        'tot', 'tot_period', 'unique_listeners', 'series', 'max_dl',
        'apps_agg', 'countries_agg', 'metros_agg', 'latam_agg', 'eu_agg', 'as_agg', 'devices_agg', 'browsers_agg',
        'apps_total', 'countries_total', 'metros_total', 'latam_total', 'eu_total', 'as_total', 'devices_total', 'browsers_total'
    );
    kp_cache_set($cache_key, $cached_data, 1800); // 30 minutes
}

function rng_btn($k, $l, $cur, $tab = 'downloads', $page = 'analytics') {
  $active = $k === $cur ? ' active' : '';
  $tab_param = ($tab && $tab !== 'downloads') ? '&tab=' . urlencode($tab) : '';
  return "<a class=\"btn$active\" href=\"/admin/$page?range=$k$tab_param\">$l</a>";
}
?>
<div class="page-head">
  <div>
    <h1 class="page-title">Estadísticas</h1>
    <p class="page-sub">
      Métricas servidas por OP3 nativo · auditables · sin tracking de usuario
      <?php if ($last_sync): ?> · sincronizado <?= e(kp_relative_time($last_sync['updated_at'] ?? $last_sync['v'] ?? 0)) ?><?php endif; ?>
    </p>
  </div>
  <div class="row">
    <div class="seg">
      <?= rng_btn('7d','7 días', $range, $active_tab) ?>
      <?= rng_btn('30d','30 días', $range, $active_tab) ?>
      <?= rng_btn('mtd','Este mes', $range, $active_tab) ?>
      <?= rng_btn('all','Histórico', $range, $active_tab) ?>
    </div>
    <a class="btn" href="/api/v1/stats/export.csv?range=<?= e($range) ?>"><?= icon('download',13) ?> Exportar CSV</a>
  </div>
</div>

<?php if (!$has_op3 || $tot === 0): ?>
  <div class="card card-lg" style="text-align:center;padding:64px 24px">
    <div style="opacity:.4;margin-bottom:12px"><?= icon('chart',48) ?></div>
    <h3 style="margin:0 0 8px">Sin datos todavía</h3>
    <p class="muted" style="margin:0;max-width:520px;margin-inline:auto">
      OP3 acumula descargas en cuanto las URLs de tus feeds pasen por <code>/r/&lt;show&gt;/&lt;ep&gt;</code>.
      Las estadísticas aparecerán aquí en cuanto el worker procese los primeros logs.
    </p>
    <div class="row" style="justify-content:center;gap:8px;margin-top:18px">
      <a class="btn" href="/admin/preferences#op3"><?= icon('settings',13) ?> Configurar OP3</a>
      <code style="background:var(--surface-2);padding:6px 10px;border-radius:6px;font-size:12px">php cli/op3-worker.php</code>
    </div>
  </div>
<?php else: ?>

  <!-- Tarjetas de resumen global -->
  <div class="stat-grid">
    <?= stat_card('Descargas totales', number_format($tot), 'download') ?>
    <?= stat_card('Descargas · '.e($range), number_format($tot_period), 'chart') ?>
    <?= stat_card('Oyentes únicos', number_format($unique_listeners), 'users', ['sub'=>'identificados por OP3']) ?>
    <?= stat_card('Podcasts activos', (string)count($podcasts), 'mic') ?>
    <?php kp_do_action('analytics_stat_grid'); ?>
  </div>

  <!-- Barra de pestañas organizadas por tipo -->
  <div class="stats-tabs-nav" role="tablist">
    <button type="button" class="stats-tab-btn <?= $active_tab === 'downloads' ? 'active' : '' ?>" data-tab="downloads" role="tab" aria-selected="<?= $active_tab === 'downloads' ? 'true' : 'false' ?>">
      <?= icon('chart', 16) ?>
      <span>Descargas</span>
    </button>
    <button type="button" class="stats-tab-btn <?= $active_tab === 'location' ? 'active' : '' ?>" data-tab="location" role="tab" aria-selected="<?= $active_tab === 'location' ? 'true' : 'false' ?>">
      <?= icon('globe', 16) ?>
      <span>Localización</span>
      <?php if (!empty($countries_agg)): ?>
        <span class="tab-badge" title="<?= count($countries_agg) ?> países con descargas registradas"><?= count($countries_agg) ?></span>
      <?php endif; ?>
    </button>
    <button type="button" class="stats-tab-btn <?= $active_tab === 'tech' ? 'active' : '' ?>" data-tab="tech" role="tab" aria-selected="<?= $active_tab === 'tech' ? 'true' : 'false' ?>">
      <?= icon('layers', 16) ?>
      <span>Tecnología</span>
    </button>
  </div>

  <!-- PESTAÑA 1: DESCARGAS -->
  <div id="panel-downloads" class="stats-tab-panel <?= $active_tab === 'downloads' ? 'active' : '' ?>">
    <div class="grid-12" style="gap:24px">
      <!-- gráfico de barras interactivo con hover -->
      <div class="card card-lg span-8" style="padding:28px">
        <div class="card-head">
          <div>
            <div class="card-title">Descargas por día</div>
            <div class="page-sub" style="margin-top:4px;font-size:12.5px">Agregado entre todos los podcasts · últimos <?= count($series) ?> días (pasa el cursor para ver el total)</div>
          </div>
        </div>
        <div style="position:relative;height:260px;margin-top:24px;display:flex;align-items:flex-end;gap:5px">
          <?php foreach ($series as $d => $v):
            $h = $max_dl > 0 ? ($v / $max_dl) * 100 : 0;
            $pct_peak = $max_dl > 0 ? round(($v / $max_dl) * 100) : 0;
            $tip = e($d) . ': ' . number_format($v) . ' descargas (' . $pct_peak . '% del día pico)';
          ?>
            <div class="chart-day-bar-wrap" data-tooltip="<?= $tip ?>" data-label="<?= e($d) ?>" data-count="<?= number_format($v) ?>">
              <div class="chart-day-bar" style="height:<?= max(2, $h) ?>%"></div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- por podcast con hover tooltips y paginado a top 10 -->
      <?php
        $podcasts_sorted = [];
        foreach ($podcasts as $p) {
          $st = kp_op3_podcast_totals((int)$p['id'], $days);
          $podcasts_sorted[] = [
            'podcast' => $p,
            'downloads' => (int)$st['downloads'],
          ];
        }
        usort($podcasts_sorted, function($a, $b) {
          if ($b['downloads'] !== $a['downloads']) {
            return $b['downloads'] <=> $a['downloads'];
          }
          return strcasecmp($a['podcast']['title'], $b['podcast']['title']);
        });
      ?>
      <div class="card card-lg span-4 kp-paginated-card" style="padding:24px" data-page-size="5">
        <div class="card-title" style="display:flex;justify-content:space-between;align-items:center">
          <span>Por podcast</span>
          <span style="font-size:11px;color:var(--text-3);font-weight:400">Top 5 más activos</span>
        </div>
        <div class="kp-paginated-items col" style="gap:12px;margin-top:14px">
          <?php foreach ($podcasts_sorted as $ps):
            $p = $ps['podcast'];
            echo kp_stat_bar_row($p['title'], $ps['downloads'], (int)$tot_period, null, '/admin/podcast/' . urlencode($p['slug']) . '?tab=analytics');
          endforeach; ?>
        </div>
      </div>
    </div>

    <div class="grid-12" style="gap:24px;margin-top:24px;align-items:start">
      <!-- Top 5 episodios globales -->
      <div class="card card-lg span-6" style="padding:24px">
        <div class="card-title" style="display:flex;align-items:center;gap:8px">
          <span style="color:var(--accent);display:inline-flex"><?= icon('star', 16) ?></span>
          Top 5 Episodios
        </div>
        <div class="help" style="margin-top:6px;margin-bottom:14px">Los 5 episodios más descargados de todos los podcasts</div>
        <?php
          $top5 = kp_op3_top_episodes(5);
          if (empty($top5)):
        ?>
          <div class="muted" style="padding:24px 0;text-align:center">Sin datos de descargas acumuladas todavía</div>
        <?php else: ?>
          <div class="col" style="gap:12px">
            <?php foreach ($top5 as $idx => $ep):
              $p = kp_podcast_or_placeholder($ep['podcast']);
              $cover = $ep['cover'] ?: ($p['cover'] ?? null);
            ?>
              <div class="row" style="align-items:center;gap:12px;padding:8px 0;<?= $idx > 0 ? 'border-top:1px solid var(--border)' : '' ?>">
                <div style="font-size:16px;font-weight:700;color:var(--text-3);width:20px;text-align:center">#<?= $idx + 1 ?></div>
                <?php if ($cover): ?>
                  <img class="cover" src="<?= e($cover) ?>" style="width:36px;height:36px;border-radius:8px;object-fit:cover;flex-shrink:0" alt="">
                <?php else: ?>
                  <div class="cover" style="width:36px;height:36px;border-radius:8px;background:linear-gradient(135deg,<?= e($p['color']) ?>,<?= e($p['color']) ?>99);color:white;font-weight:700;font-size:11px;flex-shrink:0"><?= e($p['initial']) ?></div>
                <?php endif; ?>
                <div style="flex:1;min-width:0">
                  <div style="font-size:13.5px;font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                    <a href="/admin/new-episode?id=<?= urlencode($ep['id']) ?>&podcast=<?= urlencode($ep['podcast']) ?>" style="color:inherit;text-decoration:none" onmouseover="this.style.textDecoration='underline'" onmouseout="this.style.textDecoration='none'">
                      <?= e($ep['title']) ?>
                    </a>
                  </div>
                  <div style="font-size:11.5px;color:var(--text-3);margin-top:2px">
                    <?= e($p['title']) ?> · S<?= (int)$ep['s'] ?> · E<?= (int)$ep['n'] ?>
                  </div>
                </div>
                <div class="tabular" style="text-align:right">
                  <div style="font-size:13.5px;font-weight:600"><?= fmt_num((int)$ep['downloads']) ?></div>
                  <div style="font-size:10.5px;color:var(--text-3);margin-top:1px">descargas</div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <!-- Episodio líder por podcast (Top 5) -->
      <div class="card card-lg span-6 kp-paginated-card" style="padding:24px" data-page-size="5">
        <div class="card-title" style="display:flex;align-items:center;gap:8px">
          <span style="color:var(--accent);display:inline-flex"><?= icon('award', 16) ?></span>
          Líder de cada podcast
        </div>
        <div class="help" style="margin-top:6px;margin-bottom:14px">El episodio más descargado de cada show activo (ordenados por descargas)</div>
        <?php
          $leaders = [];
          foreach ($podcasts as $p) {
            $leader_ep = kp_op3_most_downloaded_episode((int)$p['id']);
            if ($leader_ep && $leader_ep['downloads'] > 0) {
              $full_p = kp_podcast_or_placeholder($p['slug']);
              $leaders[] = [
                'podcast_title' => $full_p['title'],
                'podcast_color' => $full_p['color'],
                'podcast_initial' => $full_p['initial'],
                'podcast_cover' => $full_p['cover'],
                'ep' => $leader_ep
              ];
            }
          }
          usort($leaders, function($a, $b) {
            return ((int)($b['ep']['downloads'] ?? 0)) <=> ((int)($a['ep']['downloads'] ?? 0));
          });
          if (empty($leaders)):
        ?>
          <div class="muted" style="padding:24px 0;text-align:center">Sin datos de descargas acumuladas todavía</div>
        <?php else: ?>
          <div class="kp-paginated-items col" style="gap:12px">
            <?php foreach ($leaders as $idx => $lead):
              $p_cover = $lead['podcast_cover'];
              $ep = $lead['ep'];
              $ep_cover = $ep['cover'] ?: $p_cover;
            ?>
              <div class="row" style="align-items:center;gap:12px;padding:8px 0;<?= $idx > 0 ? 'border-top:1px solid var(--border)' : '' ?>">
                <?php if ($ep_cover): ?>
                  <img class="cover" src="<?= e($ep_cover) ?>" style="width:36px;height:36px;border-radius:8px;object-fit:cover;flex-shrink:0" alt="">
                <?php else: ?>
                  <div class="cover" style="width:36px;height:36px;border-radius:8px;background:linear-gradient(135deg,<?= e($lead['podcast_color']) ?>,<?= e($lead['podcast_color']) ?>99);color:white;font-weight:700;font-size:11px;flex-shrink:0"><?= e($lead['podcast_initial']) ?></div>
                <?php endif; ?>
                <div style="flex:1;min-width:0">
                  <div style="font-size:13.5px;font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                    <a href="/admin/new-episode?id=<?= urlencode($ep['id']) ?>&podcast=<?= urlencode($ep['podcast']) ?>" style="color:inherit;text-decoration:none" onmouseover="this.style.textDecoration='underline'" onmouseout="this.style.textDecoration='none'">
                      <?= e($ep['title']) ?>
                    </a>
                  </div>
                  <div style="font-size:11.5px;color:var(--text-3);margin-top:2px">
                    Show: <strong style="color:var(--text-2)"><?= e($lead['podcast_title']) ?></strong> · S<?= (int)$ep['s'] ?> · E<?= (int)$ep['n'] ?>
                  </div>
                </div>
                <div class="tabular" style="text-align:right">
                  <div style="font-size:13.5px;font-weight:600"><?= fmt_num((int)$ep['downloads']) ?></div>
                  <div style="font-size:10.5px;color:var(--text-3);margin-top:1px">descargas</div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- PESTAÑA 2: LOCALIZACIÓN -->
  <div id="panel-location" class="stats-tab-panel <?= $active_tab === 'location' ? 'active' : '' ?>">
    <!-- MAPA INTERACTIVO GLOBAL -->
    <div class="card card-lg world-map-card">
      <div class="world-map-head">
        <div>
          <div class="card-title" style="display:flex;align-items:center;gap:8px">
            <?= icon('globe', 18) ?> Distribución geográfica de descargas
          </div>
          <div class="page-sub" style="margin-top:4px;font-size:12.5px">
            Pasa el cursor sobre un país para ver descargas y porcentaje. Los países con más descargas se muestran en un tono más oscuro.
          </div>
        </div>
        <div class="row" style="gap:8px">
          <?php if (!empty($countries_agg)): ?>
            <?php
              $top_cc = array_key_first($countries_agg);
              $top_cc_cnt = $countries_agg[$top_cc] ?? 0;
            ?>
            <div style="font-size:12px;color:var(--text-2);background:var(--surface-2);border:1px solid var(--border);padding:6px 12px;border-radius:20px;display:inline-flex;align-items:center;gap:6px">
              <span><?= kp_country_flag($top_cc) ?> Mayor audiencia: <strong><?= e(kp_country_name($top_cc)) ?></strong> (<?= number_format($top_cc_cnt) ?>)</span>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="world-map-container" id="world-map-wrapper">
        <div class="world-map-toolbar">
          <div class="world-map-legend">
            <span class="legend-label">0</span>
            <div class="legend-gradient-bar" title="Escala de color: tonos más oscuros representan mayor volumen de descargas"></div>
            <span class="legend-label" style="font-weight:600">Más descargas (tono más oscuro)</span>
            <?php if (!empty($countries_agg)): ?>
              <span class="legend-count-pill"><?= count($countries_agg) ?> países activos</span>
            <?php endif; ?>
          </div>
          <div class="world-map-actions">
            <button type="button" class="map-ctrl-btn" id="map-zoom-in" title="Acercar mapa">+</button>
            <button type="button" class="map-ctrl-btn" id="map-zoom-out" title="Alejar mapa">−</button>
            <button type="button" class="map-ctrl-btn" id="map-zoom-reset" title="Restablecer posición y zoom">↺</button>
          </div>
        </div>
        <div class="world-map-stage" id="world-map-stage">
          <?php
            $svg_path = __DIR__ . '/../assets/world-map.svg';
            if (file_exists($svg_path)) {
              echo file_get_contents($svg_path);
            }
          ?>
        </div>
        <div id="world-map-tooltip" class="world-map-tooltip" style="display:none"></div>
      </div>
    </div>

    <!-- Países, Metros y Regiones organizados en columnas compactas sin gaps -->
    <div class="stats-two-col" style="margin-top:24px">
      <!-- Columna 1: Países, LatAm, Asia -->
      <div class="stats-col">
        <!-- Países -->
        <div class="card card-lg kp-paginated-card" style="padding:24px" data-page-size="5">
          <div class="card-title">Países</div>
          <div class="help" style="margin-top:6px;margin-bottom:14px">Detección por cascada CF-IPCountry → GeoLite2 → ASN</div>
          <?php if (!$countries_agg): ?>
            <div class="muted" style="padding:24px 0;text-align:center">Sin datos de geolocalización todavía</div>
          <?php else: ?>
            <div class="kp-paginated-items col" style="gap:10px">
              <?php foreach ($countries_agg as $cc => $v):
                $flag = kp_country_flag($cc);
                $name = kp_country_name($cc);
                echo kp_stat_bar_row($name, (int)$v, (int)$countries_total, $flag);
              endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

        <!-- Regiones LatAm -->
        <div class="card card-lg kp-paginated-card" style="padding:24px" data-page-size="5">
          <div class="card-title">Top Latinoamérica</div>
          <div class="help" style="margin-top:6px;margin-bottom:14px">Regiones con mayor interacción en Sudamérica y Centroamérica</div>
          <?php if (!$latam_agg): ?>
            <div class="muted" style="padding:24px 0;text-align:center">Sin datos de Latinoamérica</div>
          <?php else: ?>
            <div class="kp-paginated-items col" style="gap:10px">
              <?php foreach ($latam_agg as $r => $v):
                echo kp_stat_bar_row((string)$r, (int)$v, (int)$latam_total);
              endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

        <!-- Regiones Asia -->
        <div class="card card-lg kp-paginated-card" style="padding:24px" data-page-size="5">
          <div class="card-title">Top Asia</div>
          <div class="help" style="margin-top:6px;margin-bottom:14px">Regiones con mayor interacción en Asia</div>
          <?php if (!$as_agg): ?>
            <div class="muted" style="padding:24px 0;text-align:center">Sin datos de Asia</div>
          <?php else: ?>
            <div class="kp-paginated-items col" style="gap:10px">
              <?php foreach ($as_agg as $r => $v):
                echo kp_stat_bar_row((string)$r, (int)$v, (int)$as_total);
              endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Columna 2: Metros U.S., Europa -->
      <div class="stats-col">
        <!-- Metros U.S. -->
        <div class="card card-lg kp-paginated-card" style="padding:24px" data-page-size="5">
          <div class="card-title">Top U.S. Metros</div>
          <div class="help" style="margin-top:6px;margin-bottom:14px">Mercados metropolitanos DMA (Nielsen)</div>
          <?php if (!$metros_agg): ?>
            <div class="muted" style="padding:24px 0;text-align:center">Sin datos de metros en EE.UU.</div>
          <?php else: ?>
            <div class="kp-paginated-items col" style="gap:10px">
              <?php foreach ($metros_agg as $m => $v):
                $name = dma_name((string)$m, $DMA_NAMES);
                echo kp_stat_bar_row($name, (int)$v, (int)$metros_total);
              endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

        <!-- Regiones Europa -->
        <div class="card card-lg kp-paginated-card" style="padding:24px" data-page-size="5">
          <div class="card-title">Top Europa</div>
          <div class="help" style="margin-top:6px;margin-bottom:14px">Regiones con mayor interacción en Europa</div>
          <?php if (!$eu_agg): ?>
            <div class="muted" style="padding:24px 0;text-align:center">Sin datos de Europa</div>
          <?php else: ?>
            <div class="kp-paginated-items col" style="gap:10px">
              <?php foreach ($eu_agg as $r => $v):
                echo kp_stat_bar_row((string)$r, (int)$v, (int)$eu_total);
              endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- PESTAÑA 3: TECNOLOGÍA -->
  <div id="panel-tech" class="stats-tab-panel <?= $active_tab === 'tech' ? 'active' : '' ?>">
    <div class="stats-two-col">
      <!-- Columna 1: Apps Podcasting 2.0 -->
      <div class="stats-col">
        <div class="card card-lg kp-paginated-card" style="padding:24px" data-page-size="5">
          <div class="card-title">Apps · Podcasting 2.0</div>
          <div class="help" style="margin-top:6px;margin-bottom:14px">Detectadas por <code>?aid=</code> en User-Agent vía Podcast Index</div>
          <?php if (!$apps_agg): ?>
            <div class="muted" style="padding:24px 0;text-align:center">Sin apps PC 2.0 detectadas todavía</div>
          <?php else: ?>
            <div class="kp-paginated-items col" style="gap:10px">
              <?php foreach ($apps_agg as $name => $v):
                echo kp_stat_bar_row((string)$name, (int)$v, (int)$apps_total);
              endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Columna 2: Navegadores + Dispositivos (apilados bajo navegadores para ahorrar espacio) -->
      <div class="stats-col">
        <!-- Navegadores -->
        <div class="card card-lg kp-paginated-card" style="padding:24px" data-page-size="5">
          <div class="card-title">Top Navegadores</div>
          <div class="help" style="margin-top:6px;margin-bottom:14px">Agentes y clientes web de reproducción</div>
          <?php if (!$browsers_agg): ?>
            <div class="muted" style="padding:24px 0;text-align:center">Sin datos de navegadores</div>
          <?php else: ?>
            <div class="kp-paginated-items col" style="gap:10px">
              <?php foreach ($browsers_agg as $b => $v):
                echo kp_stat_bar_row((string)$b, (int)$v, (int)$browsers_total);
              endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

        <!-- Dispositivos -->
        <div class="card card-lg kp-paginated-card" style="padding:24px" data-page-size="5">
          <div class="card-title">Top Dispositivos</div>
          <div class="help" style="margin-top:6px;margin-bottom:14px">Modelos y categorías de hardware</div>
          <?php if (!$devices_agg): ?>
            <div class="muted" style="padding:24px 0;text-align:center">Sin datos de dispositivos</div>
          <?php else: ?>
            <div class="kp-paginated-items col" style="gap:10px">
              <?php foreach ($devices_agg as $d => $v):
                echo kp_stat_bar_row((string)$d, (int)$v, (int)$devices_total);
              endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- JSON con datos de países para el mapa interactivo -->
  <script type="application/json" id="map-country-data" data-total="<?= (int)$countries_total ?>"><?= json_encode($countries_agg) ?></script>

  <!-- Script interactivo: Pestañas, Mapa interactivo & Tooltips en barras -->
  <script>
  // 1. GESTOR DE PESTAÑAS
  (function initStatsTabs() {
    const tabBtns = document.querySelectorAll('.stats-tab-btn');
    const panels = document.querySelectorAll('.stats-tab-panel');
    if (!tabBtns.length) return;

    tabBtns.forEach(btn => {
      btn.addEventListener('click', () => {
        const targetTab = btn.getAttribute('data-tab');
        if (!targetTab) return;

        tabBtns.forEach(b => {
          const isActive = b === btn;
          b.classList.toggle('active', isActive);
          b.setAttribute('aria-selected', isActive ? 'true' : 'false');
        });

        panels.forEach(p => {
          const isTarget = p.id === `panel-${targetTab}`;
          p.classList.toggle('active', isTarget);
        });

        // Actualizar URL sin recargar la página
        const url = new URL(window.location.href);
        if (targetTab === 'downloads') {
          url.searchParams.delete('tab');
        } else {
          url.searchParams.set('tab', targetTab);
        }
        window.history.replaceState({}, '', url.toString());

        // Actualizar enlaces de filtros de fecha para conservar pestaña activa
        document.querySelectorAll('.seg a.btn').forEach(link => {
          try {
            const linkUrl = new URL(link.href, window.location.origin);
            if (targetTab === 'downloads') {
              linkUrl.searchParams.delete('tab');
            } else {
              linkUrl.searchParams.set('tab', targetTab);
            }
            link.href = linkUrl.pathname + linkUrl.search;
          } catch(e) {}
        });
      });
    });
  })();

  // 2. TOOLTIP UNIVERSAL EN BARRAS DE ESTADÍSTICAS Y GRÁFICO DIARIO
  (function initBarTooltips() {
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
      const item = e.target.closest('[data-tooltip]');
      if (!item) {
        if (activeEl) {
          tooltip.classList.remove('visible');
          activeEl = null;
        }
        return;
      }

      const text = item.getAttribute('data-tooltip');
      if (!text) return;

      activeEl = item;
      tooltip.textContent = text;
      tooltip.classList.add('visible');
      updatePos(e.clientX, e.clientY);
    });

    document.addEventListener('mousemove', (e) => {
      if (activeEl) {
        updatePos(e.clientX, e.clientY);
      }
    });

    document.addEventListener('mouseout', (e) => {
      if (activeEl && !e.relatedTarget?.closest('[data-tooltip]')) {
        tooltip.classList.remove('visible');
        activeEl = null;
      }
    });
  })();

  // 3. MAPA INTERACTIVO (CHOROPLETH TONO OSCURO, ZOOM, PAN Y TOOLTIP)
  (function initInteractiveMap() {
    const mapDataEl = document.getElementById('map-country-data');
    if (!mapDataEl) return;

    let countryData = {};
    try {
      countryData = JSON.parse(mapDataEl.textContent || '{}');
    } catch(e) {}

    const totalDownloads = Number(mapDataEl.dataset.total) || 1;
    const svg = document.getElementById('world-map');
    const stage = document.getElementById('world-map-stage');
    const tooltip = document.getElementById('world-map-tooltip');
    const viewport = document.getElementById('world-map-viewport');

    if (!svg || !stage) return;

    // Traducción de nombres de país en español con fallback nativo
    const regionNames = (typeof Intl !== 'undefined' && Intl.DisplayNames)
      ? new Intl.DisplayNames(['es', 'en'], { type: 'region' })
      : null;

    const fallbackNames = {
      'ES': 'España', 'US': 'Estados Unidos', 'MX': 'México', 'CO': 'Colombia',
      'AR': 'Argentina', 'CL': 'Chile', 'PE': 'Perú', 'VE': 'Venezuela',
      'EC': 'Ecuador', 'GT': 'Guatemala', 'CU': 'Cuba', 'BO': 'Bolivia',
      'DO': 'República Dominicana', 'HN': 'Honduras', 'PY': 'Paraguay',
      'SV': 'El Salvador', 'NI': 'Nicaragua', 'CR': 'Costa Rica', 'PA': 'Panamá',
      'UY': 'Uruguay', 'PR': 'Puerto Rico', 'BR': 'Brasil', 'CA': 'Canadá',
      'DE': 'Alemania', 'FR': 'Francia', 'GB': 'Reino Unido', 'IT': 'Italia',
      'PT': 'Portugal', 'RU': 'Rusia', 'JP': 'Japón', 'CN': 'China',
      'IN': 'India', 'AU': 'Australia', 'NL': 'Países Bajos', 'BE': 'Bélgica',
      'CH': 'Suiza', 'SE': 'Suecia', 'NO': 'Noruega', 'DK': 'Dinamarca',
      'FI': 'Finlandia', 'PL': 'Polonia', 'AT': 'Austria', 'IE': 'Irlanda',
      'NZ': 'Nueva Zelanda', 'ZA': 'Sudáfrica', 'KR': 'Corea del Sur'
    };

    function getCountryName(code) {
      const c = code.toUpperCase();
      if (regionNames) {
        try {
          const name = regionNames.of(c);
          if (name && name !== c) return name;
        } catch(e) {}
      }
      return fallbackNames[c] || c;
    }

    function getFlagEmoji(code) {
      const c = code.toUpperCase();
      if (c.length !== 2 || !/^[A-Z]{2}$/.test(c)) return '🌐';
      return String.fromCodePoint(...[...c].map(char => 127397 + char.charCodeAt(0)));
    }

    // Normalizar datos y encontrar máximo
    const normData = {};
    let maxDownloads = 0;
    for (const [code, count] of Object.entries(countryData)) {
      const c = code.toLowerCase().trim();
      const val = Number(count) || 0;
      normData[c] = val;
      if (val > maxDownloads) maxDownloads = val;
    }

    // Colorear países en escala donde más descargas = tono más oscuro
    for (const [code, val] of Object.entries(normData)) {
      if (val <= 0) continue;
      const el = svg.querySelector('#' + code);
      if (!el) continue;

      el.setAttribute('data-country', code);
      el.setAttribute('data-downloads', val);

      const ratio = maxDownloads > 0 ? (Math.log(val + 1) / Math.log(maxDownloads + 1)) : 0;
      // Ligereza disminuye con más descargas (más descargas = tono azul más oscuro y profundo)
      const lightness = Math.round(76 - (ratio * 52)); // 76% (azul cielo claro) -> 24% (azul marino oscuro)
      const saturation = Math.round(75 + (ratio * 20)); // 75% -> 95%
      const fillColor = `hsl(218, ${saturation}%, ${lightness}%)`;

      if (el.tagName.toLowerCase() === 'g') {
        el.querySelectorAll('path').forEach(p => {
          p.style.fill = fillColor;
          p.setAttribute('data-country', code);
        });
      } else {
        el.style.fill = fillColor;
      }
    }

    // Identificar todos los demás elementos de países en el mapa
    svg.querySelectorAll('[id]').forEach(el => {
      const id = el.id.toLowerCase();
      if (id.length === 2 && /^[a-z]{2}$/.test(id)) {
        el.setAttribute('data-country', id);
        if (el.tagName.toLowerCase() === 'g') {
          el.querySelectorAll('path').forEach(p => p.setAttribute('data-country', id));
        }
      }
    });

    // Control de hover y tooltip
    let currentHoverEl = null;

    function showTooltip(code, clientX, clientY) {
      if (!tooltip) return;
      const val = normData[code] || 0;
      const pct = totalDownloads > 0 ? ((val / totalDownloads) * 100).toFixed(1) : '0';
      const flag = getFlagEmoji(code);
      const name = getCountryName(code);

      let bodyHtml = '';
      if (val > 0) {
        bodyHtml = `
          <div class="tooltip-downloads">${Number(val).toLocaleString()} descargas</div>
          <div class="tooltip-pct"><strong>${pct}%</strong> del total mundial</div>
        `;
      } else {
        bodyHtml = `
          <div class="tooltip-downloads" style="color:var(--text-3);font-size:13px">0 descargas (0%)</div>
          <div class="tooltip-pct" style="color:var(--text-3)">Sin descargas registradas en este período</div>
        `;
      }

      tooltip.innerHTML = `
        <div class="tooltip-header">
          <span class="tooltip-flag">${flag}</span>
          <span>${name}</span>
          <span class="tooltip-code">${code.toUpperCase()}</span>
        </div>
        <div class="tooltip-body">
          ${bodyHtml}
        </div>
      `;

      tooltip.style.display = 'block';
      positionTooltip(clientX, clientY);
    }

    function positionTooltip(clientX, clientY) {
      if (!tooltip) return;
      const stageRect = stage.getBoundingClientRect();
      const relX = clientX - stageRect.left;
      const relY = clientY - stageRect.top;
      tooltip.style.left = `${relX}px`;
      tooltip.style.top = `${relY}px`;
    }

    function hideTooltip() {
      if (tooltip) tooltip.style.display = 'none';
      if (currentHoverEl) {
        currentHoverEl.classList.remove('is-hovered');
        currentHoverEl = null;
      }
    }

    stage.addEventListener('mousemove', (e) => {
      const target = e.target;
      const countryEl = target.closest('[data-country]');
      if (countryEl) {
        const code = countryEl.getAttribute('data-country');
        if (code) {
          if (currentHoverEl !== countryEl) {
            if (currentHoverEl) currentHoverEl.classList.remove('is-hovered');
            countryEl.classList.add('is-hovered');
            // Traer al frente en el SVG para sobresalir sobre las fronteras
            const parent = countryEl.parentNode;
            if (parent && parent.appendChild) {
              parent.appendChild(countryEl);
            }
            currentHoverEl = countryEl;
          }
          showTooltip(code, e.clientX, e.clientY);
          return;
        }
      }
      hideTooltip();
    });

    stage.addEventListener('mouseleave', () => {
      hideTooltip();
    });

    // Motor de Zoom y Paneo
    let scale = 1;
    let translateX = 0;
    let translateY = 0;
    let isDragging = false;
    let startX = 0;
    let startY = 0;

    function updateTransform() {
      if (!viewport) return;
      viewport.style.transform = `translate(${translateX}px, ${translateY}px) scale(${scale})`;
      viewport.style.transformOrigin = 'center center';
    }

    const btnZoomIn = document.getElementById('map-zoom-in');
    const btnZoomOut = document.getElementById('map-zoom-out');
    const btnZoomReset = document.getElementById('map-zoom-reset');

    if (btnZoomIn) {
      btnZoomIn.addEventListener('click', (e) => {
        e.stopPropagation();
        scale = Math.min(scale * 1.35, 7);
        updateTransform();
      });
    }
    if (btnZoomOut) {
      btnZoomOut.addEventListener('click', (e) => {
        e.stopPropagation();
        scale = Math.max(scale / 1.35, 0.75);
        if (scale <= 1) { translateX = 0; translateY = 0; }
        updateTransform();
      });
    }
    if (btnZoomReset) {
      btnZoomReset.addEventListener('click', (e) => {
        e.stopPropagation();
        scale = 1;
        translateX = 0;
        translateY = 0;
        updateTransform();
      });
    }

    stage.addEventListener('mousedown', (e) => {
      if (e.button !== 0) return;
      isDragging = true;
      startX = e.clientX - translateX;
      startY = e.clientY - translateY;
      stage.style.cursor = 'grabbing';
    });

    window.addEventListener('mousemove', (e) => {
      if (!isDragging) return;
      translateX = e.clientX - startX;
      translateY = e.clientY - startY;
      updateTransform();
    });

    window.addEventListener('mouseup', () => {
      if (isDragging) {
        isDragging = false;
        stage.style.cursor = 'grab';
      }
    });

    stage.addEventListener('wheel', (e) => {
      e.preventDefault();
      const factor = e.deltaY < 0 ? 1.15 : 0.87;
      scale = Math.min(Math.max(scale * factor, 0.75), 7);
      if (scale <= 1) { translateX = 0; translateY = 0; }
      updateTransform();
    }, { passive: false });

  })();

  // 4. PAGINADOR UNIVERSAL DE TARJETAS (TOP 10 + NAVEGACIÓN + MOSTRAR TODOS)
  (function initCardPagination() {
    document.querySelectorAll('.kp-paginated-card').forEach(card => {
      const pageSize = parseInt(card.dataset.pageSize || '10', 10);
      const container = card.querySelector('.kp-paginated-items');
      if (!container) return;

      const items = Array.from(container.children);
      const total = items.length;
      if (total <= pageSize) return; // Si hay 10 o menos no necesita paginador

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
        if (showAll) {
          items.forEach(el => el.style.display = '');
          infoEl.textContent = `Mostrando todos (${total})`;
          toggleBtn.textContent = 'Ver top ' + pageSize;
          prevBtn.style.display = 'none';
          nextBtn.style.display = 'none';
          pagesSpan.style.display = 'none';
        } else {
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
