<?php
// pages/new-podcast.php · edit-podcast.php — formulario completo (8 secciones)
require_once __DIR__ . '/../includes/helpers.php';

$mode = $page === 'edit-podcast' ? 'edit' : 'create';
$id = $_GET['id'] ?? '';
$p = null;
$assigned_users = [];
if ($mode === 'edit' && $id !== '') {
    $p = kp_one("SELECT * FROM podcasts WHERE slug = ?", [$id]);
    if ($p) {
        $p['db_id'] = $p['id'];
        $p['id'] = $p['slug'];
        $p['initial'] = strtoupper(substr($p['title'] ?: '?', 0, 2));
        $assigned_rows = kp_q("SELECT user_id FROM podcast_users WHERE podcast_id = ?", [$p['db_id']]) ?: [];
        $assigned_users = array_column($assigned_rows, 'user_id');
    }
}

$team_users = kp_q("SELECT id, name, email, avatar, role FROM users WHERE role IN ('editor','author') ORDER BY name ASC") ?: [];

$apple_categories = [
    'Arts' => ['Books', 'Design', 'Fashion & Beauty', 'Food', 'Performing Arts', 'Visual Arts'],
    'Business' => ['Careers', 'Entrepreneurship', 'Investing', 'Management', 'Marketing', 'Non-Profit'],
    'Comedy' => ['Comedy Interviews', 'Improv', 'Stand-Up'],
    'Education' => ['Courses', 'How To', 'Language Learning', 'Self-Improvement'],
    'Fiction' => ['Comedy Fiction', 'Drama', 'Science Fiction'],
    'Government' => [],
    'Health & Fitness' => ['Alternative Health', 'Fitness', 'Medicine', 'Mental Health', 'Nutrition', 'Sexuality'],
    'History' => [],
    'Kids & Family' => ['Education for Kids', 'Parenting', 'Pets & Animals', 'Stories for Kids'],
    'Leisure' => ['Animation & Manga', 'Automotive', 'Aviation', 'Crafts', 'Games', 'Hobbies', 'Home & Garden', 'Video Games'],
    'Music' => ['Music Commentary', 'Music History', 'Music Interviews'],
    'News' => ['Business News', 'Daily News', 'Entertainment News', 'News Commentary', 'Politics', 'Sports News', 'Tech News'],
    'Religion & Spirituality' => ['Buddhism', 'Christianity', 'Hinduism', 'Islam', 'Judaism', 'Religion', 'Spirituality'],
    'Science' => ['Astronomy', 'Chemistry', 'Earth Sciences', 'Life Sciences', 'Mathematics', 'Natural Sciences', 'Nature', 'Physics', 'Social Sciences'],
    'Society & Culture' => ['Documentary', 'Personal Journals', 'Philosophy', 'Places & Travel', 'Relationships'],
    'Sports' => ['Baseball', 'Basketball', 'Cricket', 'Fantasy Sports', 'Football', 'Golf', 'Hockey', 'Rugby', 'Soccer', 'Swimming', 'Tennis', 'Volleyball', 'Wilderness', 'Wrestling'],
    'Technology' => [],
    'True Crime' => [],
    'TV & Film' => ['After Shows', 'Film History', 'Film Interviews', 'Film Reviews', 'TV Reviews'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $pdo = kp_db();
  
  // Detect if post_max_size was exceeded (PHP empties $_POST and $_FILES in this case)
  if (empty($_POST) && $_SERVER['CONTENT_LENGTH'] > 0) {
      setcookie('kp_flash', 'Error: El archivo subido es demasiado grande. Por favor, sube imágenes más ligeras.', time() + 30, '/');
      header('Location: ' . $_SERVER['REQUEST_URI']); exit;
  }

  if (isset($_POST['action']) && $_POST['action'] === 'delete' && $p) {
      $pdo->prepare("DELETE FROM podcasts WHERE slug = ?")->execute([$p['id']]);
      setcookie('kp_flash', 'Podcast eliminado', time() + 30, '/');
      header('Location: /admin/podcasts'); exit;
  }

  // Limpiar notas fijas de todos los episodios de este podcast
  if (isset($_POST['action']) && $_POST['action'] === 'strip_fixed_notes' && $p) {
      $fixed = str_replace("\r\n", "\n", trim($p['fixed_notes'] ?? ''));
      if ($fixed) {
          $lines = array_filter(array_map('trim', explode("\n", $fixed)));
          $first_line = reset($lines);
          
          if ($first_line && mb_strlen($first_line) >= 4) {
              // Normalize arrow representations to ensure matching
              $first_line_norm = str_replace(['->', '=>'], ['→', '⇒'], $first_line);
              
              $eps = kp_q("SELECT e.id, e.notes_md FROM episodes e WHERE e.podcast_id = ?", [$p['db_id']]);
              $count = 0;
              foreach ($eps as $ep) {
                  $notes = $ep['notes_md'] ?? '';
                  $notes = str_replace("\r\n", "\n", $notes);
                  $notes_norm = str_replace(['->', '=>'], ['→', '⇒'], $notes);
                  
                  $pos = stripos($notes_norm, $first_line_norm);
                  if ($pos !== false) {
                      $before = rtrim(substr($notes, 0, $pos));
                      
                      // Look for the last '---' separator in $before
                      $last_sep = strrpos($before, '---');
                      if ($last_sep !== false) {
                          $distance = strlen($before) - $last_sep;
                          if ($distance < 150) {
                              $before = rtrim(substr($before, 0, $last_sep));
                          }
                      } elseif (str_ends_with($before, '---')) {
                          $before = rtrim(substr($before, 0, -3));
                      }
                      
                      $cleaned = $before;
                      
                      $pdo->prepare("UPDATE episodes SET notes_md = ? WHERE id = ?")->execute([trim($cleaned), $ep['id']]);
                      $count++;
                  }
              }
              setcookie('kp_flash', "Notas fijas/footer eliminados de $count episodios", time() + 30, '/');
          } else {
              setcookie('kp_flash', 'La primera línea de las notas fijas debe tener al menos 4 caracteres para realizar la limpieza.', time() + 30, '/');
          }
      } else {
          setcookie('kp_flash', 'No hay notas fijas configuradas para limpiar', time() + 30, '/');
      }
      header('Location: ' . $_SERVER['REQUEST_URI']); exit;
  }

  $title = $_POST['title'] ?? 'Sin título';
  $slug = !empty($_POST['slug']) ? strtolower($_POST['slug']) : kp_slugify($title);
  $description = $_POST['description'] ?? '';
  $language = $_POST['language'] ?? 'es';
  $category = $_POST['category'] ?? '';
  $subcategory = $_POST['subcategory'] ?? '';
  $author = $_POST['author'] ?? '';
  $publisher = $_POST['publisher'] ?? '';
  $owner_email = $_POST['owner_email'] ?? '';
  $copyright = $_POST['copyright'] ?? '';
  $type = $_POST['type'] ?? 'episodic';
  $parental = $_POST['parental'] ?? 'clean';
  $fediverse_handle = !empty($_POST['fediverse_handle']) ? strtolower($_POST['fediverse_handle']) : $slug;
  $feed_redirect_slug = !empty($_POST['feed_redirect_slug']) ? strtolower(preg_replace('/[^a-zA-Z0-9\-]/', '', $_POST['feed_redirect_slug'])) : null;


  $cover_url = $p['cover'] ?? '';
  if (!empty($_FILES['cover']) && $_FILES['cover']['error'] !== UPLOAD_ERR_NO_FILE) {
      if ($_FILES['cover']['error'] !== UPLOAD_ERR_OK) {
          setcookie('kp_flash', 'Error al subir la portada (Código: ' . $_FILES['cover']['error'] . ')', time() + 30, '/');
          header('Location: ' . $_SERVER['REQUEST_URI']); exit;
      }
      $ext = strtolower(pathinfo($_FILES['cover']['name'], PATHINFO_EXTENSION) ?: 'jpg');
      if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) $ext = 'jpg';
      $dest = __DIR__ . '/../media/covers/podcast_' . $slug . '.' . $ext;
      @mkdir(dirname($dest), 0775, true);
      if (move_uploaded_file($_FILES['cover']['tmp_name'], $dest)) {
          $cover_url = '/media/covers/podcast_' . $slug . '.' . $ext;
      } else {
          setcookie('kp_flash', 'Error de permisos: No se pudo guardar la imagen de portada en el servidor.', time() + 30, '/');
          header('Location: ' . $_SERVER['REQUEST_URI']); exit;
      }
  }

  $banner_url = $p['banner'] ?? '';
  if (!empty($_FILES['banner']) && $_FILES['banner']['error'] !== UPLOAD_ERR_NO_FILE) {
      if ($_FILES['banner']['error'] !== UPLOAD_ERR_OK) {
          setcookie('kp_flash', 'Error al subir el banner (Código: ' . $_FILES['banner']['error'] . ')', time() + 30, '/');
          header('Location: ' . $_SERVER['REQUEST_URI']); exit;
      }
      $ext = strtolower(pathinfo($_FILES['banner']['name'], PATHINFO_EXTENSION) ?: 'jpg');
      if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) $ext = 'jpg';
      $dest = __DIR__ . '/../media/covers/podcast_banner_' . $slug . '.' . $ext;
      @mkdir(dirname($dest), 0775, true);
      if (move_uploaded_file($_FILES['banner']['tmp_name'], $dest)) {
          $banner_url = '/media/covers/podcast_banner_' . $slug . '.' . $ext;
      } else {
          setcookie('kp_flash', 'Error de permisos: No se pudo guardar el banner en el servidor.', time() + 30, '/');
          header('Location: ' . $_SERVER['REQUEST_URI']); exit;
      }
  }

  $op3 = isset($_POST['op3']) ? 1 : 0;
  $locked = isset($_POST['locked']) ? 1 : 0;
  $hidden = isset($_POST['hidden']) ? 1 : 0;
  $complete = isset($_POST['complete']) ? 1 : 0;
  $remove_email = isset($_POST['remove_email']) ? 1 : 0;
  $federate = isset($_POST['federate']) ? 1 : 0;
  $custom_tags = $_POST['custom_tags'] ?? '';
  $ownership_txt = $_POST['ownership_txt'] ?? '';
  $fixed_notes = $_POST['fixed_notes'] ?? '';
  $status = $_POST['status'] ?? 'published';

  $guid = trim($_POST['guid'] ?? '');
  if ($guid === '') {
      $feed_url = kp_canonical_feed_url($slug);
      $guid = kp_deterministic_podcast_guid($feed_url);
  }

  $color_post = trim($_POST['color'] ?? '');
  if ($color_post === '') $color_post = $p['color'] ?? kp_random_color();
  $color = $color_post;

  $location_name = trim($_POST['location_name'] ?? '');
  $osm_id = trim($_POST['osm_id'] ?? '');
  $lat = (isset($_POST['lat']) && $_POST['lat'] !== '' && is_numeric($_POST['lat'])) ? (float)$_POST['lat'] : null;
  $lon = (isset($_POST['lon']) && $_POST['lon'] !== '' && is_numeric($_POST['lon'])) ? (float)$_POST['lon'] : null;
  $license = trim($_POST['license'] ?? 'all-rights-reserved');
  $update_frequency = trim($_POST['update_frequency'] ?? '');
  if (!in_array($update_frequency, ['daily', 'weekly', 'biweekly', 'monthly'], true)) {
      $update_frequency = null;
  }

  if ($p) {
      $pdo->prepare("UPDATE podcasts SET title=?, slug=?, description=?, language=?, category=?, subcategory=?, author=?, publisher=?, owner_email=?, copyright=?, type=?, parental=?, fediverse_handle=?, federate=?, cover=?, banner=?, color=?, op3=?, locked=?, hidden=?, complete=?, remove_email=?, custom_tags=?, ownership_txt=?, fixed_notes=?, status=?, guid=?, feed_redirect_slug=?, location_name=?, osm_id=?, lat=?, lon=?, license=?, update_frequency=? WHERE slug=?")
          ->execute([$title, $slug, $description, $language, $category, $subcategory, $author, $publisher, $owner_email, $copyright, $type, $parental, $fediverse_handle, $federate, $cover_url, $banner_url, $color, $op3, $locked, $hidden, $complete, $remove_email, $custom_tags, $ownership_txt, $fixed_notes, $status, $guid, $feed_redirect_slug, $location_name, $osm_id, $lat, $lon, $license, $update_frequency, $p['id']]);
      $db_id = $p['db_id'];
  } else {
      $pdo->prepare("INSERT INTO podcasts (title, slug, description, language, category, subcategory, author, publisher, owner_email, copyright, type, parental, fediverse_handle, federate, cover, banner, color, op3, locked, hidden, complete, remove_email, custom_tags, ownership_txt, fixed_notes, status, guid, feed_redirect_slug, location_name, osm_id, lat, lon, license, update_frequency) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
          ->execute([$title, $slug, $description, $language, $category, $subcategory, $author, $publisher, $owner_email, $copyright, $type, $parental, $fediverse_handle, $federate, $cover_url, $banner_url, $color, $op3, $locked, $hidden, $complete, $remove_email, $custom_tags, $ownership_txt, $fixed_notes, $status, $guid, $feed_redirect_slug, $location_name, $osm_id, $lat, $lon, $license, $update_frequency]);
      $db_id = (int)$pdo->lastInsertId();
  }

  // Lanzar gancho para que los plugins procesen el guardado del podcast
  kp_do_action('podcast_saved', $db_id, $_POST);

  $pdo->prepare("DELETE FROM podcast_users WHERE podcast_id = ?")->execute([$db_id]);
  if (!empty($_POST['team_users']) && is_array($_POST['team_users'])) {
      $stmt = $pdo->prepare("INSERT INTO podcast_users (podcast_id, user_id) VALUES (?, ?)");
      foreach ($_POST['team_users'] as $uid) {
          $stmt->execute([$db_id, (int)$uid]);
      }
  }


  require_once __DIR__ . '/../includes/cache.php';
  kp_cache_clear();

  setcookie('kp_flash', 'Podcast guardado exitosamente', time() + 30, '/');
  header('Location: /admin/edit-podcast?id=' . urlencode($slug)); exit;
}

