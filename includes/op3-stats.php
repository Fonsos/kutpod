<?php
// ============================================================================
// KutPod · helpers de analytics OP3 · solo datos reales (sin mocks)
// ============================================================================
require_once __DIR__ . '/db.php';

function kp_op3_tables_exist(): bool {
  static $exists = null;
  if ($exists !== null) return $exists;
  try { kp_one("SELECT 1 FROM op3_stats_daily LIMIT 1"); return $exists = true; }
  catch (Throwable $e) { return $exists = false; }
}

function kp_op3_podcast_totals(int $podcastId, int $days = 7): array {
  $zero = ['downloads'=>0,'listeners'=>0,'bots'=>0];
  if (!kp_op3_tables_exist()) return $zero;
  $r = kp_one(
    "SELECT COALESCE(sum(downloads),0) downloads,
            COALESCE(sum(unique_listeners),0) listeners,
            COALESCE(sum(bot_downloads),0) bots
     FROM op3_stats_daily WHERE podcast_id = ? AND date >= date('now', ?)",
    [$podcastId, '-' . $days . ' day']
  );
  return $r ?: $zero;
}

function kp_op3_podcast_series(int $podcastId, int $days = 30): array {
  if (!kp_op3_tables_exist()) return [];
  return kp_q(
    "SELECT date, sum(downloads) downloads FROM op3_stats_daily
     WHERE podcast_id = ? AND date >= date('now', ?) GROUP BY date ORDER BY date",
    [$podcastId, '-' . $days . ' day']
  );
}

function kp_op3_all_podcasts_series(int $days = 30): array {
  if (!kp_op3_tables_exist()) return [];
  return kp_q(
    "SELECT podcast_id, date, sum(downloads) downloads FROM op3_stats_daily
     WHERE date >= date('now', ?) GROUP BY podcast_id, date ORDER BY date",
    ['-' . $days . ' day']
  );
}

function kp_op3_top_apps(int $podcastId, int $days = 30, int $limit = 8): array {
  if (!kp_op3_tables_exist()) return [];
  $rows = kp_q(
    "SELECT a.app_name, sum(a.downloads) as downloads FROM op3_apps_daily a
     JOIN episodes e ON e.id = a.episode_id
     WHERE e.podcast_id = ? AND a.date >= date('now', ?)
     GROUP BY a.app_name ORDER BY downloads DESC LIMIT ?",
    [$podcastId, '-' . $days . ' day', $limit]
  );
  $total = array_sum(array_column($rows, 'downloads'));
  foreach ($rows as &$r) $r['pct'] = $total ? round(100 * $r['downloads'] / $total, 1) : 0;
  return $rows;
}

function kp_op3_top_countries(int $podcastId, int $days = 30, int $limit = 10): array {
  if (!kp_op3_tables_exist()) return [];
  return kp_q(
    "SELECT g.country, sum(g.downloads) as downloads FROM op3_geo_daily g
     JOIN episodes e ON e.id = g.episode_id
     WHERE e.podcast_id = ? AND g.date >= date('now', ?) AND g.country != ''
     GROUP BY g.country ORDER BY downloads DESC LIMIT ?",
    [$podcastId, '-' . $days . ' day', $limit]
  );
}

function kp_op3_top_metros(int $podcastId, int $days = 30, int $limit = 10): array {
  if (!kp_op3_tables_exist()) return [];
  $rows = kp_q(
    "SELECT metro_code, count(*) as downloads FROM op3_downloads
     WHERE podcast_id = ? AND date >= date('now', ?) AND country = 'US' AND metro_code != '' AND bot_type IS NULL
     GROUP BY metro_code ORDER BY downloads DESC LIMIT ?",
    [$podcastId, '-' . $days . ' day', $limit]
  );
  $total = array_sum(array_column($rows, 'downloads'));
  foreach ($rows as &$r) $r['pct'] = $total ? round(100 * $r['downloads'] / $total, 1) : 0;
  return $rows;
}

function kp_op3_top_regions(int $podcastId, string $continent, int $days = 30, int $limit = 10): array {
  if (!kp_op3_tables_exist()) return [];
  if ($continent === 'SA') {
    $rows = kp_q(
      "SELECT region_name, count(*) as downloads FROM op3_downloads
       WHERE podcast_id = ? AND date >= date('now', ?) AND (continent = 'SA' OR country IN ('MX','GT','BZ','SV','HN','NI','CR','PA','CU','DO','PR')) AND region_name != '' AND bot_type IS NULL
       GROUP BY region_name ORDER BY downloads DESC LIMIT ?",
      [$podcastId, '-' . $days . ' day', $limit]
    );
  } else {
    $rows = kp_q(
      "SELECT region_name, count(*) as downloads FROM op3_downloads
       WHERE podcast_id = ? AND date >= date('now', ?) AND continent = ? AND region_name != '' AND bot_type IS NULL
       GROUP BY region_name ORDER BY downloads DESC LIMIT ?",
      [$podcastId, '-' . $days . ' day', $continent, $limit]
    );
  }
  $total = array_sum(array_column($rows, 'downloads'));
  foreach ($rows as &$r) $r['pct'] = $total ? round(100 * $r['downloads'] / $total, 1) : 0;
  return $rows;
}

