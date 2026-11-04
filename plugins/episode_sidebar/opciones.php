<?php
// ============================================================================
// KutPod · Opciones del Plugin de Barra Lateral de Episodios
// ============================================================================

// Evitar acceso directo
if (!defined('KUTPOD_VERSION')) {
    exit;
}

// Cargar helper del plugin si no está presente
if (!function_exists('kp_episode_sidebar_get_config')) {
    require_once __DIR__ . '/episode_sidebar.php';
}

// Procesar el guardado si es POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Sanitizar y verificar el orden de widgets
    $validKeys = ['platforms', 'navigation', 'persons', 'meta', 'support', 'fediverse_likes', 'embed'];
    $rawOrder = $_POST['widgets_order'] ?? '';
    $orderArray = [];
    if (is_array($rawOrder)) {
        $orderArray = $rawOrder;
    } elseif (is_string($rawOrder) && $rawOrder !== '') {
        $orderArray = explode(',', $rawOrder);
    }
    $cleanOrder = [];
    foreach ($orderArray as $k) {
        $k = trim($k);
        if (in_array($k, $validKeys, true) && !in_array($k, $cleanOrder, true)) {
            $cleanOrder[] = $k;
        }
    }
    foreach ($validKeys as $k) {
        if (!in_array($k, $cleanOrder, true)) {
            $cleanOrder[] = $k;
        }
    }

    $cfg = [
        'mode'                  => in_array($_POST['mode'] ?? '', ['no_chapters', 'always'], true) ? $_POST['mode'] : 'no_chapters',
        'widgets_order'         => $cleanOrder,
        'show_platforms'        => !empty($_POST['show_platforms']),
        'title_platforms'       => trim($_POST['title_platforms'] ?? 'Escúchalo en'),
        'show_rss'              => !empty($_POST['show_rss']),
        'show_navigation'       => !empty($_POST['show_navigation']),
        'title_navigation'      => trim($_POST['title_navigation'] ?? 'Navegación'),
        'show_persons'          => !empty($_POST['show_persons']),
        'title_persons'         => trim($_POST['title_persons'] ?? 'Participantes'),
        'show_meta'             => !empty($_POST['show_meta']),
        'title_meta'            => trim($_POST['title_meta'] ?? 'Detalles del episodio'),
        'show_download'         => !empty($_POST['show_download']),
        'show_transcript'       => !empty($_POST['show_transcript']),
        'show_support'          => !empty($_POST['show_support']),
        'title_support'         => trim($_POST['title_support'] ?? 'Apoyo y Comunidad'),
        'show_fediverse_likes'  => !empty($_POST['show_fediverse_likes']),
        'title_fediverse_likes' => trim($_POST['title_fediverse_likes'] ?? 'Me gusta en el Fediverso'),
        'show_embed'            => !empty($_POST['show_embed']),
        'title_embed'           => trim($_POST['title_embed'] ?? 'Incrustar reproductor'),
    ];

    kp_update_setting('episode_sidebar_config', json_encode($cfg));

    require_once __DIR__ . '/../../includes/cache.php';
    kp_cache_clear();

    setcookie('kp_flash', 'Configuración de la barra lateral guardada correctamente', time() + 30, '/');
    header('Location: ' . admin_url('plugins?action=settings&plugin=episode_sidebar'));
    exit;
}

$cfg = kp_episode_sidebar_get_config();
$widgetsOrder = $cfg['widgets_order'] ?? ['platforms', 'navigation', 'persons', 'meta', 'support', 'fediverse_likes', 'embed'];
?>

