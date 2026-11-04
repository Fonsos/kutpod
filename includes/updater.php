<?php
// ============================================================================
// KutPod · Motor de actualización (GitHub Releases + PAT)
// ============================================================================
// Funciones para comprobar, descargar, aplicar y revertir actualizaciones
// desde un repositorio privado de GitHub.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../version.php';

// ---------------------------------------------------------------------------
// Configuración
// ---------------------------------------------------------------------------
define('KUTPOD_UPDATE_SERVER', 'https://kutstudio.org/updates/');
define('KUTPOD_UPDATE_REPO', 'ernestoacostame/kutpod');
define('KUTPOD_UPDATE_SUBDIR', 'kutpod'); // Subcarpeta dentro del ZIP del repo

function kp_update_dir(): string {
  $dir = __DIR__ . '/../storage/updates';
  if (!is_dir($dir)) @mkdir($dir, 0755, true);
  return $dir;
}

// ---------------------------------------------------------------------------
// Comprobar actualización disponible
// ---------------------------------------------------------------------------
function kp_update_check(): ?array {
  $token = kp_setting('update_github_token', '');
  $localVersion = KUTPOD_VERSION;

  // 1. Modo Desarrollo / Directo: Si el usuario configuró un token de GitHub explícito
  if (!empty($token)) {
    $url = 'https://api.github.com/repos/' . KUTPOD_UPDATE_REPO . '/releases/latest';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_HTTPHEADER => [
        "Authorization: Bearer $token",
        'Accept: application/vnd.github+json',
        'X-GitHub-Api-Version: 2022-11-28',
      ],
      CURLOPT_USERAGENT => 'KutPod/' . KUTPOD_VERSION,
      CURLOPT_TIMEOUT => 15,
    ]);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($http === 401 || $http === 403) {
      return ['error' => 'Token de GitHub de desarrollo inválido o sin permisos. HTTP ' . $http];
    }
    if ($http !== 200 || !$resp) {
      return ['error' => "Error al consultar GitHub directamente (HTTP $http). $err"];
    }

    $release = json_decode($resp, true);
    if (!$release || empty($release['tag_name'])) {
      return ['error' => 'No se encontraron releases en el repositorio de GitHub.'];
    }

    $remoteVersion = ltrim($release['tag_name'], 'vV');

    if (version_compare($remoteVersion, $localVersion, '<=')) {
      kp_update_save_cache(['up_to_date' => true, 'version' => $localVersion]);
      return ['up_to_date' => true, 'version' => $localVersion];
    }

    $zipUrl = '';
    $zipIsAsset = false;
    if (!empty($release['assets'])) {
      foreach ($release['assets'] as $asset) {
        if (preg_match('/^kutpod.*\.zip$/i', $asset['name'])) {
          $zipUrl = $asset['url'];
          $zipIsAsset = true;
          break;
        }
      }
      if (!$zipUrl) {
        foreach ($release['assets'] as $asset) {
          if (preg_match('/\.zip$/i', $asset['name'])) {
            $zipUrl = $asset['url'];
            $zipIsAsset = true;
            break;
          }
        }
      }
    }
    if (!$zipUrl) {
      $zipUrl = $release['zipball_url'] ?? '';
    }

    $result = [
      'available' => true,
      'channel' => 'github_direct',
      'current_version' => $localVersion,
      'new_version' => $remoteVersion,
      'tag' => $release['tag_name'],
      'name' => $release['name'] ?? $release['tag_name'],
      'changelog' => $release['body'] ?? '',
      'published_at' => $release['published_at'] ?? '',
      'zip_url' => $zipUrl,
      'zip_is_asset' => $zipIsAsset,
      'html_url' => $release['html_url'] ?? '',
    ];

    kp_update_save_cache($result);
    return $result;
  }

  // 2. Modo Estándar Transparente: Servidor Central de Actualizaciones KutStudio
  $server = rtrim(defined('KUTPOD_UPDATE_SERVER') ? KUTPOD_UPDATE_SERVER : 'https://kutstudio.com/updates/', '/');
  $url = $server . '/?app=kutpod&action=check&version=' . rawurlencode($localVersion) . '&nocache=1';

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_USERAGENT => 'KutPod/' . KUTPOD_VERSION,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT => 15,
  ]);
  $resp = curl_exec($ch);
  $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err = curl_error($ch);
  curl_close($ch);

  if ($http !== 200 || !$resp) {
    return ['error' => 'No se pudo conectar con el servidor de actualizaciones KutStudio. ' . ($err ?: "HTTP $http")];
  }

  $data = json_decode($resp, true);
  if (!$data) {
    return ['error' => 'Respuesta no válida del servidor de actualizaciones.'];
  }
  if (!empty($data['error'])) {
    return ['error' => $data['error']];
  }

  if (!empty($data['up_to_date'])) {
    kp_update_save_cache(['up_to_date' => true, 'version' => $localVersion]);
    return ['up_to_date' => true, 'version' => $localVersion];
  }

  $data['channel'] = 'kutstudio';
  kp_update_save_cache($data);
  return $data;
}

