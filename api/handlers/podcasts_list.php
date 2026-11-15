<?php
/**
 * php/api/handlers/podcasts_list.php
 *
 * GET /api/podcasts
 * Devuelve los podcasts cuyo owner es el usuario autenticado.
 */

declare(strict_types=1);

$user = api_require_user();
$pdo  = kp_db();

$is_admin = in_array($user['role'], ['owner', 'admin'], true);

if ($is_admin) {
    $st = $pdo->prepare("
        SELECT id, slug, title AS name, author, cover, banner, description,
               language, category, 
               (SELECT count(*) FROM episodes e WHERE e.podcast_id = podcasts.id AND e.hidden = 0) AS episode_count,
               (SELECT season FROM episodes e WHERE e.podcast_id = podcasts.id AND e.hidden = 0 ORDER BY publish_at DESC LIMIT 1) AS last_season,
               (SELECT number FROM episodes e WHERE e.podcast_id = podcasts.id AND e.hidden = 0 ORDER BY publish_at DESC LIMIT 1) AS last_episode,
               created_at
        FROM podcasts
        WHERE complete = 0
        ORDER BY title COLLATE NOCASE ASC
    ");
    $st->execute();
} else {
    $st = $pdo->prepare("
        SELECT p.id, p.slug, p.title AS name, p.author, p.cover, p.banner, p.description,
               p.language, p.category, 
               (SELECT count(*) FROM episodes e WHERE e.podcast_id = p.id AND e.hidden = 0) AS episode_count,
               (SELECT season FROM episodes e WHERE e.podcast_id = p.id AND e.hidden = 0 ORDER BY publish_at DESC LIMIT 1) AS last_season,
               (SELECT number FROM episodes e WHERE e.podcast_id = p.id AND e.hidden = 0 ORDER BY publish_at DESC LIMIT 1) AS last_episode,
               p.created_at
        FROM podcasts p
        JOIN podcast_users pu ON pu.podcast_id = p.id
        WHERE pu.user_id = :uid AND p.complete = 0
        ORDER BY p.title COLLATE NOCASE ASC
    ");
    $st->execute([':uid' => $user['id']]);
}
$rows = $st->fetchAll();

// Normalizar URLs absolutas si está configurado el dominio
$base = 'https://' . kp_handle_domain();

foreach ($rows as &$r) {
    if (!empty($r['cover'])  && $base && !str_starts_with(strtolower($r['cover']), 'http')) {
        $r['cover'] = $base . '/' . ltrim($r['cover'], '/');
    }
    if (!empty($r['banner']) && $base && !str_starts_with(strtolower($r['banner']), 'http')) {
        $r['banner'] = $base . '/' . ltrim($r['banner'], '/');
    }
}

api_json($rows);
