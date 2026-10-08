/* Estudio · Shorts y Reels · panel de la pestaña Exportar */
(function () {
  'use strict';
  var root = document.getElementById('st-shorts');
  if (!root) return;
  var B = null;                       // StudioBus lo define la interfaz base, que se carga después de este script
  var $ = function (s) { return root.querySelector(s); };
  var W = null, loadedKey = null, sel = { s: null, e: null }, nodes = [];

  function file(name, dl) { return B.config.base + '&do=file&name=' + encodeURIComponent(name) + (dl ? '&download=1' : ''); }
  function setRange(s, e) {
    sel.s = s; sel.e = e;
    $('#sh-s').value = s == null ? '' : (+s).toFixed(2); $('#sh-e').value = e == null ? '' : (+e).toFixed(2);
    var d = (s != null && e != null) ? e - s : 0, b = $('#sh-dur');
    b.textContent = d > 0 ? d.toFixed(1) + ' s' : '—';
    b.className = 'st-badge' + (d > 0 ? (d <= 62 ? ' ok' : d <= 90 ? ' mid' : ' bad') : '');
    $('#sh-hint').textContent = d > 90 ? 'Instagram Reels admite hasta 90 s; YouTube Shorts, hasta 3 min.' : d > 0 && d < 5 ? 'Muy corto.' : '';
    for (var i = 0; i < nodes.length; i++) { var w = W.words[i], on = d > 0 && w[1] >= s - 0.02 && w[2] <= e + 0.1; nodes[i].classList.toggle('in', on); }
  }

  function buildDoc() {
    var out = [], lastE = -9, lastSpk = -1, n = 0, open = false;
    W.words.forEach(function (w, i) {
      if (!open || w[3] !== lastSpk || w[1] - lastE > 1.6 || n > 60) {
        if (open) out.push('</p>');
        out.push('<p><span class="who" data-t="' + w[1] + '">' + B.esc(W.speakers[w[3]] || 'Voz ' + (w[3] + 1)) + ' · ' + B.fmt(w[1]) + '</span><br>');
        open = true; n = 0;
      }
      out.push('<span class="w" data-i="' + i + '">' + B.esc(w[0]) + '</span> '); n++; lastE = w[2]; lastSpk = w[3];
    });
    if (open) out.push('</p>');
    $('#sh-doc').innerHTML = out.join('');
    nodes = $('#sh-doc').getElementsByClassName('w');
  }

  function listShorts() {
    B.api('short_list').then(function (j) {
      var box = $('#sh-list');
      if (!j.shorts.length) { box.innerHTML = '<span class="st-note">Aún no hay ninguno.</span>'; return; }
      box.innerHTML = j.shorts.slice().reverse().map(function (s) {
        return '<div class="sh-item"><video controls preload="metadata" src="' + file(s.file) + '"></video>' +
          '<div style="margin-top:6px;font-weight:600;font-size:13px">' + B.esc(s.title) + '</div>' +
          '<div class="st-note">' + B.fmt(s.s) + '–' + B.fmt(s.e) + ' · ' + s.duration + ' s · ' + (s.bytes / 1048576).toFixed(1) + ' MB</div>' +
          '<div class="st-row" style="margin-top:6px"><a class="btn btn-sm" href="' + file(s.file, 1) + '">Descargar</a><button class="btn btn-ghost btn-sm" data-del="' + s.id + '" style="color:var(--bad)">Borrar</button></div></div>';
      }).join('');
    });
  }

  function init(S) {
    var key = S.result.rendered_at + '|' + S.id;
    if (loadedKey !== key) {
      loadedKey = key; W = null; setRange(null, null);
      $('#sh-title').value = S.title; $('#sh-show').value = (S.podcast && S.podcast.title) || '';
      $('#sh-doc').innerHTML = '<span class="st-note">Cargando…</span>'; $('#sh-cands').innerHTML = '';
      B.api('short_words').then(function (j) { W = j; buildDoc(); setRange(sel.s, sel.e); });
    }
    listShorts();
  }

  var bound = false;
  function bind() {
    if (bound) return; bound = true;
    $('#sh-suggest').onclick = function () {
      $('#sh-sug-msg').textContent = 'Buscando…';
      B.api('short_suggest').then(function (j) {
        $('#sh-sug-msg').textContent = j.candidates.length ? '' : 'No hay fragmentos adecuados (el episodio es muy corto o no tiene frases completas).';
        $('#sh-cands').innerHTML = j.candidates.map(function (c, i) {
          return '<div class="sh-cand"><div><b>' + B.fmt(c.s) + ' – ' + B.fmt(c.e) + '</b> · ' + c.duration + ' s · ' + c.changes + ' cambios de voz<div class="ex">' + B.esc(c.excerpt) + '</div></div><button class="btn btn-sm" data-use="' + i + '">Usar</button></div>';
        }).join('');
        $('#sh-cands').querySelectorAll('[data-use]').forEach(function (b) { b.onclick = function () { var c = j.candidates[+b.dataset.use]; setRange(c.s, c.e); }; });
      }).catch(function () { $('#sh-sug-msg').textContent = ''; });
    };
    ['#sh-s', '#sh-e'].forEach(function (id) { $(id).onchange = function () { setRange(parseFloat($('#sh-s').value), parseFloat($('#sh-e').value)); }; });
    root.querySelectorAll('[data-n]').forEach(function (b) {
      b.onclick = function () { var p = b.dataset.n.split(':'); var s = sel.s, e = sel.e; if (p[0] === 's') s = Math.max(0, (s || 0) + +p[1]); else e = Math.max(0, (e || 0) + +p[1]); setRange(s, e); };
    });
    var fromSelection = function () {
      var s = window.getSelection(); if (!W || !s.rangeCount || s.isCollapsed) return;
      var r = s.getRangeAt(0);
      var a = -1, z = -1;
      for (var i = 0; i < nodes.length; i++) if (r.intersectsNode(nodes[i])) { if (a < 0) a = i; z = i; }
      if (a >= 0) setRange(W.words[a][1], W.words[z][2]);
    };
    // En todo el documento: al arrastrar es habitual soltar el ratón fuera del recuadro
    document.addEventListener('mouseup', fromSelection);
    document.addEventListener('keyup', function (ev) { if (ev.shiftKey) fromSelection(); });
    $('#sh-doc').addEventListener('click', function (ev) {
      var who = ev.target.closest('.who'); var au = $('#sh-audio');
      if (who) { au.src = file('episode.mp3'); au.currentTime = +who.dataset.t; au.play(); }
    });
    $('#sh-play').onclick = function () {
      if (sel.s == null || sel.e == null || sel.e <= sel.s) return B.toast('Elige primero un fragmento', true);
      var au = $('#sh-audio');
      if (!au.paused) { au.pause(); return; }
      au.src = file('episode.mp3'); au.currentTime = sel.s;
      au.ontimeupdate = function () { if (au.currentTime >= sel.e) au.pause(); };
      au.play();
    };
    $('#sh-go').onclick = function () {
      if (sel.s == null || sel.e == null || !(sel.e > sel.s)) return B.toast('Elige primero un fragmento', true);
      if (sel.e - sel.s < 3) return B.toast('El fragmento es demasiado corto (mínimo 3 s)', true);
      B.runJob('short', { s: sel.s, e: sel.e, title: $('#sh-title').value, show: $('#sh-show').value });
    };
    root.addEventListener('click', function (ev) {
      var d = ev.target.closest('[data-del]');
      if (d && confirm('¿Borrar este vídeo?')) B.api('short_delete', { short: d.dataset.del }).then(listShorts);
    });

  }
  document.addEventListener('studio:render', function (ev) {
    B = window.StudioBus; bind();
    var S = ev.detail.state, ok = ev.detail.tab === 'export' && S.result && S.result.file;
    root.hidden = !ok;
    if (ok) init(S);
  });
})();
