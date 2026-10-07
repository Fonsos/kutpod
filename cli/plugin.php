<?php
// ============================================================================
// cli/plugin.php — Gestión de plugins y ajustes desde la línea de comandos
// Uso:
//   php cli/plugin.php list
//   php cli/plugin.php activate <id>      (respeta "requires" de plugin.json)
//   php cli/plugin.php deactivate <id>
//   php cli/plugin.php set <clave> <valor>
// ============================================================================
if (php_sapi_name() !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/cache.php';

$available = [];
foreach (glob(__DIR__ . '/../plugins/*/plugin.json') ?: [] as $f) {
  $m = json_decode(file_get_contents($f), true);
  if ($m && isset($m['id'])) $available[$m['id']] = $m;
}
$active = json_decode((string)kp_setting('active_plugins', '[]'), true);
if (!is_array($active)) $active = [];

$cmd = $argv[1] ?? '';
$id  = $argv[2] ?? '';
$save = function (array $a) { kp_update_setting('active_plugins', json_encode(array_values($a))); kp_cache_clear(); };

switch ($cmd) {
  case 'list':
    foreach ($available as $pid => $m) printf("%-16s %-8s %s\n", $pid, in_array($pid, $active, true) ? 'activo' : '-', $m['name'] ?? '');
    break;
  case 'activate':
    if (!isset($available[$id])) { fwrite(STDERR, "Plugin desconocido: $id\n"); exit(1); }
    $missing = array_diff($available[$id]['requires'] ?? [], $active);
    if ($missing) { fwrite(STDERR, "Activa antes: " . implode(', ', $missing) . "\n"); exit(1); }
    if (!in_array($id, $active, true)) $active[] = $id;
    $save($active);
    echo "Plugin «{$id}» activado\n";
    break;
  case 'deactivate':
    $active = array_diff($active, [$id]);
    foreach ($available as $pid => $m) if (in_array($id, $m['requires'] ?? [], true)) $active = array_diff($active, [$pid]);
    $save($active);
    echo "Plugin «{$id}» desactivado\n";
    break;
  case 'set':
    if ($id === '' || !isset($argv[3])) { fwrite(STDERR, "Uso: set <clave> <valor>\n"); exit(1); }
    kp_update_setting($id, $argv[3]);
    echo "$id = {$argv[3]}\n";
    break;
  default:
    fwrite(STDERR, "Uso: php cli/plugin.php list|activate <id>|deactivate <id>|set <clave> <valor>\n");
    exit(1);
}
