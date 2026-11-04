<?php
// pages/search.php — Resultados de búsqueda
require_once __DIR__ . '/../includes/helpers.php';

$q = trim($_GET['q'] ?? '');
$qLower = kp_unaccent(mb_strtolower($q, 'UTF-8'));
$isAjax = isset($_GET['ajax']) || isset($_GET['json']) || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');

$podcasts = [];
$episodes = [];

if ($q !== '') {
    // Filtrar podcasts: título, autor/nombre, descripción, categoría, slug
    foreach (kp_podcasts() as $p) {
        $pTitle = $p['title'] ?? '';
        $pAuthor = $p['author'] ?? '';
        $pDesc = $p['description'] ?? '';
        $pSlug = $p['id'] ?? '';
        $pCat = $p['cat'] ?? '';
        
        $pSearchable = kp_unaccent(mb_strtolower($pTitle . ' ' . $pAuthor . ' ' . $pDesc . ' ' . $pSlug . ' ' . $pCat, 'UTF-8'));
        if (str_contains($pSearchable, $qLower)) {
            $podcasts[] = $p;
        }
    }

    // Filtrar episodios: título, descripción/notas (notes_md), invitado (guest), personas (persons_json), nombre de podcast
    foreach (kp_episodes(true) as $ep) {
        $epTitle = $ep['title'] ?? '';
        $epGuest = ($ep['guest'] && $ep['guest'] !== '—') ? $ep['guest'] : '';
        $epNotes = $ep['notes_md'] ?? '';
        $p = kp_podcast_or_placeholder($ep['podcast'] ?? '');
        $podcastTitle = $p['title'] ?? $ep['podcast'] ?? '';
        
        $personsText = '';
        if (!empty($ep['persons_json'])) {
            $persons = json_decode($ep['persons_json'], true);
            if (is_array($persons)) {
                foreach ($persons as $person) {
                    $personsText .= ' ' . ($person['name'] ?? '');
                }
            }
        }
        
        $epSearchable = kp_unaccent(mb_strtolower($epTitle . ' ' . $epGuest . ' ' . $epNotes . ' ' . $podcastTitle . ' ' . $personsText, 'UTF-8'));
        if (str_contains($epSearchable, $qLower)) {
            $ep['podcast_title'] = $podcastTitle;
            $episodes[] = $ep;
        }
    }
}