// ---------------------------------------------------------------------------
// Cache del check para el Dashboard (evita consultar GitHub en cada carga)
// ---------------------------------------------------------------------------
function kp_update_save_cache(array $data): void {
  $set = kp_db()->prepare("INSERT INTO settings (k,v) VALUES (?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v, updated_at=datetime('now')");
  $set->execute(['update_cache', json_encode($data)]);
  $set->execute(['update_cache_at', (string)time()]);
}

/** Devuelve los datos cacheados del último check, o null si nunca se comprobó */
function kp_update_cached(): ?array {
  $json = kp_setting('update_cache', '');
  if (!$json) return null;
  return json_decode($json, true) ?: null;
}

/** Comprueba si hay que re-consultar (cada 6 horas) */
function kp_update_cache_stale(): bool {
  $at = (int)kp_setting('update_cache_at', '0');
  return (time() - $at) > 21600; // 6 horas
}

// ---------------------------------------------------------------------------
// Descargar el ZIP de la release
// ---------------------------------------------------------------------------
function kp_update_download(string $zipUrl): array {
  if (!$zipUrl) return ['error' => 'URL del ZIP no disponible.'];

  $destDir = kp_update_dir();
  $destPath = $destDir . '/update-' . date('Ymd_His') . '.zip';

  $fp = fopen($destPath, 'w');
  if (!$fp) return ['error' => 'No se puede escribir en ' . $destDir];

  $headers = [
    'User-Agent: KutPod/' . KUTPOD_VERSION,
  ];

  // Si la descarga es directamente desde api.github.com, requerir y adjuntar el token
  $isGitHubApi = (strpos($zipUrl, 'api.github.com') !== false);
  if ($isGitHubApi) {
    $token = kp_setting('update_github_token', '');
    if (!$token) {
      fclose($fp);
      @unlink($destPath);
      return ['error' => 'Se requiere token de GitHub para descargar directamente desde la API privada de GitHub.'];
    }
    $headers[] = "Authorization: Bearer $token";
    $headers[] = 'Accept: application/octet-stream';
    $headers[] = 'X-GitHub-Api-Version: 2022-11-28';
  }

  $ch = curl_init($zipUrl);
  curl_setopt_array($ch, [
    CURLOPT_FILE => $fp,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_USERAGENT => 'KutPod/' . KUTPOD_VERSION,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS => 5,
    CURLOPT_TIMEOUT => 300,
  ]);
  curl_exec($ch);
  $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err = curl_error($ch);
  curl_close($ch);
  fclose($fp);

  if ($http >= 400 || !file_exists($destPath) || filesize($destPath) < 1024) {
    @unlink($destPath);
    return ['error' => "Error al descargar el ZIP (HTTP $http). $err"];
  }

  return ['path' => $destPath, 'size' => filesize($destPath)];
}

