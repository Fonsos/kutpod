<?php
// ============================================================================
// KutPod · Plugin de Estadísticas de YouTube
// Sincronización automática de métricas de podcast publicados vía YouTube RSS
// ============================================================================

if (!defined('KUTPOD_VERSION')) {
    exit;
}

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/helpers.php';

/**
 * Inicializa la tabla de base de datos para estadísticas de YouTube si no existe.
 */
function kp_youtube_init_db(): void {
    static $initialized = false;
    if ($initialized) return;

    $pdo = kp_db();
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS youtube_episode_stats (
            episode_id INTEGER PRIMARY KEY,
            podcast_id INTEGER NOT NULL,
            video_id TEXT NOT NULL,
            video_title TEXT,
            views INTEGER DEFAULT 0,
            likes INTEGER DEFAULT 0,
            comments INTEGER DEFAULT 0,
            published_at TEXT,
            synced_at TEXT
        );
        CREATE INDEX IF NOT EXISTS idx_yt_podcast ON youtube_episode_stats(podcast_id);
    ");
    $initialized = true;
}

// Inicializar DB al cargar el plugin
kp_youtube_init_db();

/**
 * Limpia y normaliza texto para comparar títulos (sin tildes, minúsculas y sin caracteres especiales).
 */
function kp_yt_normalize_text(string $text): string {
    $text = mb_strtolower(trim($text), 'UTF-8');
    // Normalizar tildes comunes
    $from = ['á', 'é', 'í', 'ó', 'ú', 'ñ', 'ü', 'à', 'è', 'ì', 'ò', 'ù'];
    $to   = ['a', 'e', 'i', 'o', 'u', 'n', 'u', 'a', 'e', 'i', 'o', 'u'];
    $text = str_replace($from, $to, $text);
    // Remover caracteres especiales y puntuación
    $text = preg_replace('/[^a-z0-9\s]/u', ' ', $text);
    // Reducir espacios múltiples
    $text = preg_replace('/\s+/', ' ', $text);
    return trim($text);
}

/**
 * Extrae el ID de una playlist de YouTube a partir de una URL o del ID crudo.
 */
function kp_yt_clean_playlist_id(string $input): string {
    $input = trim($input);
    if (empty($input)) return '';

    // Si es una URL completa de YouTube
    if (str_contains($input, 'http://') || str_contains($input, 'https://')) {
        $parts = @parse_url($input);
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $q);
            if (!empty($q['list'])) {
                return trim($q['list']);
            }
        }
    }

    return trim($input);
}

/**
 * Petición HTTP GET optimizada usando cURL o stream_context con timeout seguro.
 */
function kp_yt_http_get(string $url): ?array {
    $response = null;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 7,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT      => 'KutPod-YouTubeStats/1.0',
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code === 200 && $body) {
            $response = json_decode($body, true);
        }
    } else {
        $ctx = stream_context_create([
            'http' => [
                'timeout'    => 15,
                'user_agent' => 'KutPod-YouTubeStats/1.0',
            ]
        ]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body) {
            $response = json_decode($body, true);
        }
    }

    return is_array($response) ? $response : null;
}

/**
 * Sincroniza las estadísticas de YouTube para un podcast específico.
 *
 * @param int $podcastId ID del podcast en KutPod
 * @param string|null $errorMsg Mensaje de error si falla
 * @return array Resumen de la sincronización
 */