function kp_op3_top_browsers(int $podcastId, int $days = 30, int $limit = 10): array {
  if (!kp_op3_tables_exist()) return [];
  $rows = kp_q(
    "SELECT agent_name as name, count(*) as downloads FROM op3_downloads
     WHERE podcast_id = ? AND date >= date('now', ?) AND agent_type = 'browser' AND bot_type IS NULL
     GROUP BY name ORDER BY downloads DESC LIMIT ?",
    [$podcastId, '-' . $days . ' day', $limit]
  );
  $total = array_sum(array_column($rows, 'downloads'));
  foreach ($rows as &$r) $r['pct'] = $total ? round(100 * $r['downloads'] / $total, 1) : 0;
  return $rows;
}

function kp_op3_top_devices(int $podcastId, int $days = 30, int $limit = 10): array {
  if (!kp_op3_tables_exist()) return [];
  $rows = kp_q(
    "SELECT device_name as name, count(*) as downloads FROM op3_downloads
     WHERE podcast_id = ? AND date >= date('now', ?) AND device_name != '' AND bot_type IS NULL
     GROUP BY name ORDER BY downloads DESC LIMIT ?",
    [$podcastId, '-' . $days . ' day', $limit]
  );
  $total = array_sum(array_column($rows, 'downloads'));
  foreach ($rows as &$r) $r['pct'] = $total ? round(100 * $r['downloads'] / $total, 1) : 0;
  return $rows;
}

function kp_op3_last_sync(): ?array {
  try {
    $r = kp_one("SELECT v, updated_at FROM settings WHERE k = 'op3_last_sync'");
    return $r ? array_merge(json_decode($r['v'], true) ?: [], ['updated_at' => $r['updated_at']]) : null;
  } catch (Throwable $e) { return null; }
}

function kp_op3_rules_count(): int {
  try { return (int) kp_one("SELECT count(*) c FROM op3_botip_rules")['c']; }
  catch (Throwable $e) { return 0; }
}

function kp_op3_most_downloaded_episode(int $podcastId): ?array {
  if (!kp_op3_tables_exist()) return null;
  $row = kp_one(
    "SELECT e.id AS db_id, e.slug AS id, e.title AS title, p.slug AS podcast,
            COALESCE(SUM(s.downloads), 0) AS downloads,
            COALESCE(e.season, 1) AS s, COALESCE(e.number, 0) AS n, e.status AS status,
            COALESCE(e.published_at, e.publish_at, e.created_at) AS date_iso,
            e.cover AS cover, e.duration_secs AS duration_secs, e.guest AS guest
     FROM op3_stats_daily s
     JOIN episodes e ON e.id = s.episode_id
     JOIN podcasts p ON p.id = e.podcast_id
     WHERE s.podcast_id = ? AND e.hidden = 0
     GROUP BY s.episode_id
     ORDER BY downloads DESC
     LIMIT 1",
    [$podcastId]
  );
  if ($row) {
    $row['duration'] = kp_fmt_duration((int)($row['duration_secs'] ?? 0));
    $row['date']     = kp_fmt_relative_date($row['date_iso'] ?? '');
  }
  return $row ?: null;
}

function kp_op3_most_downloaded_episodes_by_podcast(): array {
  if (!kp_op3_tables_exist()) return [];
  $rows = kp_q(
    "WITH ranked AS (
      SELECT s.podcast_id, e.id AS db_id, e.slug AS id, e.title AS title, p.slug AS podcast,
             COALESCE(SUM(s.downloads), 0) AS downloads,
             COALESCE(e.season, 1) AS s, COALESCE(e.number, 0) AS n, e.status AS status,
             COALESCE(e.published_at, e.publish_at, e.created_at) AS date_iso,
             e.cover AS cover, e.duration_secs AS duration_secs, e.guest AS guest,
             ROW_NUMBER() OVER (PARTITION BY s.podcast_id ORDER BY SUM(s.downloads) DESC) as rn
      FROM op3_stats_daily s
      JOIN episodes e ON e.id = s.episode_id
      JOIN podcasts p ON p.id = e.podcast_id
      WHERE e.hidden = 0
      GROUP BY s.podcast_id, s.episode_id
    )
    SELECT * FROM ranked WHERE rn = 1"
  );
  $by_podcast = [];
  foreach ($rows as $row) {
    $row['duration'] = kp_fmt_duration((int)($row['duration_secs'] ?? 0));
    $row['date']     = kp_fmt_relative_date($row['date_iso'] ?? '');
    $by_podcast[$row['podcast_id']] = $row;
  }
  return $by_podcast;
}

function kp_op3_top_episodes(int $limit = 5): array {
  if (!kp_op3_tables_exist()) return [];
  $rows = kp_q(
    "SELECT e.id AS db_id, e.slug AS id, e.title AS title, p.slug AS podcast, p.title AS podcast_title,
            COALESCE(SUM(s.downloads), 0) AS downloads,
            COALESCE(e.season, 1) AS s, COALESCE(e.number, 0) AS n, e.status AS status,
            COALESCE(e.published_at, e.publish_at, e.created_at) AS date_iso,
            e.cover AS cover, e.duration_secs AS duration_secs, e.guest AS guest
     FROM op3_stats_daily s
     JOIN episodes e ON e.id = s.episode_id
     JOIN podcasts p ON p.id = e.podcast_id
     WHERE e.hidden = 0
     GROUP BY s.episode_id
     ORDER BY downloads DESC
     LIMIT ?",
    [$limit]
  );
  foreach ($rows as &$row) {
    $row['duration'] = kp_fmt_duration((int)($row['duration_secs'] ?? 0));
    $row['date']     = kp_fmt_relative_date($row['date_iso'] ?? '');
  }
  return $rows ?: [];
}

