<?php
// ============================================================================
// KutPod · Reproductor Incrustable (Embed Player) para sitios externos / blogs
// ============================================================================

// Permitir explícitamente ser cargado en iframes de cualquier sitio web
header_remove('X-Frame-Options');
header('Content-Security-Policy: frame-ancestors *');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../data.php';
require_once __DIR__ . '/../includes/helpers.php';

// Resolver episodio y podcast
$epParam = trim($_GET['ep'] ?? '');
$showParam = ltrim(trim($_GET['show'] ?? ''), '@');
$idParam = (int)($_GET['id'] ?? 0);

$ep = null;
$p = null;

if ($idParam > 0) {
    $ep = kp_one("SELECT * FROM episodes WHERE id = ? AND status = 'published'", [$idParam]);
    if ($ep) {
        $p = kp_one("SELECT * FROM podcasts WHERE id = ?", [$ep['podcast_id']]);
    }
} elseif ($showParam && $epParam) {
    $p = kp_one("SELECT * FROM podcasts WHERE slug = ?", [$showParam]);
    if ($p) {
        $ep = kp_one("SELECT * FROM episodes WHERE podcast_id = ? AND slug = ? AND status = 'published'", [$p['id'], $epParam]);
    }
    // Fallback: si por alguna razón no coincidió con ese podcast, buscar directamente por slug del episodio
    if (!$ep) {
        $ep = kp_one("SELECT * FROM episodes WHERE slug = ? AND status = 'published'", [$epParam]);
        if ($ep) {
            $p = kp_one("SELECT * FROM podcasts WHERE id = ?", [$ep['podcast_id']]);
        }
    }
} elseif ($epParam) {
    $ep = kp_one("SELECT * FROM episodes WHERE slug = ? AND status = 'published'", [$epParam]);
    if ($ep) {
        $p = kp_one("SELECT * FROM podcasts WHERE id = ?", [$ep['podcast_id']]);
    }
}

