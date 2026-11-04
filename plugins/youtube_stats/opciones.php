<?php
// ============================================================================
// KutPod · Opciones del Plugin de Estadísticas de YouTube
// ============================================================================

if (!defined('KUTPOD_VERSION')) {
    exit;
}

require_once __DIR__ . '/youtube_stats.php';

$pdo = kp_db();
$podcasts = kp_q("SELECT id, title, slug, cover FROM podcasts ORDER BY title ASC");

$msg = '';
$error = '';
$sync_results = null;

// Procesar formularios
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action_type'] ?? 'save_settings';

    if ($action === 'save_settings') {
        kp_update_setting('youtube_stats_api_key', trim($_POST['youtube_stats_api_key'] ?? ''));
        kp_update_setting('youtube_stats_auto_sync', isset($_POST['youtube_stats_auto_sync']) ? '1' : '0');
        kp_update_setting('youtube_stats_interval_hours', (int)($_POST['youtube_stats_interval_hours'] ?? 6));

        // Guardar playlists por podcast
        $rawPlaylists = $_POST['playlists'] ?? [];
        $cleanPlaylists = [];
        if (is_array($rawPlaylists)) {
            foreach ($rawPlaylists as $podId => $val) {
                $cleaned = kp_yt_clean_playlist_id((string)$val);
                if (!empty($cleaned)) {
                    $cleanPlaylists[(string)$podId] = $cleaned;
                }
            }
        }
        kp_update_setting('youtube_stats_playlists', json_encode($cleanPlaylists));

        $msg = 'Configuración de YouTube guardada correctamente.';
    } elseif ($action === 'sync_now') {
        $podId = (int)($_POST['sync_podcast_id'] ?? 0);
        $sync_results = [];

        if ($podId > 0) {
            $err = null;
            $res = kp_youtube_sync_podcast($podId, $err);
            if ($err) {
                $error = "Error al sincronizar: " . htmlspecialchars($err);
            } else {
                $msg = "Sincronización completada: {$res['matched']} vídeos emparejados con éxito (" . number_format($res['total_views']) . " reproducciones registradas).";
                $sync_results = $res;
            }
        } else {
            // Sincronizar todos los que tengan playlist configurada
            $playlists = json_decode(kp_setting('youtube_stats_playlists', '{}'), true) ?: [];
            $totalMatched = 0;
            $totalViews = 0;
            $errors = [];

            foreach ($playlists as $pId => $plist) {
                $err = null;
                $res = kp_youtube_sync_podcast((int)$pId, $err);
                if ($err) {
                    $errors[] = "Podcast #$pId: $err";
                } else {
                    $totalMatched += $res['matched'];
                    $totalViews += $res['total_views'];
                }
            }

            if (!empty($errors)) {
                $error = implode('<br>', $errors);
            } else {
                $msg = "Sincronización global completada: $totalMatched vídeos emparejados (" . number_format($totalViews) . " visualizaciones).";
            }
        }
    }
}

// Cargar valores actuales
$api_key = kp_setting('youtube_stats_api_key', '');
$auto_sync = kp_setting('youtube_stats_auto_sync', '1') === '1';
$interval_hours = (int)kp_setting('youtube_stats_interval_hours', '6');
$playlists = json_decode(kp_setting('youtube_stats_playlists', '{}'), true) ?: [];
$last_sync = (int)kp_setting('youtube_stats_last_sync', '0');
?>

