<?php
// ============================================================================
// KutPod · Administrador de Plugins
// ============================================================================

// Evitar acceso directo
if (!defined('KUTPOD_VERSION')) {
    exit;
}

$pluginsDir = __DIR__ . '/../plugins';
if (!is_dir($pluginsDir)) {
    @mkdir($pluginsDir, 0755, true);
}

// ── Helpers de escaneo y carga ──────────────────────────────────────────────

/**
 * Escanea la carpeta plugins y devuelve la lista de metadatos de los plugins encontrados.
 */
function kp_get_available_plugins(): array {
    global $pluginsDir;
    if (!is_dir($pluginsDir)) {
        return [];
    }

    $plugins = [];
    $folders = array_diff(scandir($pluginsDir), ['.', '..']);
    foreach ($folders as $folder) {
        $path = "$pluginsDir/$folder";
        if (is_dir($path) && file_exists("$path/plugin.json")) {
            $meta = json_decode(file_get_contents("$path/plugin.json"), true);
            if ($meta && isset($meta['id'])) {
                $meta['folder'] = $folder;
                // Verificar si tiene archivo de opciones
                $meta['has_options'] = file_exists("$path/opciones.php") || file_exists("$path/options.php");
                $plugins[$meta['id']] = $meta;
            }
        }
    }
    return $plugins;
}

// ── Procesamiento de POST ───────────────────────────────────────────────────

// Activar o desactivar plugins
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['activate', 'deactivate'])) {
    $plugin_id = $_POST['plugin_id'] ?? '';
    
    // Obtener plugins activos actualmente
    $active = json_decode(kp_setting('active_plugins', '[]'), true);
    if (!is_array($active)) {
        $active = [];
    }

    $all_plugins = kp_get_available_plugins();

    if ($_POST['action'] === 'activate') {
        // Dependencias: "requires": ["otro-plugin"] en plugin.json
        $missing = array_values(array_diff($all_plugins[$plugin_id]['requires'] ?? [], $active));
        if ($missing) {
            setcookie('kp_flash', 'Error: este plugin necesita tener activo antes: ' . implode(', ', $missing), time() + 30, '/');
            header('Location: ' . admin_url('plugins'));
            exit;
        }
        if (!in_array($plugin_id, $active)) {
            $active[] = $plugin_id;
        }
        $msg = 'Plugin activado exitosamente';
    } else {
        $active = array_values(array_diff($active, [$plugin_id]));
        // Desactivar también los plugins que dependen de este
        foreach ($all_plugins as $dep_id => $dep) {
            if (in_array($plugin_id, $dep['requires'] ?? [], true)) {
                $active = array_values(array_diff($active, [$dep_id]));
            }
        }
        $msg = 'Plugin desactivado exitosamente';
    }

    kp_update_setting('active_plugins', json_encode($active));
    
    // Vaciar caché para asegurar que los ganchos tomen efecto
    require_once __DIR__ . '/../includes/cache.php';
    kp_cache_clear();

    setcookie('kp_flash', $msg, time() + 30, '/');
    header('Location: ' . admin_url('plugins'));
    exit;
}

