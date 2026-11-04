<?php
/**
 * php/api/handlers/episode_update.php
 *
 * POST /api/podcasts/{id}/episodes/{episode_id}  (multipart/form-data)
 */

declare(strict_types=1);

$user      = api_require_user();
$podcastId = $GLOBALS['_podcast_id'] ?? '';
$episodeId = (int)($GLOBALS['_episode_id'] ?? 0);
$podcast   = api_require_podcast_owner((int)$user['id'], $podcastId);

require_once __DIR__ . '/../../includes/helpers.php';

$pdo = kp_db();

// ── Obtener el episodio existente ───────────────────────────────────
$stEp = $pdo->prepare("SELECT * FROM episodes WHERE id = :id AND podcast_id = :pid");
$stEp->execute([':id' => $episodeId, ':pid' => $podcast['id']]);
$episode = $stEp->fetch();
if (!$episode) {
    api_error(404, 'not_found', 'Episodio no encontrado');
}

if (($user['role'] ?? '') === 'author' && (int)$episode['created_by'] !== (int)$user['id']) {
    api_error(403, 'forbidden', 'Rol de autor: Solo puedes modificar tus propios episodios');
}

// ── 1) Validación de inputs ────────────────────────────────────────
$title = trim((string)($_POST['title'] ?? ''));
if ($title === '') {
    $title = $episode['title'];
}

$slug = $episode['slug'];
$audioDir  = __DIR__ . '/../../media/audio/' . $podcast['id'];
$coverDir  = __DIR__ . '/../../media/covers/' . $podcast['id'];
$chapDir   = __DIR__ . '/../../media/chapters/' . $podcast['id'];
$transDir  = __DIR__ . '/../../media/uploads';

foreach ([$audioDir, $coverDir, $chapDir, $transDir] as $d) {
    if (!is_dir($d) && !@mkdir($d, 0775, true) && !is_dir($d)) {
        api_error(500, 'fs_error', "No se pudo crear $d");
    }
}

$audioPublicUrl = $episode['audio_url'];
$bytes = $episode['audio_bytes'];
$duration_secs = (int)($episode['duration_secs'] ?? 0);

// ── 4) Mover audio (Opcional en actualización) ──────────────────────
if (!empty($_FILES['audio']) && ($_FILES['audio']['error'] ?? 99) === UPLOAD_ERR_OK) {
    $audioExt = strtolower(pathinfo($_FILES['audio']['name'], PATHINFO_EXTENSION) ?: 'mp3');
    $allowed_audio = ['mp3', 'm4a', 'ogg', 'wav', 'flac', 'aac'];
    if (!in_array($audioExt, $allowed_audio, true)) {
        api_error(400, 'bad_request', 'Extensión de audio no permitida: ' . $audioExt);
    }
    $audioRel = 'media/audio/' . $podcast['id'] . "/$slug.$audioExt";
    $audioAbs = $audioDir . "/$slug.$audioExt";
    if (move_uploaded_file($_FILES['audio']['tmp_name'], $audioAbs)) {
        $audioPublicUrl = "/" . $audioRel;
        $bytes = filesize($audioAbs) ?: 0;

        $probeJson = @shell_exec('ffprobe -v quiet -print_format json -show_format ' . escapeshellarg($audioAbs));
        if ($probeJson) {
            $probe = json_decode($probeJson, true);
            if (!empty($probe['format']['duration'])) {
                $duration_secs = (int)round((float)$probe['format']['duration']);
            }
        }
    }
}

// ── 5) Cover (opcional) ────────────────────────────────────────────
$coverRel = $episode['cover'];
if (!empty($_FILES['cover']) && ($_FILES['cover']['error'] ?? 99) === UPLOAD_ERR_OK) {
    $ext = strtolower(pathinfo($_FILES['cover']['name'], PATHINFO_EXTENSION) ?: 'jpg');
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) $ext = 'jpg';
    $coverRelPath = 'media/covers/' . $podcast['id'] . "/ep_$slug.$ext";
    $coverAbs = $coverDir . "/ep_$slug.$ext";
    if (move_uploaded_file($_FILES['cover']['tmp_name'], $coverAbs)) {
        $coverRel = '/' . $coverRelPath;
    }
}

// ── 6) Transcripción (opcional) ────────────────────────────────────
$transcriptRel = $episode['transcript_url'];
if (!empty($_FILES['transcript']) && ($_FILES['transcript']['error'] ?? 99) === UPLOAD_ERR_OK) {
    $ext = strtolower(pathinfo($_FILES['transcript']['name'], PATHINFO_EXTENSION) ?: 'srt');
    if (!in_array($ext, ['srt', 'vtt'], true)) $ext = 'srt';
    $transcriptRelPath = "media/uploads/$slug.$ext";
    $transcriptAbs = $transDir . "/$slug.$ext";
    if (move_uploaded_file($_FILES['transcript']['tmp_name'], $transcriptAbs)) {
        $transcriptRel = '/' . $transcriptRelPath;
    }
}