$sections = [
  's1-1' => 'Identidad',
  's1-2' => 'Clasificación',
  's1-3' => 'Autor & Licencia',
  's1-9' => 'Equipo',
  // 's1-5' => 'Premium', // TODO: backend logic needed
  's1-6' => 'Estadísticas · OP3',
  's1-7' => 'Ubicación',
  's1-8' => 'Avanzados',
];
$sections = kp_apply_filters('podcast_form_sections', $sections);
?>
<form method="POST" enctype="multipart/form-data" class="form-podcast">
  <?= kp_csrf_field() ?>
  <div class="page-head">
    <div>
      <h1 class="page-title"><?= $mode === 'create' ? 'Nuevo podcast' : 'Editar podcast' ?></h1>
      <p class="page-sub"><?= $mode === 'create' ? 'Configura tu nuevo show en 8 secciones.' : 'Edita la configuración del podcast.' ?></p>
    </div>
  </div>

  <div class="grid-12" style="gap:24px">
    <div class="span-9 col" style="gap:24px">

      <!-- 1.1 Identidad -->
      <section id="s1-1" class="card card-lg">
        <header class="section-head"><h2>Identidad del Podcast</h2></header>
        <div class="row" style="gap:24px;align-items:flex-start;flex-wrap:wrap">
          <div class="cover-upload-container" style="flex:0 0 200px">
            <label for="cover" class="cover-upload" style="display:block;aspect-ratio:1;border-radius:12px;background:var(--surface-2);border:1px dashed var(--border);display:grid;place-items:center;color:var(--text-3);cursor:pointer;background-size:cover;background-position:center;<?= !empty($p['cover']) ? 'background-image:url('.e($p['cover']).');' : '' ?>">
              <?= empty($p['cover']) ? icon('image',28) : '' ?>
            </label>
            <input id="cover" name="cover" type="file" accept="image/png,image/jpeg,image/gif,image/webp" hidden>
            <div class="help" style="margin-top:8px;text-align:center">PNG/JPG · mín. 1400×1400</div>
            <button id="cover-cancel" type="button" class="btn btn-ghost" style="margin-top:8px;font-size:12px;display:none;color:var(--red);width:100%;justify-content:center;"><?= icon('x',12) ?> Cancelar selección</button>
          </div>
          <div style="flex:1" class="col">
            <div class="field"><label class="label">Título <span class="req">*</span></label><input class="input" name="title" id="title" required value="<?= e($p['title'] ?? '') ?>" placeholder="Mi nuevo podcast" oninput="updateSlugs(this.value)"></div>
            <div class="field" style="margin-top:14px">
              <label class="label">Slug · URL del feed</label>
              <div class="input-affix"><span><?= e(kp_setting('instance_domain', $_SERVER['HTTP_HOST'] ?? '')) ?>/@</span><input class="input" name="slug" id="slug" value="<?= e($p['id'] ?? '') ?>" pattern="[a-zA-Z0-9\-]+" style="font-family:ui-monospace,monospace" oninput="this.dataset.manual=1"></div>
              <div class="help">Solo minúsculas, números y guiones.</div>
            </div>
            <div class="field" style="margin-top:14px">
              <label class="label">Color del Podcast</label>
              <style>
              .color-swatch input[type="radio"]:checked + svg { display: block !important; }
              </style>
              <div class="row" style="flex-wrap:wrap;gap:8px;margin-top:6px">
                <?php foreach (kp_color_palette() as $cp): ?>
                  <label class="color-swatch" style="cursor:pointer;position:relative;width:24px;height:24px;border-radius:50%;background:<?= $cp ?>;box-shadow:inset 0 0 0 1px rgba(0,0,0,0.1)">
                    <input type="radio" name="color" value="<?= $cp ?>" <?= ($p['color'] ?? '') === $cp ? 'checked' : '' ?> style="opacity:0;position:absolute;width:100%;height:100%;margin:0;cursor:pointer">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="position:absolute;top:5px;left:5px;display:none"><path d="M5 12l4 4 10-10"/></svg>
                  </label>
                <?php endforeach; ?>
              </div>
              <div class="help" style="margin-top:8px">Selecciona el color de acento principal. Si no eliges, se asignará uno aleatorio.</div>
            </div>
            <div class="field" style="margin-top:14px"><label class="label">Descripción <span class="req">*</span></label><textarea class="textarea" name="description" required rows="4" placeholder="Una conversación semanal sobre…"><?= e($p['description'] ?? '') ?></textarea></div>
            <div class="field" style="margin-top:14px"><label class="label">Notas fijas</label><textarea class="textarea" name="fixed_notes" rows="4" placeholder="Texto que aparecerá siempre al final de cada episodio. Ej: enlaces de contacto, redes sociales, créditos…" style="font-size:13.5px"><?= e($p['fixed_notes'] ?? '') ?></textarea><div class="help" style="margin-top:4px">Este texto se añadirá automáticamente debajo de las notas de cada episodio. Soporta Markdown.</div>
            <?php if ($mode === 'edit' && !empty($p['fixed_notes'])): ?>
            <button type="submit" name="action" value="strip_fixed_notes" formnovalidate class="btn" style="margin-top:8px;font-size:12px" onclick="return confirm('Esto eliminará el texto de las notas fijas de las notas individuales de todos los episodios de este podcast. ¿Continuar?')"><?= icon('trash',12) ?> Limpiar de episodios existentes</button>
            <div class="help" style="margin-top:4px">Si importaste episodios que ya incluyen este texto en sus notas, usa este botón para eliminarlo y evitar duplicados.</div>
            <?php endif; ?>
            </div>
          </div>
        </div>
      </section>

      <!-- 1.2 Clasificación -->
      <section id="s1-2" class="card card-lg">
        <header class="section-head"><h2>Clasificación</h2></header>
        <div class="field-row">
          <div class="field"><label class="label">Idioma <span class="req">*</span></label>
            <select class="select" name="language" required>
              <option value="es" <?= ($p['language']??'es')==='es'?'selected':'' ?>>Español (es)</option>
              <option value="en" <?= ($p['language']??'')==='en'?'selected':'' ?>>Inglés (en)</option>
              <option value="pt" <?= ($p['language']??'')==='pt'?'selected':'' ?>>Portugués (pt)</option>
              <option value="fr" <?= ($p['language']??'')==='fr'?'selected':'' ?>>Francés (fr)</option>
              <option value="de" <?= ($p['language']??'')==='de'?'selected':'' ?>>Alemán (de)</option>
            </select>
          </div>
          <div class="field">
            <label class="label">Categoría principal <span class="req">*</span></label>
            <select class="select" id="podcast_category" name="category" required>
              <option value="">Selecciona una categoría...</option>
              <?php foreach (array_keys($apple_categories) as $c): ?>
                <option value="<?= e($c) ?>" <?= ($p['category'] ?? '') === $c ? 'selected' : '' ?>><?= e($c) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label class="label">Subcategoría (Apple Podcasts)</label>
            <select class="select" id="podcast_subcategory" name="subcategory">
              <option value="">Sin subcategoría / Ninguna</option>
              <?php 
                $curr_cat = $p['category'] ?? '';
                $curr_sub = $p['subcategory'] ?? '';
                $avail_subs = $apple_categories[$curr_cat] ?? [];
                foreach ($avail_subs as $s): 
              ?>
                <option value="<?= e($s) ?>" <?= $curr_sub === $s ? 'selected' : '' ?>><?= e($s) ?></option>
              <?php endforeach; ?>
              <?php if ($curr_sub !== '' && !in_array($curr_sub, $avail_subs, true)): ?>
                <option value="<?= e($curr_sub) ?>" selected><?= e($curr_sub) ?> (personalizada)</option>
              <?php endif; ?>
            </select>
          </div>
        </div>
        <div class="field-row-3" style="margin-top:14px">
          <div class="field"><label class="label">Tipo de podcast</label>
            <select class="select" name="type"><option value="episodic" <?= ($p['type']??'episodic')==='episodic'?'selected':'' ?>>Episódico</option><option value="serial" <?= ($p['type']??'')==='serial'?'selected':'' ?>>Serial</option></select>
          </div>
          <div class="field"><label class="label">Frecuencia de actualización</label>
            <?php $curr_freq = strtolower(trim($p['update_frequency'] ?? '')); ?>
            <select class="select" name="update_frequency">
              <option value="" <?= ($curr_freq === '' || $curr_freq === 'auto') ? 'selected' : '' ?>>Automático (detectar ritmo)</option>
              <option value="daily" <?= ($curr_freq === 'daily' || $curr_freq === 'diario') ? 'selected' : '' ?>>Diario</option>
              <option value="weekly" <?= ($curr_freq === 'weekly' || $curr_freq === 'semanal') ? 'selected' : '' ?>>Semanal</option>
              <option value="biweekly" <?= ($curr_freq === 'biweekly' || $curr_freq === 'quincenal') ? 'selected' : '' ?>>Quincenal</option>
              <option value="monthly" <?= ($curr_freq === 'monthly' || $curr_freq === 'mensual') ? 'selected' : '' ?>>Mensual</option>
            </select>
            <div class="help">Etiqueta <code>podcast:updateFrequency</code>. Si no se escoge nada manual, se detecta el ritmo automáticamente.</div>
          </div>
          <div class="field"><label class="label">Parental advisory</label>
            <select class="select" name="parental"><option value="clean" <?= ($p['parental']??'clean')==='clean'?'selected':'' ?>>Apto para todos</option><option value="explicit" <?= ($p['parental']??'')==='explicit'?'selected':'' ?>>Explícito</option></select>
          </div>
        </div>
      </section>

      <!-- 1.3 Autor & Licencia -->
      <section id="s1-3" class="card card-lg">
        <header class="section-head"><h2>Autor &amp; Licencia</h2></header>
        <div class="field-row">
          <div class="field"><label class="label">Nombre del autor</label><input class="input" id="podcast_author" name="author" value="<?= e($p['author'] ?? kp_setting('owner_name', '')) ?>"></div>
          <div class="field"><label class="label">Publisher / editorial</label><input class="input" name="publisher" value="<?= e($p['publisher'] ?? '') ?>" placeholder="Independiente"></div>
        </div>
        <div class="field-row" style="margin-top:14px">
          <div class="field"><label class="label">Email del owner (iTunes)</label><input class="input" type="email" name="owner_email" value="<?= e($p['owner_email'] ?? '') ?>" placeholder="owner@ejemplo.com"></div>
          <div class="field">
            <label class="label">Licencia (Podcasting 2.0)</label>
            <?php
              $curr_license = $p['license'] ?? '';
              if ($curr_license === '') {
                  $c_lower = strtolower($p['copyright'] ?? '');
                  if (strpos($c_lower, 'cc-by-nc-nd') !== false) {
                      $curr_license = 'cc-by-nc-nd-4.0';
                  } elseif (strpos($c_lower, 'cc-by-nc-sa') !== false) {
                      $curr_license = 'cc-by-nc-sa-4.0';
                  } elseif (strpos($c_lower, 'cc-by-nc') !== false) {
                      $curr_license = 'cc-by-nc-4.0';
                  } elseif (strpos($c_lower, 'cc-by-nd') !== false) {
                      $curr_license = 'cc-by-nd-4.0';
                  } elseif (strpos($c_lower, 'cc-by-sa') !== false) {
                      $curr_license = 'cc-by-sa-4.0';
                  } elseif (strpos($c_lower, 'cc-by') !== false) {
                      $curr_license = 'cc-by-4.0';
                  } elseif (strpos($c_lower, 'cc0') !== false || strpos($c_lower, 'public domain') !== false) {
                      $curr_license = 'cc0-1.0';
                  } else {
                      $curr_license = 'all-rights-reserved';
                  }
              }
            ?>
            <select class="select" id="podcast_license" name="license">
              <option value="all-rights-reserved" <?= $curr_license === 'all-rights-reserved' ? 'selected' : '' ?>>Todos los derechos reservados (All Rights Reserved)</option>
              <option value="cc-by-4.0" <?= $curr_license === 'cc-by-4.0' ? 'selected' : '' ?>>Creative Commons Atribución (CC BY 4.0)</option>
              <option value="cc-by-sa-4.0" <?= $curr_license === 'cc-by-sa-4.0' ? 'selected' : '' ?>>Creative Commons Atribución - CompartirIgual (CC BY-SA 4.0)</option>
              <option value="cc-by-nc-4.0" <?= $curr_license === 'cc-by-nc-4.0' ? 'selected' : '' ?>>Creative Commons Atribución - NoComercial (CC BY-NC 4.0)</option>
              <option value="cc-by-nc-sa-4.0" <?= $curr_license === 'cc-by-nc-sa-4.0' ? 'selected' : '' ?>>Creative Commons Atribución - NoComercial - CompartirIgual (CC BY-NC-SA 4.0)</option>
              <option value="cc-by-nd-4.0" <?= $curr_license === 'cc-by-nd-4.0' ? 'selected' : '' ?>>Creative Commons Atribución - SinDerivadas (CC BY-ND 4.0)</option>
              <option value="cc-by-nc-nd-4.0" <?= $curr_license === 'cc-by-nc-nd-4.0' ? 'selected' : '' ?>>Creative Commons Atribución - NoComercial - SinDerivadas (CC BY-NC-ND 4.0)</option>
              <option value="cc0-1.0" <?= $curr_license === 'cc0-1.0' ? 'selected' : '' ?>>Dominio Público (Creative Commons CC0 1.0)</option>
              <option value="custom" <?= $curr_license === 'custom' ? 'selected' : '' ?>>Otra / Personalizada</option>
            </select>
            <div class="help">Define los derechos de uso en apps Podcasting 2.0. Al cambiar la opción se sugerirá el texto legal correspondiente.</div>
          </div>
        </div>
        <div class="field" style="margin-top:14px">
          <label class="label">Aviso de Copyright</label>
          <input class="input" id="podcast_copyright" name="copyright" value="<?= e($p['copyright'] ?? '© ' . date('Y') . ' ' . ($p['author'] ?? kp_setting('owner_name', ''))) ?>">
          <div class="help">Texto de copyright tradicional visible en Apple Podcasts, Spotify y agregadores (<code>&lt;copyright&gt;</code>).</div>
        </div>
        <label class="toggle-row" style="margin-top:14px"><span><strong>Ocultar email</strong><br><span class="help">No incluir tu dirección de email en el feed RSS público</span></span><label class="toggle"><input type="checkbox" name="remove_email" <?= ($p['remove_email']??0)?'checked':'' ?>><span class="toggle-track"></span></label></label>
      </section>

      <?php kp_do_action('podcast_form_sections_render', $p); ?>



      <!-- 1.9 Equipo -->
      <section id="s1-9" class="card card-lg">
        <header class="section-head"><h2>Equipo y Permisos</h2></header>
        <?php if (empty($team_users)): ?>
          <div class="help" style="margin-top:14px">No hay usuarios con el rol de Editor o Autor en la plataforma.</div>
        <?php else: ?>
          <div class="help" style="margin-top:14px;margin-bottom:18px">Selecciona los usuarios que tendrán acceso para editar episodios en este podcast. Los Administradores y Owners tienen acceso global automático.</div>
          <div class="col" style="gap:12px">
            <?php foreach ($team_users as $u): 
              $is_checked = in_array($u['id'], $assigned_users);
            ?>
              <label class="row" style="gap:14px;padding:12px;border:1px solid var(--border);border-radius:10px;align-items:center;cursor:pointer">
                <input type="checkbox" name="team_users[]" value="<?= $u['id'] ?>" <?= $is_checked ? 'checked' : '' ?>>
                <div style="width:32px;height:32px;border-radius:50%;background:var(--surface-3);color:var(--text-3);display:grid;place-items:center;font-weight:700;font-size:12px;overflow:hidden">
                  <?php if (!empty($u['avatar'])): ?>
                    <img src="<?= e($u['avatar']) ?>" style="width:100%;height:100%;object-fit:cover">
                  <?php else: ?>
                    <?= e(strtoupper(substr($u['name'], 0, 2))) ?>
                  <?php endif; ?>
                </div>
                <div style="flex:1;min-width:0">
                  <div style="font-weight:600;font-size:14px"><?= e($u['name']) ?></div>
                  <div style="font-size:12px;color:var(--text-3);overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($u['email']) ?></div>
                </div>
                <div><span class="tag" style="background:var(--surface-3);font-size:11px"><?= e(ucfirst($u['role'])) ?></span></div>
              </label>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>

      <!-- 1.6 OP3 -->
      <section id="s1-6" class="card card-lg">
        <header class="section-head"><h2>Estadísticas · OP3</h2></header>
        <label class="toggle-row"><span><strong>Auditoría con OP3</strong><br><span class="help">Antepone el prefijo <code>op3.dev/e/</code> a las URLs de audio del feed para auditar descargas.</span></span><label class="toggle"><input type="checkbox" name="op3" <?= (is_array($p) ? ($p['op3'] ?? 1) : 1) ? 'checked' : '' ?>><span class="toggle-track"></span></label></label>
        <div class="field" style="margin-top:14px">
          <label class="label">Prefijo OP3</label>
          <?php
            $p_slug = is_array($p) ? ($p['id'] ?? 'slug') : 'slug';
            $p_guid = is_array($p) ? ($p['guid'] ?? '') : '';
            $display_base = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
            $display_tracker = $display_base . '/r/' . rawurlencode($p_slug) . '/';
            $display_guid = !empty($p_guid) ? $p_guid : kp_deterministic_podcast_guid(kp_canonical_feed_url($p_slug));
            $display_prefix = 'https://op3.dev/e,pg=' . $display_guid . '/' . preg_replace('#^https?://#', '', $display_tracker);
          ?>
          <input class="input" name="op3_prefix" value="<?= e($display_prefix) ?>" readonly style="font-family:ui-monospace,monospace">
        </div>
        <div class="field" style="margin-top:14px">
          <label class="label">Podcast GUID</label>
          <input class="input" name="guid" value="<?= e(is_array($p) ? ($p['guid'] ?? '') : '') ?>" placeholder="Ej: edc1dcff-dda4-5036-abdc-12dd1f71db7e" style="font-family:ui-monospace,monospace">
          <div class="help">Identificador único y permanente del podcast (podcast:guid). Si lo dejas vacío, se generará uno automáticamente.</div>
        </div>
      </section>

      <!-- 1.7 Ubicación -->
      <section id="s1-7" class="card card-lg">
        <header class="section-head">
          <h2>Ubicación</h2>
        </header>
        <div class="help" style="margin-bottom:14px">
          Indica la ubicación geográfica de tu podcast según el estándar Podcasting 2.0. Puedes buscar cualquier ciudad, estado o país para rellenar las coordenadas y el ID de OpenStreetMap automáticamente.
        </div>

        <div class="field">
          <label class="label">Lugar o dirección</label>
          <div style="display:flex;gap:8px">
            <input class="input" id="loc_name" name="location_name" value="<?= e($p['location_name'] ?? '') ?>" placeholder="Ej: Texas, Estados Unidos">
            <button type="button" id="btn-search-loc" class="btn" style="white-space:nowrap;display:inline-flex;align-items:center;gap:6px">
              <?= icon('search', 13) ?> Buscar coordenadas
            </button>
            <button type="button" id="btn-clear-loc" class="btn" title="Limpiar ubicación" style="display:inline-flex;align-items:center">
              <?= icon('trash', 13) ?>
            </button>
          </div>
          <div id="loc-spinner" style="display:none;margin-top:8px;font-size:12px;color:var(--text-muted)">
            Buscando en OpenStreetMap...
          </div>
          <div id="loc-results" style="display:none;margin-top:8px;border:1px solid var(--border);border-radius:var(--radius);background:var(--bg-card);max-height:220px;overflow-y:auto;box-shadow:var(--shadow-md)"></div>
        </div>

        <div class="field-row" style="margin-top:14px">
          <div class="field">
            <label class="label">OpenStreetMap ID (OSM ID)</label>
            <input class="input tabular" id="loc_osm_id" name="osm_id" value="<?= e($p['osm_id'] ?? '') ?>" placeholder="Ej: R114690">
            <div class="help">Prefijo: <code>R</code> (relación), <code>W</code> (vía), o <code>N</code> (nodo).</div>
          </div>
          <div class="field">
            <label class="label">Latitud</label>
            <input class="input tabular" id="loc_lat" type="number" step="any" name="lat" value="<?= (isset($p['lat']) && $p['lat'] !== null && $p['lat'] !== '') ? e($p['lat']) : '' ?>" placeholder="Ej: 31.2638">
          </div>
          <div class="field">
            <label class="label">Longitud</label>
            <input class="input tabular" id="loc_lon" type="number" step="any" name="lon" value="<?= (isset($p['lon']) && $p['lon'] !== null && $p['lon'] !== '') ? e($p['lon']) : '' ?>" placeholder="Ej: -98.5456">
          </div>
        </div>
      </section>

      <!-- 1.8 Avanzados -->
      <section id="s1-8" class="card card-lg">
        <header class="section-head"><h2>Parámetros avanzados</h2></header>

        <div class="field" style="margin-top:14px"><label class="label">Custom tags (RSS XML)</label><textarea class="textarea" name="custom_tags" rows="4" placeholder="&lt;itunes:keywords&gt;...&lt;/itunes:keywords&gt;" style="font-family:ui-monospace,monospace;font-size:12.5px"><?= e($p['custom_tags'] ?? '') ?></textarea></div>
        <div class="field" style="margin-top:14px"><label class="label">podcast:txt (ownership)</label><input class="input" name="ownership_txt" value="<?= e($p['ownership_txt'] ?? '') ?>" placeholder="<?= bin2hex(random_bytes(8)) ?>" style="font-family:ui-monospace,monospace"></div>
        <div class="field" style="margin-top:14px">
          <label class="label">Redirección de feed (Slug antiguo)</label>
          <div class="input-affix"><span><?= e(kp_setting('instance_domain', $_SERVER['HTTP_HOST'] ?? '')) ?>/feed/</span><input class="input" name="feed_redirect_slug" id="feed_redirect_slug" value="<?= e($p['feed_redirect_slug'] ?? '') ?>" pattern="[a-zA-Z0-9\-]*" style="font-family:ui-monospace,monospace"></div>
          <div class="help">Si se configura, cualquier petición a <code>/feed/slug_antiguo</code> o <code>/feed/slug_antiguo.xml</code> será redireccionada permanentemente a este podcast.</div>
        </div>
        <?php kp_do_action('podcast_form_advanced', $p); ?>
        <div class="col" style="gap:10px;margin-top:14px">
          <label class="toggle-row"><span><strong>Locked</strong><br><span class="help">Prohibir importar este feed en otros hosts</span></span><label class="toggle"><input type="checkbox" name="locked" <?= ($p['locked']??0)?'checked':'' ?>><span class="toggle-track"></span></label></label>
          <label class="toggle-row"><span><strong>Hidden</strong><br><span class="help">No listar en directorios públicos</span></span><label class="toggle"><input type="checkbox" name="hidden" <?= ($p['hidden']??0)?'checked':'' ?>><span class="toggle-track"></span></label></label>
          <label class="toggle-row"><span><strong>Complete</strong><br><span class="help">Marcar feed como finalizado</span></span><label class="toggle"><input type="checkbox" name="complete" <?= ($p['complete']??0)?'checked':'' ?>><span class="toggle-track"></span></label></label>
        </div>
      </section>

    </div>
    
    <aside class="span-3 form-toc" style="position:sticky; top:90px; align-self:start">
      <?php foreach ($sections as $anchor => $label): ?>
        <a href="#<?= $anchor ?>" class="form-toc-item"><?= e($label) ?></a>
      <?php endforeach; ?>
      
      <div style="margin-top:32px;display:flex;flex-direction:column;gap:10px">
        <button class="btn btn-primary" type="submit" name="status" value="published" style="width:100%;justify-content:center;padding:10px"><?= icon('check',13) ?> Guardar</button>
        <button class="btn" type="submit" name="status" value="draft" formnovalidate style="width:100%;justify-content:center"><?= icon('file',13) ?> Guardar borrador</button>
        <?php if ($mode === 'edit' && $p): ?>
          <a class="btn" href="/@<?= e($p['slug'] ?? $p['id']) ?>" target="_blank" rel="noopener" style="width:100%;justify-content:center"><?= icon('externalLink',13) ?> Ir al Podcast</a>
        <?php endif; ?>
        <a class="btn" href="/admin/podcasts" style="width:100%;justify-content:center">Cancelar</a>
        <?php if ($mode === 'edit'): ?>
          <button class="btn btn-danger" style="color:var(--red,#ef4444);border:1px solid var(--red,#ef4444);width:100%;justify-content:center" type="submit" name="action" value="delete" formnovalidate onclick="return confirm('¿Estás seguro de que quieres eliminar este podcast y TODOS sus episodios?')"><?= icon('x',13) ?> Eliminar</button>
        <?php endif; ?>
      </div>
    </aside>

  </div>