<div class="card span-12" style="max-width:920px; margin:0 auto">
  <div class="card-head" style="margin-bottom:20px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px">
    <div>
      <div style="display:flex; align-items:center; gap:10px">
        <span style="display:inline-flex; align-items:center; justify-content:center; width:34px; height:34px; border-radius:8px; background:rgba(239,68,68,0.15); color:#ef4444; font-size:18px">
          ▶️
        </span>
        <h2 style="margin:0; font-size:1.35rem">Estadísticas de YouTube para Podcasts</h2>
      </div>
      <p class="help" style="margin:6px 0 0 0; font-size:0.88rem">
        Sincroniza y suma las reproducciones, me gusta y comentarios de tus episodios publicados automáticamente en YouTube vía RSS.
      </p>
    </div>

    <?php if ($last_sync > 0): ?>
      <div style="font-size:12px; color:var(--text-3); text-align:right">
        Última sincronización:<br>
        <strong style="color:var(--text-1)"><?= date('d/m/Y H:i', $last_sync) ?></strong>
      </div>
    <?php endif; ?>
  </div>

  <?php if (!empty($msg)): ?>
    <div style="background:rgba(16,185,129,0.15); border:1px solid rgba(16,185,129,0.3); color:#10b981; padding:12px 16px; border-radius:8px; margin-bottom:20px; font-size:14px">
      ✅ <?= $msg ?>
    </div>
  <?php endif; ?>

  <?php if (!empty($error)): ?>
    <div style="background:rgba(239,68,68,0.15); border:1px solid rgba(239,68,68,0.3); color:#ef4444; padding:12px 16px; border-radius:8px; margin-bottom:20px; font-size:14px">
      ⚠️ <?= $error ?>
    </div>
  <?php endif; ?>

  <!-- Resumen de Métricas Globales de YouTube -->
  <?php
    $globalStats = kp_one("
      SELECT 
        COALESCE(SUM(views), 0)    AS total_views,
        COALESCE(SUM(likes), 0)    AS total_likes,
        COALESCE(SUM(comments), 0) AS total_comments,
        COUNT(episode_id)          AS total_episodes
      FROM youtube_episode_stats
    ");
  ?>
  <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:12px; margin-bottom:24px">
    <div style="background:var(--surface2, rgba(255,255,255,0.03)); border:1px solid var(--border); border-radius:10px; padding:14px 16px">
      <div style="font-size:11px; text-transform:uppercase; letter-spacing:0.05em; color:var(--text-3)">Vistas en YouTube</div>
      <div style="font-size:22px; font-weight:700; color:#ef4444; margin-top:4px">
        <?= number_format($globalStats['total_views'] ?? 0) ?>
      </div>
    </div>
    <div style="background:var(--surface2, rgba(255,255,255,0.03)); border:1px solid var(--border); border-radius:10px; padding:14px 16px">
      <div style="font-size:11px; text-transform:uppercase; letter-spacing:0.05em; color:var(--text-3)">Me gusta (Likes)</div>
      <div style="font-size:22px; font-weight:700; color:var(--text-1); margin-top:4px">
        <?= number_format($globalStats['total_likes'] ?? 0) ?>
      </div>
    </div>
    <div style="background:var(--surface2, rgba(255,255,255,0.03)); border:1px solid var(--border); border-radius:10px; padding:14px 16px">
      <div style="font-size:11px; text-transform:uppercase; letter-spacing:0.05em; color:var(--text-3)">Comentarios</div>
      <div style="font-size:22px; font-weight:700; color:var(--text-1); margin-top:4px">
        <?= number_format($globalStats['total_comments'] ?? 0) ?>
      </div>
    </div>
    <div style="background:var(--surface2, rgba(255,255,255,0.03)); border:1px solid var(--border); border-radius:10px; padding:14px 16px">
      <div style="font-size:11px; text-transform:uppercase; letter-spacing:0.05em; color:var(--text-3)">Episodios Vinculados</div>
      <div style="font-size:22px; font-weight:700; color:var(--accent); margin-top:4px">
        <?= number_format($globalStats['total_episodes'] ?? 0) ?>
      </div>
    </div>
  </div>

  <!-- Formulario de Configuración Principal -->
  <form method="POST" style="margin-bottom:30px">
    <?= kp_csrf_field() ?>
    <input type="hidden" name="action_type" value="save_settings">

    <div class="field-row">
      <div class="field">
        <label class="label" style="display:flex; justify-content:space-between; align-items:center">
          <span>YouTube Data API v3 Key</span>
          <a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener" style="font-size:12px; color:var(--accent); text-decoration:none">
            Obtener clave en Google Cloud &rarr;
          </a>
        </label>
        <input class="input" name="youtube_stats_api_key" value="<?= e($api_key) ?>" placeholder="AIzaSy..." style="font-family:ui-monospace,monospace" required>
        <div class="help" style="margin-top:4px">
          API Key pública de Google Cloud con la biblioteca <strong>YouTube Data API v3</strong> habilitada (cuota gratuita de 10.000 unidades diarias).
        </div>
      </div>
    </div>

    <div style="margin-top:22px">
      <label class="label" style="margin-bottom:8px; display:block">Playlists de YouTube por Podcast</label>
      <div class="help" style="margin-bottom:12px">
        Pega el <strong>ID de la Playlist</strong> (ej. <code>PL4n0g6c6a...</code>) o la <strong>URL completa</strong> de la lista de reproducción que YouTube Studio genera automáticamente para cada podcast.
      </div>

      <div style="display:flex; flex-direction:column; gap:10px">
        <?php foreach ($podcasts as $pod): ?>
          <?php $currentPl = $playlists[(string)$pod['id']] ?? ''; ?>
          <div style="display:flex; align-items:center; gap:12px; padding:10px 14px; background:var(--surface2, rgba(255,255,255,0.02)); border:1px solid var(--border); border-radius:8px">
            <div style="min-width:180px; font-weight:600; font-size:13.5px">
              🎙️ <?= e($pod['title']) ?>
            </div>
            <div style="flex:1">
              <input class="input" name="playlists[<?= (int)$pod['id'] ?>]" value="<?= e($currentPl) ?>" placeholder="https://www.youtube.com/playlist?list=PL... o ID" style="font-size:12.5px; font-family:ui-monospace,monospace">
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="field-row" style="margin-top:22px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:16px">
      <div style="display:flex; align-items:center; gap:8px">
        <input type="checkbox" id="yt_auto_sync" name="youtube_stats_auto_sync" value="1" <?= $auto_sync ? 'checked' : '' ?>>
        <label for="yt_auto_sync" style="font-size:13.5px; cursor:pointer">
          Sincronizar automáticamente en segundo plano
        </label>
      </div>

      <div style="display:flex; align-items:center; gap:8px">
        <label style="font-size:12.5px; color:var(--text-2)">Frecuencia:</label>
        <select class="input" name="youtube_stats_interval_hours" style="width:auto; padding:4px 10px; font-size:12.5px">
          <option value="3" <?= $interval_hours === 3 ? 'selected' : '' ?>>Cada 3 horas</option>
          <option value="6" <?= $interval_hours === 6 ? 'selected' : '' ?>>Cada 6 horas (Recomendado)</option>
          <option value="12" <?= $interval_hours === 12 ? 'selected' : '' ?>>Cada 12 horas</option>
          <option value="24" <?= $interval_hours === 24 ? 'selected' : '' ?>>Una vez al día</option>
        </select>
      </div>
    </div>

    <div style="margin-top:24px; display:flex; gap:12px; align-items:center">
      <button class="btn btn-primary" type="submit">Guardar Configuración</button>
      <a class="btn" href="<?= admin_url('plugins') ?>">Volver a Plugins</a>
    </div>
  </form>

  <!-- Panel de Sincronización Manual Inmediata -->
  <div style="margin-top:30px; padding-top:24px; border-top:1px solid var(--border)">
    <h3 style="margin:0 0 10px 0; font-size:1.05rem">Sincronización Manual Inmediata</h3>
    <p class="help" style="margin:0 0 14px 0">
      Fuerza una actualización ahora mismo para consultar YouTube y vincular los nuevos episodios emitidos.
    </p>

    <form method="POST" style="display:flex; align-items:center; gap:10px; flex-wrap:wrap">
      <?= kp_csrf_field() ?>
      <input type="hidden" name="action_type" value="sync_now">
      <select class="input" name="sync_podcast_id" style="max-width:280px">
        <option value="0">Todos los podcasts configurados</option>
        <?php foreach ($podcasts as $pod): ?>
          <?php if (!empty($playlists[(string)$pod['id']])): ?>
            <option value="<?= (int)$pod['id'] ?>"><?= e($pod['title']) ?></option>
          <?php endif; ?>
        <?php endforeach; ?>
      </select>
      <button class="btn" type="submit" style="background:#ef4444; color:#fff; border-color:#dc2626">
        ▶️ Sincronizar con YouTube Ahora
      </button>
    </form>
  </div>

  <!-- Detalles de la última sincronización en vivo -->
  <?php if (!empty($sync_results['details'])): ?>
    <div style="margin-top:24px">
      <h4 style="margin:0 0 12px 0; font-size:0.95rem">Detalle de episodios procesados:</h4>
      <div style="overflow-x:auto; border:1px solid var(--border); border-radius:8px">
        <table style="width:100%; border-collapse:collapse; font-size:12.5px; text-align:left">
          <thead>
            <tr style="background:var(--surface2, rgba(255,255,255,0.03)); border-bottom:1px solid var(--border)">
              <th style="padding:8px 12px">Vídeo en YouTube</th>
              <th style="padding:8px 12px">Episodio KutPod</th>
              <th style="padding:8px 12px; text-align:right">Vistas</th>
              <th style="padding:8px 12px; text-align:right">Likes</th>
              <th style="padding:8px 12px; text-align:center">Estado</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($sync_results['details'] as $det): ?>
              <tr style="border-bottom:1px solid var(--border)">
                <td style="padding:8px 12px">
                  <a href="https://www.youtube.com/watch?v=<?= urlencode($det['video_id']) ?>" target="_blank" rel="noopener" style="color:inherit; text-decoration:none; font-weight:600">
                    <?= e($det['video_title']) ?>
                  </a>
                </td>
                <td style="padding:8px 12px; color:var(--text-2)">
                  <?= $det['episode_name'] ? '🎙️ ' . e($det['episode_name']) : '<em>No emparejado</em>' ?>
                </td>
                <td style="padding:8px 12px; text-align:right; font-weight:700; color:#ef4444">
                  <?= number_format($det['views']) ?>
                </td>
                <td style="padding:8px 12px; text-align:right">
                  <?= number_format($det['likes']) ?>
                </td>
                <td style="padding:8px 12px; text-align:center">
                  <?php if ($det['matched']): ?>
                    <span style="display:inline-block; padding:2px 8px; border-radius:99px; background:rgba(16,185,129,0.15); color:#10b981; font-weight:600; font-size:11px">
                      Vinculado
                    </span>
                  <?php else: ?>
                    <span style="display:inline-block; padding:2px 8px; border-radius:99px; background:rgba(239,68,68,0.15); color:#ef4444; font-weight:600; font-size:11px">
                      Sin vincular
                    </span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>

</div>