function kp_youtube_sync_podcast(int $podcastId, ?string &$errorMsg = null): array {
    kp_youtube_init_db();

    $apiKey = trim(kp_setting('youtube_stats_api_key', ''));
    if (empty($apiKey)) {
        $errorMsg = 'No se ha configurado la API Key de YouTube en los ajustes del plugin.';
        return ['ok' => false, 'error' => $errorMsg];
    }

    $playlistsJson = kp_setting('youtube_stats_playlists', '{}');
    $playlists = json_decode($playlistsJson, true) ?: [];
    $rawPlaylist = $playlists[(string)$podcastId] ?? '';
    $playlistId = kp_yt_clean_playlist_id($rawPlaylist);

    if (empty($playlistId)) {
        $errorMsg = 'No se ha configurado la Playlist de YouTube para este podcast.';
        return ['ok' => false, 'error' => $errorMsg];
    }

    // 1. Obtener todos los vídeos de la Playlist (hasta 200 vídeos por show)
    $videos = [];
    $pageToken = '';
    $pageCount = 0;

    do {
        $url = 'https://www.googleapis.com/youtube/v3/playlistItems?' . http_build_query([
            'part'       => 'snippet,contentDetails',
            'playlistId' => $playlistId,
            'maxResults' => 50,
            'pageToken'  => $pageToken,
            'key'        => $apiKey,
        ]);

        $data = kp_yt_http_get($url);
        if (!$data || empty($data['items'])) {
            if ($pageCount === 0) {
                $errorMsg = 'No se pudieron obtener vídeos de la playlist. Verifica que el ID de la Playlist y la API Key sean correctos y que la lista sea pública o no listada.';
                return ['ok' => false, 'error' => $errorMsg];
            }
            break;
        }

        foreach ($data['items'] as $item) {
            $videoId = $item['contentDetails']['videoId'] ?? ($item['snippet']['resourceId']['videoId'] ?? '');
            if (!$videoId) continue;

            $videos[$videoId] = [
                'video_id'     => $videoId,
                'title'        => $item['snippet']['title'] ?? '',
                'published_at' => $item['snippet']['publishedAt'] ?? '',
                'views'        => 0,
                'likes'        => 0,
                'comments'     => 0,
            ];
        }

        $pageToken = $data['nextPageToken'] ?? '';
        $pageCount++;
    } while (!empty($pageToken) && $pageCount < 4);

    if (empty($videos)) {
        $errorMsg = 'La lista de reproducción de YouTube no contiene ningún vídeo.';
        return ['ok' => false, 'error' => $errorMsg];
    }

    // 2. Obtener estadísticas por lotes de 50 vídeos (1 unidad de cuota por lote)
    $videoIds = array_keys($videos);
    $chunks = array_chunk($videoIds, 50);

    foreach ($chunks as $chunk) {
        $statsUrl = 'https://www.googleapis.com/youtube/v3/videos?' . http_build_query([
            'part' => 'statistics',
            'id'   => implode(',', $chunk),
            'key'  => $apiKey,
        ]);

        $statsData = kp_yt_http_get($statsUrl);
        if ($statsData && !empty($statsData['items'])) {
            foreach ($statsData['items'] as $sItem) {
                $vid = $sItem['id'] ?? '';
                if (isset($videos[$vid])) {
                    $st = $sItem['statistics'] ?? [];
                    $videos[$vid]['views']    = (int)($st['viewCount'] ?? 0);
                    $videos[$vid]['likes']    = (int)($st['likeCount'] ?? 0);
                    $videos[$vid]['comments'] = (int)($st['commentCount'] ?? 0);
                }
            }
        }
    }

    // 3. Obtener todos los episodios del podcast en KutPod
    $pdo = kp_db();
    $episodes = kp_q(
        "SELECT id, title, season, number, published_at FROM episodes WHERE podcast_id = ? ORDER BY id DESC",
        [$podcastId]
    );

    if (empty($episodes)) {
        return [
            'ok'            => true,
            'matched'       => 0,
            'unmatched'     => count($videos),
            'total_videos'  => count($videos),
            'total_views'   => 0,
            'details'       => []
        ];
    }

    // Preparar lista de episodios normalizada para emparejamiento
    $normEpisodes = [];
    foreach ($episodes as $ep) {
        $normEpisodes[$ep['id']] = [
            'id'           => (int)$ep['id'],
            'title'        => $ep['title'],
            'norm_title'   => kp_yt_normalize_text($ep['title']),
            'season'       => (int)($ep['season'] ?? 0),
            'number'       => (int)($ep['number'] ?? 0),
            'published_at' => $ep['published_at'] ?? '',
        ];
    }

    $matchedCount = 0;
    $totalViews = 0;
    $details = [];

    // Preparar sentencia de inserción/actualización
    $stmt = $pdo->prepare("
        INSERT INTO youtube_episode_stats 
            (episode_id, podcast_id, video_id, video_title, views, likes, comments, published_at, synced_at)
        VALUES 
            (?, ?, ?, ?, ?, ?, ?, ?, datetime('now'))
        ON CONFLICT(episode_id) DO UPDATE SET
            video_id    = excluded.video_id,
            video_title = excluded.video_title,
            views       = excluded.views,
            likes       = excluded.likes,
            comments    = excluded.comments,
            synced_at   = datetime('now')
    ");

    // 4. Algoritmo de emparejamiento inteligente
    $usedEpisodes = [];

    foreach ($videos as $vid => $vData) {
        $vTitleNorm = kp_yt_normalize_text($vData['title']);
        $matchedEpId = null;

        // Regla 1: Coincidencia exacta de título normalizado
        foreach ($normEpisodes as $epId => $epData) {
            if (isset($usedEpisodes[$epId])) continue;
            if ($epData['norm_title'] === $vTitleNorm) {
                $matchedEpId = $epId;
                break;
            }
        }

        // Regla 2: Subcadena (el título del episodio está dentro del vídeo o viceversa)
        if ($matchedEpId === null) {
            foreach ($normEpisodes as $epId => $epData) {
                if (isset($usedEpisodes[$epId])) continue;
                if (!empty($epData['norm_title']) && (str_contains($vTitleNorm, $epData['norm_title']) || str_contains($epData['norm_title'], $vTitleNorm))) {
                    $matchedEpId = $epId;
                    break;
                }
            }
        }

        // Regla 3: Coincidencia por temporada y número de episodio (T1 Ep 4, #4, Episodio 4)
        if ($matchedEpId === null) {
            foreach ($normEpisodes as $epId => $epData) {
                if (isset($usedEpisodes[$epId])) continue;
                if ($epData['number'] > 0) {
                    $patterns = [
                        '/\bep\s*0*' . $epData['number'] . '\b/i',
                        '/\bepisodio\s*0*' . $epData['number'] . '\b/i',
                        '/\b#0*' . $epData['number'] . '\b/i',
                    ];
                    if ($epData['season'] > 0) {
                        $patterns[] = '/\bt0*' . $epData['season'] . '\s*ep\s*0*' . $epData['number'] . '\b/i';
                        $patterns[] = '/\bt0*' . $epData['season'] . 'e0*' . $epData['number'] . '\b/i';
                    }
                    foreach ($patterns as $pattern) {
                        if (preg_match($pattern, $vData['title'])) {
                            $matchedEpId = $epId;
                            break 2;
                        }
                    }
                }
            }
        }

        if ($matchedEpId !== null) {
            $usedEpisodes[$matchedEpId] = true;
            $stmt->execute([
                $matchedEpId,
                $podcastId,
                $vid,
                $vData['title'],
                $vData['views'],
                $vData['likes'],
                $vData['comments'],
                $vData['published_at']
            ]);

            $matchedCount++;
            $totalViews += $vData['views'];

            $details[] = [
                'episode_id'  => $matchedEpId,
                'episode_name'=> $normEpisodes[$matchedEpId]['title'],
                'video_id'    => $vid,
                'video_title' => $vData['title'],
                'views'       => $vData['views'],
                'likes'       => $vData['likes'],
                'comments'    => $vData['comments'],
                'matched'     => true,
            ];
        } else {
            $details[] = [
                'episode_id'  => null,
                'episode_name'=> null,
                'video_id'    => $vid,
                'video_title' => $vData['title'],
                'views'       => $vData['views'],
                'likes'       => $vData['likes'],
                'comments'    => $vData['comments'],
                'matched'     => false,
            ];
        }
    }

    // Guardar timestamp de última sincronización
    kp_update_setting('youtube_stats_last_sync', time());

    return [
        'ok'           => true,
        'matched'      => $matchedCount,
        'unmatched'    => count($videos) - $matchedCount,
        'total_videos' => count($videos),
        'total_views'  => $totalViews,
        'details'      => $details,
    ];
}

/**
 * Devuelve las estadísticas de YouTube de un episodio específico.
 */
function kp_youtube_get_episode_stats(int $episodeId): ?array {
    kp_youtube_init_db();
    try {
        $row = kp_one("SELECT * FROM youtube_episode_stats WHERE episode_id = ?", [$episodeId]);
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Devuelve el resumen global de estadísticas de YouTube de un podcast.
 */
function kp_youtube_get_podcast_totals(int $podcastId): array {
    kp_youtube_init_db();
    $zero = ['total_views' => 0, 'total_likes' => 0, 'total_comments' => 0, 'episodes_count' => 0];
    try {
        $row = kp_one("
            SELECT 
                COALESCE(SUM(views), 0)    AS total_views,
                COALESCE(SUM(likes), 0)    AS total_likes,
                COALESCE(SUM(comments), 0) AS total_comments,
                COUNT(episode_id)          AS episodes_count
            FROM youtube_episode_stats
            WHERE podcast_id = ?
        ", [$podcastId]);
        return $row ?: $zero;
    } catch (Throwable $e) {
        return $zero;
    }
}

/**
 * Verificación periódica y sincronización automática (ejecutada con throttle de 6 horas).
 */
function kp_youtube_auto_sync_check(): void {
    $autoSync = (int)kp_setting('youtube_stats_auto_sync', '1');
    if (!$autoSync) return;

    $lastSync = (int)kp_setting('youtube_stats_last_sync', '0');
    $interval = (int)kp_setting('youtube_stats_interval_hours', '6') * 3600;

    if (time() - $lastSync < $interval) {
        return;
    }

    // Realizar sincronización en segundo plano para cada podcast configurado
    $playlistsJson = kp_setting('youtube_stats_playlists', '{}');
    $playlists = json_decode($playlistsJson, true) ?: [];

    foreach ($playlists as $podId => $pId) {
        if (!empty($pId)) {
            @kp_youtube_sync_podcast((int)$podId);
        }
    }
}

// Conectar con el gancho global 'init'
kp_add_action('init', 'kp_youtube_auto_sync_check');

// Hook para mostrar la tarjeta de vistas de YouTube en Analytics
kp_add_action('analytics_stat_grid', function() {
    kp_youtube_init_db();
    try {
        $row = kp_one("SELECT COALESCE(SUM(views), 0) AS total_views FROM youtube_episode_stats");
        $views = (int)($row['total_views'] ?? 0);
        if ($views > 0) {
            echo stat_card('Vistas YouTube', number_format($views), 'play', ['sub' => 'Vía RSS de YouTube']);
        }
    } catch (Throwable $e) {}
});