// ---------------------------------------------------------------------------
// Crear backup pre-actualización
// ---------------------------------------------------------------------------
function kp_update_backup(): array {
  $rootDir = realpath(__DIR__ . '/..');
  $destDir = kp_update_dir();
  $backupName = 'rollback-' . KUTPOD_VERSION . '-' . date('Ymd_His') . '.tar.gz';
  $backupPath = $destDir . '/' . $backupName;

  // Directorios/ficheros a excluir del backup (datos de usuario)
  $excludes = ['storage', 'media', '.git', 'scratch'];

  // Construir comando tar con exclusiones
  $excludeArgs = '';
  foreach ($excludes as $ex) {
    $excludeArgs .= ' --exclude=' . escapeshellarg('./' . $ex);
  }

  $cmd = sprintf(
    'tar -czf %s -C %s %s .',
    escapeshellarg($backupPath),
    escapeshellarg($rootDir),
    $excludeArgs
  );

  exec($cmd . ' 2>&1', $output, $exitCode);

  if ($exitCode !== 0 || !file_exists($backupPath)) {
    return ['error' => 'Error al crear el backup: ' . implode("\n", $output)];
  }

  // Guardar la referencia del rollback en settings
  $set = kp_db()->prepare("INSERT INTO settings (k,v) VALUES (?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v, updated_at=datetime('now')");
  $set->execute(['update_rollback_path', $backupPath]);
  $set->execute(['update_rollback_version', KUTPOD_VERSION]);

  return ['path' => $backupPath, 'size' => filesize($backupPath)];
}

// ---------------------------------------------------------------------------
// Aplicar actualización desde el ZIP descargado
// ---------------------------------------------------------------------------
function kp_update_apply(string $zipPath, string $newVersion): array {
  if (!file_exists($zipPath)) return ['error' => 'El fichero ZIP no existe.'];
  if (!class_exists('ZipArchive')) return ['error' => 'La extensión ZipArchive de PHP no está instalada.'];

  $rootDir = realpath(__DIR__ . '/..');

  // 1. Crear backup
  $backup = kp_update_backup();
  if (!empty($backup['error'])) return $backup;

  // 2. Extraer ZIP a un directorio temporal
  $tmpDir = kp_update_dir() . '/tmp-' . uniqid();
  @mkdir($tmpDir, 0755, true);

  $zip = new ZipArchive();
  $res = $zip->open($zipPath);
  if ($res !== true) {
    kp_update_cleanup_tmp($tmpDir);
    return ['error' => 'No se pudo abrir el ZIP (código: ' . $res . ')'];
  }
  $zip->extractTo($tmpDir);
  $zip->close();

  // 3. Localizar el directorio raíz de KutPod dentro del ZIP
  //    GitHub zipball crea un directorio como "owner-repo-hash/"
  //    Buscamos la subcarpeta que contenga version.php
  $sourceDir = kp_update_find_kutpod_dir($tmpDir);
  if (!$sourceDir) {
    kp_update_cleanup_tmp($tmpDir);
    return ['error' => 'No se encontró la carpeta de KutPod dentro del ZIP. Asegúrate de que el release contiene la carpeta "' . KUTPOD_UPDATE_SUBDIR . '/" o un version.php en la raíz.'];
  }

  // 4. Directorios/ficheros protegidos que NUNCA se sobrescriben
  $protected = ['storage', 'media', '.htaccess', '.git', 'scratch'];

  // 5. Copiar ficheros nuevos sobre la instalación actual
  $copied = 0;
  $errors = [];

  try {
    $copied = kp_update_copy_recursive($sourceDir, $rootDir, $protected, $errors);
  } catch (Throwable $e) {
    $errors[] = $e->getMessage();
  }

  if (!empty($errors)) {
    // Intentar rollback automático
    $rb = kp_update_rollback_from_path($backup['path']);
    kp_update_cleanup_tmp($tmpDir);
    return ['error' => 'Error al copiar ficheros. Se restauró el backup automáticamente. Errores: ' . implode('; ', array_slice($errors, 0, 5))];
  }

  // 6. Actualizar version.php
  $versionContent = "<?php\n// ============================================================================\n// KutPod · versión de la instalación\n// ============================================================================\n// Este fichero es actualizado automáticamente por el sistema de actualizaciones.\n// No modificar manualmente.\n\ndefine('KUTPOD_VERSION', '$newVersion');\n";
  file_put_contents($rootDir . '/version.php', $versionContent);

  // 7. Registrar en el historial
  try {
    kp_exec("INSERT INTO updates (from_version, to_version, status, backup_path, applied_at) VALUES (?, ?, 'applied', ?, datetime('now'))",
      [KUTPOD_VERSION, $newVersion, $backup['path']]);
  } catch (Throwable $e) { /* tabla puede no existir aún en esta misma actualización */ }

  // 8. Limpiar
  kp_update_cleanup_tmp($tmpDir);
  @unlink($zipPath);

  // 9. Limpiar cache de actualización (para que el banner del Dashboard desaparezca)
  kp_update_save_cache(['up_to_date' => true, 'version' => $newVersion]);

  // Limpiar la caché global de KutPod para que los cambios surtan efecto de inmediato
  if (!function_exists('kp_cache_clear')) require_once __DIR__ . '/cache.php';
  kp_cache_clear();

  // 10. Generar alerta
  kp_alert('system', 'Actualización aplicada', "KutPod actualizado de v" . KUTPOD_VERSION . " a v$newVersion.", '/admin/update');

  return ['success' => true, 'from' => KUTPOD_VERSION, 'to' => $newVersion, 'files_updated' => $copied];
}

