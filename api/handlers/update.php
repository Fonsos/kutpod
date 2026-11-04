<?php
// ============================================================================
// KutPod · API endpoint para actualizaciones
// ============================================================================
// Acciones: check, apply, rollback
// Usa autenticación por sesión (cookie) desde el panel admin.
// Se carga a través de api/index.php → _bootstrap.php ya incluido.

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/updater.php';

header('Content-Type: application/json; charset=utf-8');

// Verificar sesión admin (cookie, no token Bearer) — solo owner/admin
$u = kp_current_user();
if (!$u || !in_array($u['role'], ['owner', 'admin'], true)) {
  http_response_code(403);
  echo json_encode(['error' => 'Solo el owner o admin pueden gestionar actualizaciones.']);
  exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {

  // -----------------------------------------------------------------------
  // Comprobar si hay actualización disponible
  // -----------------------------------------------------------------------
  case 'check':
    $result = kp_update_check();
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    break;

  // -----------------------------------------------------------------------
  // Descargar actualización
  // -----------------------------------------------------------------------
  case 'download':
    set_time_limit(300);
    $zipUrlRaw = $_POST['zip_url'] ?? '';
    $zipUrl = $zipUrlRaw ? base64_decode($zipUrlRaw) : '';
    
    if (!$zipUrl) {
      echo json_encode(['error' => 'Faltan parámetros (zip_url).']);
      break;
    }

    // Validar que la URL pertenezca a GitHub o al servidor oficial KutStudio
    if (!preg_match('#^https://(?:(?:api\.)?github\.com/|(?:www\.)?kutstudio\.(?:org|com)/)#i', $zipUrl)) {
      echo json_encode(['error' => 'URL de descarga no válida. Solo se permiten URLs oficiales de GitHub o KutStudio.']);
      break;
    }

    $dl = kp_update_download($zipUrl);
    echo json_encode($dl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    break;

  // -----------------------------------------------------------------------
  // Backup + aplicar actualización
  // -----------------------------------------------------------------------
  case 'apply':
    // Aumentar límites para la operación
    set_time_limit(300);
    ini_set('memory_limit', '256M');

    $zipPath = $_POST['zip_path'] ?? '';
    $newVersion = $_POST['new_version'] ?? '';

    if (!$zipPath || !$newVersion) {
      echo json_encode(['error' => 'Faltan parámetros (zip_path, new_version).']);
      break;
    }

    // Validar que zip_path esté dentro del directorio de updates
    $realZipPath = realpath($zipPath);
    $realUpdateDir = realpath(kp_update_dir());
    if (!$realZipPath || !$realUpdateDir || !str_starts_with($realZipPath, $realUpdateDir)) {
      echo json_encode(['error' => 'Ruta del ZIP no válida o fuera del directorio de actualizaciones.']);
      break;
    }

    // Sanitizar version (solo dígitos y puntos)
    $newVersion = preg_replace('/[^0-9.]/', '', $newVersion);

    // Aplicar (incluye backup automático)
    $result = kp_update_apply($zipPath, $newVersion);
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    break;

  // -----------------------------------------------------------------------
  // Subida manual de archivo ZIP para actualizar
  // -----------------------------------------------------------------------
  case 'upload':
    set_time_limit(300);
    ini_set('memory_limit', '256M');

    if (empty($_FILES['zip_file']) || $_FILES['zip_file']['error'] !== UPLOAD_ERR_OK) {
      $errCode = $_FILES['zip_file']['error'] ?? UPLOAD_ERR_NO_FILE;
      $errMap = [
        UPLOAD_ERR_INI_SIZE   => 'El archivo supera upload_max_filesize en php.ini.',
        UPLOAD_ERR_FORM_SIZE  => 'El archivo supera el tamaño máximo permitido.',
        UPLOAD_ERR_PARTIAL    => 'El archivo solo se subió parcialmente.',
        UPLOAD_ERR_NO_FILE    => 'No se seleccionó ningún archivo.',
        UPLOAD_ERR_NO_TMP_DIR => 'Falta la carpeta temporal en el servidor.',
        UPLOAD_ERR_CANT_WRITE => 'Error al escribir el archivo en disco.',
        UPLOAD_ERR_EXTENSION  => 'Una extensión de PHP detuvo la subida.',
      ];
      echo json_encode(['error' => $errMap[$errCode] ?? 'Error al subir el archivo (código ' . $errCode . ').']);
      break;
    }

    $uploaded = $_FILES['zip_file'];
    $origName = $uploaded['name'] ?? '';
    if (!str_ends_with(strtolower($origName), '.zip')) {
      echo json_encode(['error' => 'El archivo subido debe tener extensión .zip']);
      break;
    }

    $destDir = kp_update_dir();
    $destPath = $destDir . '/manual-' . date('Ymd_His') . '.zip';
    if (!move_uploaded_file($uploaded['tmp_name'], $destPath)) {
      echo json_encode(['error' => 'No se pudo mover el archivo ZIP a ' . $destDir . '. Verifica permisos de escritura.']);
      break;
    }

    $result = kp_update_apply_zip($destPath);
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    break;

  // -----------------------------------------------------------------------
  // Revertir a la versión anterior o seleccionada
  // -----------------------------------------------------------------------
  case 'rollback':
    set_time_limit(120);
    $backupFile = $_POST['backup_file'] ?? $_GET['backup_file'] ?? null;
    $result = kp_update_rollback($backupFile);
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    break;

  // -----------------------------------------------------------------------
  // Estadísticas de almacenamiento de actualizaciones
  // -----------------------------------------------------------------------
  case 'storage_info':
    $result = kp_update_get_storage_info();
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    break;

  // -----------------------------------------------------------------------
  // Borrar actualizaciones descargadas (y opcionalmente rollbacks)
  // -----------------------------------------------------------------------
  case 'clean_downloads':
    set_time_limit(120);
    $includeRollbacks = !empty($_POST['include_rollbacks']) || !empty($_GET['include_rollbacks']);
    $result = kp_update_clean_downloads($includeRollbacks);
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    break;

  default:
    http_response_code(400);
    echo json_encode(['error' => 'Acción no reconocida: ' . $action]);
    break;
}