if (!$ep || !$p) {
    http_response_code(404);
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
      <meta charset="utf-8">
      <title>Episodio no encontrado · KutPod</title>
      <style>
        body { margin:0; padding:24px; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif; background:#0f172a; color:#f8fafc; display:flex; align-items:center; justify-content:center; height:100vh; box-sizing:border-box; text-align:center }
        .box { background:rgba(255,255,255,0.06); padding:24px; border-radius:12px; border:1px solid rgba(255,255,255,0.1) }
        h1 { font-size:16px; margin:0 0 8px 0 }
        p { font-size:13px; color:#94a3b8; margin:0 }
      </style>
    </head>
    <body>
      <div class="box">
        <h1>Episodio no encontrado</h1>
        <p>El contenido solicitado no está disponible o ha sido retirado.</p>
      </div>
    </body>
    </html>
    <?php
    exit;
}

$theme = $_GET['theme'] ?? 'auto'; // 'auto', 'dark', 'light'
$pSlug = $p['slug'] ?? ($p['id'] ?? '');
$epSlug = $ep['slug'] ?? ($ep['id'] ?? '');
$audioSrc = kp_base_url() . "/r/" . rawurlencode($pSlug) . "/" . rawurlencode($epSlug) . "/audio.mp3";
$cover = $ep['cover'] ?: ($p['cover'] ?? '');
if (str_starts_with($cover, 'file://')) {
    $cover = '';
}
if (str_starts_with($audioSrc, 'file://')) {
    $audioSrc = '';
}
$accent = $p['color'] ?: '#ff5f7e';
$fullEpisodeUrl = kp_base_url() . "/@" . rawurlencode($pSlug) . "/" . rawurlencode($epSlug);
if (str_starts_with($fullEpisodeUrl, 'file://')) {
    $fullEpisodeUrl = '#';
}
$durationSecs = (int)($ep['duration_secs'] ?? 0);
$durationFmt = $ep['duration'] ?: ($durationSecs > 0 ? kp_fmt_duration($durationSecs) : '0:00');
?>
<!DOCTYPE html>
<html lang="es" data-theme="<?= e($theme) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($ep['title']) ?> · <?= e($p['title']) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    :root {
      color-scheme: light;
      --accent: <?= e($accent) ?>;
      --bg: #ffffff;
      --card-bg: #f8fafc;
      --text: #0f172a;
      --text-muted: #64748b;
      --border: #e2e8f0;
      --scrub-bg: #cbd5e1;
      --hover-bg: #f1f5f9;
      --speed-bg: #f1f5f9;
      --speed-border: #cbd5e1;
    }

    @media (prefers-color-scheme: dark) {
      :root[data-theme="auto"] {
        color-scheme: dark;
        --bg: #090d16;
        --card-bg: #0f172a;
        --text: #f8fafc;
        --text-muted: #94a3b8;
        --border: rgba(255, 255, 255, 0.08);
        --scrub-bg: rgba(255, 255, 255, 0.15);
        --hover-bg: rgba(255, 255, 255, 0.08);
        --speed-bg: #1e293b;
        --speed-border: rgba(255, 255, 255, 0.14);
      }
    }

    :root[data-theme="dark"],
    html[data-theme="dark"] {
      color-scheme: dark;
      --bg: #090d16;
      --card-bg: #0f172a;
      --text: #f8fafc;
      --text-muted: #94a3b8;
      --border: rgba(255, 255, 255, 0.08);
      --scrub-bg: rgba(255, 255, 255, 0.15);
      --hover-bg: rgba(255, 255, 255, 0.08);
      --speed-bg: #1e293b;
      --speed-border: rgba(255, 255, 255, 0.14);
    }

    * { box-sizing: border-box; margin: 0; padding: 0; }

    body {
      font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
      background: var(--bg);
      color: var(--text);
      display: flex;
      flex-direction: column;
      justify-content: center;
      min-height: 100vh;
      padding: 12px;
      overflow: hidden;
      -webkit-font-smoothing: antialiased;
    }

    .kp-embed-card {
      background: var(--card-bg);
      border: 1px solid var(--border);
      border-radius: 16px;
      padding: 14px 18px;
      display: flex;
      flex-direction: column;
      gap: 12px;
      box-shadow: 0 4px 20px -4px rgba(0, 0, 0, 0.08);
      position: relative;
    }

    .kp-embed-top {
      display: flex;
      align-items: center;
      gap: 14px;
      min-width: 0;
    }

    .kp-embed-cover {
      width: 58px;
      height: 58px;
      border-radius: 12px;
      object-fit: cover;
      flex-shrink: 0;
      background: var(--border);
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.12);
    }

    .kp-embed-info {
      flex: 1;
      min-width: 0;
      display: flex;
      flex-direction: column;
      gap: 2px;
    }

    .kp-embed-podcast {
      font-size: 11.5px;
      font-weight: 600;
      color: var(--accent);
      text-transform: uppercase;
      letter-spacing: 0.05em;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .kp-embed-title {
      font-size: 14.5px;
      font-weight: 700;
      color: var(--text);
      line-height: 1.3;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      text-decoration: none;
    }

    .kp-embed-title:hover {
      color: var(--accent);
    }

    .kp-embed-badge {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      font-size: 11px;
      color: var(--text-muted);
      text-decoration: none;
      padding: 4px 8px;
      border-radius: 6px;
      border: 1px solid var(--border);
      background: var(--card-bg);
      flex-shrink: 0;
      transition: all 0.15s ease;
    }

    .kp-embed-badge:hover {
      background: var(--hover-bg);
      color: var(--text);
      border-color: var(--text-muted);
    }

    /* Controles */
    .kp-embed-controls-row {
      display: flex;
      align-items: center;
      gap: 12px;
    }

    .kp-embed-btn-play {
      width: 42px;
      height: 42px;
      border-radius: 50%;
      background: var(--accent);
      color: #ffffff;
      border: none;
      display: flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      font-size: 16px;
      flex-shrink: 0;
      box-shadow: 0 4px 14px -2px rgba(0,0,0,0.25);
      transition: transform 0.15s ease, filter 0.15s ease;
    }

    .kp-embed-btn-play:hover {
      transform: scale(1.05);
      filter: brightness(1.1);
    }

    .kp-embed-btn-skip {
      background: transparent;
      border: none;
      color: var(--text-muted);
      cursor: pointer;
      padding: 6px;
      border-radius: 8px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 11px;
      font-weight: 600;
      transition: all 0.15s ease;
    }

    .kp-embed-btn-skip:hover {
      color: var(--text);
      background: var(--hover-bg);
    }

    /* Scrubber */
    .kp-embed-scrubber-wrap {
      flex: 1;
      display: flex;
      align-items: center;
      gap: 8px;
      min-width: 0;
    }

    .kp-embed-time {
      font-size: 11.5px;
      font-weight: 500;
      color: var(--text-muted);
      font-variant-numeric: tabular-nums;
      min-width: 32px;
    }

    .kp-embed-time.cur { text-align: right; }

    .kp-embed-scrubber {
      flex: 1;
      height: 6px;
      background: var(--scrub-bg);
      border-radius: 999px;
      position: relative;
      cursor: pointer;
      touch-action: none;
    }

    .kp-embed-scrubber-fill {
      position: absolute;
      left: 0;
      top: 0;
      bottom: 0;
      width: 0%;
      background: var(--accent);
      border-radius: 999px;
      pointer-events: none;
    }

    .kp-embed-scrubber-thumb {
      position: absolute;
      top: 50%;
      left: 0%;
      width: 12px;
      height: 12px;
      background: #ffffff;
      border: 2px solid var(--accent);
      border-radius: 50%;
      transform: translate(-50%, -50%);
      pointer-events: none;
      box-shadow: 0 1px 4px rgba(0,0,0,0.3);
      opacity: 0;
      transition: opacity 0.15s ease;
    }

    .kp-embed-scrubber:hover .kp-embed-scrubber-thumb,
    .kp-embed-scrubber.dragging .kp-embed-scrubber-thumb {
      opacity: 1;
    }

    /* Extras */
    .kp-embed-extras {
      display: flex;
      align-items: center;
      gap: 8px;
      flex-shrink: 0;
    }

    .kp-embed-speed {
      appearance: none;
      -webkit-appearance: none;
      -moz-appearance: none;
      background-color: var(--speed-bg);
      border: 1px solid var(--speed-border);
      color: var(--text);
      font-family: inherit;
      font-size: 11px;
      font-weight: 600;
      padding: 4px 18px 4px 7px;
      border-radius: 6px;
      cursor: pointer;
      outline: none;
      color-scheme: light dark;
      background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='10' viewBox='0 0 24 24' fill='none' stroke='%2394a3b8' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E");
      background-repeat: no-repeat;
      background-position: right 5px center;
      background-size: 9px;
      transition: all 0.15s ease;
    }

    .kp-embed-speed:hover,
    .kp-embed-speed:focus-visible {
      border-color: var(--accent);
      color: var(--text);
    }

    .kp-embed-speed option {
      background-color: var(--card-bg);
      color: var(--text);
      font-size: 12px;
      padding: 6px 10px;
    }

    @media (max-width: 480px) {
      .kp-embed-card { padding: 10px 12px; gap: 8px; }
      .kp-embed-cover { width: 46px; height: 46px; border-radius: 8px; }
      .kp-embed-title { font-size: 13.5px; }
      .kp-embed-badge { display: none; }
      .kp-embed-btn-skip { display: none; }
    }
  </style>
</head>
<body>
  <div class="kp-embed-card">
    <div class="kp-embed-top">
      <img src="<?= e($cover) ?>" alt="" class="kp-embed-cover" loading="lazy">
      <div class="kp-embed-info">
        <span class="kp-embed-podcast"><?= e($p['title']) ?></span>
        <a href="<?= e($fullEpisodeUrl) ?>" target="_blank" class="kp-embed-title" title="<?= e($ep['title']) ?>">
          <?= e($ep['title']) ?>
        </a>
      </div>
      <a href="<?= e($fullEpisodeUrl) ?>" target="_blank" rel="noopener" class="kp-embed-badge" title="Abrir en KutPod">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path><polyline points="15 3 21 3 21 9"></polyline><line x1="10" y1="14" x2="21" y2="3"></line></svg>
        <span>KutPod</span>
      </a>
    </div>

    <div class="kp-embed-controls-row">
      <button type="button" class="kp-embed-btn-play" id="btnPlay" aria-label="Reproducir">
        <svg id="playIcon" width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><polygon points="6 3 20 12 6 21 6 3"></polygon></svg>
        <svg id="pauseIcon" width="16" height="16" viewBox="0 0 24 24" fill="currentColor" style="display:none"><rect x="6" y="4" width="4" height="16"></rect><rect x="14" y="4" width="4" height="16"></rect></svg>
      </button>

      <button type="button" class="kp-embed-btn-skip" id="btnBack" title="Retroceder 15 segundos">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="1 4 1 10 7 10"></polyline><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"></path></svg>
        <span style="margin-left:2px">15</span>
      </button>

      <div class="kp-embed-scrubber-wrap">
        <span class="kp-embed-time cur" id="timeCur">0:00</span>
        <div class="kp-embed-scrubber" id="scrubber">
          <div class="kp-embed-scrubber-fill" id="scrubFill"></div>
          <div class="kp-embed-scrubber-thumb" id="scrubThumb"></div>
        </div>
        <span class="kp-embed-time dur" id="timeDur"><?= e($durationFmt) ?></span>
      </div>

      <button type="button" class="kp-embed-btn-skip" id="btnFwd" title="Adelantar 30 segundos">
        <span style="margin-right:2px">30</span>
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"></polyline><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"></path></svg>
      </button>

      <div class="kp-embed-extras">
        <select class="kp-embed-speed" id="speedSelect" title="Velocidad de reproducción">
          <option value="0.75">0.75×</option>
          <option value="1" selected>1×</option>
          <option value="1.25">1.25×</option>
          <option value="1.5">1.5×</option>
          <option value="2">2×</option>
        </select>
      </div>
    </div>
  </div>

  <audio id="audio" src="<?= e($audioSrc) ?>" preload="metadata"></audio>

  <script>
    (function() {
      const audio = document.getElementById('audio');
      const btnPlay = document.getElementById('btnPlay');
      const playIcon = document.getElementById('playIcon');
      const pauseIcon = document.getElementById('pauseIcon');
      const btnBack = document.getElementById('btnBack');
      const btnFwd = document.getElementById('btnFwd');
      const scrubber = document.getElementById('scrubber');
      const scrubFill = document.getElementById('scrubFill');
      const scrubThumb = document.getElementById('scrubThumb');
      const timeCur = document.getElementById('timeCur');
      const timeDur = document.getElementById('timeDur');
      const speedSelect = document.getElementById('speedSelect');

      function fmt(sec) {
        if (isNaN(sec) || sec < 0) return '0:00';
        const m = Math.floor(sec / 60);
        const s = Math.floor(sec % 60);
        return m + ':' + String(s).padStart(2, '0');
      }

      function updateUI() {
        const isPaused = audio.paused;
        playIcon.style.display = isPaused ? 'block' : 'none';
        pauseIcon.style.display = isPaused ? 'none' : 'block';
        btnPlay.setAttribute('aria-label', isPaused ? 'Reproducir' : 'Pausar');
      }

      btnPlay.onclick = () => {
        if (audio.paused) {
          audio.play().catch(() => {});
        } else {
          audio.pause();
        }
      };

      btnBack.onclick = () => {
        audio.currentTime = Math.max(0, audio.currentTime - 15);
      };

      btnFwd.onclick = () => {
        audio.currentTime = Math.min(audio.duration || 0, audio.currentTime + 30);
      };

      speedSelect.onchange = (e) => {
        audio.playbackRate = parseFloat(e.target.value);
      };

      audio.onplay = updateUI;
      audio.onpause = updateUI;

      audio.ondurationchange = () => {
        if (audio.duration && !isNaN(audio.duration)) {
          timeDur.textContent = fmt(audio.duration);
        }
      };

      audio.ontimeupdate = () => {
        if (isDragging) return;
        const dur = audio.duration || 0;
        const cur = audio.currentTime || 0;
        const pct = dur > 0 ? (cur / dur) * 100 : 0;
        scrubFill.style.width = pct + '%';
        scrubThumb.style.left = pct + '%';
        timeCur.textContent = fmt(cur);
      };

      // Cuando termina el audio, resetear al inicio
      audio.onended = () => {
        audio.pause();
        audio.currentTime = 0;
        scrubFill.style.width = '0%';
        scrubThumb.style.left = '0%';
        timeCur.textContent = '0:00';
        updateUI();
      };

      // Soporte Scrubber interactivo
      let isDragging = false;
      function setScrub(e) {
        const rect = scrubber.getBoundingClientRect();
        let pos = (e.clientX - rect.left) / rect.width;
        pos = Math.max(0, Math.min(1, pos));
        scrubFill.style.width = (pos * 100) + '%';
        scrubThumb.style.left = (pos * 100) + '%';
        if (audio.duration) {
          audio.currentTime = pos * audio.duration;
          timeCur.textContent = fmt(audio.currentTime);
        }
      }

      scrubber.addEventListener('pointerdown', (e) => {
        isDragging = true;
        scrubber.classList.add('dragging');
        scrubber.setPointerCapture(e.pointerId);
        setScrub(e);
      });

      scrubber.addEventListener('pointermove', (e) => {
        if (isDragging) setScrub(e);
      });

      scrubber.addEventListener('pointerup', (e) => {
        if (isDragging) {
          isDragging = false;
          scrubber.classList.remove('dragging');
          try { scrubber.releasePointerCapture(e.pointerId); } catch(err){}
        }
      });

      scrubber.addEventListener('pointercancel', () => {
        isDragging = false;
        scrubber.classList.remove('dragging');
      });

      // Soporte postMessage desde KutBlog (reproducción, pausas, saltos de capítulos y timestamps)
      window.addEventListener('message', (event) => {
        if (!event.data || typeof event.data !== 'object') return;
        if (event.data.action === 'play') {
          if (audio.paused) audio.play().catch(() => {});
        } else if (event.data.action === 'pause') {
          audio.pause();
        } else if (event.data.action === 'seek') {
          const time = parseFloat(event.data.time);
          if (!isNaN(time) && isFinite(time)) {
            audio.currentTime = Math.max(0, Math.min(audio.duration || time, time));
            if (audio.paused) audio.play().catch(() => {});
          }
        }
      });

      // Soporte parámetro ?autoplay=1 o ?autoplay=true
      const urlParams = new URLSearchParams(window.location.search);
      if (urlParams.get('autoplay') === '1' || urlParams.get('autoplay') === 'true') {
        const tryPlay = () => {
          const p = audio.play();
          if (p !== undefined) {
            p.catch(err => {
              console.warn('[KutPod Embed] Autoplay no permitido por política del navegador:', err);
            });
          }
        };
        if (audio.readyState >= 2) {
          tryPlay();
        } else {
          audio.addEventListener('canplay', tryPlay, { once: true });
        }
      }
    })();
  </script>
</body>
</html>
