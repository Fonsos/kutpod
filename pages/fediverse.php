<?php
// ============================================================================
// pages/fediverse.php — Federación ActivityPub
// ============================================================================
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../data.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (isset($_POST['action']) && $_POST['action'] === 'block_domain') {
    $d = trim($_POST['domain'] ?? '');
    if ($d) {
      kp_q("INSERT OR REPLACE INTO fediverse_blocks (domain, reason, kind) VALUES (?, '', 'manual')", [$d]);
    }
    header('Location: /admin/fediverse');
    exit;
  }
  if (isset($_POST['action']) && $_POST['action'] === 'unblock_domain') {
    $d = $_POST['domain'] ?? '';
    if ($d) {
      kp_q("DELETE FROM fediverse_blocks WHERE domain = ?", [$d]);
    }
    header('Location: /admin/fediverse');
    exit;
  }
  if (isset($_POST['action']) && $_POST['action'] === 'refederate') {
    $epId = (int)($_POST['episode_id'] ?? 0);
    if ($epId > 0 && kp_plugin_is_active('fediverse')) {
      require_once __DIR__ . '/../activitypub.php';
      try {
        kp_ap_publish_episode($epId, true);
        kp_alert('system', 'Re-envío solicitado', 'El episodio se ha encolado para re-envío al Fediverso.', '');
        header('Location: /admin/fediverse?refederated=1');
        exit;
      } catch (Throwable $e) {
        kp_alert('system', 'Error al re-enviar', $e->getMessage(), '');
      }
    }
  }
  if (isset($_POST['action']) && $_POST['action'] === 'defederate') {
    $epId = (int)($_POST['episode_id'] ?? 0);
    if ($epId > 0 && kp_plugin_is_active('fediverse')) {
      require_once __DIR__ . '/../activitypub.php';
      try {
        kp_ap_delete_episode($epId);
        kp_alert('system', 'Eliminación solicitada', 'El post de este episodio en el Fediverso se ha encolado para eliminación.', '');
        header('Location: /admin/fediverse?defederated=1');
        exit;
      } catch (Throwable $e) {
        kp_alert('system', 'Error al eliminar del Fediverso', $e->getMessage(), '');
      }
    }
  }
}

$podcasts = kp_podcasts();
$domain   = kp_instance()['domain'];

// Lectura real · si las tablas no existen / están vacías → empty state.
$followers = []; $blocked_domains = []; $blocked_users = []; $recent_episodes = [];
$total_followers = 0; $total_notes = 0; $total_boosts = 0;
try {
  $followers       = kp_q("SELECT f.handle, f.handle as name, p.title as podcast_title, '#6b7280' as color, f.followed_at as since, 'Siguiendo a ' || p.title as action FROM ap_followers f JOIN podcasts p ON p.id = f.podcast_id ORDER BY f.followed_at DESC LIMIT 12") ?: [];
  $blocked_domains = kp_q("SELECT domain, kind, created_at as since, reason FROM fediverse_blocks ORDER BY created_at DESC") ?: [];
  $total_followers = (int)(kp_one("SELECT count(*) c FROM ap_followers")['c'] ?? 0);
  $total_notes     = (int)(kp_one("SELECT count(*) c FROM ap_outbox WHERE type = 'Create'")['c'] ?? 0);
  $total_boosts    = 0; // Not implemented yet
  $recent_episodes = kp_q("SELECT e.*, e.season as s, e.number as n, p.title as podcast_title, p.color as podcast_color, p.cover as podcast_cover, SUBSTR(p.title, 1, 1) as podcast_initial, p.federate, COALESCE(e.published_at, e.publish_at, e.created_at) as display_date FROM episodes e JOIN podcasts p ON p.id = e.podcast_id WHERE p.federate = 1 AND e.status = 'published' ORDER BY COALESCE(e.published_at, e.publish_at, e.created_at) DESC LIMIT 10") ?: [];
} catch (Throwable $e) {}
?>
<?php if (isset($_GET['refederated']) && $_GET['refederated'] === '1'): ?>
  <div class="alert success" style="margin-bottom:var(--gap);display:flex;align-items:center;gap:8px;padding:12px;background:#10b98122;color:#10b981;border-radius:8px;border:1px solid #10b98144;font-size:14px">
    <?= icon('check', 16) ?> El episodio se ha encolado para re-envío al Fediverso.
  </div>
