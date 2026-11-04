<?php
// ============================================================================
// pages/backups.php — Respaldos automáticos y manuales
// ============================================================================
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';

// POST: política / nuevo respaldo
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['_action'] ?? '';
  if ($action === 'policy') {
    foreach (['backup_frequency','backup_retention_days'] as $k) {
      if (isset($_POST[$k])) {
        kp_exec("INSERT INTO settings(k,v,updated_at) VALUES(?,?,strftime('%s','now'))
                 ON CONFLICT(k) DO UPDATE SET v=excluded.v, updated_at=excluded.updated_at",
                [$k, (string)$_POST[$k]]);
      }
    }
    header('Location: /admin/backups?ok=1'); exit;
  }
  if ($action === 'create') {
    $type = $_POST['backup_type'] ?? 'db';
    $pid = ($type === 'zip' && !empty($_POST['podcast_id'])) ? (int)$_POST['podcast_id'] : null;
    
    // Create actual backup of the DB
    $bkDir = __DIR__ . '/../media/backups';
    @mkdir($bkDir, 0775, true);
    
    $fname = 'bk_' . date('Ymd_His') . ($type === 'zip' ? '.zip' : '.db');
    $dest = $bkDir . '/' . $fname;
    $dbPath = __DIR__ . '/../storage/kutpod.db';
    
    $size = 0;
    if ($type === 'db') {
        if (file_exists($dbPath)) {
            copy($dbPath, $dest);
            $size = filesize($dest);
        }
        kp_exec("INSERT INTO backups(podcast_id, kind, bytes, path, created_at)
                 VALUES(?, 'manual', ?, ?, datetime('now'))", 
                 [$pid, $size, '/media/backups/' . $fname]);
    } else {
        $id = kp_exec("INSERT INTO backups(podcast_id, kind, bytes, path, created_at)
                 VALUES(?, 'manual', 0, ?, datetime('now'))", 
                 [$pid, '/media/backups/' . $fname]);
        $cmd = "php " . escapeshellarg(__DIR__ . '/../cli/backup_worker.php') . " " . (int)$id . " " . escapeshellarg($dest) . " > /dev/null 2>&1 &";
        exec($cmd);
    }
             
    header('Location: /admin/backups?queued=1'); exit;
  }

  if ($action === 'restore') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
      $bk = kp_q("SELECT * FROM backups WHERE id = ?", [$id])[0] ?? null;
      if ($bk && !empty($bk['path'])) {
        $fullPath = __DIR__ . '/../' . ltrim($bk['path'], '/');
        if (file_exists($fullPath) && is_file($fullPath)) {
          $dbPath = __DIR__ . '/../storage/kutpod.db';
          $walPath = __DIR__ . '/../storage/kutpod.db-wal';
          $shmPath = __DIR__ . '/../storage/kutpod.db-shm';
          if (str_ends_with(strtolower($fullPath), '.db')) {
             @unlink($walPath);
             @unlink($shmPath);
             copy($fullPath, $dbPath);
          } elseif (str_ends_with(strtolower($fullPath), '.zip')) {
             $zip = new ZipArchive();
             if ($zip->open($fullPath) === TRUE) {
                 if ($zip->locateName('kutpod.db') !== false) {
                     $tmpDb = __DIR__ . '/../storage/kutpod_tmp.db';
                     copy('zip://' . $fullPath . '#kutpod.db', $tmpDb);
                     if (file_exists($tmpDb) && filesize($tmpDb) > 0) {
                         @unlink($walPath);
                         @unlink($shmPath);
                         rename($tmpDb, $dbPath);
                     }
                 }
                 $zip->extractTo(__DIR__ . '/../');
                 $zip->close();
                 if (file_exists(__DIR__ . '/../kutpod.db')) {
                     @unlink(__DIR__ . '/../kutpod.db');
                 }
             }
          }
        }
      }
    }
    header('Location: /admin/backups?restored=1'); exit;
  }
  if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
      $bk = kp_q("SELECT * FROM backups WHERE id = ?", [$id])[0] ?? null;
      if ($bk) {
        if (!empty($bk['path'])) {
          $fullPath = __DIR__ . '/../' . ltrim($bk['path'], '/');
          if (file_exists($fullPath) && is_file($fullPath)) {
            @unlink($fullPath);
          }
        }
        kp_exec("DELETE FROM backups WHERE id = ?", [$id]);
      }
    }
    header('Location: /admin/backups?deleted=1'); exit;
  }
}