</form>

<script>
function slugify(s) {
  return (s||'').toString().toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g,'')
    .replace(/[^a-z0-9]/g,'');
}
function updateSlugs(val) {
  const s = slugify(val);
  const slugInput = document.getElementById('slug');
  const fediInput = document.getElementById('fediverse_handle');
  if (slugInput && !slugInput.dataset.manual && !slugInput.defaultValue) slugInput.value = s;
  if (fediInput && !fediInput.dataset.manual && !fediInput.defaultValue) fediInput.value = s;
}

const coverInput = document.getElementById('cover');
const coverLabel = document.querySelector('label[for="cover"]');
const coverCancel = document.getElementById('cover-cancel');
let initialCoverBgImage = '';
let initialCoverBg = '';
let initialCoverBorder = '';
let initialCoverHTML = '';
if (coverLabel) {
  initialCoverBgImage = coverLabel.style.backgroundImage;
  initialCoverBg = coverLabel.style.background;
  initialCoverBorder = coverLabel.style.border;
  initialCoverHTML = coverLabel.innerHTML;
}

coverInput?.addEventListener('change', function() {
  if(this.files[0]){
    const r=new FileReader();
    r.onload=e=>{
      coverLabel.style.backgroundImage='url('+e.target.result+')';
      coverLabel.style.backgroundSize='cover';
      coverLabel.innerHTML='';
      if(coverCancel) coverCancel.style.display = 'inline-flex';
    };
    r.readAsDataURL(this.files[0]);
  } else {
    resetCover();
  }
});

