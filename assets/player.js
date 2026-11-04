/* Sticky audio player para el sitio público (kutpod.io) */
(() => {
  const audio = new Audio();
  let state = { ep: null, podcast: null, playing: false, speed: 1 };

  function $(s, r = document) { return r.querySelector(s); }
  function fmt(sec) { return Math.floor(sec/60) + ':' + String(Math.floor(sec%60)).padStart(2,'0'); }

  const ICONS = {
    volumeHigh: `<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:block"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"></polygon><path d="M15.54 8.46a5 5 0 0 1 0 7.07"></path><path d="M19.07 4.93a10 10 0 0 1 0 14.14"></path></svg>`,
    volumeLow: `<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:block"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"></polygon><path d="M15.54 8.46a5 5 0 0 1 0 7.07"></path></svg>`,
    volumeMute: `<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:block"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"></polygon><line x1="23" y1="9" x2="17" y2="15"></line><line x1="17" y1="9" x2="23" y2="15"></line></svg>`
  };

  function ensurePlayerDOM() {
    if ($('.pub-player')) return;
    const el = document.createElement('div');
    el.className = 'pub-player';
    el.innerHTML = `<div class="pub-player-inner">
      <div class="pub-player-meta">
        <div class="pub-player-cover" id="kpCover"></div>
        <div style="min-width:0"><div class="pub-player-title" id="kpTitle"></div><div class="pub-player-sub" id="kpSub"></div></div>
      </div>
      <div class="pub-player-controls">
        <div class="row" style="gap:16px;justify-content:center;margin-bottom:8px">
          <button id="kpBack" class="pub-player-btn-s">← 15</button>
          <button id="kpPlay" class="pub-player-btn-play">▶</button>
          <button id="kpFwd" class="pub-player-btn-s">30 →</button>
        </div>
        <div class="row" style="gap:10px;align-items:center">
          <span id="kpCur" class="tabular" style="font-size:11px;color:var(--pub-muted);min-width:36px;text-align:right">0:00</span>
          <div class="pub-scrubber" id="kpScrub"><div class="pub-scrubber-fill" id="kpFill"></div><div class="pub-scrubber-thumb" id="kpThumb"></div></div>
          <span id="kpDur" class="tabular" style="font-size:11px;color:var(--pub-muted);min-width:36px">0:00</span>
        </div>
      </div>
      <div class="pub-player-extras">
        <div class="row" style="gap:6px;align-items:center;margin-right:10px">
          <button id="kpVolBtn" class="pub-player-chip" style="padding: 5px 8px; min-width: 32px; display: inline-flex; align-items: center; justify-content: center" aria-label="Silenciar" title="Silenciar">${ICONS.volumeHigh}</button>
          <input type="range" id="kpVolume" class="pub-volume" min="0" max="1" step="0.05" value="1">
        </div>
        <select id="kpSpeed" class="pub-player-chip" style="appearance:none;padding-right:10px;text-align:center;outline:none">
          <option value="0.75">0.75×</option>
          <option value="1" selected>1×</option>
          <option value="1.25">1.25×</option>
          <option value="1.5">1.5×</option>
          <option value="2">2×</option>
        </select>
        <button id="kpClose" class="pub-player-chip">×</button>
      </div>
    </div>`;
    document.body.appendChild(el);

    $('#kpPlay').onclick = () => { if (audio.paused) audio.play(); else audio.pause(); };
    $('#kpBack').onclick = () => { audio.currentTime = Math.max(0, audio.currentTime - 15); };
    $('#kpFwd').onclick  = () => { audio.currentTime = Math.min(audio.duration||0, audio.currentTime + 30); };
    $('#kpSpeed').onchange = (e) => {
      state.speed = parseFloat(e.target.value);
      audio.playbackRate = state.speed;
    };
    $('#kpClose').onclick = () => { audio.pause(); el.remove(); };

    const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) ||
      (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);

    const isVolumeSettable = (() => {
      if (isIOS) return false;
      try {
        const a = document.createElement('audio');
        a.volume = 0.5;
        return Math.abs(a.volume - 0.5) < 0.05;
      } catch (e) {
        return false;
      }
    })();

    let lastVolume = 1;
    const volBtn = $('#kpVolBtn');
    const volSlider = $('#kpVolume');

    if (!isVolumeSettable) {
      volSlider.style.display = 'none';
    }

    const updateVolumeUI = () => {
      const isMuted = audio.muted || (isVolumeSettable && audio.volume === 0);
      if (isMuted) {
        volBtn.innerHTML = ICONS.volumeMute;
        volBtn.title = 'Activar sonido';
        volBtn.setAttribute('aria-label', 'Activar sonido');
        if (isVolumeSettable) {
          volSlider.value = 0;
          volSlider.style.background = `linear-gradient(to right, var(--accent) 0%, var(--accent) 0%, color-mix(in srgb, var(--pub-text) 15%, transparent) 0%, color-mix(in srgb, var(--pub-text) 15%, transparent) 100%)`;
        }
      } else {
        const vol = isVolumeSettable ? audio.volume : 1;
        if (vol < 0.5) {
          volBtn.innerHTML = ICONS.volumeLow;
        } else {
          volBtn.innerHTML = ICONS.volumeHigh;
        }
        volBtn.title = 'Silenciar';
        volBtn.setAttribute('aria-label', 'Silenciar');
        if (isVolumeSettable) {
          volSlider.value = vol;
          const pct = vol * 100;
          volSlider.style.background = `linear-gradient(to right, var(--accent) 0%, var(--accent) ${pct}%, color-mix(in srgb, var(--pub-text) 15%, transparent) ${pct}%, color-mix(in srgb, var(--pub-text) 15%, transparent) 100%)`;
        }
      }
    };

    updateVolumeUI();

    volBtn.onclick = () => {
      if (audio.muted || (isVolumeSettable && audio.volume === 0)) {
        audio.muted = false;
        if (isVolumeSettable && audio.volume === 0) {
          audio.volume = lastVolume > 0 ? lastVolume : 1;
        }
      } else {
        if (isVolumeSettable) {
          lastVolume = audio.volume;
        }
        audio.muted = true;
      }
      updateVolumeUI();
    };

    if (isVolumeSettable) {
      volSlider.oninput = (e) => {
        const vol = parseFloat(e.target.value);
        if (vol > 0) {
          audio.muted = false;
          audio.volume = vol;
          lastVolume = vol;
        } else {
          audio.volume = 0;
          audio.muted = true;
        }
        updateVolumeUI();
      };
    }

    audio.onvolumechange = updateVolumeUI;
    
    let scrubDragging = false;
    const scrubEl = $('#kpScrub');
    const updateScrub = (e) => {
      const r = scrubEl.getBoundingClientRect();
      let t = (e.clientX - r.left) / r.width;
      t = Math.max(0, Math.min(1, t));
      if (audio.duration) audio.currentTime = t * audio.duration;
    };
    scrubEl.addEventListener('pointerdown', (e) => {
      scrubDragging = true;
      scrubEl.setPointerCapture(e.pointerId);
      updateScrub(e);
    });
    scrubEl.addEventListener('pointermove', (e) => {
      if (scrubDragging) updateScrub(e);
    });
    scrubEl.addEventListener('pointerup', (e) => {
      scrubDragging = false;
      try { scrubEl.releasePointerCapture(e.pointerId); } catch(err){}
    });
    scrubEl.addEventListener('pointercancel', () => { scrubDragging = false; });

    audio.onplay = () => $('#kpPlay').textContent = '❚❚';
    audio.onpause = () => $('#kpPlay').textContent = '▶';
    audio.ontimeupdate = () => {
      const pct = audio.duration ? (audio.currentTime / audio.duration) * 100 : 0;
      $('#kpFill').style.width = pct + '%';
      $('#kpThumb').style.left = pct + '%';
      $('#kpCur').textContent = fmt(audio.currentTime);
      if (audio.duration) $('#kpDur').textContent = fmt(audio.duration);
    };
    audio.onended = () => {
      audio.pause();
      const player = $('.pub-player');
      if (player) {
        player.remove();
      }
      audio.src = '';
    };
  }

  function isSameTrack(src1, src2) {
    if (!src1 || !src2) return false;
    if (src1 === src2) return true;
    try {
      const u1 = new URL(src1, window.location.href);
      const u2 = new URL(src2, window.location.href);
      return u1.pathname === u2.pathname;
    } catch (e) {
      return src1.endsWith(src2) || src2.endsWith(src1);
    }
  }

  function playTrack(dataEl, seekTime = null) {
    ensurePlayerDOM();
    const targetSrc = dataEl.dataset.src;
    if (!targetSrc) return;

    const alreadyLoaded = isSameTrack(audio.src, targetSrc);

    if (!alreadyLoaded) {
      audio.src = targetSrc;
      $('#kpTitle').textContent = dataEl.dataset.title || '';
      $('#kpSub').textContent = dataEl.dataset.podcast || '';
      const c = $('#kpCover');
      if (dataEl.dataset.cover) {
        c.style.backgroundImage = `url("${dataEl.dataset.cover}")`;
        c.style.backgroundSize = 'cover';
        c.style.backgroundPosition = 'center';
        c.textContent = '';
        c.style.display = 'block';
      } else {
        c.style.background = `linear-gradient(135deg, ${dataEl.dataset.color || '#ff5f7e'}, ${(dataEl.dataset.color || '#ff5f7e')}99)`;
        c.textContent = dataEl.dataset.initial || '·';
        c.style.color = '#fff'; c.style.fontWeight = '700'; c.style.fontSize = '14px';
        c.style.display = 'grid'; c.style.placeItems = 'center';
      }
      const color = dataEl.dataset.color || 'var(--accent)';
      $('#kpPlay').style.background = color;
      $('#kpPlay').style.color = '#fff';
      $('#kpClose').style.background = color;
      $('#kpClose').style.color = '#fff';
      $('#kpClose').style.borderColor = color;

      if (seekTime !== null && !isNaN(seekTime) && seekTime >= 0) {
        const applySeek = () => {
          try {
            audio.currentTime = seekTime;
          } catch(err) {}
          audio.play().catch(() => {});
        };
        if (audio.readyState >= 1) {
          applySeek();
        } else {
          audio.addEventListener('loadedmetadata', applySeek, { once: true });
        }
      }
      audio.play().catch(() => {});
    } else {
      if (seekTime !== null && !isNaN(seekTime) && seekTime >= 0) {
        try {
          audio.currentTime = seekTime;
        } catch(err) {}
        if (audio.paused) {
          audio.play().catch(() => {});
        }
      } else {
        if (audio.paused) {
          audio.play().catch(() => {});
        } else {
          audio.pause();
        }
      }
    }
  }

  // Cualquier <button data-kp-play data-src data-title data-podcast data-color data-initial> dispara el player.
  document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-kp-play]');
    if (!btn) return;
    e.preventDefault();
    playTrack(btn);
  });

  // Capítulo click -> saltar tiempo
  document.addEventListener('click', (e) => {
    const chap = e.target.closest('[data-time]');
    if (!chap) return;
    const time = parseFloat(chap.dataset.time);
    if (isNaN(time)) return;

    // Buscar el botón o elemento con datos del episodio
    const epBtn = (chap.dataset.src ? chap : null)
               || chap.closest('.pub-ep-detail-grid, .pub-container, .pub-body, body')?.querySelector('[data-kp-play]');

    if (epBtn && epBtn.dataset.src) {
      playTrack(epBtn, time);
    } else if (audio.src) {
      try { audio.currentTime = time; } catch(err) {}
      if (audio.paused) audio.play().catch(() => {});
    }
  });

  // Motor de navegación PJAX (SPA) para mantener el reproductor sonando
  document.addEventListener('click', async (e) => {
    const a = e.target.closest('a');
    if (!a || !a.href || a.origin !== location.origin || a.hasAttribute('download') || a.target) return;
    
    // Ignorar rutas del Studio (admin) o rutas de raw/feed
    if (a.pathname.startsWith('/admin') || a.pathname.startsWith('/r/') || a.pathname.endsWith('.xml')) return;

    e.preventDefault();
    try {
      const res = await fetch(a.href);
      if (!res.ok) { location.href = a.href; return; }
      const html = await res.text();
      const doc = new DOMParser().parseFromString(html, 'text/html');
      
      const newBody = doc.querySelector('.pub-body');
      if (newBody) {
        document.querySelector('.pub-body').innerHTML = newBody.innerHTML;
        document.title = doc.title;
        history.pushState({}, '', a.href);
        const root = document.querySelector('.pub-root');
        if (root) root.scrollTop = 0;
        else window.scrollTo(0, 0);
      } else {
        location.href = a.href;
      }
    } catch (err) {
      location.href = a.href;
    }
  });

  window.addEventListener('popstate', () => {
    location.reload();
  });
})();
