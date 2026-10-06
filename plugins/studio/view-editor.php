<?php
// ============================================================================
// KutPod · Estudio · vista del proyecto (editor por pasos)
// ============================================================================
if (!defined('KUTPOD_VERSION')) { exit; }

$st_cfg = [
  'id'   => (int)$proj['id'],
  'base' => admin_url('studio') . '?ajax=1&id=' . (int)$proj['id'],
  'csrf' => kp_csrf_token(),
  'list' => admin_url('studio'),
  'optionsUrl' => admin_url('plugins?action=settings&plugin=studio'),
  'isOwner' => ($studio_user['role'] ?? '') === 'owner',
];
?>
<div class="page-head">
  <div>
    <div class="crumbs" style="font-size:12.5px;color:var(--text-3);margin-bottom:6px"><a href="<?= admin_url('studio') ?>" style="color:inherit">Estudio de edición</a> › <?= e($proj['title']) ?></div>
    <h1 class="page-title" id="st-title"><?= e($proj['title']) ?></h1>
  </div>
  <div class="row" style="gap:8px">
    <button class="btn btn-ghost" id="st-delete" style="color:var(--bad)"><?= icon('trash', 14) ?> Eliminar proyecto</button>
  </div>
</div>

<div id="st-app" class="st">
  <div id="st-job" class="st-job" hidden></div>
  <nav class="st-steps" id="st-steps"></nav>
  <div id="st-panel"></div>
  <div id="st-plugin-holder" hidden><?php kp_do_action('studio_panels', $proj); ?></div>
</div>
<div id="st-toast" class="st-toast" hidden></div>

<style><?php readfile(__DIR__ . '/ui.css'); ?></style>
<script>window.STUDIO = <?= json_encode($st_cfg) ?>;</script>
<script><?php readfile(__DIR__ . '/ui.js'); ?></script>