<div class="card span-12" style="max-width:840px; margin:0 auto">
  <div class="card-head" style="margin-bottom:24px; display:flex; justify-content:space-between; align-items:flex-start">
    <div>
      <h2 style="margin:0 0 6px 0">Configuración · Barra Lateral de Episodios</h2>
      <p class="help" style="margin:0; font-size:13px">Personaliza qué bloques se muestran en el sidebar y arrastra las tarjetas para cambiar su orden.</p>
    </div>
    <span class="badge" style="background:rgba(16,185,129,0.12); color:#10b981; font-weight:600; font-size:11px; padding:4px 8px; border-radius:6px">Activo</span>
  </div>

  <form method="POST" id="sidebarConfigForm">
    <?= kp_csrf_field() ?>
    <input type="hidden" name="widgets_order" id="kpWidgetsOrderInput" value="<?= e(implode(',', $widgetsOrder)) ?>">

    <!-- 1. Regla de Visualización -->
    <div style="background:var(--surface-2); border:1px solid var(--border); border-radius:12px; padding:18px 20px; margin-bottom:28px">
      <div style="font-weight:700; font-size:14px; margin-bottom:6px">Regla de visualización</div>
      <p class="help" style="margin:0 0 14px 0; font-size:12.5px">Elige cuándo deben mostrarse estos widgets en la página del episodio.</p>

      <div style="display:flex; flex-direction:column; gap:10px">
        <label style="display:flex; align-items:flex-start; gap:10px; cursor:pointer">
          <input type="radio" name="mode" value="no_chapters" <?= $cfg['mode'] === 'no_chapters' ? 'checked' : '' ?> style="margin-top:3px; accent-color:var(--accent)">
          <div>
            <div style="font-size:13px; font-weight:600">Solo cuando el episodio NO tenga capítulos <span style="font-size:11px; color:var(--accent); font-weight:700; margin-left:4px">(Recomendado)</span></div>
            <div style="font-size:12px; color:var(--text-3); margin-top:2px">Cubre el espacio en blanco bajo la carátula sin competir con la lista de capítulos.</div>
          </div>
        </label>

        <label style="display:flex; align-items:flex-start; gap:10px; cursor:pointer">
          <input type="radio" name="mode" value="always" <?= $cfg['mode'] === 'always' ? 'checked' : '' ?> style="margin-top:3px; accent-color:var(--accent)">
          <div>
            <div style="font-size:13px; font-weight:600">Siempre visible</div>
            <div style="font-size:12px; color:var(--text-3); margin-top:2px">Si el episodio tiene capítulos, estos widgets se mostrarán ordenadamente debajo de los capítulos.</div>
          </div>
        </label>
      </div>
    </div>

    <!-- 2. Bloques Configurables con Drag & Drop -->
    <div style="display:flex; justify-content:space-between; align-items:baseline; margin-bottom:14px">
      <div>
        <div style="font-weight:700; font-size:15px">Bloques y Widgets</div>
        <div class="help" style="font-size:12px; margin-top:2px">Arrastra cualquier tarjeta desde el icono <span style="font-family:monospace; font-weight:bold">⋮⋮</span> o usa las flechas ▲ / ▼ para ordenar cómo aparecen en la página.</div>
      </div>
    </div>

    <div id="widgetsSortableList" style="display:flex; flex-direction:column; gap:14px; margin-bottom:28px">
      <?php foreach ($widgetsOrder as $idx => $wKey): ?>
        <?php if ($wKey === 'platforms'): ?>
          <!-- Widget 1: Plataformas de Escucha -->
          <div class="widget-drag-card" data-widget-key="platforms" draggable="true" style="border:1px solid var(--border); border-radius:12px; padding:16px 18px; background:var(--surface); transition:box-shadow 0.15s ease, border-color 0.15s ease">
            <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:10px">
              <div style="display:flex; align-items:center; gap:10px">
                <div class="drag-handle" title="Arrastra para reordenar" style="cursor:grab; display:flex; align-items:center; justify-content:center; color:var(--text-3); width:28px; height:28px; border-radius:6px; background:var(--surface-2); border:1px solid var(--border)">
                  <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="9" cy="5" r="1.5"></circle><circle cx="9" cy="12" r="1.5"></circle><circle cx="9" cy="19" r="1.5"></circle><circle cx="15" cy="5" r="1.5"></circle><circle cx="15" cy="12" r="1.5"></circle><circle cx="15" cy="19" r="1.5"></circle></svg>
                </div>
                <span class="widget-order-badge" style="font-size:11px; font-weight:700; background:rgba(99,102,241,0.12); color:#6366f1; padding:2px 8px; border-radius:6px">#<span class="order-num"><?= $idx + 1 ?></span></span>
                <div style="display:flex; gap:3px">
                  <button type="button" class="btn-order-move btn-order-up" title="Subir posición" style="background:var(--surface-2); border:1px solid var(--border); border-radius:4px; padding:1px 5px; font-size:9px; cursor:pointer; color:var(--text-2)">▲</button>
                  <button type="button" class="btn-order-move btn-order-down" title="Bajar posición" style="background:var(--surface-2); border:1px solid var(--border); border-radius:4px; padding:1px 5px; font-size:9px; cursor:pointer; color:var(--text-2)">▼</button>
                </div>
              </div>
              <label class="toggle">
                <input type="checkbox" name="show_platforms" value="1" <?= !empty($cfg['show_platforms']) ? 'checked' : '' ?>>
                <span class="toggle-track"></span>
                <span class="toggle-label" style="font-weight:600">Plataformas de escucha («Escúchalo en...»)</span>
              </label>
            </div>
            <p class="help" style="margin:0 0 12px 0; font-size:12px">Muestra las apps donde está distribuido el podcast (Spotify, Apple Podcasts, etc.).</p>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; align-items:center">
              <div class="field">
                <label class="label" style="font-size:11.5px">Título del bloque</label>
                <input class="input" name="title_platforms" value="<?= e($cfg['title_platforms']) ?>" placeholder="Escúchalo en">
              </div>
              <div style="padding-top:18px">
                <label class="checkbox">
                  <input type="checkbox" name="show_rss" value="1" <?= !empty($cfg['show_rss']) ? 'checked' : '' ?>>
                  <span class="check-box"></span>
                  <span style="font-size:12.5px">Incluir botón directo al Feed RSS</span>
                </label>
              </div>
            </div>
          </div>

        <?php elseif ($wKey === 'navigation'): ?>
          <!-- Widget 2: Navegación entre Episodios -->
          <div class="widget-drag-card" data-widget-key="navigation" draggable="true" style="border:1px solid var(--border); border-radius:12px; padding:16px 18px; background:var(--surface); transition:box-shadow 0.15s ease, border-color 0.15s ease">
            <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:10px">
              <div style="display:flex; align-items:center; gap:10px">
                <div class="drag-handle" title="Arrastra para reordenar" style="cursor:grab; display:flex; align-items:center; justify-content:center; color:var(--text-3); width:28px; height:28px; border-radius:6px; background:var(--surface-2); border:1px solid var(--border)">
                  <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="9" cy="5" r="1.5"></circle><circle cx="9" cy="12" r="1.5"></circle><circle cx="9" cy="19" r="1.5"></circle><circle cx="15" cy="5" r="1.5"></circle><circle cx="15" cy="12" r="1.5"></circle><circle cx="15" cy="19" r="1.5"></circle></svg>
                </div>
                <span class="widget-order-badge" style="font-size:11px; font-weight:700; background:rgba(99,102,241,0.12); color:#6366f1; padding:2px 8px; border-radius:6px">#<span class="order-num"><?= $idx + 1 ?></span></span>
                <div style="display:flex; gap:3px">
                  <button type="button" class="btn-order-move btn-order-up" title="Subir posición" style="background:var(--surface-2); border:1px solid var(--border); border-radius:4px; padding:1px 5px; font-size:9px; cursor:pointer; color:var(--text-2)">▲</button>
                  <button type="button" class="btn-order-move btn-order-down" title="Bajar posición" style="background:var(--surface-2); border:1px solid var(--border); border-radius:4px; padding:1px 5px; font-size:9px; cursor:pointer; color:var(--text-2)">▼</button>
                </div>
              </div>
              <label class="toggle">
                <input type="checkbox" name="show_navigation" value="1" <?= !empty($cfg['show_navigation']) ? 'checked' : '' ?>>
                <span class="toggle-track"></span>
                <span class="toggle-label" style="font-weight:600">Navegación entre episodios (Anterior / Siguiente)</span>
              </label>
            </div>
            <p class="help" style="margin:0 0 12px 0; font-size:12px">Permite saltar rápidamente al episodio anterior o siguiente del podcast.</p>
            <div class="field" style="max-width:320px">
              <label class="label" style="font-size:11.5px">Título del bloque</label>
              <input class="input" name="title_navigation" value="<?= e($cfg['title_navigation']) ?>" placeholder="Navegación">
            </div>
          </div>

        <?php elseif ($wKey === 'persons'): ?>
          <!-- Widget 3: Participantes -->
          <div class="widget-drag-card" data-widget-key="persons" draggable="true" style="border:1px solid var(--border); border-radius:12px; padding:16px 18px; background:var(--surface); transition:box-shadow 0.15s ease, border-color 0.15s ease">
            <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:10px">
              <div style="display:flex; align-items:center; gap:10px">
                <div class="drag-handle" title="Arrastra para reordenar" style="cursor:grab; display:flex; align-items:center; justify-content:center; color:var(--text-3); width:28px; height:28px; border-radius:6px; background:var(--surface-2); border:1px solid var(--border)">
                  <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="9" cy="5" r="1.5"></circle><circle cx="9" cy="12" r="1.5"></circle><circle cx="9" cy="19" r="1.5"></circle><circle cx="15" cy="5" r="1.5"></circle><circle cx="15" cy="12" r="1.5"></circle><circle cx="15" cy="19" r="1.5"></circle></svg>
                </div>
                <span class="widget-order-badge" style="font-size:11px; font-weight:700; background:rgba(99,102,241,0.12); color:#6366f1; padding:2px 8px; border-radius:6px">#<span class="order-num"><?= $idx + 1 ?></span></span>
                <div style="display:flex; gap:3px">
                  <button type="button" class="btn-order-move btn-order-up" title="Subir posición" style="background:var(--surface-2); border:1px solid var(--border); border-radius:4px; padding:1px 5px; font-size:9px; cursor:pointer; color:var(--text-2)">▲</button>
                  <button type="button" class="btn-order-move btn-order-down" title="Bajar posición" style="background:var(--surface-2); border:1px solid var(--border); border-radius:4px; padding:1px 5px; font-size:9px; cursor:pointer; color:var(--text-2)">▼</button>
                </div>
              </div>
              <label class="toggle">
                <input type="checkbox" name="show_persons" value="1" <?= !empty($cfg['show_persons']) ? 'checked' : '' ?>>
                <span class="toggle-track"></span>
                <span class="toggle-label" style="font-weight:600">Participantes / Invitados</span>
              </label>
            </div>
            <p class="help" style="margin:0 0 12px 0; font-size:12px">Muestra las personas vinculadas al episodio con su avatar, nombre y rol.</p>
            <div class="field" style="max-width:320px">
              <label class="label" style="font-size:11.5px">Título del bloque</label>
              <input class="input" name="title_persons" value="<?= e($cfg['title_persons']) ?>" placeholder="Participantes">
            </div>
          </div>

        <?php elseif ($wKey === 'meta'): ?>
          <!-- Widget 4: Ficha Técnica y Descarga -->
          <div class="widget-drag-card" data-widget-key="meta" draggable="true" style="border:1px solid var(--border); border-radius:12px; padding:16px 18px; background:var(--surface); transition:box-shadow 0.15s ease, border-color 0.15s ease">
            <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:10px">
              <div style="display:flex; align-items:center; gap:10px">
                <div class="drag-handle" title="Arrastra para reordenar" style="cursor:grab; display:flex; align-items:center; justify-content:center; color:var(--text-3); width:28px; height:28px; border-radius:6px; background:var(--surface-2); border:1px solid var(--border)">
                  <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="9" cy="5" r="1.5"></circle><circle cx="9" cy="12" r="1.5"></circle><circle cx="9" cy="19" r="1.5"></circle><circle cx="15" cy="5" r="1.5"></circle><circle cx="15" cy="12" r="1.5"></circle><circle cx="15" cy="19" r="1.5"></circle></svg>
                </div>
                <span class="widget-order-badge" style="font-size:11px; font-weight:700; background:rgba(99,102,241,0.12); color:#6366f1; padding:2px 8px; border-radius:6px">#<span class="order-num"><?= $idx + 1 ?></span></span>
                <div style="display:flex; gap:3px">
                  <button type="button" class="btn-order-move btn-order-up" title="Subir posición" style="background:var(--surface-2); border:1px solid var(--border); border-radius:4px; padding:1px 5px; font-size:9px; cursor:pointer; color:var(--text-2)">▲</button>
                  <button type="button" class="btn-order-move btn-order-down" title="Bajar posición" style="background:var(--surface-2); border:1px solid var(--border); border-radius:4px; padding:1px 5px; font-size:9px; cursor:pointer; color:var(--text-2)">▼</button>
                </div>
              </div>
              <label class="toggle">
                <input type="checkbox" name="show_meta" value="1" <?= !empty($cfg['show_meta']) ? 'checked' : '' ?>>
                <span class="toggle-track"></span>
                <span class="toggle-label" style="font-weight:600">Ficha técnica y Descarga</span>
              </label>
            </div>
            <p class="help" style="margin:0 0 12px 0; font-size:12px">Muestra duración, tamaño del archivo en MB, tipo y fecha de publicación.</p>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; align-items:center">
              <div class="field">
                <label class="label" style="font-size:11.5px">Título del bloque</label>
                <input class="input" name="title_meta" value="<?= e($cfg['title_meta']) ?>" placeholder="Detalles del episodio">
              </div>
              <div style="display:flex; flex-direction:column; gap:8px; padding-top:12px">
                <label class="checkbox">
                  <input type="checkbox" name="show_download" value="1" <?= !empty($cfg['show_download']) ? 'checked' : '' ?>>
                  <span class="check-box"></span>
                  <span style="font-size:12.5px">Mostrar botón de Descarga directa (MP3)</span>
                </label>
                <label class="checkbox">
                  <input type="checkbox" name="show_transcript" value="1" <?= !empty($cfg['show_transcript']) ? 'checked' : '' ?>>
                  <span class="check-box"></span>
                  <span style="font-size:12.5px">Mostrar enlace a la Transcripción (si existe)</span>
                </label>
              </div>
            </div>
          </div>

        <?php elseif ($wKey === 'support'): ?>
          <!-- Widget 5: Apoyo y Comunidad -->
          <div class="widget-drag-card" data-widget-key="support" draggable="true" style="border:1px solid var(--border); border-radius:12px; padding:16px 18px; background:var(--surface); transition:box-shadow 0.15s ease, border-color 0.15s ease">
            <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:10px">
              <div style="display:flex; align-items:center; gap:10px">
                <div class="drag-handle" title="Arrastra para reordenar" style="cursor:grab; display:flex; align-items:center; justify-content:center; color:var(--text-3); width:28px; height:28px; border-radius:6px; background:var(--surface-2); border:1px solid var(--border)">
                  <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="9" cy="5" r="1.5"></circle><circle cx="9" cy="12" r="1.5"></circle><circle cx="9" cy="19" r="1.5"></circle><circle cx="15" cy="5" r="1.5"></circle><circle cx="15" cy="12" r="1.5"></circle><circle cx="15" cy="19" r="1.5"></circle></svg>
                </div>
                <span class="widget-order-badge" style="font-size:11px; font-weight:700; background:rgba(99,102,241,0.12); color:#6366f1; padding:2px 8px; border-radius:6px">#<span class="order-num"><?= $idx + 1 ?></span></span>
                <div style="display:flex; gap:3px">
                  <button type="button" class="btn-order-move btn-order-up" title="Subir posición" style="background:var(--surface-2); border:1px solid var(--border); border-radius:4px; padding:1px 5px; font-size:9px; cursor:pointer; color:var(--text-2)">▲</button>
                  <button type="button" class="btn-order-move btn-order-down" title="Bajar posición" style="background:var(--surface-2); border:1px solid var(--border); border-radius:4px; padding:1px 5px; font-size:9px; cursor:pointer; color:var(--text-2)">▼</button>
                </div>
              </div>
              <label class="toggle">
                <input type="checkbox" name="show_support" value="1" <?= !empty($cfg['show_support']) ? 'checked' : '' ?>>
                <span class="toggle-track"></span>
                <span class="toggle-label" style="font-weight:600">Apoyo y Comunidad</span>
              </label>
            </div>
            <p class="help" style="margin:0 0 12px 0; font-size:12px">Muestra enlaces a plataformas de mecenazgo (Patreon, Buy Me a Coffee) y redes sociales del show.</p>
            <div class="field" style="max-width:320px">
              <label class="label" style="font-size:11.5px">Título del bloque</label>
              <input class="input" name="title_support" value="<?= e($cfg['title_support']) ?>" placeholder="Apoyo y Comunidad">
            </div>
          </div>

        <?php elseif ($wKey === 'fediverse_likes'): ?>
          <!-- Widget 6: Me gusta en el Fediverso -->
          <div class="widget-drag-card" data-widget-key="fediverse_likes" draggable="true" style="border:1px solid var(--border); border-radius:12px; padding:16px 18px; background:var(--surface); transition:box-shadow 0.15s ease, border-color 0.15s ease">
            <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:10px">
              <div style="display:flex; align-items:center; gap:10px">
                <div class="drag-handle" title="Arrastra para reordenar" style="cursor:grab; display:flex; align-items:center; justify-content:center; color:var(--text-3); width:28px; height:28px; border-radius:6px; background:var(--surface-2); border:1px solid var(--border)">
                  <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="9" cy="5" r="1.5"></circle><circle cx="9" cy="12" r="1.5"></circle><circle cx="9" cy="19" r="1.5"></circle><circle cx="15" cy="5" r="1.5"></circle><circle cx="15" cy="12" r="1.5"></circle><circle cx="15" cy="19" r="1.5"></circle></svg>
                </div>
                <span class="widget-order-badge" style="font-size:11px; font-weight:700; background:rgba(99,102,241,0.12); color:#6366f1; padding:2px 8px; border-radius:6px">#<span class="order-num"><?= $idx + 1 ?></span></span>
                <div style="display:flex; gap:3px">
                  <button type="button" class="btn-order-move btn-order-up" title="Subir posición" style="background:var(--surface-2); border:1px solid var(--border); border-radius:4px; padding:1px 5px; font-size:9px; cursor:pointer; color:var(--text-2)">▲</button>
                  <button type="button" class="btn-order-move btn-order-down" title="Bajar posición" style="background:var(--surface-2); border:1px solid var(--border); border-radius:4px; padding:1px 5px; font-size:9px; cursor:pointer; color:var(--text-2)">▼</button>
                </div>
              </div>
              <label class="toggle">
                <input type="checkbox" name="show_fediverse_likes" value="1" <?= !empty($cfg['show_fediverse_likes']) ? 'checked' : '' ?>>
                <span class="toggle-track"></span>
                <span class="toggle-label" style="font-weight:600">Likes del Fediverso (ActivityPub)</span>
              </label>
            </div>
            <p class="help" style="margin:0 0 12px 0; font-size:12px">Muestra los avatares y la cantidad de likes recibidos desde Mastodon y otras instancias federadas para este episodio.</p>
            <div class="field" style="max-width:320px">
              <label class="label" style="font-size:11.5px">Título del bloque</label>
              <input class="input" name="title_fediverse_likes" value="<?= e($cfg['title_fediverse_likes'] ?? 'Me gusta en el Fediverso') ?>" placeholder="Me gusta en el Fediverso">
            </div>
          </div>

        <?php elseif ($wKey === 'embed'): ?>
          <!-- Widget 7: Incrustar reproductor -->
          <div class="widget-drag-card" data-widget-key="embed" draggable="true" style="border:1px solid var(--border); border-radius:12px; padding:16px 18px; background:var(--surface); transition:box-shadow 0.15s ease, border-color 0.15s ease">
            <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:10px">
              <div style="display:flex; align-items:center; gap:10px">
                <div class="drag-handle" title="Arrastra para reordenar" style="cursor:grab; display:flex; align-items:center; justify-content:center; color:var(--text-3); width:28px; height:28px; border-radius:6px; background:var(--surface-2); border:1px solid var(--border)">
                  <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="9" cy="5" r="1.5"></circle><circle cx="9" cy="12" r="1.5"></circle><circle cx="9" cy="19" r="1.5"></circle><circle cx="15" cy="5" r="1.5"></circle><circle cx="15" cy="12" r="1.5"></circle><circle cx="15" cy="19" r="1.5"></circle></svg>
                </div>
                <span class="widget-order-badge" style="font-size:11px; font-weight:700; background:rgba(99,102,241,0.12); color:#6366f1; padding:2px 8px; border-radius:6px">#<span class="order-num"><?= $idx + 1 ?></span></span>
                <div style="display:flex; gap:3px">
                  <button type="button" class="btn-order-move btn-order-up" title="Subir posición" style="background:var(--surface-2); border:1px solid var(--border); border-radius:4px; padding:1px 5px; font-size:9px; cursor:pointer; color:var(--text-2)">▲</button>
                  <button type="button" class="btn-order-move btn-order-down" title="Bajar posición" style="background:var(--surface-2); border:1px solid var(--border); border-radius:4px; padding:1px 5px; font-size:9px; cursor:pointer; color:var(--text-2)">▼</button>
                </div>
              </div>
              <label class="toggle">
                <input type="checkbox" name="show_embed" value="1" <?= !empty($cfg['show_embed']) ? 'checked' : '' ?>>
                <span class="toggle-track"></span>
                <span class="toggle-label" style="font-weight:600">Incrustar reproductor (Widget de Compartir)</span>
              </label>
            </div>
            <p class="help" style="margin:0 0 12px 0; font-size:12px">Muestra una caja con el código HTML <code>&lt;iframe&gt;</code> para copiar e incrustar el reproductor en blogs o sitios web externos.</p>
            <div class="field" style="max-width:320px">
              <label class="label" style="font-size:11.5px">Título del bloque</label>
              <input class="input" name="title_embed" value="<?= e($cfg['title_embed'] ?? 'Incrustar reproductor') ?>" placeholder="Incrustar reproductor">
            </div>
          </div>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>

    <!-- Botones de Acción -->
    <div style="display:flex; gap:12px; align-items:center; padding-top:8px; border-top:1px solid var(--border)">
      <button class="btn btn-primary" type="submit">Guardar Configuración</button>
      <a class="btn" href="<?= admin_url('plugins') ?>">Volver a Plugins</a>
    </div>
  </form>
