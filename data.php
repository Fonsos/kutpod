<?php
// ============================================================================
// KutPod · acceso a datos · SQLite (sin demos)
// ============================================================================
// Todas las funciones leen exclusivamente de la BD real. Si una tabla está
// vacía las páginas reciben arrays vacíos y muestran sus empty states.

require_once __DIR__ . '/includes/db.php';

/** Lista de podcasts visibles + métricas agregadas desde episodes/op3_stats. */
function kp_podcasts(bool $force = false): array {
  static $cache = null;
  if ($cache !== null && !$force) return $cache;
  try {
    $rows = kp_q("
      SELECT
        p.id                                    AS db_id,
        p.slug                                  AS id,
        p.title                                 AS title,
        p.author                                AS author,
        p.description                           AS description,
        p.cover                                 AS cover,
        COALESCE(p.color,'#6b7280')             AS color,
        p.apple_url                             AS apple_url,
        p.spotify_url                           AS spotify_url,
        p.broadcast_links                       AS broadcast_links,
        p.parental                              AS parental,
        p.type                                  AS type,
        substr(upper(p.title),1,2)              AS initial,
        COALESCE(p.category,'—')                AS cat,
        p.meta_json                             AS meta_json,
        (SELECT count(*) FROM episodes e
           WHERE e.podcast_id = p.id AND e.hidden = 0) AS episodes,
        (SELECT COALESCE(SUM(s.downloads),0) FROM op3_stats_daily s
           WHERE s.podcast_id = p.id)           AS downloads,
        (SELECT COALESCE(SUM(s.downloads),0) FROM op3_stats_daily s
           WHERE s.podcast_id = p.id AND s.date >= date('now','-7 day')) AS downloads7,
        (SELECT COALESCE(SUM(s.unique_listeners),0) FROM op3_stats_daily s
           WHERE s.podcast_id = p.id) AS unique_listeners,
        0                                       AS growth,
        p.fixed_notes                           AS fixed_notes,
        p.op3                                   AS op3,
        p.guid                                  AS guid,
        p.federate                              AS federate,
        p.fediverse_handle                      AS fediverse_handle
      FROM podcasts p
      WHERE p.hidden = 0
      ORDER BY p.title ASC
    ");
    return $cache = ($rows ?: []);
  } catch (Throwable $e) {
    return [];
  }
}

function kp_check_scheduled() {
  static $checked = false;
  if ($checked) return;
  $checked = true;

  try {
    $pdo = kp_db();
    $now = gmdate('Y-m-d H:i:s');
    $stmt = $pdo->prepare("SELECT id, podcast_id, slug, title FROM episodes WHERE status = 'scheduled' AND COALESCE(published_at, publish_at, created_at) <= ?");
    $stmt->execute([$now]);
    $pending = $stmt->fetchAll();
    if (!$pending) return;

    $stmt = $pdo->prepare("UPDATE episodes SET status = 'published', published_at = COALESCE(published_at, publish_at, created_at, ?) WHERE status = 'scheduled' AND COALESCE(published_at, publish_at, created_at) <= ?");
    $stmt->execute([$now, $now]);

    $domain = kp_setting('instance_domain', $_SERVER['HTTP_HOST'] ?? 'localhost');
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";

    foreach ($pending as $ep) {
      $p = kp_find_podcast((string)$ep['podcast_id']);
      if ($p) {
        $feed_url = kp_canonical_feed_url($p['id'] ?? '');
        if (function_exists('kp_notify_websub')) kp_notify_websub($feed_url);
      }
      
      // Lanzar gancho de publicación de episodio
      try {
        $ep_url = $protocol . $domain . '/@' . ($p['id'] ?? '') . '/' . $ep['slug'];
        kp_do_action('episode_published', (int)$ep['id'], (int)$ep['podcast_id'], $ep['title'], '', $ep_url);
      } catch (Throwable $e) {}
    }
  } catch (Throwable $e) {}
}

/** Episodios recientes globales (todos los podcasts), pre-formateados para tablas. */
function kp_episodes(bool $all = false, ?string $podcastSlug = null, ?int $limit = null): array {
  kp_check_scheduled();
  try {
    $where = $all ? "WHERE e.hidden = 0" : "WHERE e.hidden = 0
        AND (
          e.status = 'published' 
          OR (e.status = 'scheduled' AND COALESCE(e.published_at, e.publish_at, e.created_at) <= :now_utc)
        )";
    $params_q = $all ? [] : [':now_utc' => gmdate('Y-m-d H:i:s')];

    if ($podcastSlug !== null && $podcastSlug !== '') {
      $where .= " AND LOWER(p.slug) = :pslug";
      $params_q[':pslug'] = strtolower($podcastSlug);
    }

    $limit_sql = ($limit !== null && $limit > 0) ? "LIMIT " . (int)$limit : "";

    $rows = kp_q("
      SELECT
        e.id                                    AS db_id,
        e.slug                                  AS id,
        p.slug                                  AS podcast,
        COALESCE(e.season,1)                    AS s,
        COALESCE(e.number,0)                    AS n,
        e.ep_type                               AS type,
        e.title                                 AS title,
        COALESCE(e.guest,'—')                   AS guest,
        e.duration_secs                         AS duration_secs,
        (SELECT COALESCE(SUM(s.downloads),0) FROM op3_stats_daily s WHERE s.episode_id = e.id) AS downloads,
        e.status                                AS status,
        e.cover                                 AS cover,
        e.audio_url                             AS audio_url,
        e.audio_bytes                           AS audio_bytes,
        e.transcript_url                        AS transcript_url,
        e.chapters_url                          AS chapters_url,
        e.notes_md                              AS notes_md,
        e.persons_json                          AS persons_json,
        e.meta_json                             AS meta_json,
        COALESCE(e.published_at, e.publish_at, e.created_at) AS date_iso
      FROM episodes e
      JOIN podcasts p ON p.id = e.podcast_id
      $where
      ORDER BY COALESCE(e.published_at, e.publish_at, e.created_at) DESC
      $limit_sql
    ", $params_q);
    foreach ($rows as &$r) {
      $r['duration'] = kp_fmt_duration((int)($r['duration_secs'] ?? 0));
      $r['date']     = kp_fmt_relative_date($r['date_iso'] ?? '');
    }
    return $rows ?: [];
  } catch (Throwable $e) {
    return [];
  }
}

function kp_episodes_of(string $podcastSlug, bool $all = false, bool $reverseSerial = true): array {
  $podcastSlug = strtolower($podcastSlug);
  $eps = kp_episodes($all, $podcastSlug);
  $p = kp_find_podcast($podcastSlug);
  if ($reverseSerial && $p && ($p['type'] ?? 'episodic') === 'serial') {
    $eps = array_reverse($eps);
  }
  return $eps;
}

function kp_find_podcast(string $id): ?array {
  if ($id === '') return null;
  $id = strtolower($id);
  foreach (kp_podcasts() as $p) if (strtolower($p['id']) === $id) return $p;
  return null;
}

/** Nunca devuelve null: si el slug no existe sintetiza un placeholder gris. */
function kp_podcast_or_placeholder(string $id): array {
  $p = kp_find_podcast($id);
  if ($p) return $p;
  $init = strtoupper(substr($id ?: '?', 0, 2));
  return ['id'=>$id, 'title'=>$id ?: '—', 'initial'=>$init, 'color'=>'#6b7280',
          'episodes'=>0, 'downloads'=>0, 'downloads7'=>0, 'unique_listeners'=>0, 'growth'=>0, 'cat'=>'—', 'author'=>'', 'cover'=>'', 'fixed_notes'=>''];
}

function kp_find_episode(string $id, string $podcastSlug = ''): ?array {
  if ($id === '') return null;
  $id = strtolower($id);
  $podcastSlug = strtolower($podcastSlug);
  foreach (kp_episodes(true, $podcastSlug ?: null) as $e) {
    if (strtolower($e['id']) === $id && ($podcastSlug === '' || strtolower($e['podcast']) === $podcastSlug)) return $e;
  }
  return null;
}

/** Posts del blog editorial · si no hay tabla pages_blog devuelve []. */
function kp_posts(): array {
  try {
    $rows = kp_q("SELECT id, title, tag, strftime('%b %d, %Y', created_at) as date, excerpt
                  FROM posts WHERE published = 1 ORDER BY created_at DESC LIMIT 6");
    return $rows ?: [];
  } catch (Throwable $e) {
    return [];
  }
}

// ---------------------------------------------------------------------------
// Helpers de formato
// ---------------------------------------------------------------------------

function kp_fmt_duration(int $s): string {
  if ($s <= 0) return '—';
  $h = intdiv($s, 3600); $m = intdiv($s % 3600, 60); $sec = $s % 60;
  return $h > 0 ? sprintf('%d:%02d:%02d', $h, $m, $sec) : sprintf('%d:%02d', $m, $sec);
}

function kp_fmt_relative_date(string $iso): string {
  if (!$iso) return '—';
  if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $iso)) {
    $iso .= ' UTC';
  }
  $ts = strtotime($iso); if (!$ts) return '—';
  $diff = max(0, time() - $ts);
  if ($diff < 60)         return 'ahora';
  if ($diff < 3600)       return intdiv($diff, 60) . ' min';
  if ($diff < 86400)      return intdiv($diff, 3600) . ' h';
  if ($diff < 7*86400)    return intdiv($diff, 86400) . ' d';
  return date('M j', $ts);
}
