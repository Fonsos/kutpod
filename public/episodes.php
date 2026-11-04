<?php
require_once __DIR__ . '/../includes/helpers.php';
$eps = kp_episodes();

if (isset($_GET['json']) || (isset($_GET['format']) && $_GET['format'] === 'json') || (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'))) {
    header('Content-Type: application/json; charset=utf-8');
    $q = trim(mb_strtolower($_GET['q'] ?? '', 'UTF-8'));
    $tag = trim(mb_strtolower($_GET['tag'] ?? '', 'UTF-8'));
    $items = [];
    foreach ($eps as $ep) {
        $p = kp_podcast_or_placeholder($ep['podcast'] ?? '');
        $titleLower = mb_strtolower($ep['title'] ?? '', 'UTF-8');
        $notesLower = mb_strtolower($ep['notes_md'] ?? '', 'UTF-8');
        $tagsLower = mb_strtolower(($ep['custom_tags'] ?? '') . ',' . ($p['custom_tags'] ?? ''), 'UTF-8');
        $catLower = mb_strtolower(($p['category'] ?? '') . ' ' . ($p['subcategory'] ?? ''), 'UTF-8');

        if ($q !== '' && !str_contains($titleLower, $q) && !str_contains($notesLower, $q) && !str_contains($tagsLower, $q) && !str_contains($catLower, $q)) {
            continue;
        }
        if ($tag !== '' && !str_contains($tagsLower, $tag)) {
            continue;
        }

        $items[] = [
            'id'            => $ep['id'],
            'db_id'         => $ep['db_id'] ?? null,
            'slug'          => $ep['id'],
            'title'         => $ep['title'],
            'podcast_slug'  => $p['id'] ?? $p['slug'] ?? '',
            'podcast_title' => $p['title'] ?? '',
            'category'      => $p['category'] ?? '',
            'cover'         => $ep['cover'] ?: ($p['cover'] ?? ''),
            'duration'      => $ep['duration'] ?? '',
            'duration_secs' => (int)($ep['duration_secs'] ?? 0),
            'notes_md'      => $ep['notes_md'] ?? '',
            'custom_tags'   => $ep['custom_tags'] ?? '',
            'date_iso'      => $ep['date_iso'] ?? '',
            'audio_url'     => $ep['audio_url'] ?? ($ep['audio'] ?? '')
        ];
    }
    echo json_encode(['items' => $items, 'total' => count($items)], JSON_UNESCAPED_UNICODE);
    exit;
}

$pageEp = max(1, (int)($_GET['p'] ?? 1));
$perPage = 24; // Múltiplo de 2 o 3, mejor 24
$totalEpsCount = count($eps);
$totalPages = max(1, ceil($totalEpsCount / $perPage));
$epsPage = array_slice($eps, ($pageEp - 1) * $perPage, $perPage);

function pub_play_btn(array $ep, array $p, int $size = 14): string {
  $src = '/r/' . urlencode($p['id']) . '/' . urlencode($ep['id']) . '/audio.mp3';
  return '<button class="pub-ep-play" data-kp-play data-src="'.e($src).'" data-title="'.e($ep['title']).'" data-podcast="'.e($p['title']).'" data-color="'.e($p['color']).'" data-initial="'.e($p['initial']).'" data-cover="'.e($ep['cover'] ?: ($p['cover'] ?? '')).'">'.icon('play',$size).'</button>';
}
?>
<section class="pub-section"><div class="pub-container">
  <h1 class="pub-h1" style="margin-bottom:12px">Últimos episodios</h1>
  <p style="color:var(--pub-muted);font-size:15px;margin-bottom:40px;max-width:620px">Todos los episodios publicados recientemente en nuestra red.</p>
  
  <div class="pub-eps-grid">
    <?php foreach ($epsPage as $ep): $p = kp_podcast_or_placeholder($ep['podcast'] ?? ''); ?>
      <a class="pub-ep-card" href="/@<?= e($p['id']) ?>/<?= e($ep['id']) ?>" style="text-decoration:none;color:inherit">
        <?php if ($p['cover']): ?>
          <img class="pub-ep-cover" style="object-fit:cover" src="<?= e($p['cover']) ?>" alt="">
          <?= pub_play_btn($ep, $p) ?>
        <?php else: ?>
          <div class="pub-ep-cover" style="background:linear-gradient(135deg,<?= e($p['color']) ?>,<?= e($p['color']) ?>99)">
            <span style="color:#fff;font-weight:700;font-size:22px"><?= e($p['initial']) ?></span>
            <?= pub_play_btn($ep, $p) ?>
          </div>
        <?php endif; ?>
        <div style="flex:1;min-width:0">
          <div class="pub-tag" style="color:<?= e($p['color']) ?>;border-color:<?= e($p['color']) ?>55"><?= e($p['title']) ?></div>
          <h3 class="pub-ep-title" style="display:flex;align-items:center;flex-wrap:wrap;gap:4px">
            <?= e($ep['title']) ?>
            <?php if (($p['parental'] ?? 'clean') === 'explicit'): ?>
              <span style="display:inline-block;background:var(--border);color:var(--text-2);border-radius:4px;font-size:9px;padding:1px 3px;line-height:1;font-weight:800">E</span>
            <?php endif; ?>
            <?php if ((int)kp_get_podcast_meta($p, 'premium_enabled', 0) || (int)kp_get_episode_meta($ep, 'premium_enabled', 0)): ?>
              <span style="display:inline-block;background:#10b981;color:#fff;border-radius:4px;font-size:9px;padding:1px 3px;line-height:1;font-weight:800">$</span>
            <?php endif; ?>
          </h3>
          <?php if (!empty($ep['notes_md'])): ?>
            <p style="font-size:13.5px;color:var(--pub-text-2);margin:4px 0 0;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;line-height:1.4;opacity:0.8"><?= e(mb_strimwidth(strip_tags($ep['notes_md']), 0, 200, '...')) ?></p>
          <?php endif; ?>
          <div style="font-size:12.5px;color:var(--pub-muted);margin-top:6px"><?= e($ep['duration']) ?> · <span data-kp-date="<?= e($ep['date_iso']) ?>">Publicado <?= e(kp_relative_time($ep['date_iso'])) ?></span></div>
        </div>
      </a>
    <?php endforeach; ?>
  </div>

  <?php if ($totalPages > 1): ?>
    <div class="row" style="padding-top:40px;justify-content:center;gap:6px">
      <?php 
      $window = 2;
      for ($i = 1; $i <= $totalPages; $i++): 
        if ($i == 1 || $i == $totalPages || ($i >= $pageEp - $window && $i <= $pageEp + $window)):
      ?>
        <a href="/episodes?p=<?= $i ?>" class="pub-btn <?= $i === $pageEp ? 'pub-btn-primary' : 'pub-btn-ghost' ?>" style="padding:6px 12px;font-weight:600"><?= $i ?></a>
      <?php elseif ($i == $pageEp - $window - 1 || $i == $pageEp + $window + 1): ?>
        <span style="padding:6px 4px;color:var(--pub-muted)">...</span>
      <?php endif; ?>
      <?php endfor; ?>
    </div>
  <?php endif; ?>
</div></section>