</div>

<style>
  .widget-drag-card.is-dragging {
    opacity: 0.4;
    border: 2px dashed var(--accent, #10b981) !important;
    transform: scale(0.99);
  }
  .widget-drag-card.drag-over-top {
    border-top: 3px solid var(--accent, #10b981) !important;
  }
  .widget-drag-card.drag-over-bottom {
    border-bottom: 3px solid var(--accent, #10b981) !important;
  }
  .drag-handle:hover {
    color: var(--accent, #10b981) !important;
    background: var(--surface) !important;
  }
  .btn-order-move:hover {
    background: var(--surface) !important;
    color: var(--accent, #10b981) !important;
  }
</style>

<script>
(function() {
  const list = document.getElementById('widgetsSortableList');
  const inputOrder = document.getElementById('kpWidgetsOrderInput');
  if (!list || !inputOrder) return;

  function updateOrder() {
    const cards = Array.from(list.querySelectorAll('.widget-drag-card'));
    const keys = cards.map(c => c.getAttribute('data-widget-key'));
    inputOrder.value = keys.join(',');

    cards.forEach((c, idx) => {
      const numEl = c.querySelector('.order-num');
      if (numEl) numEl.textContent = String(idx + 1);
    });
  }

  // Delegación de botones de subir y bajar
  list.addEventListener('click', (e) => {
    const btnUp = e.target.closest('.btn-order-up');
    const btnDown = e.target.closest('.btn-order-down');
    if (!btnUp && !btnDown) return;

    const card = e.target.closest('.widget-drag-card');
    if (!card) return;

    if (btnUp) {
      const prev = card.previousElementSibling;
      if (prev && prev.classList.contains('widget-drag-card')) {
        list.insertBefore(card, prev);
        updateOrder();
      }
    } else if (btnDown) {
      const next = card.nextElementSibling;
      if (next && next.classList.contains('widget-drag-card')) {
        list.insertBefore(next, card);
        updateOrder();
      }
    }
  });

  // Drag and Drop
  let draggedEl = null;

  list.addEventListener('dragstart', (e) => {
    const card = e.target.closest('.widget-drag-card');
    if (!card) return;
    draggedEl = card;
    card.classList.add('is-dragging');
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/plain', card.getAttribute('data-widget-key'));
  });

  list.addEventListener('dragend', (e) => {
    const card = e.target.closest('.widget-drag-card');
    if (card) card.classList.remove('is-dragging');
    list.querySelectorAll('.widget-drag-card').forEach(c => {
      c.classList.remove('drag-over-top', 'drag-over-bottom', 'is-dragging');
    });
    draggedEl = null;
    updateOrder();
  });

  list.addEventListener('dragover', (e) => {
    e.preventDefault();
    if (!draggedEl) return;
    const targetCard = e.target.closest('.widget-drag-card');
    if (!targetCard || targetCard === draggedEl) return;

    const rect = targetCard.getBoundingClientRect();
    const midY = rect.top + rect.height / 2;
    if (e.clientY < midY) {
      list.insertBefore(draggedEl, targetCard);
    } else {
      list.insertBefore(draggedEl, targetCard.nextSibling);
    }
  });
})();
</script>
