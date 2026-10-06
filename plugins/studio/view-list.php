<?php
// ============================================================================
// KutPod · Estudio · lista de proyectos
// ============================================================================
if (!defined('KUTPOD_VERSION')) { exit; }

$podcasts = array_values(array_filter(kp_q("SELECT id, title FROM podcasts ORDER BY title"), fn($p) => kp_can_edit_podcast((int)$p['id'], $studio_user)));
$ids = array_column($podcasts, 'id');
$projects = $ids ? kp_q("SELECT sp.*, p.title AS podcast_title FROM studio_projects sp JOIN podcasts p ON p.id = sp.podcast_id WHERE sp.podcast_id IN (" . implode(',', array_map('intval', $ids)) . ") ORDER BY sp.updated_at DESC") : [];
$labels = ['new' => 'Nuevo', 'ingested' => 'Audios listos', 'synced' => 'Sincronizado', 'transcribed' => 'Transcrito', 'rendered' => 'Exportado', 'drafted' => 'Borrador creado'];
?>
<div class="page-head">
  <div>
    <h1 class="page-title">Estudio de edición</h1>
    <p class="page-sub">Sube la grabación, sincroniza, transcribe y edita el episodio recortando texto.</p>
  </div>
  <div class="row" style="gap:8px">
    <?php if (($studio_user['role'] ?? '') === 'owner'): ?>
      <a class="btn" href="<?= admin_url('plugins?action=settings&plugin=studio') ?>"><?= icon('settings', 14) ?> Configurar</a>
    <?php endif; ?>
  </div>
</div>

<div class="grid-12" style="gap:24px">
  <div class="span-4 col" style="gap:24px">
    <div class="card">
      <div class="card-title" style="margin-bottom:12px">Nuevo episodio</div>
      <?php if (!$podcasts): ?>
        <p class="help">Primero crea un podcast.</p>
      <?php else: ?>
      <form id="st-new">
        <div class="field">
          <label class="label">Podcast</label>
          <select class="input" name="podcast_id"><?php foreach ($podcasts as $p): ?><option value="<?= (int)$p['id'] ?>"><?= e($p['title']) ?></option><?php endforeach; ?></select>
        </div>
        <div class="field" style="margin-top:12px">
          <label class="label">Título del episodio</label>
          <input class="input" name="title" required placeholder="Ej. 42 · Juegos de cartas cooperativos">
        </div>
        <button class="btn btn-primary" type="submit" style="margin-top:16px;width:100%;justify-content:center"><?= icon('plus', 14) ?> Crear proyecto</button>
        <p class="help" id="st-new-err" style="color:var(--bad);margin-top:8px"></p>
      </form>
      <?php endif; ?>
    </div>
  </div>
  <div class="span-8 col">
    <div class="card">
      <div class="card-title" style="margin-bottom:14px">Proyectos</div>
      <?php if (!$projects): ?>
        <div style="text-align:center;padding:40px 0;color:var(--text-3)"><?= icon('mic', 30) ?><p style="margin-top:10px">Aún no hay proyectos.</p></div>
      <?php else: foreach ($projects as $pr): ?>
        <a href="<?= admin_url('studio?id=' . (int)$pr['id']) ?>" style="display:flex;align-items:center;gap:14px;padding:14px 4px;border-top:1px solid var(--border);text-decoration:none;color:inherit">
          <div style="flex:1;min-width:0">
            <div style="font-weight:600"><?= e($pr['title']) ?></div>
            <div class="help" style="margin:2px 0 0"><?= e($pr['podcast_title']) ?> · actualizado <?= e(kp_relative_time($pr['updated_at'])) ?></div>
          </div>
          <span class="tag <?= in_array($pr['status'], ['rendered', 'drafted'], true) ? 'published' : 'draft' ?>"><?= e($labels[$pr['status']] ?? $pr['status']) ?></span>
        </a>
      <?php endforeach; endif; ?>
    </div>
  </div>
</div>

<script>
(function () {
  var f = document.getElementById('st-new'); if (!f) return;
  f.addEventListener('submit', function (ev) {
    ev.preventDefault();
    var fd = new FormData(f); fd.append('_csrf', <?= json_encode(kp_csrf_token()) ?>);
    fetch('<?= admin_url('studio') ?>?ajax=1&do=create', { method: 'POST', body: fd }).then(function (r) { return r.json(); }).then(function (j) {
      if (j.error) { document.getElementById('st-new-err').textContent = j.error; return; }
      location.href = '<?= admin_url('studio') ?>?id=' + j.id;
    });
  });
})();
</script>