function resetCover() {
  if (coverInput) coverInput.value = '';
  if (coverLabel) {
    coverLabel.style.background = initialCoverBg;
    coverLabel.style.backgroundImage = initialCoverBgImage;
    coverLabel.style.border = initialCoverBorder;
    coverLabel.innerHTML = initialCoverHTML;
  }
  if (coverCancel) coverCancel.style.display = 'none';
}
coverCancel?.addEventListener('click', resetCover);

const bannerInput = document.getElementById('banner');
const bannerLabel = document.querySelector('label[for="banner"]');
const bannerCancel = document.getElementById('banner-cancel');
let initialBannerBgImage = '';
let initialBannerBg = '';
let initialBannerBorder = '';
let initialBannerHTML = '';
if (bannerLabel) {
  initialBannerBgImage = bannerLabel.style.backgroundImage;
  initialBannerBg = bannerLabel.style.background;
  initialBannerBorder = bannerLabel.style.border;
  initialBannerHTML = bannerLabel.innerHTML;
}

bannerInput?.addEventListener('change', function() {
  if(this.files[0]){
    const r=new FileReader();
    r.onload=e=>{
      bannerLabel.style.backgroundImage='url('+e.target.result+')';
      bannerLabel.style.backgroundSize='cover';
      bannerLabel.style.border='none';
      bannerLabel.innerHTML='';
      if(bannerCancel) bannerCancel.style.display = 'inline-flex';
    };
    r.readAsDataURL(this.files[0]);
  } else {
    resetBanner();
  }
});