// Subida e instalación de plugin (.ZIP)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload') {
    // Restringir a rol owner
    if (($current_user['role'] ?? '') !== 'owner') {
        setcookie('kp_flash', 'Error: Solo el propietario de la instancia puede instalar plugins.', time() + 30, '/');
        header('Location: ' . admin_url('plugins'));
        exit;
    }

    if (empty($_FILES['zip']) || $_FILES['zip']['error'] !== UPLOAD_ERR_OK) {
        setcookie('kp_flash', 'Error al subir el archivo. Selecciona un archivo ZIP válido.', time() + 30, '/');
        header('Location: ' . admin_url('plugins'));
        exit;
    }

    if (!is_writable($pluginsDir)) {
        setcookie('kp_flash', 'Error de escritura en el servidor. Verifica los permisos de la carpeta plugins/.', time() + 30, '/');
        header('Location: ' . admin_url('plugins'));
        exit;
    }

    if (!class_exists('ZipArchive')) {
        setcookie('kp_flash', 'Error: La extensión PHP ZipArchive no está habilitada en tu servidor.', time() + 30, '/');
        header('Location: ' . admin_url('plugins'));
        exit;
    }

    $zipFile = $_FILES['zip']['tmp_name'];
    $zip = new ZipArchive();

    if ($zip->open($zipFile) === TRUE) {
        $has_json = false;
        $invalid_path = false;

        // Validar seguridad del ZIP (Prevenir Path Traversal)
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (strpos($name, '../') !== false || strpos($name, '..\\') !== false) {
                $invalid_path = true;
                break;
            }
            if (basename($name) === 'plugin.json') {
                $has_json = true;
            }
        }

        if ($invalid_path) {
            $zip->close();
            setcookie('kp_flash', 'Error de seguridad: El archivo ZIP contiene rutas relativas no válidas.', time() + 30, '/');
            header('Location: ' . admin_url('plugins'));
            exit;
        }

        if (!$has_json) {
            $zip->close();
            setcookie('kp_flash', 'Error: El ZIP no contiene un archivo metadata plugin.json obligatorio.', time() + 30, '/');
            header('Location: ' . admin_url('plugins'));
            exit;
        }

        // Extraer a la carpeta de plugins
        $zip->extractTo($pluginsDir);
        $zip->close();

        setcookie('kp_flash', 'Plugin instalado con éxito', time() + 30, '/');
        header('Location: ' . admin_url('plugins'));
        exit;
    } else {
        setcookie('kp_flash', 'Error al procesar el archivo ZIP.', time() + 30, '/');
        header('Location: ' . admin_url('plugins'));
        exit;
    }
}

// ── Rutas y Subpáginas ──────────────────────────────────────────────────────

$action = $_GET['action'] ?? '';
$pluginId = $_GET['plugin'] ?? '';
$available_plugins = kp_get_available_plugins();
$active_plugins = json_decode(kp_setting('active_plugins', '[]'), true) ?: [];

// Enrutador de opciones para el plugin
if ($action === 'settings' && $pluginId !== '') {
    if (!isset($available_plugins[$pluginId])) {
        setcookie('kp_flash', 'Plugin no encontrado.', time() + 30, '/');
        header('Location: ' . admin_url('plugins'));
        exit;
    }
    
    $plugin = $available_plugins[$pluginId];
    $folder = $plugin['folder'];
    
    // Cargar opciones.php o options.php del plugin
    $optionsFile = "$pluginsDir/$folder/opciones.php";
    if (!file_exists($optionsFile)) {
        $optionsFile = "$pluginsDir/$folder/options.php";
    }

    if (file_exists($optionsFile)) {
        // Renderizar el archivo de opciones del plugin
        require $optionsFile;
        return;
    } else {
        setcookie('kp_flash', 'Este plugin no tiene opciones de configuración.', time() + 30, '/');
        header('Location: ' . admin_url('plugins'));
        exit;
    }
}
?>

<div class="page-head">
  <div>
    <h1 class="page-title">Manejador de Plugins</h1>
    <p class="page-sub">Activa integraciones o extiende la funcionalidad de tu instancia</p>
  </div>
</div>

