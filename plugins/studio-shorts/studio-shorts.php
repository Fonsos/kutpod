<?php
// ============================================================================
// KutPod · Estudio · Shorts y Reels (plugin, requiere «studio»)
// Vídeo vertical 1080×1920 a partir del episodio exportado: portada, forma de onda,
// título y subtítulos palabra a palabra resaltados.
// ============================================================================
if (!defined('KUTPOD_VERSION')) { exit; }
if (!function_exists('studio_render')) { return; }   // el plugin base no está cargado

require_once __DIR__ . '/lib/shorts.php';

kp_add_filter('studio_job_handlers', function (array $h): array { $h['short'] = 'studio_short_job'; return $h; });

kp_add_action('studio_panels', function (array $proj) {
  echo '<div class="st-plugin-panel" data-tab="export" id="st-shorts" hidden>';
  readfile(__DIR__ . '/panel.html');
  echo '<style>'; readfile(__DIR__ . '/panel.css'); echo '</style>';
  echo '<script>'; readfile(__DIR__ . '/panel.js'); echo '</script>';
  echo '</div>';
});

// ── Acciones AJAX (studio_ajax_<do>) ────────────────────────────────────────
kp_add_action('studio_ajax_short_words', function (array $proj) {
  $f = studio_pdir((int)$proj['id'], 'render') . '/final_words.json';
  if (!is_file($f)) studio_fail('Primero exporta el episodio');
  $w = json_decode(file_get_contents($f), true) ?: [];
  studio_json(['words' => array_map(fn($x) => [$x['w'], $x['s'], $x['e'], $x['spk']], $w),
               'speakers' => $proj['result']['speakers'] ?? [], 'duration' => $proj['result']['duration'] ?? 0,
               'voice_offset' => $proj['result']['voice_offset'] ?? 0, 'voice_len' => $proj['result']['voice_len'] ?? 0]);
});

kp_add_action('studio_ajax_short_suggest', function (array $proj) {
  $f = studio_pdir((int)$proj['id'], 'render') . '/final_words.json';
  if (!is_file($f)) studio_fail('Primero exporta el episodio');
  $w = json_decode(file_get_contents($f), true) ?: [];
  $r = $proj['result'];
  studio_json(['candidates' => studio_short_suggest($w, (float)($r['voice_offset'] ?? 0), (float)($r['voice_offset'] ?? 0) + (float)($r['voice_len'] ?? 0))]);
});

kp_add_action('studio_ajax_short_list', function (array $proj) {
  studio_json(['shorts' => studio_short_list((int)$proj['id'])]);
});

kp_add_action('studio_ajax_short_delete', function (array $proj) {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') studio_fail('Método no permitido', 405);
  $pid = (int)$proj['id']; $id = (int)($_POST['short'] ?? 0);
  $list = array_values(array_filter(studio_short_list($pid), function ($s) use ($pid, $id) {
    if ((int)$s['id'] !== $id) return true;
    @unlink(studio_pdir($pid, 'render') . '/' . $s['file']);
    return false;
  }));
  studio_short_save($pid, $list);
  studio_json(['ok' => true]);
});