function resetBanner() {
  if (bannerInput) bannerInput.value = '';
  if (bannerLabel) {
    bannerLabel.style.background = initialBannerBg;
    bannerLabel.style.backgroundImage = initialBannerBgImage;
    bannerLabel.style.border = initialBannerBorder;
    bannerLabel.innerHTML = initialBannerHTML;
  }
  if (bannerCancel) bannerCancel.style.display = 'none';
}
bannerCancel?.addEventListener('click', resetBanner);

// Geocodificación interactiva con OpenStreetMap Nominatim
(function() {
  const locInput = document.getElementById('loc_name');
  const btnSearch = document.getElementById('btn-search-loc');
  const btnClear = document.getElementById('btn-clear-loc');
  const resultsDiv = document.getElementById('loc-results');
  const spinner = document.getElementById('loc-spinner');
  const osmInput = document.getElementById('loc_osm_id');
  const latInput = document.getElementById('loc_lat');
  const lonInput = document.getElementById('loc_lon');

  if (!locInput || !btnSearch) return;

  function escapeHtml(str) {
    return (str || '').replace(/[&<>"']/g, function(m) {
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m];
    });
  }

  async function searchLocation() {
    const q = locInput.value.trim();
    if (!q) {
      locInput.focus();
      return;
    }

    spinner.style.display = 'block';
    resultsDiv.style.display = 'none';
    resultsDiv.innerHTML = '';

    try {
      const url = 'https://nominatim.openstreetmap.org/search?format=json&q=' + encodeURIComponent(q) + '&limit=5&addressdetails=1';
      const res = await fetch(url, {
        headers: { 'Accept': 'application/json' }
      });
      if (!res.ok) throw new Error('Error en el servicio de mapas');
      const data = await res.json();
      spinner.style.display = 'none';

      if (!data || data.length === 0) {
        resultsDiv.innerHTML = '<div style="padding:10px 14px;color:var(--text-muted);font-size:13px">No se encontraron resultados para "' + escapeHtml(q) + '". Puedes escribir las coordenadas a mano.</div>';
        resultsDiv.style.display = 'block';
        return;
      }

      data.forEach(item => {
        const div = document.createElement('div');
        div.style.cssText = 'padding:10px 14px;border-bottom:1px solid var(--border);cursor:pointer;display:flex;flex-direction:column;gap:2px;transition:background 0.15s ease;';
        div.onmouseenter = () => div.style.background = 'var(--bg-hover, rgba(255,255,255,0.05))';
        div.onmouseleave = () => div.style.background = 'transparent';

        // Determinar prefijo OSM (R para relation, W para way, N para node)
        let osmPrefix = 'N';
        if (item.osm_type === 'relation') osmPrefix = 'R';
        else if (item.osm_type === 'way') osmPrefix = 'W';
        const formattedOsmId = osmPrefix + item.osm_id;

        div.innerHTML = `
          <strong style="font-size:13.5px;color:var(--text-main)">${escapeHtml(item.display_name)}</strong>
          <span style="font-size:11.5px;color:var(--text-muted);font-family:ui-monospace,monospace">OSM: ${formattedOsmId} · Lat: ${parseFloat(item.lat).toFixed(4)}, Lon: ${parseFloat(item.lon).toFixed(4)} · Tipo: ${item.type || item.class}</span>
        `;

        div.onclick = () => {
          locInput.value = item.display_name;
          latInput.value = parseFloat(item.lat);
          lonInput.value = parseFloat(item.lon);
          osmInput.value = formattedOsmId;
          resultsDiv.style.display = 'none';
        };

        resultsDiv.appendChild(div);
      });
      resultsDiv.style.display = 'block';

    } catch (err) {
      spinner.style.display = 'none';
      resultsDiv.innerHTML = '<div style="padding:10px 14px;color:var(--red, #e53e3e);font-size:13px">No se pudo consultar el servicio de OpenStreetMap. Por favor verifica tu conexión o ingresa los datos manualmente.</div>';
      resultsDiv.style.display = 'block';
    }
  }

  btnSearch.addEventListener('click', (e) => {
    e.preventDefault();
    searchLocation();
  });

  locInput.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') {
      e.preventDefault();
      searchLocation();
    }
  });

  btnClear?.addEventListener('click', (e) => {
    e.preventDefault();
    locInput.value = '';
    latInput.value = '';
    lonInput.value = '';
    osmInput.value = '';
    resultsDiv.style.display = 'none';
    resultsDiv.innerHTML = '';
  });

  document.addEventListener('click', (e) => {
    if (!resultsDiv.contains(e.target) && e.target !== btnSearch && e.target !== locInput) {
      resultsDiv.style.display = 'none';
    }
  });
})();

