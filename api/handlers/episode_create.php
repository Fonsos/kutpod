<?php
/**
 * php/api/handlers/episode_create.php
 *
 * POST /api/podcasts/{id}/episodes  (multipart/form-data)
 *
 * Campos:
 *   title           (texto, requerido)
 *   description     (texto)
 *   author          (texto)
 *   episode         (int)
 *   season          (int)
 *   episode_type    ("full"|"trailer"|"bonus", default "full")
 *   explicit        (0|1)
 *   publish_at      (ISO 8601, opcional → si vacío, publica ya)
 *   audio           (archivo, requerido)
 *   cover           (archivo, opcional)
 *   transcript      (archivo .srt|.vtt, opcional)
 *   chapters        (texto: JSON array Podcasting 2.0)
 *   chapter_img_N   (archivos, opcionales; referenciados desde chapters[i].img_ref)
 *
 * Respuesta: { id, slug, podcast_slug, url, audio_url, ... }
 */

$user      = api_require_user();
$podcastId = $GLOBALS['_podcast_id'] ?? '';
$podcast   = api_require_podcast_owner((int)$user['id'], $podcastId);

require_once __DIR__ . '/../../includes/helpers.php';

$pdo = kp_db();

// ── 1) Validación de inputs ────────────────────────────────────────
$title = trim((string)($_POST['title'] ?? ''));
if ($title === '') {
    api_error(400, 'bad_request', 'Falta el campo title');
}

if (empty($_FILES['audio']) || ($_FILES['audio']['error'] ?? 99) !== UPLOAD_ERR_OK) {
    api_error(400, 'bad_request', 'Archivo de audio ausente o con error de subida');
}

// ── 2) Generar slug único dentro del podcast ───────────────────────
$baseSlug = api_slugify(preg_replace('/#\w+/u', '', $title));

// ── 2.5) Comprobar si el episodio ya existe en este podcast para actualizarlo ──
$existingId = null;
$season = (int)($_POST['season'] ?? 0);
$number = (int)($_POST['episode'] ?? 0);

if ($number > 0) {
    // Buscar por temporada y número de episodio
    $st = $pdo->prepare("SELECT id FROM episodes WHERE podcast_id = :pid AND season = :sea AND number = :num LIMIT 1");
    $st->execute([':pid' => $podcast['id'], ':sea' => $season, ':num' => $number]);
    $existingId = $st->fetchColumn();
}

if (!$existingId) {
    // Buscar por slug idéntico (derivado del título)
    $st = $pdo->prepare("SELECT id FROM episodes WHERE podcast_id = :pid AND slug = :s LIMIT 1");
    $st->execute([':pid' => $podcast['id'], ':s' => $baseSlug]);
    $existingId = $st->fetchColumn();
}

if ($existingId) {
    // El episodio ya existe: redirigir e incorporar el manejador de actualización
    $GLOBALS['_episode_id'] = $existingId;
    require __DIR__ . '/episode_update.php';
    exit;
}

$slug     = $baseSlug;
$probe    = $pdo->prepare("SELECT 1 FROM episodes WHERE podcast_id = :pid AND slug = :s");
$i = 2;
while (true) {
    $probe->execute([':pid' => $podcast['id'], ':s' => $slug]);
    if (!$probe->fetch()) break;
    $slug = $baseSlug . '-' . $i;
    $i++;
}

// ── 3) Carpeta de destino ──────────────────────────────────────────
$audioDir  = __DIR__ . '/../../media/audio/' . $podcast['id'];
$coverDir  = __DIR__ . '/../../media/covers/' . $podcast['id'];
$chapDir   = __DIR__ . '/../../media/chapters/' . $podcast['id'];
$transDir  = __DIR__ . '/../../media/uploads';

foreach ([$audioDir, $coverDir, $chapDir, $transDir] as $d) {
    if (!is_dir($d) && !@mkdir($d, 0775, true) && !is_dir($d)) {
        api_error(500, 'fs_error', "No se pudo crear $d");
    }
}