if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    
    $podcastsJson = array_map(function($p) {
        return [
            'id' => $p['id'],
            'title' => $p['title'],
            'author' => $p['author'] ?: 'Sin autor',
            'description' => mb_strimwidth(strip_tags($p['description'] ?? ''), 0, 100, '...'),
            'cover' => $p['cover'] ?: null,
            'color' => $p['color'] ?? '#6b7280',
            'initial' => $p['initial'] ?? substr($p['title'], 0, 2),
            'url' => '/admin/podcast?id=' . urlencode($p['id']),
        ];
    }, array_slice($podcasts, 0, 6));

    $episodesJson = array_map(function($ep) {
        $p = kp_podcast_or_placeholder($ep['podcast'] ?? '');
        $cover = $ep['cover'] ?: ($p['cover'] ?? null);
        return [
            'id' => $ep['id'],
            'db_id' => $ep['db_id'] ?? null,
            'title' => $ep['title'],
            'podcast' => $ep['podcast'],
            'podcast_title' => $ep['podcast_title'] ?? ($p['title'] ?? $ep['podcast']),
            'guest' => ($ep['guest'] && $ep['guest'] !== '—') ? $ep['guest'] : '',
            'description' => mb_strimwidth(strip_tags($ep['notes_md'] ?? ''), 0, 100, '...'),
            'season' => (int)($ep['s'] ?? 1),
            'episode' => (int)($ep['n'] ?? 0),
            'date' => $ep['date'] ?? '',
            'status' => $ep['status'] ?? 'draft',
            'cover' => $cover,
            'color' => $p['color'] ?? '#6b7280',
            'initial' => $p['initial'] ?? 'EP',
            'url' => '/admin/new-episode?id=' . urlencode($ep['id']) . '&podcast=' . urlencode($ep['podcast']),
        ];
    }, array_slice($episodes, 0, 8));

    echo json_encode([
        'query' => $q,
        'total' => count($podcasts) + count($episodes),
        'total_podcasts' => count($podcasts),
        'total_episodes' => count($episodes),
        'podcasts' => $podcastsJson,
        'episodes' => $episodesJson,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$totalResults = count($podcasts) + count($episodes);
?>
<div class="page-head">
  <div>
    <h1 class="page-title">Resultados de búsqueda</h1>
    <p class="page-sub">
      <?php if ($q === ''): ?>
        Escribe un término en la barra superior.
      <?php else: ?>
        Mostrando <?= $totalResults ?> resultado<?= $totalResults === 1 ? '' : 's' ?> para "<?= e($q) ?>"
      <?php endif; ?>
    </p>
  </div>
</div>

<?php if ($q === ''): ?>
  <div class="card" style="padding:60px 40px;text-align:center;margin-top:var(--gap)">
    <div style="color:var(--text-3);margin-bottom:12px"><?= icon('search',32) ?></div>
    <div style="font-size:16px;font-weight:500">¿Qué deseas buscar?</div>
    <div class="page-sub" style="margin-top:6px">Ingresa el título, descripción o nombre de un podcast o episodio en el buscador.</div>
  </div>
<?php else: ?>
  <?php if ($totalResults === 0): ?>
    <div class="card" style="padding:60px 40px;text-align:center;margin-top:var(--gap)">
      <div style="color:var(--text-3);margin-bottom:12px"><?= icon('search',32) ?></div>
      <div style="font-size:16px;font-weight:500">No hay coincidencias</div>
      <div class="page-sub" style="margin-top:6px">No se encontraron podcasts ni episodios para "<?= e($q) ?>".</div>
    </div>
  <?php else: ?>
    
    <?php if (!empty($podcasts)): ?>
      <div class="card" style="margin-top:var(--gap)">
        <div class="card-head"><div class="card-title">Podcasts encontrados (<?= count($podcasts) ?>)</div></div>
        <div class="list">
          <?php foreach ($podcasts as $p): ?>
            <a class="row" style="padding:14px 16px;border-top:1px solid var(--border);text-decoration:none;color:var(--text);align-items:center;gap:14px" href="/admin/podcast?id=<?= e($p['id']) ?>">
              <?php if ($p['cover']): ?>
                <img class="cover" src="<?= e($p['cover']) ?>" style="width:48px;height:48px;border-radius:10px;object-fit:cover;flex-shrink:0" alt="">
              <?php else: ?>
                <div class="cover" style="width:48px;height:48px;border-radius:10px;background:linear-gradient(135deg,<?= e($p['color']) ?>,<?= e($p['color']) ?>99);color:white;font-weight:700;display:grid;place-items:center;font-size:16px;flex-shrink:0"><?= e($p['initial']) ?></div>
              <?php endif; ?>
              <div style="flex:1;min-width:0">
                <div style="font-size:14.5px;font-weight:600"><?= e($p['title']) ?></div>
                <div style="font-size:12px;color:var(--text-3);margin-top:2px"><?= e($p['author'] ?: 'Sin autor') ?> · <?= (int)($p['episodes'] ?? 0) ?> episodios</div>
                <?php if (!empty($p['description'])): ?>
                  <div style="font-size:12.5px;color:var(--text-2);margin-top:4px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;line-height:1.4">
                    <?= e(strip_tags($p['description'])) ?>
                  </div>
                <?php endif; ?>
              </div>
              <span class="btn" style="padding:5px 12px;font-size:12px;color:var(--accent);border-color:var(--accent);flex-shrink:0">Administrar →</span>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

    <?php if (!empty($episodes)): ?>
      <div class="card" style="margin-top:var(--gap)">
        <div class="card-head"><div class="card-title">Episodios encontrados (<?= count($episodes) ?>)</div></div>
        <div class="list">
          <div style="overflow-x:auto;">
            <div style="min-width: 700px;">
              <?= ep_header() ?>
              <?php foreach ($episodes as $ep) echo ep_row($ep); ?>
            </div>
          </div>
        </div>
      </div>
    <?php endif; ?>

  <?php endif; ?>
<?php endif; ?>