<?php endif; ?>
<?php if (isset($_GET['defederated']) && $_GET['defederated'] === '1'): ?>
  <div class="alert success" style="margin-bottom:var(--gap);display:flex;align-items:center;gap:8px;padding:12px;background:#10b98122;color:#10b981;border-radius:8px;border:1px solid #10b98144;font-size:14px">
    <?= icon('check', 16) ?> El post de este episodio en el Fediverso se ha encolado para eliminación.
  </div>
<?php endif; ?>

<div class="page-head">
  <div>
    <h1 class="page-title">Fediverso</h1>
    <p class="page-sub">Federación ActivityPub · cada podcast es una cuenta citable desde Mastodon · cada episodio una nota</p>
  </div>
  <div class="row" style="gap:8px">
    <a class="btn btn-primary" href="<?= admin_url('preferences') ?>"><?= icon('settings',13) ?> Configurar instancia</a>
  </div>
</div>

<div class="stat-grid">
  <?= stat_card('Cuentas federadas', (string)count($podcasts), 'users', ['sub'=>'Una por podcast']) ?>
  <?= stat_card('Seguidores totales', number_format($total_followers), 'users', ['sub'=>'ActivityPub']) ?>
  <?= stat_card('Notas publicadas', number_format($total_notes), 'rss', ['sub'=>'Episodios federados']) ?>
  <?= stat_card('Dominios bloqueados', (string)count($blocked_domains), 'x', ['sub'=>'Lista local']) ?>
</div>

<div class="card card-lg" style="padding:28px;margin-top:24px">
  <div class="card-head">
    <div>
      <div class="card-title">Cuentas federadas</div>
      <div class="page-sub" style="margin-top:4px;font-size:12.5px">Una cuenta ActivityPub por podcast · citables desde cualquier instancia Mastodon</div>
    </div>
    <a class="btn" href="https://docs.joinmastodon.org/spec/activitypub/" target="_blank" rel="noopener"><?= icon('file',13) ?> Spec ActivityPub</a>
  </div>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:14px;margin-top:18px">
    <?php if (!$podcasts): ?>
      <div class="page-sub" style="padding:40px;text-align:center;grid-column:1/-1">Aún no hay podcasts. <a class="pub-link" href="/admin/new-podcast">Crea el primero →</a></div>
    <?php endif; foreach ($podcasts as $p): $eps = (int)($p['episodes'] ?? 0);
      // Conteos reales por podcast (si las tablas existen)
      $f_count = 0; $n_count = $eps; $b_count = 0;
      try {
        $f_count = (int)(kp_one("SELECT count(*) c FROM ap_followers WHERE podcast_id = ?", [$p['db_id']])['c'] ?? 0);
        $b_count = 0; // Boosts not implemented yet
      } catch (Throwable $e) {}
    ?>
      <div style="padding:16px;border:1px solid var(--border);border-radius:12px">
        <div class="row" style="gap:12px;align-items:center">
          <div style="width:44px;height:44px;border-radius:10px;background:linear-gradient(135deg,<?= e($p['color']) ?>,<?= e($p['color']) ?>99);color:white;font-weight:700;font-size:16px;display:grid;place-items:center;flex-shrink:0"><?= e($p['initial']) ?></div>
          <div style="flex:1;min-width:0">
            <div style="font-weight:500;font-size:14px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($p['title']) ?></div>
            <div style="font-size:12px;color:var(--text-3);font-family:ui-monospace,monospace;margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">@<?= e($p['id']) ?>@<?= e($domain) ?></div>
          </div>
          <span class="tag" style="background:#10b98122;color:#10b981;font-size:11px;flex-shrink:0">activa</span>
        </div>
        <div class="row" style="gap:22px;margin-top:14px;padding-top:14px;border-top:1px solid var(--border);font-size:12.5px">
          <div>
            <div style="color:var(--text-3);font-size:10.5px;text-transform:uppercase;letter-spacing:0.06em;font-weight:600">Seguidores</div>
            <div class="tabular" style="font-weight:600;margin-top:3px;font-size:14px"><?= number_format($f_count) ?></div>
          </div>
          <div>
            <div style="color:var(--text-3);font-size:10.5px;text-transform:uppercase;letter-spacing:0.06em;font-weight:600">Notas</div>
            <div class="tabular" style="font-weight:600;margin-top:3px;font-size:14px"><?= $n_count ?></div>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
  <div style="margin-top:18px;padding:14px;background:var(--surface-2);border-radius:10px;font-size:12.5px;color:var(--text-2)">
    <?= icon('globe',14) ?> URL pública: <code><?= e($domain) ?>/@&lt;podcast&gt;</code> · perfil WebFinger válido en <code>/.well-known/webfinger</code>
  </div>
