<?php
// ============================================================================
// KutPod · Estudio · opciones del plugin
// ============================================================================
if (!defined('KUTPOD_VERSION')) { exit; }
if (!in_array(kp_current_user()['role'] ?? '', ['owner', 'admin'], true)) { http_response_code(403); exit('403 · Acceso denegado'); }

$back = admin_url('plugins?action=settings&plugin=studio');
const STUDIO_AUDIO_EXTS = ['wav', 'mp3', 'flac', 'm4a', 'aac', 'ogg', 'opus'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $form = $_POST['_form'] ?? '';
  if ($form === 'studio_settings') {
    $text = ['engine', 'api_url', 'api_key', 'api_model', 'fw_model', 'fw_device', 'python', 'language', 'prompt', 'filler_words'];
    foreach ($text as $k) if (isset($_POST[$k])) kp_update_setting('studio_' . $k, trim((string)$_POST[$k]));
    kp_update_setting('studio_auto_fillers', !empty($_POST['auto_fillers']) ? '1' : '0');
    foreach (['pad_ms' => [0, 100], 'mp3_kbps' => [64, 320]] as $k => [$lo, $hi]) if (isset($_POST[$k])) kp_update_setting('studio_' . $k, (string)max($lo, min($hi, (int)$_POST[$k])));
    kp_update_setting('studio_channels', ($_POST['channels'] ?? '2') === '1' ? '1' : '2');
    foreach (['max_pause' => [0, 5], 'lufs' => [-30, -8], 'music_gain' => [-30, 6]] as $k => [$lo, $hi]) if (isset($_POST[$k])) kp_update_setting('studio_' . $k, (string)max($lo, min($hi, (float)$_POST[$k])));
    kp_update_setting('studio_denoise', !empty($_POST['denoise']) ? '1' : '0');
    kp_update_setting('studio_compress', !empty($_POST['compress']) ? '1' : '0');
    $msg = 'Configuración del Estudio guardada';
  } elseif ($form === 'studio_library') {
    $pid = (int)($_POST['podcast_id'] ?? 0);
    $msg = 'Elige un podcast';
    if ($pid && kp_one("SELECT 1 FROM podcasts WHERE id = ?", [$pid])) {
      $msg = 'No se recibió ningún archivo';
      foreach (['intro', 'outro'] as $k) {
        if (!empty($_POST['remove_' . $k])) { foreach (glob(studio_library($pid) . "/$k.*") ?: [] as $f) @unlink($f); $msg = 'Música eliminada'; continue; }
        if (empty($_FILES[$k]['name']) || $_FILES[$k]['error'] !== UPLOAD_ERR_OK) continue;
        $ext = strtolower(pathinfo($_FILES[$k]['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, STUDIO_AUDIO_EXTS, true)) { $msg = "Formato no admitido: .$ext"; continue; }
        foreach (glob(studio_library($pid) . "/$k.*") ?: [] as $f) @unlink($f);
        move_uploaded_file($_FILES[$k]['tmp_name'], studio_library($pid) . "/$k.$ext");
        file_put_contents(studio_library($pid) . "/$k.name", basename($_FILES[$k]['name']));
        $msg = 'Música guardada · se usará en los proyectos nuevos de este podcast';
      }
    }
  } else {
    $msg = '';
  }
  if ($msg !== '') setcookie('kp_flash', $msg, time() + 30, '/');
  header('Location: ' . $back);
  exit;
}

$v = fn($k) => e((string)studio_opt($k));
$checks = [];
$ff = studio_run([studio_bin('ffmpeg'), '-version']);
$checks[] = ['FFmpeg', $ff[0] === 0, $ff[0] === 0 ? strtok($ff[1], "\n") : 'No encontrado. Instálalo desde el panel (Preferencias) o con tu gestor de paquetes.'];
$checks[] = ['Lanzar procesos en segundo plano (proc_open)', studio_exec_available(), studio_exec_available() ? 'Disponible' : 'Desactivado en PHP: los procesos largos se ejecutarán dentro de la petición web y pueden cortarse por tiempo.'];
$checks[] = ['cURL (motor API)', function_exists('curl_init'), function_exists('curl_init') ? 'Disponible' : 'Falta la extensión curl de PHP'];
$checks[] = ['Escritura en storage/studio', is_writable(studio_root()), studio_root()];
$checks[] = ['Límite de subida PHP', true, 'upload_max_filesize=' . ini_get('upload_max_filesize') . ' · post_max_size=' . ini_get('post_max_size') . ' (para grabaciones grandes usa la carpeta de entrada)'];
$free = @disk_free_space(studio_root());
$checks[] = ['Espacio libre', $free === false || $free > 5e9, $free === false ? 'Desconocido' : round($free / 1073741824, 1) . ' GB (cada hora de audio y pista ocupa ~300 MB de copia de trabajo)'];
$podcasts = kp_q("SELECT id, title FROM podcasts ORDER BY title");
$field = fn($label, $name, $help = '', $type = 'text') => '<div class="field"><label class="label">' . $label . '</label><input class="input" type="' . $type . '" name="' . $name . '" value="' . $v($name) . '">' . ($help ? '<div class="help">' . $help . '</div>' : '') . '</div>';
?>
<div class="card span-12" style="max-width:900px;margin:0 auto 24px">
  <div class="card-head" style="margin-bottom:12px"><div><h2 style="margin:0">Estado del entorno</h2><p class="help" style="margin:4px 0 0">Lo que necesita el Estudio para funcionar en este servidor.</p></div></div>
  <?php foreach ($checks as [$name, $ok, $info]): ?>
    <div style="display:flex;gap:10px;padding:8px 0;border-top:1px solid var(--border);font-size:13.5px">
      <span style="color:<?= $ok ? 'var(--good)' : 'var(--bad)' ?>;width:18px"><?= $ok ? '✓' : '✗' ?></span>
      <b style="width:290px"><?= e($name) ?></b><span style="color:var(--text-2);flex:1;word-break:break-word"><?= e($info) ?></span>
    </div>
  <?php endforeach; ?>
  <p class="help" style="margin-top:10px">Carpeta de entrada para archivos grandes: <code><?= e(studio_inbox()) ?></code></p>
</div>

<form method="POST" class="card span-12" style="max-width:900px;margin:0 auto 24px">
  <?= kp_csrf_field() ?><input type="hidden" name="_form" value="studio_settings">
  <div class="card-head" style="margin-bottom:12px"><div><h2 style="margin:0">Transcripción</h2><p class="help" style="margin:4px 0 0">Motor que convierte la voz en texto con timestamps por palabra.</p></div></div>
  <div class="field"><label class="label">Motor</label>
    <select class="input" name="engine"><option value="api" <?= studio_opt('engine') === 'api' ? 'selected' : '' ?>>API compatible con OpenAI (OpenAI, Groq, servidor propio…)</option><option value="faster-whisper" <?= studio_opt('engine') === 'faster-whisper' ? 'selected' : '' ?>>faster-whisper local (Python)</option></select></div>
  <div class="field-row" style="margin-top:12px"><?= $field('URL base de la API', 'api_url', 'OpenAI: https://api.openai.com/v1 · Groq: https://api.groq.com/openai/v1 · servidor local: http://host:8000/v1') ?><?= $field('Modelo', 'api_model', 'whisper-1, whisper-large-v3…') ?></div>
  <?= $field('Clave de API', 'api_key', 'Déjala vacía si tu servidor local no la pide.', 'password') ?>
  <div class="field-row" style="margin-top:12px"><?= $field('Modelo faster-whisper', 'fw_model', 'large-v3, medium, small… (necesita GPU para ser ágil)') ?><?= $field('Dispositivo', 'fw_device', 'auto, cuda o cpu') ?><?= $field('Ejecutable de Python', 'python', 'Con «pip install faster-whisper»') ?></div>
  <div class="field-row" style="margin-top:12px"><?= $field('Idioma', 'language', 'Código ISO: es, en, ca…') ?></div>
  <?= $field('Prompt de arranque', 'prompt', 'Ayuda al motor a escribir los «eeeh» y «mmm» en vez de omitirlos. Mejor escríbelo en el idioma del podcast.') ?>

  <div class="card-head" style="margin:26px 0 12px"><div><h2 style="margin:0">Muletillas y cortes</h2></div></div>
  <label class="st-row" style="display:flex;gap:8px;align-items:center"><input type="checkbox" name="auto_fillers" value="1" <?= studio_opt('auto_fillers') === '1' ? 'checked' : '' ?>> Marcar para corte los sonidos de relleno (eeeh, mmm…) al transcribir</label>
  <?= $field('Muletillas-palabra', 'filler_words', 'Separadas por comas. Se resaltan para que las revises; no se cortan solas porque a veces aportan.') ?>
  <div class="field-row" style="margin-top:12px"><?= $field('Margen de corte (ms)', 'pad_ms', 'Colchón alrededor de cada corte', 'number') ?><?= $field('Pausa máxima por defecto (s)', 'max_pause', '0 = no acortar pausas', 'number') ?></div>

  <div class="card-head" style="margin:26px 0 12px"><div><h2 style="margin:0">Mezcla y exportación (valores por defecto)</h2></div></div>
  <div class="field-row"><?= $field('Sonoridad objetivo (LUFS)', 'lufs', '-16 es el estándar de podcast', 'number') ?><?= $field('Volumen de la música (dB)', 'music_gain', '', 'number') ?><?= $field('Bitrate MP3 (kbps)', 'mp3_kbps', '', 'number') ?></div>
  <div class="field" style="margin-top:12px"><label class="label">Canales</label><select class="input" name="channels" style="max-width:220px"><option value="2" <?= studio_opt('channels') == '2' ? 'selected' : '' ?>>Estéreo</option><option value="1" <?= studio_opt('channels') == '1' ? 'selected' : '' ?>>Mono</option></select></div>
  <div style="display:flex;gap:22px;margin-top:12px"><label style="display:flex;gap:6px"><input type="checkbox" name="denoise" value="1" <?= studio_opt('denoise') == '1' ? 'checked' : '' ?>> Reducir ruido</label><label style="display:flex;gap:6px"><input type="checkbox" name="compress" value="1" <?= studio_opt('compress') == '1' ? 'checked' : '' ?>> Compresor de voz</label></div>
  <div style="margin-top:24px;display:flex;gap:12px"><button class="btn btn-primary" type="submit">Guardar configuración</button><a class="btn" href="<?= admin_url('plugins') ?>">Volver a Plugins</a></div>
</form>

<form method="POST" enctype="multipart/form-data" class="card span-12" style="max-width:900px;margin:0 auto 24px">
  <?= kp_csrf_field() ?><input type="hidden" name="_form" value="studio_library">
  <div class="card-head" style="margin-bottom:12px"><div><h2 style="margin:0">Música por defecto de cada podcast</h2><p class="help" style="margin:4px 0 0">La entradilla y la salida se copian a cada proyecto nuevo del podcast; después puedes cambiarlas en el proyecto.</p></div></div>
  <div class="field"><label class="label">Podcast</label><select class="input" name="podcast_id" style="max-width:360px"><?php foreach ($podcasts as $p): ?><option value="<?= (int)$p['id'] ?>"><?= e($p['title']) ?></option><?php endforeach; ?></select></div>
  <div class="field-row" style="margin-top:12px">
    <div class="field"><label class="label">Entradilla</label><input class="input" type="file" name="intro" accept="audio/*" style="padding:6px"><label style="font-size:12px"><input type="checkbox" name="remove_intro" value="1"> Quitar la actual</label></div>
    <div class="field"><label class="label">Salida</label><input class="input" type="file" name="outro" accept="audio/*" style="padding:6px"><label style="font-size:12px"><input type="checkbox" name="remove_outro" value="1"> Quitar la actual</label></div>
  </div>
  <div style="margin-top:18px"><button class="btn" type="submit">Guardar música</button></div>
</form>