// ── 4) Mover audio y extraer duración ──────────────────────────────
$audioExt = strtolower(pathinfo($_FILES['audio']['name'], PATHINFO_EXTENSION) ?: 'mp3');
$allowed_audio = ['mp3', 'm4a', 'ogg', 'wav', 'flac', 'aac'];
if (!in_array($audioExt, $allowed_audio, true)) {
    api_error(400, 'bad_request', 'Extensión de audio no permitida: ' . $audioExt);
}
$audioRel = 'media/audio/' . $podcast['id'] . "/$slug.$audioExt";
$audioAbs = $audioDir . "/$slug.$audioExt";
if (!move_uploaded_file($_FILES['audio']['tmp_name'], $audioAbs)) {
    api_error(500, 'upload_failed', 'No se pudo mover el archivo de audio');
}

$duration_secs = 0;
$probeJson = @shell_exec('ffprobe -v quiet -print_format json -show_format ' . escapeshellarg($audioAbs));
if ($probeJson) {
    $probe = json_decode($probeJson, true);
    if (!empty($probe['format']['duration'])) {
        $duration_secs = (int)round((float)$probe['format']['duration']);
    }
}

// audio_url se publica vía /r/ (OP3 tracker nativo)
$audioPublicUrl = "/r/{$podcast['slug']}/$slug/audio.$audioExt";

// ── 5) Cover (opcional) ────────────────────────────────────────────
$coverRel = '';
if (!empty($_FILES['cover']) && ($_FILES['cover']['error'] ?? 99) === UPLOAD_ERR_OK) {
    $ext = strtolower(pathinfo($_FILES['cover']['name'], PATHINFO_EXTENSION) ?: 'jpg');
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) $ext = 'jpg';
    $coverRel = 'media/covers/' . $podcast['id'] . "/ep_$slug.$ext";
    $coverAbs = $coverDir . "/ep_$slug.$ext";
    if (move_uploaded_file($_FILES['cover']['tmp_name'], $coverAbs)) {
    }
}

// ── 6) Transcripción (opcional) ────────────────────────────────────
$transcriptRel = '';
if (!empty($_FILES['transcript']) && ($_FILES['transcript']['error'] ?? 99) === UPLOAD_ERR_OK) {
    $ext = strtolower(pathinfo($_FILES['transcript']['name'], PATHINFO_EXTENSION) ?: 'srt');
    if (!in_array($ext, ['srt', 'vtt'], true)) $ext = 'srt';
    $transcriptRel = "media/uploads/$slug.$ext";
    $transcriptAbs = $transDir . "/$slug.$ext";
    move_uploaded_file($_FILES['transcript']['tmp_name'], $transcriptAbs);
}

