<?php
// ============================================================================
// KutPod · Estudio · worker CLI.  Uso: php worker.php <job_id>
// ============================================================================
if (php_sapi_name() !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../../includes/helpers.php';   // carga también los plugins activos
if (!function_exists('studio_job_run')) {
  fwrite(STDERR, "El plugin «studio» no está activo\n");
  exit(1);
}
$id = (int)($argv[1] ?? 0);
if ($id <= 0) { fwrite(STDERR, "Uso: php worker.php <job_id>\n"); exit(1); }
studio_job_run($id);