<div class="grid-12" style="gap:24px">
  
  <!-- Formulario de Instalación -->
  <div class="span-4 col" style="gap:24px">
    <div class="card">
      <div class="card-title" style="margin-bottom:12px">Subir nuevo Plugin</div>
      <p style="font-size:12.5px;color:var(--text-3);line-height:1.5;margin-bottom:16px">
        Puedes subir un plugin empaquetado en formato <strong>.zip</strong>. Debe contener el archivo <code>plugin.json</code> en la raíz.
      </p>
      
      <?php if (!is_writable($pluginsDir)): ?>
        <div style="background:var(--red-soft); border: 1px solid var(--red-10); color:var(--red); padding:12px; border-radius:8px; font-size:12px; line-height:1.5; margin-bottom:16px">
          <strong>Directorio sin permisos de escritura:</strong><br>
          Para instalar manualmente, usa SSH en la raíz del servidor y asigna permisos:
          <code style="display:block; margin-top:6px; background:rgba(0,0,0,0.15); padding:6px; border-radius:4px; font-family:monospace; user-select:all">chmod -R 775 storage/plugins</code>
        </div>
      <?php endif; ?>

      <form method="POST" enctype="multipart/form-data">
        <?= kp_csrf_field() ?>
        <input type="hidden" name="action" value="upload">
        <div class="field">
          <label class="label">Selecciona el ZIP del plugin</label>
          <input class="input" type="file" name="zip" accept=".zip" required style="padding:6px">
        </div>
        <button class="btn btn-primary" type="submit" style="margin-top:16px; width:100%; justify-content:center" <?= !is_writable($pluginsDir) ? 'disabled' : '' ?>>
          <?= icon('upload', 14) ?> Instalar Plugin
        </button>
      </form>
    </div>
  </div>

  <!-- Listado de Plugins -->
  <div class="span-8 col">
    <div class="card">
      <div class="card-title" style="margin-bottom:18px">Plugins Disponibles</div>
      
      <?php if (empty($available_plugins)): ?>
        <div style="text-align:center; padding:48px 0; color:var(--text-3)">
          <?= icon('layers', 32) ?>
          <p style="margin-top:12px; font-size:14px">No hay plugins instalados.</p>
        </div>
      <?php else: ?>
        <div class="col" style="gap:16px">
          <?php foreach ($available_plugins as $id => $plugin): 
              $is_active = in_array($id, $active_plugins);
          ?>
            <div class="plugin-item-row" style="display:flex; align-items:flex-start; gap:16px; padding:18px; border:1px solid var(--border); border-radius:12px; background:var(--surface-2)">
              <div style="width:40px; height:40px; border-radius:8px; background:var(--accent-soft); color:var(--accent); display:grid; place-items:center; flex-shrink:0">
                <?= icon('layers', 20) ?>
              </div>
              <div style="flex:1; min-width:0">
                <div style="display:flex; align-items:center; gap:8px">
                  <h3 style="margin:0; font-size:15px; font-weight:600"><?= e($plugin['name']) ?></h3>
                  <span class="tag <?= $is_active ? 'published' : 'draft' ?>" style="font-size:10.5px">
                    <?= $is_active ? 'Activo' : 'Inactivo' ?>
                  </span>
                </div>
                <p style="margin:6px 0; font-size:13px; color:var(--text-2); line-height:1.4">
                  <?= e($plugin['description'] ?? 'Sin descripción.') ?>
                </p>
                <div style="display:flex; gap:12px; font-size:11.5px; color:var(--text-3)">
                  <span>Versión: <strong><?= e($plugin['version'] ?? '1.0') ?></strong></span>
                  <span>Autor: <strong><?= e($plugin['author'] ?? 'Desconocido') ?></strong></span>
                  <?php if (!empty($plugin['requires'])): ?>
                    <span>Requiere: <strong><?= e(implode(', ', $plugin['requires'])) ?></strong></span>
                  <?php endif; ?>
                </div>
              </div>
              
              <div class="plugin-actions" style="display:flex; flex-direction:column; gap:8px; align-items:stretch; flex-shrink:0">
                <form method="POST" style="margin:0">
                  <?= kp_csrf_field() ?>
                  <input type="hidden" name="plugin_id" value="<?= e($id) ?>">
                  <?php if ($is_active): ?>
                    <input type="hidden" name="action" value="deactivate">
                    <button class="btn btn-ghost" type="submit" style="color:var(--text-3); width:100%; justify-content:center; padding:6px 12px; font-size:12px">
                      Desactivar
                    </button>
                  <?php else: ?>
                    <input type="hidden" name="action" value="activate">
                    <button class="btn" type="submit" style="color:var(--accent); border-color:var(--accent); width:100%; justify-content:center; padding:6px 12px; font-size:12px">
                      Activar
                    </button>
                  <?php endif; ?>
                </form>
                
                <?php if ($is_active && $plugin['has_options']): ?>
                  <a class="btn btn-primary" href="<?= admin_url('plugins?action=settings&plugin=' . urlencode($id)) ?>" style="padding:6px 12px; font-size:12px; text-align:center; display:block">
                    <?= icon('settings', 12) ?> Configurar
                  </a>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

</div>
