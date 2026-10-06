/* KutPod · Estudio de edición · interfaz del proyecto */
(function () {
  'use strict';
  var C = window.STUDIO;
  var S = null;                 // estado del proyecto (servidor)
  var tab = null;               // pestaña activa
  var W = null;                 // transcripción cargada (editor)
  var polling = null, lastJobId = 0, dismissedJob = 0;
  var el = function (s, r) { return (r || document).querySelector(s); };
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var fmt = function (s) { s = Math.max(0, Math.round(s || 0)); var h = Math.floor(s / 3600), m = Math.floor(s % 3600 / 60), x = s % 60; return (h ? h + ':' + String(m).padStart(2, '0') : m) + ':' + String(x).padStart(2, '0'); };
  var STEPS = [['audio', 'Audios'], ['sync', 'Sincronización'], ['transcribe', 'Transcripción'], ['edit', 'Edición'], ['export', 'Exportar']];

  // ── Utilidades ────────────────────────────────────────────────────────────
  function toast(msg, err) {
    var t = el('#st-toast'); t.textContent = msg; t.className = 'st-toast' + (err ? ' err' : ''); t.hidden = false;
    clearTimeout(toast.t); toast.t = setTimeout(function () { t.hidden = true; }, err ? 7000 : 2500);
  }
  function api(action, data, method) {
    var url = C.base + '&do=' + action, opt = {};
    if (data !== undefined || method === 'POST') {
      var fd = data instanceof FormData ? data : new FormData();
      if (!(data instanceof FormData)) for (var k in (data || {})) fd.append(k, typeof data[k] === 'object' ? JSON.stringify(data[k]) : data[k]);
      fd.append('_csrf', C.csrf);
      opt = { method: 'POST', body: fd };
    }
    return fetch(url, opt).then(function (r) { return r.json().catch(function () { return { error: 'Respuesta no válida del servidor (' + r.status + ')' }; }); })
      .then(function (j) { if (j && j.error) { toast(j.error, true); throw new Error(j.error); } return j; });
  }
  function upload(action, fd, onProgress) {
    return new Promise(function (resolve, reject) {
      var x = new XMLHttpRequest();
      x.open('POST', C.base + '&do=' + action);
      fd.append('_csrf', C.csrf);
      x.upload.onprogress = function (e) { if (e.lengthComputable && onProgress) onProgress(e.loaded / e.total); };
      x.onload = function () {
        var j; try { j = JSON.parse(x.responseText); } catch (e) { j = { error: 'Respuesta no válida del servidor (' + x.status + '). ¿El archivo supera el límite de subida?' }; }
        if (j.error) { toast(j.error, true); reject(new Error(j.error)); } else resolve(j);
      };
      x.onerror = function () { toast('Error de red al subir', true); reject(new Error('net')); };
      x.send(fd);
    });
  }
  function track(id) { return S.tracks.filter(function (t) { return t.id === id; })[0]; }
  function voices() { return S.tracks.filter(function (t) { return t.role === 'voice' && t.ready; }); }

  // ── Estado y trabajos ─────────────────────────────────────────────────────
  function refresh() {
    return api('status').then(function (s) { S = s; renderSteps(); renderJob(); return s; });
  }
  function jobActive() { return S && S.job && (S.job.status === 'queued' || S.job.status === 'running'); }
  function startPolling() {
    if (polling) return;
    polling = setInterval(function () {
      api('status').then(function (s) {
        var prev = S && S.job; S = s; renderJob(); renderSteps();
        if (!jobActive()) {
          clearInterval(polling); polling = null;
          if (prev && s.job && s.job.status === 'done') onJobDone(s.job.type);
          else if (s.job && s.job.status === 'failed') render(true);
        }
      }).catch(function () { });
    }, 1300);
  }
  function runJob(type, params) {
    return api('run', { type: type, params: params || {} }).then(function () { return refresh(); }).then(function () { renderJob(); startPolling(); });
  }
  function onJobDone(type) {
    W = type === 'transcribe' || type === 'sync' ? null : W;
    if (type === 'ingest') go('sync');
    else if (type === 'transcribe') go('edit');
    else if (type === 'render') go('export');
    else render(true);
  }
  var JOBNAMES = { ingest: 'Preparando audios', sync: 'Sincronizando', preview: 'Creando vista previa', transcribe: 'Transcribiendo', render: 'Exportando episodio', short: 'Generando vídeo' };
  function renderJob() {
    var b = el('#st-job'), j = S && S.job;
    if (!j || (j.status === 'done') || (j.status === 'failed' && j.id <= dismissedJob)) { b.hidden = true; return; }
    b.hidden = false; b.className = 'st-job' + (j.status === 'failed' ? ' err' : '');
    if (j.status === 'failed') {
      b.innerHTML = '<div class="st-row"><b style="color:var(--bad)">' + esc(JOBNAMES[j.type] || j.type) + ': error</b><span style="flex:1"></span><button class="btn btn-sm" id="st-job-x">Cerrar</button></div><div class="st-note" style="margin-top:4px;word-break:break-word">' + esc(j.message || '') + '</div>';
      el('#st-job-x').onclick = function () { dismissedJob = j.id; renderJob(); };
    } else {
      b.innerHTML = '<div class="st-row"><b>' + esc(JOBNAMES[j.type] || j.type) + '…</b><span class="st-note">' + esc(j.message || '') + '</span><span style="flex:1"></span><span class="st-note">' + Math.round(j.progress * 100) + '%</span></div><div class="st-bar"><i style="width:' + Math.round(j.progress * 100) + '%"></i></div>';
    }
  }

  // ── Pasos ─────────────────────────────────────────────────────────────────
  function stepDone(id) {
    var v = voices();
    if (id === 'audio') return S.tracks.length > 0 && S.tracks.every(function (t) { return t.ready; });
    if (id === 'sync') return ['synced', 'transcribed', 'rendered', 'drafted'].indexOf(S.status) >= 0;
    if (id === 'transcribe') return v.length > 0 && v.every(function (t) { return t.transcribed; });
    if (id === 'edit') return ['rendered', 'drafted'].indexOf(S.status) >= 0;
    if (id === 'export') return !!(S.result && S.result.file);
    return false;
  }
  function renderSteps() {
    el('#st-steps').innerHTML = STEPS.map(function (s, i) {
      return '<div class="st-step' + (tab === s[0] ? ' active' : '') + (stepDone(s[0]) ? ' done' : '') + '" data-tab="' + s[0] + '"><span class="n">' + (stepDone(s[0]) ? '✓' : i + 1) + '</span>' + s[1] + '</div>';
    }).join('');
  }
  function suggestTab() {
    if (!S.tracks.length || !stepDone('audio')) return 'audio';
    if (!stepDone('sync')) return 'sync';
    if (!stepDone('transcribe')) return 'transcribe';
    if (S.result && S.result.file) return 'export';
    return 'edit';
  }
  function go(t) { tab = t; try { history.replaceState(null, '', '#' + t); } catch (e) { } refresh().then(function () { render(true); }); }
  el('#st-steps').addEventListener('click', function (e) { var s = e.target.closest('.st-step'); if (s) { tab = s.dataset.tab; try { history.replaceState(null, '', '#' + tab); } catch (x) { } renderSteps(); render(true); } });

  function render(force) {
    var p = el('#st-panel');
    if (document.activeElement && p.contains(document.activeElement) && !force) return;
    if (tab !== 'edit') stopPlayLoop();
    // Los paneles de otros plugins vuelven al contenedor oculto antes de redibujar
    var holder = el('#st-plugin-holder');
    Array.prototype.slice.call(p.querySelectorAll('.st-plugin-panel')).forEach(function (n) { holder.appendChild(n); });
    renderSteps();
    ({ audio: viewAudio, sync: viewSync, transcribe: viewTranscribe, edit: viewEdit, export: viewExport })[tab](p);
    Array.prototype.slice.call(holder.querySelectorAll('.st-plugin-panel')).forEach(function (n) { if (n.dataset.tab === tab) p.appendChild(n); });
    document.dispatchEvent(new CustomEvent('studio:render', { detail: { tab: tab, state: S } }));
  }

  // ── 1 · Audios ────────────────────────────────────────────────────────────
  function viewAudio(p) {
    var busy = jobActive();
    var rows = S.tracks.map(function (t) {
      var st = t.error ? '<span class="st-badge bad" title="' + esc(t.error) + '">Error</span>' : t.ready ? '<span class="st-badge ok">Listo</span>' : t.pending ? '<span class="st-badge mid">Preparando…</span>' : '<span class="st-badge">Sin audio</span>';
      return '<tr data-t="' + t.id + '"><td><input class="input" data-f="name" value="' + esc(t.name) + '"></td>' +
        '<td><select class="input" data-f="role"><option value="voice"' + (t.role === 'voice' ? ' selected' : '') + '>Voz (se edita y mezcla)</option><option value="reference"' + (t.role === 'reference' ? ' selected' : '') + '>Solo sincronizar (se descarta)</option></select></td>' +
        '<td>' + (t.duration ? fmt(t.duration) : '—') + '</td><td>' + st + '</td>' +
        '<td><button class="btn btn-ghost btn-sm" data-rm="' + t.id + '" ' + (busy ? 'disabled' : '') + '>Quitar</button></td></tr>';
    }).join('');
    var inbox = S.inbox.length ? S.inbox.map(function (f) { return '<label><input type="checkbox" value="' + esc(f.name) + '"> ' + esc(f.name) + ' <span class="st-note">(' + (f.bytes / 1048576).toFixed(0) + ' MB)</span></label>'; }).join('') : '<span class="st-note">Carpeta vacía.</span>';
    var m = function (k, label) {
      return '<div class="st-row" style="padding:8px 0;border-top:1px solid var(--border)"><b style="width:130px">' + label + '</b><span style="flex:1">' + (S.music[k] ? esc(S.music[k].name) : '<span class="st-note">Ninguna</span>') + '</span>' +
        '<label class="btn btn-sm">Subir<input type="file" accept="audio/*" data-music="' + k + '" hidden></label>' +
        (S.inbox.length ? '<select class="input" style="width:auto;padding:5px 8px;font-size:12.5px" data-musicbox="' + k + '"><option value="">Desde carpeta de entrada…</option>' + S.inbox.map(function (f) { return '<option>' + esc(f.name) + '</option>'; }).join('') + '</select>' : '') +
        (S.music[k] ? '<button class="btn btn-ghost btn-sm" data-musicrm="' + k + '">Quitar</button>' : '') + '</div>';
    };
    p.innerHTML =
      '<div class="card st-card"><h2>Audios de la grabación</h2><p class="st-note">Sube las pistas de la grabación (tu micro, el del cohost…). Si tienes una pista que solo sirve para sincronizar (p. ej. la mezcla de la Zoom), márcala como «Solo sincronizar»: se usa para alinear y se descarta.</p>' +
      (rows ? '<table class="st-table"><thead><tr><th>Nombre / hablante</th><th>Uso</th><th>Duración</th><th>Estado</th><th></th></tr></thead><tbody>' + rows + '</tbody></table>' : '') +
      '<div class="st-drop" id="st-drop"><div>Arrastra aquí los audios o <label class="btn btn-sm" style="margin-left:6px">Elegir archivos<input type="file" id="st-files" multiple accept="audio/*,video/*" hidden></label></div><div class="st-note" style="margin-top:6px">Límite de subida del servidor: ' + esc(S.limits.upload) + ' por archivo. <span id="st-up"></span></div></div>' +
      '<details style="margin-top:12px"' + (S.inbox.length ? ' open' : '') + '><summary class="st-note" style="cursor:pointer">Archivos grandes: usar la carpeta de entrada del servidor <code>storage/studio/inbox</code></summary><div class="st-inbox" style="margin-top:8px">' + inbox + '</div>' + (S.inbox.length ? '<button class="btn btn-sm" id="st-use-inbox" style="margin-top:6px">Usar seleccionados</button>' : '') + '</details></div>' +
      '<div class="card st-card"><h2>Música</h2><p class="st-note">Entradilla y salida del episodio. Se pueden fijar por defecto para cada podcast en la configuración del plugin.</p>' + m('intro', 'Entradilla') + m('outro', 'Salida') + '</div>' +
      (S.tracks.length && S.tracks.every(function (t) { return t.ready; }) ? '<div class="st-row"><button class="btn btn-primary" data-go="sync">Continuar: sincronizar →</button></div>' : '');
    bindAudio(p);
  }
  function bindAudio(p) {
    var send = function (files) {
      if (!files.length) return;
      var fd = new FormData(); for (var i = 0; i < files.length; i++) fd.append('files[]', files[i]);
      var u = el('#st-up'); u.textContent = 'Subiendo…';
      upload('track_add', fd, function (f) { u.textContent = 'Subiendo… ' + Math.round(f * 100) + '%'; }).then(function () { return refresh(); }).then(function () { render(true); startPolling(); });
    };
    el('#st-files').onchange = function (e) { send(e.target.files); };
    var d = el('#st-drop');
    ['dragover', 'dragenter'].forEach(function (n) { d.addEventListener(n, function (e) { e.preventDefault(); d.classList.add('over'); }); });
    ['dragleave', 'drop'].forEach(function (n) { d.addEventListener(n, function (e) { e.preventDefault(); d.classList.remove('over'); }); });
    d.addEventListener('drop', function (e) { send(e.dataTransfer.files); });
    var ub = el('#st-use-inbox');
    if (ub) ub.onclick = function () {
      var names = Array.prototype.slice.call(p.querySelectorAll('.st-inbox input:checked')).map(function (i) { return i.value; });
      if (!names.length) return toast('Marca algún archivo', true);
      var fd = new FormData(); names.forEach(function (n) { fd.append('inbox[]', n); });
      api('track_add', fd).then(function () { return refresh(); }).then(function () { render(true); startPolling(); });
    };
    p.querySelectorAll('[data-t] [data-f]').forEach(function (i) {
      i.onchange = function () { var d = { tid: i.closest('tr').dataset.t }; d[i.dataset.f] = i.value; api('track_update', d).then(refresh).then(function () { render(true); }); };
    });
    p.querySelectorAll('[data-rm]').forEach(function (b) { b.onclick = function () { if (confirm('¿Quitar esta pista y su transcripción?')) api('track_remove', { tid: b.dataset.rm }).then(refresh).then(function () { render(true); }); }; });
    p.querySelectorAll('[data-music]').forEach(function (i) {
      i.onchange = function () { var fd = new FormData(); fd.append('kind', i.dataset.music); fd.append('file', i.files[0]); upload('music_set', fd).then(refresh).then(function () { render(true); }); };
    });
    p.querySelectorAll('[data-musicbox]').forEach(function (s) { s.onchange = function () { if (s.value) api('music_set', { kind: s.dataset.musicbox, inbox: s.value }).then(refresh).then(function () { render(true); }); }; });
    p.querySelectorAll('[data-musicrm]').forEach(function (b) { b.onclick = function () { api('music_set', { kind: b.dataset.musicrm }).then(refresh).then(function () { render(true); }); }; });
    p.querySelectorAll('[data-go]').forEach(function (b) { b.onclick = function () { go(b.dataset.go); }; });
  }

  // ── 2 · Sincronización ────────────────────────────────────────────────────
  function confBadge(c) {
    if (c == null) return '<span class="st-badge">—</span>';
    return c >= 10 ? '<span class="st-badge ok">Alta (' + c + ')</span>' : c >= 7 ? '<span class="st-badge mid">Media (' + c + ')</span>' : '<span class="st-badge bad">Baja (' + c + ')</span>';
  }
  function viewSync(p) {
    var ready = S.tracks.filter(function (t) { return t.ready; });
    if (!ready.length) { p.innerHTML = '<div class="card">Primero sube y prepara los audios.</div>'; return; }
    var rows = ready.map(function (t, i) {
      var opts = '<option value="">Automático' + (i === 0 ? ' (pista de anclaje)' : ' (primera pista)') + '</option>' + ready.filter(function (o) { return o.id !== t.id; }).map(function (o) { return '<option value="' + o.id + '"' + (t.sync_to === o.id ? ' selected' : '') + '>' + esc(o.name) + '</option>'; }).join('');
      return '<tr data-t="' + t.id + '"><td><b>' + esc(t.name) + '</b><div class="st-note">' + (t.role === 'voice' ? 'Voz' : 'Solo sincronizar') + '</div></td>' +
        '<td><input class="input" data-f="offset" type="number" step="0.001" min="0" style="width:110px" value="' + (+t.offset).toFixed(3) + '"> s' + (t.manual_offset ? ' <span class="st-badge mid">manual</span>' : '') + '</td>' +
        '<td>' + confBadge(t.sync_conf) + '</td><td><select class="input" data-f="sync_to">' + opts + '</select></td>' +
        '<td><input class="input" data-f="gain_db" type="number" step="0.5" style="width:80px" value="' + (+t.gain_db || 0) + '"> dB</td></tr>';
    }).join('');
    p.innerHTML = '<div class="card st-card"><h2>Sincronización</h2><p class="st-note">Cada pista se alinea comparando la forma de su señal con la de su referencia (por defecto, la primera pista). Funciona porque todos los micros recogen algo de la misma conversación; si la confianza es baja, elige otra referencia (p. ej. la pista de la Zoom) o ajusta el retraso a mano.</p>' +
      '<table class="st-table"><thead><tr><th>Pista</th><th>Empieza en</th><th>Confianza</th><th>Sincronizar con</th><th>Ganancia</th></tr></thead><tbody>' + rows + '</tbody></table>' +
      '<div class="st-row"><button class="btn btn-primary" id="st-sync"' + (jobActive() ? ' disabled' : '') + '>' + (stepDone('sync') ? 'Volver a sincronizar' : 'Sincronizar automáticamente') + '</button>' +
      '<button class="btn" id="st-prev"' + (jobActive() ? ' disabled' : '') + '>Actualizar vista previa</button></div></div>' +
      (S.has_preview ? '<div class="card st-card"><h2>Escucha el resultado</h2><p class="st-note">Mezcla de las pistas de voz ya alineadas. Si las voces se oyen con eco o desfasadas, corrige el retraso y actualiza la vista previa.</p><audio controls preload="none" style="width:100%" src="' + C.base + '&do=audio&v=' + (S.job ? S.job.id : 0) + '"></audio></div>' : '') +
      (stepDone('sync') ? '<div class="st-row"><button class="btn btn-primary" data-go="transcribe">Continuar: transcribir →</button></div>' : '');
    el('#st-sync').onclick = function () { runJob('sync'); };
    el('#st-prev').onclick = function () { runJob('preview'); };
    p.querySelectorAll('[data-t] [data-f]').forEach(function (i) {
      i.onchange = function () { var d = { tid: i.closest('tr').dataset.t }; d[i.dataset.f] = i.value; api('track_update', d).then(refresh).then(function () { render(true); if (i.dataset.f === 'offset') toast('Retraso guardado · actualiza la vista previa'); }); };
    });
    p.querySelectorAll('[data-go]').forEach(function (b) { b.onclick = function () { go(b.dataset.go); }; });
  }

  // ── 3 · Transcripción ─────────────────────────────────────────────────────
  function viewTranscribe(p) {
    var v = voices();
    if (!v.length) { p.innerHTML = '<div class="card">Primero sube y prepara al menos una pista de voz.</div>'; return; }
    var rows = v.map(function (t) { return '<tr><td><b>' + esc(t.name) + '</b></td><td>' + (t.transcribed ? '<span class="st-badge ok">Transcrita</span>' : '<span class="st-badge">Pendiente</span>') + '</td></tr>'; }).join('');
    p.innerHTML = '<div class="card st-card"><h2>Transcripción</h2><p class="st-note">Cada pista se transcribe por separado, palabra a palabra, así sabemos siempre quién habla y se pueden recortar las muletillas de cada persona sin tocar la otra. Se fuerza al motor a escribir los sonidos de relleno («eeeh», «mmm») en lugar de limpiarlos.</p>' +
      '<p>Motor: <b>' + esc(S.engine.label) + '</b>' + (S.engine.ok ? '' : ' <span class="st-badge bad">sin configurar</span>') + (C.isOwner ? ' · <a href="' + C.optionsUrl + '">Configurar</a>' : '') + '</p>' +
      (S.engine.ok ? '' : '<p class="st-note" style="color:var(--bad)">' + esc(S.engine.hint) + '</p>') +
      '<table class="st-table"><thead><tr><th>Pista</th><th>Estado</th></tr></thead><tbody>' + rows + '</tbody></table>' +
      '<div class="st-row"><button class="btn btn-primary" id="st-tr"' + (jobActive() || !S.engine.ok ? ' disabled' : '') + '>' + (stepDone('transcribe') ? 'Volver a transcribir' : 'Transcribir') + '</button>' +
      (stepDone('transcribe') ? '<button class="btn" data-go="edit">Ir a la edición →</button>' : '') + '</div>' +
      (stepDone('transcribe') ? '<p class="st-note" style="margin-top:8px">Al volver a transcribir se pierden las ediciones de texto hechas sobre esas pistas.</p>' : '') + '</div>';
    el('#st-tr').onclick = function () { if (!stepDone('transcribe') || confirm('Se perderán las ediciones de texto actuales. ¿Continuar?')) runJob('transcribe'); };
    p.querySelectorAll('[data-go]').forEach(function (b) { b.onclick = function () { go(b.dataset.go); }; });
  }

  // ── 4 · Edición por texto ─────────────────────────────────────────────────
  var rafId = 0, curIdx = -1, undo = [], saveTimer = 0, saveSeq = 0, saving = false;
  function stopPlayLoop() { if (rafId) cancelAnimationFrame(rafId); rafId = 0; }

  function viewEdit(p) {
    if (!stepDone('transcribe')) { p.innerHTML = '<div class="card">Primero transcribe las pistas de voz.</div>'; return; }
    if (!W) {
      p.innerHTML = '<div class="card">Cargando transcripción…</div>';
      api('transcript').then(function (j) { W = j; W.del = new Set(j.deleted); undo = []; if (tab === 'edit') viewEdit(p); });
      return;
    }
    var nF = 0, nT = 0; W.words.forEach(function (w) { if (w[5] === 'f') nF++; else if (w[5] === 't') nT++; });
    p.innerHTML =
      '<div class="st-edbar"><audio id="st-audio" controls preload="auto" src="' + C.base + '&do=audio&v=' + W.words.length + '"></audio>' +
      '<div class="st-tools"><button class="btn" id="st-del" title="Supr / Retroceso">Eliminar selección</button><button class="btn" id="st-res">Restaurar selección</button>' +
      '<button class="btn" id="st-undo" title="Ctrl+Z">Deshacer</button><span style="width:8px"></span>' +
      '<button class="btn" id="st-delf">Borrar sonidos de relleno (' + nF + ')</button><button class="btn" id="st-resf">Conservar</button>' +
      '<button class="btn" id="st-delt">Borrar muletillas-palabra (' + nT + ')</button>' +
      '<button class="btn" id="st-next" title="Ir a la siguiente muletilla">Siguiente muletilla ▶</button>' +
      '<label class="st-note" style="display:flex;gap:4px;align-items:center"><input type="checkbox" id="st-only"> Resaltar solo muletillas</label>' +
      '<label class="st-note" style="display:flex;gap:4px;align-items:center"><input type="checkbox" id="st-skip" checked> Saltar cortes al reproducir</label>' +
      '<label class="st-note" style="display:flex;gap:4px;align-items:center"><input type="checkbox" id="st-follow" checked> Seguir</label>' +
      '<span class="st-stats" id="st-stats"></span></div>' +
      '<div class="st-note">Selecciona texto y pulsa <span class="st-kbd">Supr</span> para quitarlo del episodio · clic en una palabra para ir a ese punto · <span class="st-kbd">Espacio</span> reproduce/pausa · <span class="st-kbd">Ctrl</span>+<span class="st-kbd">Z</span> deshace. Amarillo = sonido de relleno · azul = muletilla-palabra · tachado = se eliminará.</div></div>' +
      '<div id="st-doc"></div>' +
      '<div class="st-row" style="margin-top:16px"><button class="btn btn-primary" data-go="export">Continuar: exportar →</button></div>';
    buildDoc(); updateStats(); bindEdit(p);
  }

  function buildDoc() {
    var out = [], para = null, n = 0, lastEnd = -9, lastSpk = -1;
    W.words.forEach(function (w, i) {
      if (para === null || w[4] !== lastSpk || w[2] - lastEnd > 1.6 || n > 90) {
        if (para !== null) out.push('</p>');
        var sp = W.speakers[w[4]] || ('Voz ' + (w[4] + 1));
        out.push('<p class="st-para"><span class="st-who" data-t="' + w[2] + '"><i style="background:var(--st-spk' + (w[4] % 4) + ')"></i>' + esc(sp) + ' · ' + fmt(w[2]) + '</span>');
        para = true; n = 0;
      }
      out.push('<span class="w' + (w[5] ? ' ' + w[5] : '') + (W.del.has(w[0]) ? ' x' : '') + '" data-i="' + i + '">' + esc(w[1]) + '</span> ');
      n++; lastEnd = w[3]; lastSpk = w[4];
    });
    if (para !== null) out.push('</p>');
    el('#st-doc').innerHTML = out.join('');
    curIdx = -1; W.nodes = el('#st-doc').getElementsByClassName('w');
  }

  function updateStats() {
    var s = W.plan, saved = Math.max(0, s.src - s.out);
    var e = el('#st-stats'); if (!e) return;
    e.innerHTML = 'Duración final de la voz: <b>' + fmt(s.out) + '</b> · ahorro <b>−' + fmt(saved) + '</b>' + (saving ? ' · guardando…' : '');
  }

  function setDeleted(idxs, flag) {
    var changed = [];
    idxs.forEach(function (i) {
      var k = W.words[i][0], has = W.del.has(k);
      if (flag && !has) { W.del.add(k); changed.push([i, false]); W.nodes[i].classList.add('x'); }
      else if (!flag && has) { W.del.delete(k); changed.push([i, true]); W.nodes[i].classList.remove('x'); }
    });
    if (changed.length) { undo.push(changed); scheduleSave(); }
  }
  function doUndo() {
    var last = undo.pop(); if (!last) return;
    last.forEach(function (c) { var k = W.words[c[0]][0]; if (c[1]) W.del.add(k); else W.del.delete(k); W.nodes[c[0]].classList.toggle('x', c[1]); });
    scheduleSave();
  }
  function scheduleSave() {
    clearTimeout(saveTimer); saving = true; updateStats();
    saveTimer = setTimeout(doSave, 500);
  }
  function doSave() {
    var seq = ++saveSeq;
    api('cuts', { data: Array.from(W.del) }).then(function (j) {
      if (seq === saveSeq) { W.plan = j.plan; saving = false; updateStats(); }
    }).catch(function () { saving = false; updateStats(); });
  }

  function selectedIdx() {
    var s = window.getSelection(); if (!s.rangeCount || s.isCollapsed) return null;
    var r = s.getRangeAt(0), doc = el('#st-doc'); if (!doc || !doc.contains(r.commonAncestorContainer)) return null;
    var of = function (n) { var e = n.nodeType === 3 ? n.parentElement : n; var w = e && e.closest && e.closest('.w'); return w ? +w.dataset.i : -1; };
    var a = of(r.startContainer), b = of(r.endContainer);
    var N = W.nodes.length;
    if (a < 0) { a = -1; for (var i = 0; i < N; i++) if (r.intersectsNode(W.nodes[i])) { a = i; break; } }
    if (b < 0) { b = -1; for (var j = N - 1; j >= 0; j--) if (r.intersectsNode(W.nodes[j])) { b = j; break; } }
    if (a < 0 || b < 0) return null;
    var out = []; for (var k = Math.min(a, b); k <= Math.max(a, b); k++) out.push(k);
    return out;
  }
  function bulk(kind, flag) { var ix = []; W.words.forEach(function (w, i) { if (w[5] === kind) ix.push(i); }); setDeleted(ix, flag); toast((flag ? 'Marcadas ' : 'Restauradas ') + ix.length + ' muletillas'); }

  function bindEdit(p) {
    var au = el('#st-audio'), doc = el('#st-doc');
    el('#st-del').onclick = function () { var s = selectedIdx(); if (s) { setDeleted(s, true); window.getSelection().removeAllRanges(); } else toast('Selecciona texto primero', true); };
    el('#st-res').onclick = function () { var s = selectedIdx(); if (s) { setDeleted(s, false); window.getSelection().removeAllRanges(); } else toast('Selecciona texto primero', true); };
    el('#st-undo').onclick = doUndo;
    el('#st-delf').onclick = function () { bulk('f', true); };
    el('#st-resf').onclick = function () { bulk('f', false); };
    el('#st-delt').onclick = function () { bulk('t', true); };
    el('#st-only').onchange = function (e) { doc.classList.toggle('only', e.target.checked); };
    el('#st-next').onclick = function () {
      var t = au.currentTime, i = -1;
      for (var k = 0; k < W.words.length; k++) if (W.words[k][5] && W.words[k][2] > t + 0.05) { i = k; break; }
      if (i < 0) return toast('No hay más muletillas');
      au.currentTime = Math.max(0, W.words[i][2] - 0.6); W.nodes[i].scrollIntoView({ block: 'center', behavior: 'smooth' });
    };
    doc.addEventListener('click', function (e) {
      var who = e.target.closest('.st-who'); if (who) { au.currentTime = +who.dataset.t; return; }
      var w = e.target.closest('.w'); if (!w || !window.getSelection().isCollapsed) return;
      au.currentTime = Math.max(0, W.words[+w.dataset.i][2] - 0.05);
    });
    doc.addEventListener('dblclick', function () { au.play(); });
    document.onkeydown = function (e) {
      if (tab !== 'edit' || !W) return;
      var tag = (e.target.tagName || '').toLowerCase(); if (tag === 'input' || tag === 'textarea' || tag === 'select') return;
      if ((e.key === 'Delete' || e.key === 'Backspace')) { var s = selectedIdx(); if (s) { e.preventDefault(); setDeleted(s, true); window.getSelection().removeAllRanges(); } }
      else if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'z') { e.preventDefault(); doUndo(); }
      else if (e.key === ' ' && tag !== 'button' && tag !== 'audio') { e.preventDefault(); au.paused ? au.play() : au.pause(); }
    };
    p.querySelectorAll('[data-go]').forEach(function (b) { b.onclick = function () { go(b.dataset.go); }; });
    // Bucle de reproducción: saltar cortes y resaltar la palabra actual
    stopPlayLoop();
    var loop = function () {
      if (tab !== 'edit' || !W) return;
      var t = au.currentTime;
      if (!au.paused && el('#st-skip').checked) {
        var cuts = W.plan.cut, lo = 0, hi = cuts.length;
        while (lo < hi) { var m = (lo + hi) >> 1; if (cuts[m][1] <= t) lo = m + 1; else hi = m; }
        if (lo < cuts.length && t >= cuts[lo][0] && t < cuts[lo][1]) { au.currentTime = cuts[lo][1]; t = cuts[lo][1]; }
      }
      var a = 0, b = W.words.length - 1, f = -1;
      while (a <= b) { var mid = (a + b) >> 1; if (W.words[mid][2] <= t) { f = mid; a = mid + 1; } else b = mid - 1; }
      if (f >= 0 && t > W.words[f][3] + 0.4) f = -1;
      if (f !== curIdx) {
        if (curIdx >= 0 && W.nodes[curIdx]) W.nodes[curIdx].classList.remove('cur');
        curIdx = f;
        if (f >= 0) {
          var n = W.nodes[f]; n.classList.add('cur');
          if (!au.paused && el('#st-follow').checked) { var r = n.getBoundingClientRect(); if (r.top < 160 || r.bottom > innerHeight - 40) n.scrollIntoView({ block: 'center', behavior: 'smooth' }); }
        }
      }
      rafId = requestAnimationFrame(loop);
    };
    rafId = requestAnimationFrame(loop);
  }

  // ── 5 · Exportar ──────────────────────────────────────────────────────────
  function viewExport(p) {
    var s = S.settings, r = S.result || {}, has = !!r.file;
    var chk = function (k, label) { return '<label class="st-row" style="gap:6px"><input type="checkbox" data-s="' + k + '"' + (s[k] ? ' checked' : '') + '> ' + label + '</label>'; };
    var num = function (k, label, step, min, max) { return '<div class="field"><label class="label">' + label + '</label><input class="input" type="number" data-s="' + k + '" step="' + step + '" min="' + min + '" max="' + max + '" value="' + s[k] + '"></div>'; };
    p.innerHTML =
      '<div class="card st-card"><h2>Ajustes de la mezcla</h2><div class="st-grid2" style="margin-top:10px">' +
      num('max_pause', 'Pausa máxima (s) · 0 = no acortar', 0.1, 0, 5) + num('lufs', 'Sonoridad objetivo (LUFS)', 0.5, -30, -8) +
      num('intro_overlap', 'La voz entra X s antes de acabar la entradilla', 0.5, 0, 10) + num('outro_overlap', 'La salida entra X s antes de acabar la voz', 0.5, 0, 10) +
      num('music_gain', 'Volumen de la música (dB)', 1, -30, 6) + '</div>' +
      '<div class="st-row" style="margin-top:12px;gap:18px">' + chk('denoise', 'Reducir ruido') + chk('compress', 'Compresor de voz') + chk('trim_edges', 'Recortar silencio inicial/final') + '</div></div>' +
      '<div class="card st-card"><h2>Exportar episodio</h2>' +
      '<p class="st-note">Aplica los cortes, silencia las muletillas que se pisan con la otra voz, mezcla con la música, normaliza la sonoridad y genera el MP3 y la transcripción final (WebVTT).</p>' +
      '<div class="st-row"><button class="btn btn-primary" id="st-render"' + (jobActive() ? ' disabled' : '') + '>' + (has ? 'Volver a exportar' : 'Exportar episodio') + '</button></div>' +
      (has ? '<div style="margin-top:16px"><audio controls preload="none" style="width:100%" src="' + C.base + '&do=file&name=episode.mp3&t=' + encodeURIComponent(r.rendered_at || '') + '"></audio>' +
        '<p class="st-note" style="margin-top:6px">Duración <b>' + fmt(r.duration) + '</b> · ' + (r.bytes / 1048576).toFixed(1) + ' MB · se han quitado <b>' + fmt(r.saved) + '</b> de pausas y muletillas · exportado ' + esc(r.rendered_at) + ' UTC</p>' +
        '<div class="st-row"><a class="btn btn-sm" href="' + C.base + '&do=file&name=episode.mp3&download=1">Descargar MP3</a><a class="btn btn-sm" href="' + C.base + '&do=file&name=episode.vtt&download=1">Descargar transcripción (VTT)</a></div></div>' : '') + '</div>' +
      (has ? '<div class="card st-card"><h2>Publicar</h2><p class="st-note">Crea el episodio como <b>borrador</b> en KutPod con este audio y la transcripción. Podrás completar título, notas y capítulos antes de publicarlo.</p>' +
        '<div class="field"><label class="label">Notas del episodio (opcional)</label><textarea class="textarea" id="st-notes" rows="3"></textarea></div>' +
        '<div class="st-row" style="margin-top:10px"><button class="btn btn-primary" id="st-draft">' + (S.episode_id ? 'Actualizar el borrador existente' : 'Crear borrador del episodio') + '</button></div></div>' : '') +
      '<div class="card st-card"><h2>Espacio en disco</h2><p class="st-note">Las copias de trabajo ocupan unos 300 MB por hora y pista. Cuando termines el episodio puedes liberarlas (el audio exportado y las transcripciones se conservan; para volver a editar tendrías que subir los audios otra vez).</p><button class="btn btn-sm" id="st-free">Liberar espacio</button></div>';
    p.querySelectorAll('[data-s]').forEach(function (i) {
      i.onchange = function () {
        var d = {}; p.querySelectorAll('[data-s]').forEach(function (x) { d[x.dataset.s] = x.type === 'checkbox' ? (x.checked ? 1 : 0) : x.value; });
        api('save_settings', { data: d }).then(refresh).then(function () { W = null; toast('Ajustes guardados'); });
      };
    });
    el('#st-render').onclick = function () { runJob('render'); };
    var d = el('#st-draft');
    if (d) d.onclick = function () {
      d.disabled = true;
      api('draft', { notes: el('#st-notes').value }).then(function (j) { toast(j.updated ? 'Borrador actualizado' : 'Borrador creado'); location.href = j.url; }).catch(function () { d.disabled = false; });
    };
    el('#st-free').onclick = function () { if (confirm('Se borrarán las copias de trabajo de las pistas. ¿Continuar?')) api('free_space', {}, 'POST').then(function (j) { toast('Liberados ' + (j.freed / 1048576).toFixed(0) + ' MB'); return refresh(); }).then(function () { render(true); }); };
  }

  // ── Inicio ────────────────────────────────────────────────────────────────
  el('#st-delete').onclick = function () {
    if (confirm('¿Eliminar este proyecto y todos sus archivos de trabajo? El episodio ya creado en KutPod no se toca.')) api('delete', {}, 'POST').then(function () { location.href = C.list; });
  };
  window.StudioBus = { api: api, upload: upload, runJob: runJob, refresh: refresh, toast: toast, fmt: fmt, esc: esc, state: function () { return S; }, config: C };
  refresh().then(function () {
    tab = (location.hash || '').slice(1);
    if (STEPS.map(function (s) { return s[0]; }).indexOf(tab) < 0) tab = suggestTab();
    render(true);
    if (jobActive()) startPolling();
  });
})();