/**
 * Aplica una actualización desde un archivo ZIP local (manual o descargado)
 * detectando automáticamente la nueva versión desde version.php dentro del ZIP.
 */
function kp_update_apply_zip(string $zipPath, ?string $manualVersion = null): array {
  if (!file_exists($zipPath)) return ['error' => 'El fichero ZIP no existe.'];
  if (!class_exists('ZipArchive')) return ['error' => 'La extensión ZipArchive de PHP no está instalada.'];

  $zip = new ZipArchive();
  $res = $zip->open($zipPath);
  if ($res !== true) {
    @unlink($zipPath);
    return ['error' => 'No se pudo abrir el archivo ZIP (código: ' . $res . '). Verifica que sea un archivo ZIP válido.'];
  }

  // Buscar version.php dentro del ZIP para auto-detectar la versión
  $detectedVersion = '';
  for ($i = 0; $i < $zip->numFiles; $i++) {
    $stat = $zip->statIndex($i);
    $name = $stat['name'] ?? '';
    if (preg_match('#(?:^|/)version\.php$#i', $name)) {
      $content = $zip->getFromIndex($i);
      if ($content && preg_match("/KUTPOD_VERSION',\s*'([^']+)'/", $content, $m)) {
        $detectedVersion = trim($m[1]);
        break;
      }
    }
  }
  $zip->close();

  $versionToApply = $manualVersion ?: $detectedVersion;
  if (!$versionToApply) {
    // Si no se encontró version.php, intentar extraer del nombre de archivo (ej: kutpod-v1.2.16.zip)
    if (preg_match('/(?:v|kutpod-v?)([0-9]+\.[0-9]+\.[0-9]+)/i', basename($zipPath), $vm)) {
      $versionToApply = $vm[1];
    }
  }

  if (!$versionToApply) {
    @unlink($zipPath);
    return ['error' => 'No se pudo determinar la versión de KutPod dentro del ZIP. Asegúrate de subir un release oficial de KutPod que contenga version.php.'];
  }

  return kp_update_apply($zipPath, $versionToApply);
}

