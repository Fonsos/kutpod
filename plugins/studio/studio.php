<?php
// ============================================================================
// KutPod · Estudio de edición (plugin)
// ----------------------------------------------------------------------------
// Flujo: audios de la grabación + música de entradilla/salida → sincronización →
// transcripción → edición recortando texto (con filtro de muletillas) →
// mezcla, normalización y exportación del episodio (borrador en KutPod).
//
// Extensible desde otros plugins:
//   filter  studio_job_handlers   añadir tipos de trabajo  [tipo => fn($pid,$params,$progress)]
//   action  studio_panels         pintar paneles extra en el editor ($proyecto, $estado)
//   action  studio_ajax_{do}      atender peticiones AJAX nuevas ($proyecto)
// ============================================================================
if (!defined('KUTPOD_VERSION')) { exit; }

require_once __DIR__ . '/lib/core.php';
require_once __DIR__ . '/lib/audio.php';
require_once __DIR__ . '/lib/transcribe.php';
require_once __DIR__ . '/lib/plan.php';
require_once __DIR__ . '/lib/render.php';
require_once __DIR__ . '/lib/jobs.php';

kp_add_filter('admin_pages', function (array $pages): array {
  $pages['studio'] = ['file' => __DIR__ . '/page.php', 'title' => 'Estudio de edición'];
  return $pages;
});

kp_add_action('admin_nav', function ($page) {
  echo '<a class="nav-item ' . ($page === 'studio' ? 'active' : '') . '" href="' . admin_url('studio') . '">'
     . icon('mic') . ' Estudio de edición</a>';
});