// ── 7) Capítulos + sus imágenes ────────────────────────────────────
$chaptersRel = $episode['chapters_url'];
$chaptersJson = $_POST['chapters'] ?? '';
if (is_string($chaptersJson) && $chaptersJson !== '') {
    $arr = json_decode($chaptersJson, true);
    if (is_array($arr)) {
        foreach ($arr as $idx => &$ch) {
            if (empty($ch['img_ref'])) continue;
            $ref = $ch['img_ref'];
            unset($ch['img_ref']);
            if (empty($_FILES[$ref]) || ($_FILES[$ref]['error'] ?? 99) !== UPLOAD_ERR_OK) {
                continue;
            }
            $ext = strtolower(pathinfo($_FILES[$ref]['name'], PATHINFO_EXTENSION) ?: 'jpg');
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) $ext = 'jpg';
            $imgRel = 'media/chapters/' . $podcast['id'] . "/ep_$slug-ch$idx.$ext";
            $imgAbs = $chapDir . "/ep_$slug-ch$idx.$ext";
            if (move_uploaded_file($_FILES[$ref]['tmp_name'], $imgAbs)) {
                $ch['img'] = '/' . $imgRel;
            }
        }
        unset($ch);

        $chaptersRelPath = 'media/chapters/' . $podcast['id'] . "/ep_$slug.json";
        $chaptersAbs = $chapDir . "/ep_$slug.json";
        file_put_contents($chaptersAbs, json_encode([
            'version'  => '1.2.0',
            'chapters' => $arr,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        $chaptersRel = '/' . $chaptersRelPath;
    }
}

// ── 8) Actualizar episodio ─────────────────────────────────────────
$status = $episode['status'];
$publishAt = $episode['publish_at'];
$publishedAt = $episode['published_at'] ?? $episode['publish_at'];

if (!empty($_POST['publish_at'])) {
    $ts = strtotime((string)$_POST['publish_at']);
    $status = $ts > time() ? 'scheduled' : 'published';
    $publishAt = gmdate('Y-m-d H:i:s', $ts);
    if ($status === 'published') {
        $publishedAt = $publishAt;
    }
} elseif ($status === 'published' && empty($publishedAt)) {
    $publishedAt = $publishAt ?: gmdate('Y-m-d H:i:s');
}

$upd = $pdo->prepare("
    UPDATE episodes SET
        title = :title,
        notes_md = :notes,
        season = :sea,
        number = :ep,
        ep_type = :type,
        parental = :parental,
        audio_url = :audio,
        audio_bytes = :bytes,
        duration_secs = :dur,
        cover = :cover,
        transcript_url = :tr,
        chapters_url = :ch,
        status = :st,
        publish_at = :pub,
        published_at = :pub_at,
        updated_at = :now
    WHERE id = :id
");
$now = gmdate('Y-m-d H:i:s');
$upd->execute([
    ':title'    => $title,
    ':notes'    => isset($_POST['description']) ? (string)$_POST['description'] : $episode['notes_md'],
    ':sea'      => isset($_POST['season']) ? (int)$_POST['season'] : $episode['season'],
    ':ep'       => isset($_POST['episode']) ? (int)$_POST['episode'] : $episode['number'],
    ':type'     => isset($_POST['episode_type']) ? (string)$_POST['episode_type'] : $episode['ep_type'],
    ':parental' => isset($_POST['explicit']) ? (!empty($_POST['explicit']) ? 'explicit' : 'clean') : $episode['parental'],
    ':audio'    => $audioPublicUrl,
    ':bytes'    => $bytes,
    ':dur'      => $duration_secs,
    ':cover'    => $coverRel,
    ':tr'       => $transcriptRel,
    ':ch'       => $chaptersRel,
    ':st'       => $status,
    ':pub'      => $publishAt,
    ':pub_at'   => ($status === 'published') ? $publishedAt : null,
    ':now'      => $now,
    ':id'       => $episodeId,
]);

if ($status === 'published' && $episode['status'] !== 'published') {
    // Si acaba de ser publicado, notificar a WebSub y plugins
    $feed_url = kp_canonical_feed_url($podcast['slug']);
    kp_notify_websub($feed_url);

    // Calcular URL pública del episodio para el gancho
    $settings = $pdo->query("SELECT v FROM settings WHERE k='instance_domain' LIMIT 1")->fetch();
    $base = $settings ? rtrim((string)$settings['v'], '/') : '';
    if ($base && !str_starts_with(strtolower($base), 'http')) $base = 'https://' . $base;
    $episodeUrl = $base . '/@' . $podcast['slug'] . '/' . $slug;

    kp_do_action('episode_published', (int)$episodeId, (int)$podcast['id'], $title, isset($_POST['description']) ? (string)$_POST['description'] : $episode['notes_md'], $episodeUrl);
}

// Alerta de sistema
kp_alert('system', 'Episodio actualizado (API)', "'{$title}' fue actualizado en {$podcast['title']} vía Kut Editor.", '/admin/new-episode?id=' . $episodeId . '&podcast=' . urlencode($podcast['slug']), ['actor_name' => $user['name'] ?? 'API']);

// ── 9) Construir URL pública del episodio ──────────────────────────
$settings = $pdo->query("SELECT v FROM settings WHERE k='instance_domain' LIMIT 1")->fetch();
$base = $settings ? rtrim((string)$settings['v'], '/') : '';
if ($base && !str_starts_with(strtolower($base), 'http')) $base = 'https://' . $base;
$episodeUrl = $base . '/@' . $podcast['slug'] . '/' . $slug;

api_json([
    'id'           => $episodeId,
    'slug'         => $slug,
    'podcast_id'   => (int)$podcast['id'],
    'podcast_slug' => $podcast['slug'],
    'title'        => $title,
    'status'       => $status,
    'audio_url'    => $audioPublicUrl,
    'cover'        => $coverRel,
    'transcript'   => $transcriptRel,
    'chapters'     => $chaptersRel,
    'published_at' => $publishedAt,
    'url'          => $episodeUrl,
], 200);