// ---------------------------------------------------------------------------
// Rollback a la versión anterior
// ---------------------------------------------------------------------------
function kp_update_rollback(?string $specificFilename = null): array {
  if ($specificFilename !== null) {
    if (!preg_match('/^rollback-(.*?)-(\d{8}_\d{6})\.tar\.gz$/', $specificFilename, $matches)) {
      return ['error' => 'Nombre de backup inválido.'];
    }
    $backupPath = kp_update_dir() . '/' . $specificFilename;
    if (!file_exists($backupPath)) {
      return ['error' => 'El archivo de backup seleccionado no existe.'];
    }
    $rollbackVersion = $matches[1];
  } else {
    $backupPath = kp_setting('update_rollback_path', '');
    $rollbackVersion = kp_setting('update_rollback_version', '');
  }

  if (!$backupPath || !file_exists($backupPath)) {
    return ['error' => 'No hay backup de rollback disponible.'];
  }

  $result = kp_update_rollback_from_path($backupPath);

  if (!empty($result['error'])) return $result;

  // Actualizar version.php al valor anterior
  if ($rollbackVersion) {
    $rootDir = realpath(__DIR__ . '/..');
    $versionContent = "<?php\n// ============================================================================\n// KutPod · versión de la instalación\n// ============================================================================\n// Este fichero es actualizado automáticamente por el sistema de actualizaciones.\n// No modificar manualmente.\n\ndefine('KUTPOD_VERSION', '$rollbackVersion');\n";
    file_put_contents($rootDir . '/version.php', $versionContent);
  }

  // Marcar en historial
  try {
    kp_exec("UPDATE updates SET status = 'rolled_back', rolled_back_at = datetime('now')
             WHERE id = (SELECT id FROM updates ORDER BY id DESC LIMIT 1)");
  } catch (Throwable $e) {}

  // Si era el de por defecto, limpiar la referencia de settings
  $defaultBackupPath = kp_setting('update_rollback_path', '');
  if ($backupPath === $defaultBackupPath) {
    $set = kp_db()->prepare("INSERT INTO settings (k,v) VALUES (?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v");
    $set->execute(['update_rollback_path', '']);
    $set->execute(['update_rollback_version', '']);
  }

  // Limpiar cache de actualización
  kp_update_save_cache(['up_to_date' => true, 'version' => $rollbackVersion]);

  // Limpiar la caché global de KutPod
  if (!function_exists('kp_cache_clear')) require_once __DIR__ . '/cache.php';
  kp_cache_clear();

  kp_alert('system', 'Rollback completado', "KutPod revertido a v$rollbackVersion.", '/admin/update');

  return ['success' => true, 'restored_version' => $rollbackVersion];
}

/** Obtiene la lista de todos los rollbacks disponibles en storage/updates */
function kp_update_get_available_rollbacks(): array {
  $dir = kp_update_dir();
  $files = glob($dir . '/rollback-*.tar.gz');
  if (!$files) return [];
  
  $rollbacks = [];
  foreach ($files as $file) {
    $filename = basename($file);
    if (preg_match('/^rollback-(.*?)-(\d{8}_\d{6})\.tar\.gz$/', $filename, $matches)) {
      $version = $matches[1];
      $datetimeStr = $matches[2];
      
      // Parse datetime: YYYYMMDD_HHMMSS -> YYYY-MM-DD HH:MM:SS
      $year = substr($datetimeStr, 0, 4);
      $month = substr($datetimeStr, 4, 2);
      $day = substr($datetimeStr, 6, 2);
      $hour = substr($datetimeStr, 9, 2);
      $minute = substr($datetimeStr, 11, 2);
      $second = substr($datetimeStr, 13, 2);
      $formattedDate = "$day/$month/$year $hour:$minute:$second";
      
      $rollbacks[] = [
        'filename' => $filename,
        'path' => $file,
        'version' => $version,
        'date' => $formattedDate,
        'timestamp' => strtotime("$year-$month-$day $hour:$minute:$second"),
        'size' => filesize($file)
      ];
    }
  }
  
  // Sort by date desc (newer first)
  usort($rollbacks, function($a, $b) {
    return $b['timestamp'] <=> $a['timestamp'];
  });
  
  return $rollbacks;
}

// ---------------------------------------------------------------------------
// Funciones auxiliares
// ---------------------------------------------------------------------------

/** Restaurar desde un tar.gz de backup */
function kp_update_rollback_from_path(string $backupPath): array {
  if (!file_exists($backupPath)) return ['error' => 'Archivo de backup no encontrado.'];

  $rootDir = realpath(__DIR__ . '/..');
  $cmd = sprintf(
    'tar -xzf %s -C %s --exclude=%s --exclude=%s --exclude=%s --exclude=%s 2>&1',
    escapeshellarg($backupPath),
    escapeshellarg($rootDir),
    escapeshellarg('./storage'),
    escapeshellarg('./media'),
    escapeshellarg('./.htaccess'),
    escapeshellarg('./.git')
  );

  exec($cmd, $output, $exitCode);

  if ($exitCode !== 0) {
    return ['error' => 'Error al restaurar el backup: ' . implode("\n", $output)];
  }

  return ['success' => true];
}

/** Buscar el directorio de KutPod dentro del ZIP extraído */
function kp_update_find_kutpod_dir(string $tmpDir): ?string {
  // Caso 1: version.php está directamente en tmpDir (release solo de KutPod)
  if (file_exists("$tmpDir/version.php")) return $tmpDir;

  // Caso 2: GitHub zipball — tiene un directorio raíz "owner-repo-hash/"
  $entries = @scandir($tmpDir);
  if (!$entries) return null;

  foreach ($entries as $entry) {
    if ($entry === '.' || $entry === '..') continue;
    $path = "$tmpDir/$entry";
    if (!is_dir($path)) continue;

    // Caso 2a: El subdirectorio raíz contiene version.php (release solo de kutpod)
    if (file_exists("$path/version.php")) return $path;

    // Caso 2b: Monorepo — buscar la subcarpeta kutpod/ dentro
    $subdir = KUTPOD_UPDATE_SUBDIR;
    if (is_dir("$path/$subdir") && file_exists("$path/$subdir/version.php")) {
      return "$path/$subdir";
    }
  }

  return null;
}

/** Copiar recursivamente, respetando directorios protegidos */
function kp_update_copy_recursive(string $src, string $dst, array $protected, array &$errors, string $relPath = ''): int {
  $copied = 0;
  $entries = @scandir($src);
  if (!$entries) return 0;

  foreach ($entries as $entry) {
    if ($entry === '.' || $entry === '..') continue;

    $currentRel = $relPath ? "$relPath/$entry" : $entry;

    // Saltar directorios/ficheros protegidos (solo en el nivel raíz)
    if (!$relPath && in_array($entry, $protected, true)) continue;

    $srcPath = "$src/$entry";
    $dstPath = "$dst/$entry";

    if (is_dir($srcPath)) {
      if (!is_dir($dstPath)) {
        if (!@mkdir($dstPath, 0755, true)) {
          $errors[] = "No se pudo crear directorio: $currentRel";
          continue;
        }
      }
      $copied += kp_update_copy_recursive($srcPath, $dstPath, $protected, $errors, $currentRel);
    } else {
      // Capturar el error real de PHP
      $ok = @copy($srcPath, $dstPath);
      if ($ok) {
        @chmod($dstPath, 0644);
        $copied++;
      } else {
        $reason = '';
        if (!is_readable($srcPath)) {
          $reason = 'origen no legible';
        } elseif (file_exists($dstPath) && !is_writable($dstPath)) {
          $owner = function_exists('posix_getpwuid') ? (posix_getpwuid(fileowner($dstPath))['name'] ?? '?') : fileowner($dstPath);
          $reason = "destino no escribible (owner: $owner, perms: " . decoct(fileperms($dstPath) & 0777) . ")";
        } elseif (!is_writable(dirname($dstPath))) {
          $reason = 'directorio destino no escribible';
        } else {
          $err = error_get_last();
          $reason = $err['message'] ?? 'error desconocido';
        }
        $errors[] = "No se pudo copiar: $currentRel — $reason";
      }
    }
  }

  return $copied;
}

/** Eliminar directorio temporal recursivamente */
function kp_update_cleanup_tmp(string $dir): void {
  if (!is_dir($dir)) return;
  $entries = @scandir($dir);
  if (!$entries) return;
  foreach ($entries as $entry) {
    if ($entry === '.' || $entry === '..') continue;
    $path = "$dir/$entry";
    if (is_dir($path)) {
      kp_update_cleanup_tmp($path);
    } else {
      @unlink($path);
    }
  }
  @rmdir($dir);
}

/** Obtener historial de actualizaciones */
function kp_update_history(): array {
  try {
    return kp_q("SELECT * FROM updates ORDER BY id DESC LIMIT 20");
  } catch (Throwable $e) {
    return [];
  }
}

/** Obtener estadísticas de almacenamiento de actualizaciones */
function kp_update_get_storage_info(): array {
  $dir = kp_update_dir();
  $info = [
    'downloaded_count' => 0,
    'downloaded_bytes' => 0,
    'rollbacks_count'  => 0,
    'rollbacks_bytes'  => 0,
    'total_bytes'      => 0,
    'files'            => []
  ];

  if (!is_dir($dir)) return $info;

  $items = @scandir($dir) ?: [];
  foreach ($items as $item) {
    if ($item === '.' || $item === '..') continue;
    $path = $dir . '/' . $item;

    if (is_file($path)) {
      $size = filesize($path) ?: 0;
      if (preg_match('/\.zip$/i', $item)) {
        $info['downloaded_count']++;
        $info['downloaded_bytes'] += $size;
        $info['files'][] = ['name' => $item, 'type' => 'zip', 'size' => $size];
      } elseif (preg_match('/^rollback-.*\.tar\.gz$/i', $item)) {
        $info['rollbacks_count']++;
        $info['rollbacks_bytes'] += $size;
        $info['files'][] = ['name' => $item, 'type' => 'rollback', 'size' => $size];
      }
    } elseif (is_dir($path) && str_starts_with($item, 'tmp-')) {
      $tmpSize = kp_dir_size($path);
      $info['downloaded_count']++;
      $info['downloaded_bytes'] += $tmpSize;
      $info['files'][] = ['name' => $item, 'type' => 'tmp', 'size' => $tmpSize];
    }
  }

  $info['total_bytes'] = $info['downloaded_bytes'] + $info['rollbacks_bytes'];
  return $info;
}

/** Calcular tamaño recursivo de un directorio */
function kp_dir_size(string $dir): int {
  $size = 0;
  if (!is_dir($dir)) return 0;
  $items = @scandir($dir) ?: [];
  foreach ($items as $item) {
    if ($item === '.' || $item === '..') continue;
    $path = $dir . '/' . $item;
    if (is_dir($path)) {
      $size += kp_dir_size($path);
    } else {
      $size += filesize($path) ?: 0;
    }
  }
  return $size;
}

/** Limpiar actualizaciones descargadas (archivos zip y temporales) y opcionalmente rollbacks */
function kp_update_clean_downloads(bool $includeRollbacks = false): array {
  $dir = kp_update_dir();
  $deletedCount = 0;
  $freedBytes = 0;

  if (!is_dir($dir)) {
    return ['success' => true, 'deleted_count' => 0, 'freed_bytes' => 0, 'freed_human' => human_bytes(0)];
  }

  $items = @scandir($dir) ?: [];
  foreach ($items as $item) {
    if ($item === '.' || $item === '..') continue;
    $path = $dir . '/' . $item;

    if (is_file($path)) {
      $isZip = (bool)preg_match('/\.zip$/i', $item);
      $isRollback = (bool)preg_match('/^rollback-.*\.tar\.gz$/i', $item);

      if ($isZip || ($includeRollbacks && $isRollback)) {
        $sz = filesize($path) ?: 0;
        if (@unlink($path)) {
          $deletedCount++;
          $freedBytes += $sz;
        }
      }
    } elseif (is_dir($path) && str_starts_with($item, 'tmp-')) {
      $sz = kp_dir_size($path);
      kp_update_cleanup_tmp($path);
      $deletedCount++;
      $freedBytes += $sz;
    }
  }

  if ($includeRollbacks) {
    $defaultRb = kp_setting('update_rollback_path', '');
    if ($defaultRb && !file_exists($defaultRb)) {
      $set = kp_db()->prepare("INSERT INTO settings (k,v) VALUES (?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v");
      $set->execute(['update_rollback_path', '']);
      $set->execute(['update_rollback_version', '']);
    }
  }

  return [
    'success' => true,
    'deleted_count' => $deletedCount,
    'freed_bytes' => $freedBytes,
    'freed_human' => human_bytes($freedBytes)
  ];
}