</div>

<div class="card card-lg" style="padding:0;margin-top:24px">
  <div class="card-head" style="padding:20px 24px">
    <div class="card-title">Últimos episodios federados</div>
  </div>
  <div class="list">
    <div style="overflow-x:auto;">
      <div style="min-width: 600px;">
        <?php if (!$recent_episodes): ?>
          <div class="page-sub" style="padding:40px;text-align:center">No hay episodios federados.</div>
        <?php endif; foreach ($recent_episodes as $ep): ?>
          <div style="display:grid;grid-template-columns:44px 1.6fr 1fr 190px;gap:18px;padding:14px 24px;align-items:center;border-bottom:1px solid var(--border);font-size:13.5px">
            <?php
              $cover = $ep['cover'] ?: ($ep['podcast_cover'] ?? '');
              $pColor = $ep['podcast_color'] ?: 'var(--accent)';
              $pInitial = $ep['podcast_initial'] ?: strtoupper(substr($ep['podcast_title'] ?? '?', 0, 1));
              if ($cover) {
                echo '<img src="'.e($cover).'" style="width:36px;height:36px;border-radius:8px;object-fit:cover" alt="">';
              } else {
                echo '<div style="width:36px;height:36px;border-radius:8px;background:linear-gradient(135deg,'.e($pColor).','.e($pColor).'99);color:white;font-weight:700;display:grid;place-items:center">'.e($pInitial).'</div>';
              }
            ?>
            <div style="min-width:0">
              <div style="font-weight:500;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($ep['title']) ?></div>
              <?php
                $epDate = $ep['display_date'] ?? ($ep['published_at'] ?: ($ep['publish_at'] ?: ($ep['created_at'] ?? '')));
                $epTime = !empty($epDate) ? kp_relative_time(is_numeric($epDate) ? (int)$epDate : strtotime($epDate)) : '—';
              ?>
              <div style="font-size:12px;color:var(--text-3);margin-top:2px">S<?= (int)($ep['season'] ?? $ep['s'] ?? 0) ?> · E<?= (int)($ep['number'] ?? $ep['n'] ?? 0) ?> · <?= e($epTime) ?></div>
            </div>
            <div style="font-size:13px;color:var(--text-2);overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($ep['podcast_title']) ?></div>
            <div style="text-align:right">
              <form method="POST" style="margin:0;padding:0;display:inline-block" onsubmit="return confirm('¿Estás seguro de que quieres volver a enviar este episodio al Fediverso?')">
                <?= kp_csrf_field() ?>
                <input type="hidden" name="action" value="refederate">
                <input type="hidden" name="episode_id" value="<?= (int)$ep['id'] ?>">
                <button type="submit" class="btn" style="padding:4px 8px;font-size:12px;display:inline-flex;align-items:center;gap:4px;color:var(--accent);border-color:var(--accent)"><?= icon('globe',12) ?> Re-enviar</button>
              </form>
              <form method="POST" style="margin:0;padding:0;display:inline-block;margin-left:6px" onsubmit="return confirm('¿Estás seguro de que deseas eliminar este post del Fediverso? Esto enviará una actividad Delete a tus seguidores.')">
                <?= kp_csrf_field() ?>
                <input type="hidden" name="action" value="defederate">
                <input type="hidden" name="episode_id" value="<?= (int)$ep['id'] ?>">
                <button type="submit" class="btn" style="padding:4px 8px;font-size:12px;display:inline-flex;align-items:center;gap:4px;color:#ef4444;border-color:#ef4444"><?= icon('trash',12) ?> Eliminar</button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<div class="card card-lg" style="padding:0;margin-top:24px">
  <div class="card-head" style="padding:20px 24px">
    <div class="card-title">Actividad reciente</div>
  </div>
  <div class="list">
    <div style="overflow-x:auto;">
      <div style="min-width: 600px;">
        <?php if (!$followers): ?>
      <div class="page-sub" style="padding:40px;text-align:center">Sin actividad federada todavía. Cuando alguien siga uno de tus podcasts desde Mastodon, aparecerá aquí.</div>
    <?php endif; foreach ($followers as $f): ?>
      <div style="display:grid;grid-template-columns:44px 1fr 1fr 240px;gap:18px;padding:14px 24px;align-items:center;border-bottom:1px solid var(--border);font-size:13.5px">
        <div style="width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,<?= e($f['color']) ?>,<?= e($f['color']) ?>99);color:white;font-weight:700;display:grid;place-items:center"><?= e(strtoupper(substr($f['name']?:'?',0,1))) ?></div>
        <div>
          <div style="font-weight:500"><?= e($f['name']) ?></div>
          <div style="font-size:12px;color:var(--text-3);font-family:ui-monospace,monospace;margin-top:2px"><?= e($f['handle']) ?></div>
        </div>
        <div style="font-size:12.5px;color:var(--text-2)"><?= e($f['action']) ?></div>
        <div style="font-size:12px;color:var(--text-3)"><?= e($f['since']) ?></div>
      </div>
      <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<div class="grid-12" style="gap:24px;margin-top:24px">
  <div class="card card-lg span-12" style="padding:28px">
    <div class="card-head">
      <div class="card-title">Dominios bloqueados</div>
      <form method="POST" id="form-block-domain" style="display:none;">
        <?= kp_csrf_field() ?>
        <input type="hidden" name="action" value="block_domain">
        <input type="hidden" name="domain" id="block-domain-input">
      </form>
      <button class="btn" type="button" onclick="let d = prompt('Introduce el dominio a bloquear (ej. mastodon.social):'); if(d){ document.getElementById('block-domain-input').value=d; document.getElementById('form-block-domain').submit(); }"><?= icon('plus',13) ?> Añadir</button>
    </div>
    <div class="list" style="margin-top:14px">
      <div style="overflow-x:auto;">
        <div style="min-width: 400px;">
          <?php if (!$blocked_domains): ?><div class="page-sub" style="padding:20px 4px">Sin dominios bloqueados.</div><?php endif; ?>
      <?php foreach ($blocked_domains as $d): $auto = ($d['kind'] ?? '')==='Auto'; ?>
        <div style="display:grid;grid-template-columns:1fr 90px 110px 1fr 40px;gap:14px;padding:12px 4px;align-items:center;border-bottom:1px solid var(--border);font-size:13px">
          <div style="font-family:ui-monospace,monospace;font-weight:500"><?= e($d['domain']) ?></div>
          <div><span class="tag" style="background:<?= $auto?'#f59e0b22':'var(--surface-2)' ?>;color:<?= $auto?'#f59e0b':'var(--text-3)' ?>;font-size:11px"><?= e($d['kind']) ?></span></div>
          <div style="color:var(--text-3);font-size:12px"><?= e($d['since']) ?></div>
          <div style="color:var(--text-3);font-size:12.5px"><?= e($d['reason']) ?></div>
          <form method="POST" style="margin:0;padding:0">
            <?= kp_csrf_field() ?>
            <input type="hidden" name="action" value="unblock_domain">
            <input type="hidden" name="domain" value="<?= e($d['domain']) ?>">
            <button class="kebab" type="submit" title="Desbloquear" style="color:var(--text-3)" onclick="return confirm('¿Desbloquear <?= e($d['domain']) ?>?')"><?= icon('trash',14) ?></button>
          </form>
        </div>
        <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
</div>


