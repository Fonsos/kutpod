<?php
/**
 * php/api/handlers/episodes_list.php
 *
 * GET /api/podcasts/{id}/episodes?page=N&per_page=10&q=texto
 *
 * Devuelve listado paginado con búsqueda por título y descripción.
 *
 * Respuesta: {
 *   items: [ { id, slug, title, description, episode, season,
 *              audio_url, cover, duration, published_at, status } ],
 *   total, page, pages, per_page
 * }
 */

declare(strict_types=1);

$user      = api_require_user();
$podcastId = $GLOBALS['_podcast_id'] ?? '';
$podcast   = api_require_podcast_owner((int)$user['id'], $podcastId);

[$page, $perPage, $offset] = api_paging(10, 100);
$q = trim((string)($_GET['q'] ?? ''));

$pdo = kp_db();
$where = "podcast_id = :pid";
$params = [':pid' => $podcast['id']];

if ($q !== '') {
    $where .= " AND (title LIKE :q OR notes_md LIKE :q)";
    $params[':q'] = '%' . $q . '%';
}

// Total
$cnt = $pdo->prepare("SELECT COUNT(*) AS c FROM episodes WHERE $where");
$cnt->execute($params);
$total = (int)$cnt->fetch()['c'];
$pages = max(1, (int)ceil($total / $perPage));

// Items
$params[':lim'] = $perPage;
$params[':off'] = $offset;
$st = $pdo->prepare("
    SELECT id, slug, title, notes_md AS description, number AS episode, season,
           audio_url, cover, duration_secs AS duration,
           COALESCE(published_at, publish_at, created_at) AS published_at, status,
           ep_type AS episode_type, parental AS explicit
    FROM episodes
    WHERE $where
    ORDER BY COALESCE(published_at, publish_at, created_at) DESC, id DESC
    LIMIT :lim OFFSET :off
");
foreach ($params as $k => $v) {
    if (in_array($k, [':lim', ':off'], true)) {
        $st->bindValue($k, $v, PDO::PARAM_INT);
    } else {
        $st->bindValue($k, $v);
    }
}
$st->execute();
$items = $st->fetchAll();

api_json([
    'items'    => $items,
    'total'    => $total,
    'page'     => $page,
    'per_page' => $perPage,
    'pages'    => $pages,
    'query'    => $q,
]);