// ── 7) Capítulos + sus imágenes ────────────────────────────────────
$chaptersRel = '';
$chaptersJson = $_POST['chapters'] ?? '';
if (is_string($chaptersJson) && $chaptersJson !== '') {
    $arr = json_decode($chaptersJson, true);
    if (is_array($arr)) {
        // Resolver img_ref → URL pública
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

        $chaptersRel = 'media/chapters/' . $podcast['id'] . "/ep_$slug.json";
        $chaptersAbs = $chapDir . "/ep_$slug.json";
        file_put_contents($chaptersAbs, json_encode([
            'version'  => '1.2.0',
            'chapters' => $arr,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    }
}

// La URL que guardamos en BD debe ser el archivo real, no el tracker /r/, para evitar bucles infinitos en OP3.
$audioRealUrl = $audioRel ? '/' . $audioRel : '';

// ── 8) Insertar episodio ───────────────────────────────────────────
$status = !empty($_POST['publish_at']) && strtotime((string)$_POST['publish_at']) > time()
        ? 'scheduled'
        : 'published';
$publishedAt = !empty($_POST['publish_at'])
             ? gmdate('Y-m-d H:i:s', strtotime((string)$_POST['publish_at']))
             : gmdate('Y-m-d H:i:s');
$finalPublishedAt = ($status === 'published') ? $publishedAt : null;

$ins = $pdo->prepare("
    INSERT INTO episodes (
        podcast_id, guid, slug, title, notes_md,
        season, number, ep_type, parental,
        audio_url, audio_bytes, duration_secs, cover, transcript_url, chapters_url,
        status, publish_at, published_at, persons_json, created_by, created_at, updated_at
    ) VALUES (
        :pid, :guid, :slug, :title, :notes,
        :sea, :ep, :type, :parental,
        :audio, :bytes, :dur, :cover, :tr, :ch,
        :st, :pub, :pub_at, :persons, :created_by, :now, :now
    )
");
$now = gmdate('Y-m-d H:i:s');
$ins->execute([
    ':pid'      => $podcast['id'],
    ':guid'     => bin2hex(random_bytes(8)),
    ':slug'     => $slug,
    ':title'    => $title,
    ':notes'    => (string)($_POST['description'] ?? ''),
    ':sea'      => (int)($_POST['season']  ?? 1),
    ':ep'       => (int)($_POST['episode'] ?? 0),
    ':type'     => (string)($_POST['episode_type'] ?? 'full'),
    ':parental' => !empty($_POST['explicit']) ? 'explicit' : 'clean',
    ':audio'    => $audioRealUrl,
    ':bytes'    => filesize($audioAbs) ?: 0,
    ':dur'      => $duration_secs,
    ':cover'    => $coverRel ? '/' . $coverRel : '',
    ':tr'       => $transcriptRel ? '/' . $transcriptRel : '',
    ':ch'       => $chaptersRel ? '/' . $chaptersRel : '',
    ':st'       => $status,
    ':pub'      => $publishedAt,
    ':pub_at'   => $finalPublishedAt,
    ':persons'  => json_encode([[
        'id'   => (int)$user['id'],
        'name' => $user['name'] ?? 'Autor',
        'role' => 'host',
        'avatar' => $user['avatar'] ?? '',
        'url'  => $user['url'] ?? '',
        'bio'  => $user['bio'] ?? ''
    ]], JSON_UNESCAPED_UNICODE),
    ':created_by'=> (int)$user['id'],
    ':now'      => $now,
]);
$episodeId = (int)$pdo->lastInsertId();

if ($status === 'published') {
    $feed_url = kp_canonical_feed_url($podcast['slug']);
    kp_notify_websub($feed_url);
}

// Alerta de sistema
$statusLabel = $status === 'published' ? 'publicado' : 'programado';
kp_alert('system', "Episodio $statusLabel (API)", "'{$title}' se ha $statusLabel en {$podcast['title']} vía Kut Editor.", '/admin/new-episode?id=' . $episodeId . '&podcast=' . urlencode($podcast['slug']), ['actor_name' => $user['name'] ?? 'API']);

// El contador se calcula dinámicamente, no hay columna episode_count en podcasts

// ── 9) Construir URL pública del episodio ──────────────────────────
$settings = $pdo->query("SELECT v FROM settings WHERE k='instance_domain' LIMIT 1")->fetch();
$base = $settings ? rtrim((string)$settings['v'], '/') : '';
if ($base && !str_starts_with(strtolower($base), 'http')) $base = 'https://' . $base;
$episodeUrl = $base . '/@' . $podcast['slug'] . '/' . $slug;

// ── 10) Disparar evento de publicación de episodio ──
if ($status === 'published') {
    try {
        $description = (string)($_POST['description'] ?? '');
        kp_do_action('episode_published', (int)$episodeId, (int)$podcast['id'], $title, $description, $episodeUrl);
    } catch (Throwable $e) {
        kp_alert('system', 'Error en gancho de publicación (no crítico)', $e->getMessage(), '');
    }
}

api_json([
    'id'           => $episodeId,
    'slug'         => $slug,
    'podcast_id'   => (int)$podcast['id'],
    'podcast_slug' => $podcast['slug'],
    'title'        => $title,
    'status'       => $status,
    'audio_url'    => $audioPublicUrl,
    'cover'        => $coverRel      ? '/' . $coverRel      : '',
    'transcript'   => $transcriptRel ? '/' . $transcriptRel : '',
    'chapters'     => $chaptersRel   ? '/' . $chaptersRel   : '',
    'published_at' => $publishedAt,
    'url'          => $episodeUrl,
], 201);