// Actualización dinámica de subcategorías de Apple Podcasts
(function() {
  const catSelect = document.getElementById('podcast_category');
  const subSelect = document.getElementById('podcast_subcategory');
  if (!catSelect || !subSelect) return;

  const appleCategories = <?= json_encode($apple_categories, JSON_UNESCAPED_UNICODE) ?>;

  catSelect.addEventListener('change', function() {
    const selectedCat = this.value;
    const subs = appleCategories[selectedCat] || [];
    
    subSelect.innerHTML = '';
    
    if (subs.length === 0) {
      const opt = document.createElement('option');
      opt.value = '';
      opt.textContent = selectedCat ? 'No aplica / Sin subcategoría' : 'Sin subcategoría / Ninguna';
      subSelect.appendChild(opt);
      subSelect.disabled = subs.length === 0 && selectedCat !== '';
    } else {
      subSelect.disabled = false;
      const defOpt = document.createElement('option');
      defOpt.value = '';
      defOpt.textContent = 'Sin subcategoría / Ninguna';
      subSelect.appendChild(defOpt);
      
      subs.forEach(s => {
        const opt = document.createElement('option');
        opt.value = s;
        opt.textContent = s;
        subSelect.appendChild(opt);
      });
    }
  });
})();

// Manejador de licencias y actualización automática de Copyright
(function() {
  const licSelect = document.getElementById('podcast_license');
  const copyInput = document.getElementById('podcast_copyright');
  const authorInput = document.getElementById('podcast_author');
  if (!licSelect || !copyInput) return;

  const currentYear = new Date().getFullYear();

  licSelect.addEventListener('change', function() {
    const lic = this.value;
    const author = (authorInput?.value || '').trim() || 'Autor';

    const templates = {
      'all-rights-reserved': `© ${currentYear} ${author}. Todos los derechos reservados.`,
      'cc-by-4.0': `© ${currentYear} ${author}. Licencia Creative Commons Atribución 4.0 Internacional (CC BY 4.0).`,
      'cc-by-sa-4.0': `© ${currentYear} ${author}. Licencia Creative Commons Atribución-CompartirIgual 4.0 Internacional (CC BY-SA 4.0).`,
      'cc-by-nc-4.0': `© ${currentYear} ${author}. Licencia Creative Commons Atribución-NoComercial 4.0 Internacional (CC BY-NC 4.0).`,
      'cc-by-nc-sa-4.0': `© ${currentYear} ${author}. Licencia Creative Commons Atribución-NoComercial-CompartirIgual 4.0 Internacional (CC BY-NC-SA 4.0).`,
      'cc-by-nd-4.0': `© ${currentYear} ${author}. Licencia Creative Commons Atribución-SinDerivadas 4.0 Internacional (CC BY-ND 4.0).`,
      'cc-by-nc-nd-4.0': `© ${currentYear} ${author}. Licencia Creative Commons Atribución-NoComercial-SinDerivadas 4.0 Internacional (CC BY-NC-ND 4.0).`,
      'cc0-1.0': `Dedicado al dominio público bajo Creative Commons CC0 1.0 Universal.`
    };

    if (templates[lic]) {
      copyInput.value = templates[lic];
    }
  });
})();
</script>