$podcasts = kp_q("SELECT id, slug, title FROM podcasts ORDER BY title");
$rows = [];
try {
  $rows = kp_q("SELECT b.id, b.podcast_id, p.slug, p.title AS podcast_title,
                       b.kind, CASE WHEN b.bytes = 0 THEN 'pending' WHEN b.bytes < 0 THEN 'failed' ELSE 'completed' END AS status, b.bytes AS size_bytes, 0 AS items_count, b.created_at, b.path
                FROM backups b LEFT JOIN podcasts p ON p.id = b.podcast_id
                ORDER BY b.created_at DESC LIMIT 100") ?: [];
                
  // Auto-reparar estado 'pending' si el archivo ya terminó de crearse (útil tras restaurar DB)
  foreach ($rows as &$r) {
      if ($r['size_bytes'] == 0 && !empty($r['path'])) {
          $p = __DIR__ . '/../' . ltrim($r['path'], '/');
          if (file_exists($p)) {
              clearstatcache(true, $p);
              if (time() - filemtime($p) > 30) { // Si no se ha modificado en 30s
                  $sz = filesize($p);
                  if ($sz > 0) {
                      kp_exec("UPDATE backups SET bytes = ? WHERE id = ?", [$sz, $r['id']]);
                      $r['size_bytes'] = $sz;
                      $r['status'] = 'completed';
                  }
              }
          }
      }
  }
} catch (Throwable $e) {}

$total = count($rows);
$total_bytes = array_sum(array_column($rows, 'size_bytes'));
$last = $rows[0] ?? null;

$freq      = kp_setting('backup_frequency', 'daily');
$retention = kp_setting('backup_retention_days', '90');
$destination = kp_setting('backup_destination', 'local');

if (!function_exists('human_bytes')) {
  function human_bytes($n) {
    if ($n === null) return '—';
    $u = ['B','KB','MB','GB','TB']; $i = 0; $n = (float)$n;
    while ($n >= 1024 && $i < count($u)-1) { $n /= 1024; $i++; }
    return number_format($n, $n < 10 && $i > 0 ? 2 : 0) . ' ' . $u[$i];
  }
}
?>
<div class="page-head">
  <div>
    <h1 class="page-title">Respaldos</h1>
    <p class="page-sub">Snapshots completos por podcast · audio + feed + portadas + metadatos</p>
  </div>
  <div class="row" style="gap:8px">
    <form method="POST" style="display:inline" id="backupForm">
      <?= kp_csrf_field() ?>
      <input type="hidden" name="_action" value="create"/>
      <select name="podcast_id" id="backup_podcast_id" class="select" style="width:200px">
        <option value="">Toda la instancia</option>
        <?php foreach ($podcasts as $p): ?>
          <option value="<?= (int)$p['id'] ?>"><?= e($p['title']) ?></option>
        <?php endforeach; ?>
      </select>
      <select name="backup_type" id="backup_backup_type" class="select" style="width:160px">
        <option value="db">Solo Datos (.db)</option>
        <option value="zip">Completo (.zip)</option>
      </select>
      <button class="btn btn-primary" type="submit"><?= icon('download',13) ?> Nuevo respaldo</button>
    </form>

  </div>
</div>

<div class="stat-grid">
  <?= stat_card('Respaldos totales', (string)$total, 'layers', ['sub'=>'En historial']) ?>
  <?= stat_card('Espacio usado', human_bytes($total_bytes), 'download') ?>
  <?= stat_card('Último respaldo', $last ? kp_relative_time(is_numeric($last['created_at']) ? (int)$last['created_at'] : strtotime($last['created_at'])) : 'Nunca', 'check',
       ['sub'=>$last ? ($last['podcast_title'] ?? 'Toda la instancia') : '—']) ?>
  <?= stat_card('Próximo', $freq === 'daily' ? 'Mañana 04:00' : ($freq === 'weekly' ? 'Domingo 04:00' : 'Manual'), 'bell',
       ['sub'=>'Política activa']) ?>
</div>

<!-- Política -->
<form method="POST" class="card card-lg" style="padding:28px;margin-top:24px">
  <?= kp_csrf_field() ?>
  <input type="hidden" name="_action" value="policy"/>
  <div class="card-title" style="margin-bottom:18px">Política de respaldos</div>
  <div class="grid-12" style="gap:16px">
    <div class="card span-6" style="padding:16px;background:var(--surface-2)">
      <label class="label">Frecuencia</label>
      <select name="backup_frequency" class="select">
        <option value="daily" <?= $freq==='daily'?'selected':''?>>Diaria · 04:00 UTC</option>
        <option value="weekly" <?= $freq==='weekly'?'selected':''?>>Semanal · domingos</option>
        <option value="manual" <?= $freq==='manual'?'selected':''?>>Solo manual</option>
      </select>
    </div>
    <div class="card span-6" style="padding:16px;background:var(--surface-2)">
      <label class="label">Retención (días)</label>
      <select name="backup_retention_days" class="select">
        <?php foreach (['30','90','180','365','0'] as $d): ?>
          <option value="<?= $d ?>" <?= $retention==$d?'selected':''?>>
            <?= $d === '0' ? 'Indefinida' : ($d.' días') ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>
  <div style="margin-top:16px;text-align:right">
    <button class="btn btn-primary" type="submit"><?= icon('check',13) ?> Guardar política</button>
  </div>
</form>

<!-- Historial -->
<div class="card card-lg" style="padding:0;margin-top:24px">
  <div class="card-head" style="padding:20px 24px">
    <div class="card-title">Historial</div>
    <input class="input" placeholder="Filtrar por podcast…" style="width:240px" id="bkFilter"/>
  </div>
  <?php if (!$rows): ?>
    <div style="padding:48px;text-align:center;color:var(--text-3)">
      Sin respaldos todavía. Lanza uno manual o espera al primer ciclo programado.
    </div>
  <?php else: ?>
    <div class="list">
      <div style="display:grid;grid-template-columns:1fr 150px 100px 110px 95px 125px;gap:14px;padding:12px 32px;font-size:11px;color:var(--text-3);text-transform:uppercase;letter-spacing:.06em;font-weight:600;border-bottom:1px solid var(--border)">
        <div>Podcast</div><div>Fecha</div><div class="tabular">Tamaño</div><div>Contenido</div><div>Tipo</div><div></div>
      </div>
      <?php foreach ($rows as $b):
        $label = $b['podcast_title'] ?? 'Toda la instancia';
        $initial = strtoupper(substr($label, 0, 1));
        $kind_label = $b['kind'] === 'scheduled' ? 'Programado' : ($b['kind'] === 'pre_import' ? 'Pre-import' : 'Manual');
        $kind_color = $b['kind'] === 'scheduled' ? '#10b981' : ($b['kind'] === 'pre_import' ? '#f59e0b' : 'var(--accent)');
        $status_color = $b['status'] === 'completed' ? '#10b981' : ($b['status'] === 'failed' ? '#ef4444' : '#f59e0b');
      ?>
        <div class="bk-row" data-label="<?= e(strtolower($label)) ?>" style="display:grid;grid-template-columns:1fr 150px 100px 110px 95px 125px;gap:14px;padding:14px 32px;align-items:center;border-bottom:1px solid var(--border);font-size:13.5px">
          <div class="row" style="gap:12px;align-items:center;min-width:0">
            <div style="width:36px;height:36px;border-radius:8px;background:var(--accent-soft);color:var(--accent);display:grid;place-items:center;font-weight:700"><?= e($initial) ?></div>
            <div style="min-width:0">
              <div style="font-weight:500;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($label) ?></div>
              <div style="font-size:12px;color:var(--text-3);font-family:ui-monospace,monospace">bk-<?= str_pad((string)$b['id'], 6, '0', STR_PAD_LEFT) ?> · <span style="color:<?= $status_color ?>"><?= e($b['status'] ?? 'queued') ?></span></div>
            </div>
          </div>
          <div style="color:var(--text-2);font-size:12.5px"><?= e(kp_relative_time(is_numeric($b['created_at']) ? (int)$b['created_at'] : strtotime($b['created_at']))) ?></div>
          <div class="tabular" style="color:var(--text-2)"><?= human_bytes($b['size_bytes']) ?></div>
          <div style="color:var(--text-3);font-size:12.5px"><?= e($b['items_count'] ? $b['items_count'].' items' : '—') ?></div>
          <div><span class="tag" style="background:<?= $kind_color ?>22;color:<?= $kind_color ?>;font-size:11px"><?= e($kind_label) ?></span></div>
          <div class="row" style="gap:6px;justify-content:flex-end">
            <?php if (!empty($b['path']) && $b['status'] === 'completed'): ?>
              <a class="kebab" href="<?= e($b['path']) ?>" title="Descargar" download style="width:32px;height:32px;border-radius:8px;display:inline-grid;place-items:center"><?= icon('download',16) ?></a>
              <form method="POST" style="margin:0;display:inline" onsubmit="return confirm('¿Restaurar este respaldo? Reemplazará la base de datos actual.')">
                <?= kp_csrf_field() ?>
                <input type="hidden" name="_action" value="restore"/>
                <input type="hidden" name="id" value="<?= $b['id'] ?>"/>
                <button class="kebab" type="submit" title="Restaurar" style="border:none;background:none;cursor:pointer;width:32px;height:32px;border-radius:8px;display:grid;place-items:center"><?= icon('refreshCw',15) ?></button>
              </form>
            <?php endif; ?>
            <form method="POST" style="margin:0;display:inline" onsubmit="return confirm('¿Eliminar este respaldo permanentemente?')">
              <?= kp_csrf_field() ?>
              <input type="hidden" name="_action" value="delete"/>
              <input type="hidden" name="id" value="<?= $b['id'] ?>"/>
              <button class="kebab" type="submit" title="Eliminar" style="border:none;background:none;cursor:pointer;width:32px;height:32px;border-radius:8px;display:grid;place-items:center"><?= icon('trash',15) ?></button>
            </form>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<script>
document.getElementById('bkFilter')?.addEventListener('input', e => {
  const q = e.target.value.toLowerCase();
  document.querySelectorAll('.bk-row').forEach(r => {
    r.style.display = !q || r.dataset.label.includes(q) ? '' : 'none';
  });
});

(() => {
  const typeSelect = document.getElementById('backup_backup_type');
  const podcastSelect = document.getElementById('backup_podcast_id');
  if (typeSelect && podcastSelect) {
    const updateDropdown = () => {
      if (typeSelect.value === 'db') {
        podcastSelect.value = '';
        podcastSelect.disabled = true;
        podcastSelect.style.opacity = '0.5';
      } else {
        podcastSelect.disabled = false;
        podcastSelect.style.opacity = '1';
      }
    };
    typeSelect.addEventListener('change', updateDropdown);
    updateDropdown(); // Run on load
  }
})();
</script>
