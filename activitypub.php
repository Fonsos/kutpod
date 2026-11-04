<?php
// ============================================================================
// KutPod · ActivityPub (Webfinger + Actor + Outbox + Inbox + HTTP Signatures)
// ============================================================================
// Rewrites recomendados (Apache/Nginx → todos a este archivo):
//   /.well-known/webfinger       → activitypub.php?ap=webfinger
//   /.well-known/host-meta       → activitypub.php?ap=host-meta
//   /users/{slug}                → activitypub.php?ap=actor&slug={slug}
//   /users/{slug}/outbox         → activitypub.php?ap=outbox&slug={slug}
//   /users/{slug}/inbox          → activitypub.php?ap=inbox&slug={slug}
//   /users/{slug}/followers      → activitypub.php?ap=followers&slug={slug}
//   /users/{slug}/notes/{ep}     → activitypub.php?ap=note&slug={slug}&ep={ep}

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';

if (!kp_plugin_is_active('fediverse')) {
    http_response_code(404);
    exit('El Fediverso está desactivado.');
}

// ---------------------------------------------------------------------------
// Esquema · claves RSA + cola de entrega + actividades salientes
// ---------------------------------------------------------------------------
function kp_ap_ensure_schema(): void {
  $pdo = kp_db();
  $pdo->exec("CREATE TABLE IF NOT EXISTS ap_keys (
    podcast_id  INTEGER PRIMARY KEY REFERENCES podcasts(id) ON DELETE CASCADE,
    public_key  TEXT NOT NULL,
    private_key TEXT NOT NULL,
    created_at  TEXT NOT NULL DEFAULT (datetime('now'))
  )");
  $pdo->exec("CREATE TABLE IF NOT EXISTS ap_outbox (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    podcast_id  INTEGER NOT NULL REFERENCES podcasts(id) ON DELETE CASCADE,
    activity_id TEXT NOT NULL UNIQUE,
    type        TEXT NOT NULL,
    object_json TEXT NOT NULL,
    published   TEXT NOT NULL DEFAULT (datetime('now'))
  )");
  $pdo->exec("CREATE TABLE IF NOT EXISTS ap_delivery (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    outbox_id   INTEGER NOT NULL REFERENCES ap_outbox(id) ON DELETE CASCADE,
    inbox       TEXT NOT NULL,
    status      TEXT NOT NULL DEFAULT 'pending',
    attempts    INTEGER NOT NULL DEFAULT 0,
    last_error  TEXT,
    next_try    TEXT NOT NULL DEFAULT (datetime('now')),
    delivered_at TEXT
  )");
  $pdo->exec("CREATE TABLE IF NOT EXISTS ap_likes (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    podcast_id  INTEGER NOT NULL REFERENCES podcasts(id) ON DELETE CASCADE,
    episode_id  INTEGER NOT NULL REFERENCES episodes(id) ON DELETE CASCADE,
    actor_url   TEXT NOT NULL,
    actor_name  TEXT,
    actor_handle TEXT,
    actor_avatar TEXT,
    created_at  TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE(episode_id, actor_url)
  )");
}
kp_ap_ensure_schema();

function kp_ap_base(): string {
  $domain = kp_setting('instance_domain', $_SERVER['HTTP_HOST'] ?? 'www.tupodcast.com');
  $proto = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'https'; // Forzamos https en producción
  return "$proto://$domain";
}

function kp_ap_keypair(int $podcastId): array {
  $row = kp_one("SELECT * FROM ap_keys WHERE podcast_id = ?", [$podcastId]);
  if ($row) return $row;
  $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
  openssl_pkey_export($res, $priv);
  $pub = openssl_pkey_get_details($res)['key'];
  kp_exec("INSERT INTO ap_keys (podcast_id,public_key,private_key) VALUES (?,?,?)",
    [$podcastId, $pub, $priv]);
  return ['public_key' => $pub, 'private_key' => $priv];
}

function kp_ap_json(array $payload, string $type = 'application/activity+json'): void {
  header("Content-Type: $type; charset=utf-8");
  echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

// ---------------------------------------------------------------------------
// Webfinger · resuelve acct:slug@host → actor URL
// ---------------------------------------------------------------------------
function kp_ap_webfinger(): void {
  $r = $_GET['resource'] ?? '';
  if (!preg_match('/^acct:([^@]+)@(.+)$/', $r, $m)) { http_response_code(400); exit; }
  $slug = strtolower($m[1]);
  $p = kp_one("SELECT * FROM podcasts WHERE slug = ? OR fediverse_handle = ?", [$slug, $slug]);
  if (!$p || !$p['federate']) { http_response_code(404); exit; }
  $actor = kp_ap_base() . "/users/" . $p['slug'];
  kp_ap_json([
    'subject' => "acct:$slug@" . kp_handle_domain(),
    'aliases' => [$actor],
    'links' => [
      ['rel' => 'self', 'type' => 'application/activity+json', 'href' => $actor],
      ['rel' => 'http://webfinger.net/rel/profile-page', 'type' => 'text/html', 'href' => kp_ap_base() . "/@$slug"],
    ],
  ], 'application/jrd+json');
}

// ---------------------------------------------------------------------------
// Actor · perfil del podcast como Service ActivityPub
// ---------------------------------------------------------------------------
function kp_ap_make_actor_array(array $p): array {
  $slug = $p['slug'];
  $kp = kp_ap_keypair($p['id']);
  $url = kp_ap_base() . "/users/$slug";
  $base = kp_ap_base();
  return [
    '@context' => ['https://www.w3.org/ns/activitystreams', 'https://w3id.org/security/v1'],
    'id' => $url,
    'type' => 'Service',
    'preferredUsername' => $slug,
    'name' => $p['title'],
    'summary' => $p['description'] ?? '',
    'manuallyApprovesFollowers' => false,
    'url' => "$base/@$slug",
    'inbox' => "$url/inbox",
    'outbox' => "$url/outbox",
    'followers' => "$url/followers",
    'icon' => $p['cover'] ? ['type' => 'Image', 'url' => $base . $p['cover']] : null,
    'image' => $p['banner'] ? ['type' => 'Image', 'url' => $base . $p['banner']] : null,
    'attachment' => [
      ['type' => 'PropertyValue', 'name' => 'RSS', 'value' => kp_canonical_feed_url($slug)],
    ],
    'publicKey' => [
      'id' => "$url#main-key",
      'owner' => $url,
      'publicKeyPem' => $kp['public_key'],
    ],
  ];
}

function kp_ap_actor(string $slug): void {
  $p = kp_one("SELECT * FROM podcasts WHERE slug = ? AND federate = 1", [$slug]);
  if (!$p) { http_response_code(404); exit; }
  kp_ap_json(kp_ap_make_actor_array($p));
}

function kp_ap_update_actor(int $podcastId): void {
  $p = kp_one("SELECT * FROM podcasts WHERE id = ?", [$podcastId]);
  if (!$p || !$p['federate']) return;

  $base = kp_ap_base();
  $actorUrl = "$base/users/{$p['slug']}";
  $actId = "$actorUrl/activities/update-" . bin2hex(random_bytes(8));

  $actorObj = kp_ap_make_actor_array($p);

  $activity = [
    '@context' => [
      'https://www.w3.org/ns/activitystreams',
      'https://w3id.org/security/v1'
    ],
    'id' => $actId,
    'type' => 'Update',
    'actor' => $actorUrl,
    'to' => ['https://www.w3.org/ns/activitystreams#Public'],
    'object' => $actorObj,
  ];

  $oid = kp_exec("INSERT INTO ap_outbox (podcast_id,activity_id,type,object_json) VALUES (?,?,?,?)",
    [$p['id'], $actId, 'Update', json_encode($activity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);

  kp_alert('fediverse', 'Perfil actualizado', "El perfil del podcast en el Fediverso se ha encolado para actualización.", "");

  // Encolar entrega a todos los seguidores
  $followers = kp_q("SELECT DISTINCT inbox FROM ap_followers WHERE podcast_id = ? AND inbox != ''", [$p['id']]);
  $ins = kp_db()->prepare("INSERT INTO ap_delivery (outbox_id, inbox) VALUES (?, ?)");
  foreach ($followers as $f) {
      $ins->execute([$oid, $f['inbox']]);
  }
}

// ---------------------------------------------------------------------------
// Outbox · OrderedCollection con la última publicación por episodio
// ---------------------------------------------------------------------------
function kp_ap_outbox(string $slug): void {
  $p = kp_one("SELECT * FROM podcasts WHERE slug = ?", [$slug]);
  if (!$p) { http_response_code(404); exit; }
  $items = kp_q("SELECT * FROM ap_outbox WHERE podcast_id = ? ORDER BY id DESC LIMIT 40", [$p['id']]);
  $total = (int)(kp_one("SELECT count(*) c FROM ap_outbox WHERE podcast_id = ?", [$p['id']])['c'] ?? 0);
  $url = kp_ap_base() . "/users/$slug/outbox";
  kp_ap_json([
    '@context' => 'https://www.w3.org/ns/activitystreams',
    'id' => $url, 'type' => 'OrderedCollection',
    'totalItems' => $total,
    'orderedItems' => array_map(fn($i) => json_decode($i['object_json'], true), $items),
  ]);
}

function kp_ap_followers(string $slug): void {
  $p = kp_one("SELECT * FROM podcasts WHERE slug = ?", [$slug]);
  if (!$p) { http_response_code(404); exit; }
  $rows = kp_q("SELECT actor_url FROM ap_followers WHERE podcast_id = ?", [$p['id']]);
  kp_ap_json([
    '@context' => 'https://www.w3.org/ns/activitystreams',
    'id' => kp_ap_base() . "/users/$slug/followers",
    'type' => 'OrderedCollection',
    'totalItems' => count($rows),
    'orderedItems' => array_column($rows, 'actor_url'),
  ]);
}

// ---------------------------------------------------------------------------
// Inbox · recibe Follow/Undo/Like/Announce de otras instancias
// ---------------------------------------------------------------------------
function kp_ap_inbox(string $slug): void {
  $p = kp_one("SELECT * FROM podcasts WHERE slug = ?", [$slug]);
  if (!$p) { http_response_code(404); exit; }
  $raw = file_get_contents('php://input');
  $a = json_decode($raw, true);
  if (!$a || empty($a['type'])) { http_response_code(400); exit; }

  kp_ap_log("Inbox: Recibido POST para '$slug'. Tipo: " . $a['type'] . " | Actor: " . ($a['actor'] ?? 'desconocido'));

  // Verificar firma HTTP del emisor
  $sigVerified = kp_ap_verify_signature($raw);
  if (!$sigVerified) {
    $actorUrl = is_array($a['actor'] ?? null) ? ($a['actor']['id'] ?? '') : ($a['actor'] ?? '');
    kp_ap_log("Aviso: Firma HTTP no verificada para '$slug'. Tipo: " . ($a['type'] ?? '') . " | Actor: " . $actorUrl);
    
    // Si la firma falla, validar que el actor sea una URL válida
    if (empty($actorUrl) || !filter_var($actorUrl, FILTER_VALIDATE_URL)) {
      if ($a['type'] === 'Delete') {
        $obj = is_array($a['object'] ?? null) ? ($a['object']['id'] ?? '') : ($a['object'] ?? '');
        if ($actorUrl && $obj === $actorUrl) {
          $actorObj = kp_ap_fetch_actor($actorUrl);
          if ($actorObj === null || empty($actorObj['publicKey']) || ($actorObj['type'] ?? '') === 'Tombstone') {
            kp_db()->prepare("DELETE FROM ap_followers WHERE actor_url = ?")->execute([$actorUrl]);
            http_response_code(202);
            exit;
          }
        }
      }
      http_response_code(401);
      exit('signature invalid');
    }
  }

  switch ($a['type']) {
    case 'Follow':
      $actor = is_array($a['actor'] ?? null) ? ($a['actor']['id'] ?? '') : ($a['actor'] ?? '');
      $info = kp_ap_fetch_actor($actor);
      $handle = $info['preferredUsername'] ?? '?';
      $name = $info['name'] ?? $handle;
      $avatar = $info['icon']['url'] ?? '';
      kp_exec("INSERT OR IGNORE INTO ap_followers (podcast_id,actor_url,handle,inbox) VALUES (?,?,?,?)",
        [$p['id'], $actor, $handle, ($info['inbox'] ?? '')]);
      kp_ap_send_accept($p, $a);
      require_once __DIR__ . '/includes/helpers.php';
      kp_alert('fediverse', 'Nuevo seguidor', "@{$handle} ha comenzado a seguir a tu podcast '{$p['title']}'.", $actor, [
        'actor_url' => $actor, 'action_type' => 'Follow', 'actor_avatar' => $avatar, 'actor_name' => $name
      ]);
      break;
    case 'Undo':
      $undoObj = $a['object'] ?? [];
      $undoType = is_array($undoObj) ? ($undoObj['type'] ?? '') : '';
      $actor = is_array($a['actor'] ?? null) ? ($a['actor']['id'] ?? '') : ($a['actor'] ?? '');
      if ($undoType === 'Follow' || (is_string($undoObj) && str_contains($undoObj, 'followers'))) {
        kp_db()->prepare("DELETE FROM ap_followers WHERE podcast_id = ? AND actor_url = ?")
              ->execute([$p['id'], $actor]);
      } elseif ($undoType === 'Like') {
        $likedObj = is_array($undoObj) ? ($undoObj['object'] ?? '') : '';
        $targetLink = is_string($likedObj) ? $likedObj : ($likedObj['id'] ?? $likedObj['url'] ?? '');
        $episodeId = null;
        if ($targetLink) {
          if (preg_match('#/users/([^/]+)/(?:notes|activities)/([^/?#]+)#', $targetLink, $m)) {
            $cleanEp = preg_replace('/-\d+$/', '', $m[2]);
            $epRow = kp_one("SELECT id FROM episodes WHERE podcast_id = ? AND slug = ?", [$p['id'], $cleanEp]);
            if ($epRow) $episodeId = (int)$epRow['id'];
          } elseif (preg_match('#/@([^/]+)/([^/?#]+)#', $targetLink, $m)) {
            $epRow = kp_one("SELECT id FROM episodes WHERE podcast_id = ? AND slug = ?", [$p['id'], $m[2]]);
            if ($epRow) $episodeId = (int)$epRow['id'];
          }
        }
        if ($episodeId) {
          kp_db()->prepare("DELETE FROM ap_likes WHERE episode_id = ? AND actor_url = ?")->execute([$episodeId, $actor]);
        } else {
          kp_db()->prepare("DELETE FROM ap_likes WHERE podcast_id = ? AND actor_url = ?")->execute([$p['id'], $actor]);
        }
        if (file_exists(__DIR__ . '/includes/cache.php')) {
          require_once __DIR__ . '/includes/cache.php';
          kp_cache_clear();
        }
      }
      break;
    case 'Like':
    case 'Announce':
      $actor = is_array($a['actor'] ?? null) ? ($a['actor']['id'] ?? '') : ($a['actor'] ?? '');
      $info = kp_ap_fetch_actor($actor);
      $handle = $info['preferredUsername'] ?? (preg_match('#@([^@/]+)$#', $actor, $hm) ? $hm[1] : (parse_url($actor, PHP_URL_PATH) ? basename(parse_url($actor, PHP_URL_PATH)) : 'user'));
      $name = $info['name'] ?? $handle;
      $avatar = $info['icon']['url'] ?? '';
      require_once __DIR__ . '/includes/helpers.php';
      
      $actionName = $a['type'] === 'Like' ? 'Me Gusta' : 'Boost';
      $bodyMsg = $a['type'] === 'Like' ? "Alguien le ha dado like a una publicación de" : "Alguien ha compartido una publicación de";
      
      $rawObj = $a['object'] ?? '';
      $targetLink = is_string($rawObj) ? $rawObj : ($rawObj['id'] ?? $rawObj['url'] ?? '');
      $episodeId = null;
      if ($targetLink && preg_match('#/users/([^/]+)/(?:notes|activities)/([^/?#]+)#', $targetLink, $m)) {
        $cleanEp = preg_replace('/-\d+$/', '', $m[2]);
        $targetLink = kp_ap_base() . "/@" . $m[1] . "/" . $cleanEp;
        $epRow = kp_one("SELECT id FROM episodes WHERE podcast_id = ? AND slug = ?", [$p['id'], $cleanEp]);
        if ($epRow) $episodeId = (int)$epRow['id'];
      } elseif ($targetLink && preg_match('#/@([^/]+)/([^/?#]+)#', $targetLink, $m)) {
        $epRow = kp_one("SELECT id FROM episodes WHERE podcast_id = ? AND slug = ?", [$p['id'], $m[2]]);
        if ($epRow) $episodeId = (int)$epRow['id'];
      }

      // Si no se resolvió por URL directa, buscar en el outbox si el objeto coincide con una actividad o nota publicada
      if (!$episodeId && $targetLink) {
        $outboxRow = kp_one("SELECT object_json FROM ap_outbox WHERE podcast_id = ? AND (activity_id = ? OR object_json LIKE ?) ORDER BY id DESC LIMIT 1",
          [$p['id'], $targetLink, '%' . $targetLink . '%']);
        if ($outboxRow) {
          $outJson = json_decode($outboxRow['object_json'] ?? '', true);
          $noteUrl = $outJson['object']['url'] ?? $outJson['url'] ?? '';
          if ($noteUrl && preg_match('#/@([^/]+)/([^/?#]+)#', $noteUrl, $nm)) {
            $epRow = kp_one("SELECT id FROM episodes WHERE podcast_id = ? AND slug = ?", [$p['id'], $nm[2]]);
            if ($epRow) {
              $episodeId = (int)$epRow['id'];
              $targetLink = $noteUrl;
            }
          }
        }
      }

      // Si aún no se resolvió, asociar al último episodio publicado del podcast
      if (!$episodeId) {
        $latestEp = kp_one("SELECT id, slug FROM episodes WHERE podcast_id = ? AND status = 'published' ORDER BY id DESC LIMIT 1", [$p['id']]);
        if ($latestEp) {
          $episodeId = (int)$latestEp['id'];
          if (!$targetLink) {
            $targetLink = kp_ap_base() . "/@" . $p['slug'] . "/" . $latestEp['slug'];
          }
        }
      }

      if ($a['type'] === 'Like' && $episodeId) {
        try {
          kp_exec("INSERT OR REPLACE INTO ap_likes (podcast_id, episode_id, actor_url, actor_name, actor_handle, actor_avatar, created_at) VALUES (?, ?, ?, ?, ?, ?, datetime('now'))",
            [$p['id'], $episodeId, $actor, $name, $handle, $avatar]);
          
          if (file_exists(__DIR__ . '/includes/cache.php')) {
            require_once __DIR__ . '/includes/cache.php';
            kp_cache_clear();
          }
        } catch (Throwable $e) {
          kp_ap_log("Error guardando Like en ap_likes: " . $e->getMessage());
        }
      }

      kp_alert('fediverse', "Nuevo $actionName", "$bodyMsg '{$p['title']}'.", $targetLink, [
        'actor_url' => $actor, 'action_type' => $a['type'], 'actor_avatar' => $avatar, 'actor_name' => $name
      ]);
      break;
    case 'Create':
      if (($a['object']['type'] ?? '') === 'Note') {
        require_once __DIR__ . '/includes/helpers.php';
        $actor = $a['actor'];
        $info = kp_ap_fetch_actor($actor);
        $handle = $info['preferredUsername'] ?? '?';
        $name = $info['name'] ?? $handle;
        $avatar = $info['icon']['url'] ?? '';
        $objUrl = $a['object']['id'] ?? $a['object']['url'] ?? '';
        kp_alert('fediverse', 'Nuevo Comentario', "@{$handle} ha comentado en tu podcast.", $objUrl, [
          'actor_url' => $actor, 'action_type' => 'Reply', 'actor_avatar' => $avatar, 'actor_name' => $name
        ]);
        kp_ap_dm_admin($p, $objUrl, $handle);
      }
      break;
    case 'Delete':
      // Borrado remoto del actor: limpiar followers (solo si el objeto borrado es el actor)
      $obj = is_array($a['object'] ?? null) ? ($a['object']['id'] ?? '') : ($a['object'] ?? '');
      if ($obj === $a['actor']) {
        kp_db()->prepare("DELETE FROM ap_followers WHERE actor_url = ?")->execute([$a['actor']]);
      }
      break;
  }
  http_response_code(202);
}

// ---------------------------------------------------------------------------
// Publicar una nota nueva cuando un episodio se publica
// (llamar desde new-episode.php tras INSERT con status='published')
// ---------------------------------------------------------------------------
function kp_ap_publish_episode(int $episodeId, bool $resend = false): void {
  $e = kp_one("SELECT * FROM episodes WHERE id = ?", [$episodeId]);
  if (!$e) return;
  $p = kp_one("SELECT * FROM podcasts WHERE id = ?", [$e['podcast_id']]);
  if (!$p || !$p['federate']) return;

  $base = kp_ap_base();
  $actor = "$base/users/{$p['slug']}";
  $suffix = $resend ? '-' . time() : '';
  $noteId = "$actor/notes/{$e['slug']}$suffix";
  $actId = "$actor/activities/{$e['slug']}$suffix";

  // Evitar duplicados en el outbox si ya fue publicado/federado anteriormente
  $existing = kp_one("SELECT id FROM ap_outbox WHERE activity_id = ? LIMIT 1", [$actId]);
  if ($existing) return;

  $notes_md = $e['notes_md'] ?? '';
  $truncated_md = mb_substr($notes_md, 0, 400);
  if (mb_strlen($notes_md) > 400) {
      $truncated_md .= '...';
  }
  $notes_html = kp_md_to_html($truncated_md);

  $note = [
    'id' => $noteId, 'type' => 'Note',
    'attributedTo' => $actor,
    'to' => ['https://www.w3.org/ns/activitystreams#Public'],
    'cc' => ["$actor/followers"],
    'published' => gmdate('Y-m-d\TH:i:s\Z', strtotime($e['published_at'] ?: ($e['publish_at'] ?: ($e['created_at'] ?? 'now')))),
    'url' => "$base/@{$p['slug']}/{$e['slug']}",
    'content' => "<p><strong>" . htmlspecialchars($e['title']) . "</strong></p>"
                 . $notes_html
                 . "<p><a href=\"$base/@{$p['slug']}/{$e['slug']}\">Escuchar episodio</a></p>",
    'attachment' => $e['audio_url'] ? [[
      'type' => 'Audio',
      'mediaType' => $e['audio_mime'] ?: 'audio/mpeg',
      'url' => $base . $e['audio_url'],
      'name' => $e['title'],
    ]] : [],
  ];
  $activity = [
    '@context' => 'https://www.w3.org/ns/activitystreams',
    'id' => $actId, 'type' => 'Create',
    'actor' => $actor,
    'published' => $note['published'],
    'to' => $note['to'], 'cc' => $note['cc'],
    'object' => $note,
  ];

  try {
    $oid = kp_exec("INSERT INTO ap_outbox (podcast_id,activity_id,type,object_json) VALUES (?,?,?,?)",
      [$p['id'], $actId, 'Create', json_encode($activity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
  } catch (Throwable $e) {
    if (strpos($e->getMessage(), 'UNIQUE constraint failed') !== false) {
      return;
    }
    throw $e;
  }
  
  if ($resend) {
    kp_alert('fediverse', 'Episodio re-enviado al Fediverso', "El episodio '{$e['title']}' se ha vuelto a encolar para entrega.", "");
  } else {
    kp_alert('fediverse', 'Episodio publicado en Fediverso', "El episodio '{$e['title']}' se ha encolado para entrega.", "");
  }

  // Encolar entrega a cada follower
  $followers = kp_q("SELECT DISTINCT inbox FROM ap_followers WHERE podcast_id = ? AND inbox != ''", [$p['id']]);
  
  $ins = kp_db()->prepare("INSERT INTO ap_delivery (outbox_id, inbox) VALUES (?, ?)");
  foreach ($followers as $f) {
      $ins->execute([$oid, $f['inbox']]);
  }
}

// ---------------------------------------------------------------------------
// Eliminar un episodio publicado del Fediverso
// ---------------------------------------------------------------------------
function kp_ap_delete_episode(int $episodeId): void {
  $e = kp_one("SELECT * FROM episodes WHERE id = ?", [$episodeId]);
  if (!$e) return;
  $p = kp_one("SELECT * FROM podcasts WHERE id = ?", [$e['podcast_id']]);
  if (!$p || !$p['federate']) return;

  $base = kp_ap_base();
  // Buscar en el outbox la actividad 'Create' para este episodio
  $searchUrl = "$base/@{$p['slug']}/{$e['slug']}";
  $outboxItems = kp_q("SELECT * FROM ap_outbox WHERE podcast_id = ? AND type = 'Create' ORDER BY id DESC", [$p['id']]);
  
  $noteIdToDelete = null;
  foreach ($outboxItems as $item) {
    if (strpos($item['object_json'], $searchUrl) !== false) {
      $obj = json_decode($item['object_json'], true);
      $noteIdToDelete = $obj['object']['id'] ?? null;
      break;
    }
  }

  if (!$noteIdToDelete) return; // No se encontró o ya se borró

  $actor = "$base/users/{$p['slug']}";
  $actId = "$actor/activities/" . bin2hex(random_bytes(8));

  $activity = [
    '@context' => 'https://www.w3.org/ns/activitystreams',
    'id' => $actId,
    'type' => 'Delete',
    'actor' => $actor,
    'to' => ['https://www.w3.org/ns/activitystreams#Public'],
    'object' => $noteIdToDelete
  ];

  $oid = kp_exec("INSERT INTO ap_outbox (podcast_id,activity_id,type,object_json) VALUES (?,?,?,?)",
    [$p['id'], $actId, 'Delete', json_encode($activity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);

  $followers = kp_q("SELECT DISTINCT inbox FROM ap_followers WHERE podcast_id = ? AND inbox != ''", [$p['id']]);
  $ins = kp_db()->prepare("INSERT INTO ap_delivery (outbox_id, inbox) VALUES (?, ?)");
  foreach ($followers as $f) {
      $ins->execute([$oid, $f['inbox']]);
  }
}

// ---------------------------------------------------------------------------
// Worker de entrega · drena ap_delivery con backoff exponencial
// Invocar desde cron: php cli/ap-deliver.php
// ---------------------------------------------------------------------------
function kp_ap_deliver_pending(int $batch = 50): int {
  $rows = kp_q("SELECT * FROM ap_delivery 
                WHERE status = 'pending' AND next_try <= datetime('now', '+1 minute')
                ORDER BY id LIMIT ?", [$batch]);
  $sent = 0;
  foreach ($rows as $d) {
    try {
      $outbox = kp_one("SELECT podcast_id, object_json FROM ap_outbox WHERE id = ?", [$d['outbox_id']]);
      if (!$outbox) {
          kp_db()->prepare("UPDATE ap_delivery SET status='failed', last_error='Outbox item missing' WHERE id = ?")->execute([$d['id']]);
          continue;
      }

      kp_ap_post_signed($d['inbox'], $outbox['object_json'], (int)$outbox['podcast_id']);
      kp_db()->prepare("UPDATE ap_delivery SET status='delivered', delivered_at=datetime('now') WHERE id = ?")
            ->execute([$d['id']]);
      $sent++;
    } catch (Throwable $e) {
      $attempts = $d['attempts'] + 1;
      $backoff = min(86400, pow(2, $attempts) * 60); // hasta 1 día
      $next = gmdate('Y-m-d H:i:s', time() + $backoff);
      $status = $attempts >= 8 ? 'failed' : 'pending';
      kp_db()->prepare("UPDATE ap_delivery SET attempts=?, last_error=?, next_try=?, status=? WHERE id = ?")
            ->execute([$attempts, $e->getMessage(), $next, $status, $d['id']]);
    }
  }
  return $sent;
}

// ---------------------------------------------------------------------------
// HTTP Signatures (draft-cavage-12) · entregar firmado
// ---------------------------------------------------------------------------
function kp_ap_post_signed(string $url, string $body, int $podcastId): void {
  kp_ap_log("Entrega: Intentando enviar POST firmado a $url");
  $p = kp_one("SELECT slug FROM podcasts WHERE id = ?", [$podcastId]);
  $kp = kp_ap_keypair($podcastId);
  $u = parse_url($url);
  $date = gmdate('D, d M Y H:i:s') . ' GMT';
  $digest = 'SHA-256=' . base64_encode(hash('sha256', $body, true));
  $path = empty($u['path']) ? '/' : $u['path'];
  if (!empty($u['query'])) $path .= '?' . $u['query'];
  $signingString = "(request-target): post {$path}\nhost: {$u['host']}\ndate: $date\ndigest: $digest";
  openssl_sign($signingString, $sig, $kp['private_key'], OPENSSL_ALGO_SHA256);
  $keyId = kp_ap_base() . "/users/{$p['slug']}#main-key";
  $sigHeader = sprintf(
    'keyId="%s",algorithm="rsa-sha256",headers="(request-target) host date digest",signature="%s"',
    $keyId, base64_encode($sig)
  );

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_CUSTOMREQUEST => 'POST',
    CURLOPT_POSTFIELDS => $body,
    CURLOPT_HTTPHEADER => [
      "Host: {$u['host']}",
      "Date: $date",
      "Digest: $digest",
      "Signature: $sigHeader",
      "Content-Type: application/activity+json",
      "Accept: application/activity+json",
    ],
    CURLOPT_USERAGENT => 'KutPod/1.0 (+' . kp_ap_base() . ')',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_FAILONERROR => true,
  ]);
  $resp = curl_exec($ch);
  $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err = curl_error($ch);
  curl_close($ch);
  if ($http >= 400 || $resp === false) {
    kp_ap_log("Entrega fallida a $url: HTTP $http $err. Respuesta: " . substr((string)$resp, 0, 300));
    throw new RuntimeException("HTTP $http $err");
  }
  kp_ap_log("Entrega exitosa a $url: HTTP $http");
}

function kp_ap_log(string $msg): void {
  $file = __DIR__ . '/storage/activitypub.log';
  $date = date('Y-m-d H:i:s');
  @file_put_contents($file, "[$date] $msg\n", FILE_APPEND);
}

function kp_ap_verify_signature(string $body): bool {
  $sig = $_SERVER['HTTP_SIGNATURE'] ?? '';
  if (!$sig && function_exists('getallheaders')) {
    $headers = getallheaders();
    foreach ($headers as $k => $v) {
      if (strtolower($k) === 'signature') {
        $sig = $v;
        break;
      }
    }
  }
  if (!$sig) {
    kp_ap_log("Firma fallida: Cabecera 'Signature' no encontrada. User-Agent: " . ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    return false;
  }
  $parts = [];
  foreach (explode(',', $sig) as $kv) {
    if (preg_match('/(\w+)="([^"]*)"/', trim($kv), $m)) $parts[$m[1]] = $m[2];
  }
  if (empty($parts['keyId']) || empty($parts['signature']) || empty($parts['headers'])) {
    kp_ap_log("Firma fallida: Componentes incompletos en cabecera: " . $sig);
    return false;
  }
  
  $actorUrl = preg_replace('/#.*/', '', $parts['keyId']);
  $actor = kp_ap_fetch_actor($actorUrl);
  if (empty($actor)) {
    kp_ap_log("Firma fallida: No se pudo obtener el actor desde $actorUrl");
    return false;
  }
  
  $pubKeyPem = $actor['publicKey']['publicKeyPem'] ?? $actor['publicKeyPem'] ?? '';
  if (empty($pubKeyPem) && !empty($actor['owner'])) {
    $ownerActor = kp_ap_fetch_actor($actor['owner']);
    $pubKeyPem = $ownerActor['publicKey']['publicKeyPem'] ?? $ownerActor['publicKeyPem'] ?? '';
  }
  if (empty($pubKeyPem)) {
    kp_ap_log("Firma fallida: El actor no expone una publicKeyPem en su perfil. Actor URL: $actorUrl");
    return false;
  }
  
  $signingString = [];
  foreach (explode(' ', $parts['headers']) as $h) {
    $h = strtolower(trim($h));
    if ($h === '(request-target)') {
      $method = strtolower($_SERVER['REQUEST_METHOD']);
      $uri = $_SERVER['REQUEST_URI'] ?? '/';
      $signingString[] = "(request-target): $method " . (parse_url($uri, PHP_URL_PATH) ?: '/');
    } elseif ($h === 'content-type') {
      $val = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
      $signingString[] = "$h: $val";
    } elseif ($h === 'content-length') {
      $val = $_SERVER['CONTENT_LENGTH'] ?? $_SERVER['HTTP_CONTENT_LENGTH'] ?? '';
      $signingString[] = "$h: $val";
    } else {
      $headerKey = 'HTTP_' . strtoupper(str_replace('-', '_', $h));
      $val = $_SERVER[$headerKey] ?? '';
      if (!$val && function_exists('getallheaders')) {
        $headers = getallheaders();
        foreach ($headers as $k => $v) {
          if (strtolower($k) === $h) {
            $val = $v;
            break;
          }
        }
      }
      $signingString[] = "$h: $val";
    }
  }
  
  $ok = openssl_verify(implode("\n", $signingString), base64_decode($parts['signature']),
    $pubKeyPem, OPENSSL_ALGO_SHA256);
  if ($ok !== 1) {
    kp_ap_log("Firma fallida: openssl_verify devolvió $ok. String firmado:\n" . implode("\n", $signingString) . "\nClave ID: " . $parts['keyId']);
    return false;
  }
  
  kp_ap_log("Firma válida para keyId: " . $parts['keyId']);
  return true;
}

function kp_ap_fetch_actor(string $url): ?array {
  if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) return null;
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS => 5,
    CURLOPT_HTTPHEADER => [
      'Accept: application/activity+json, application/ld+json; profile="https://www.w3.org/ns/activitystreams"'
    ],
    CURLOPT_USERAGENT => 'KutPod/1.0 (+' . kp_ap_base() . ')',
    CURLOPT_TIMEOUT => 10,
  ]);
  $r = curl_exec($ch); curl_close($ch);
  return $r ? json_decode($r, true) : null;
}

function kp_ap_resolve_webfinger(string $acct): ?array {
  $acct = ltrim(trim($acct), '@');
  $parts = explode('@', $acct);
  if (count($parts) !== 2) return null;
  $host = $parts[1];
  $url = "https://$host/.well-known/webfinger?resource=acct:$acct";
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Accept: application/jrd+json, application/json'],
    CURLOPT_USERAGENT => 'KutPod/1.0 (+' . kp_ap_base() . ')',
    CURLOPT_TIMEOUT => 10,
  ]);
  $r = curl_exec($ch); curl_close($ch);
  $res = $r ? json_decode($r, true) : null;
  
  if (!$res || empty($res['links'])) return null;
  foreach ($res['links'] as $l) {
    if ($l['rel'] === 'self' && $l['type'] === 'application/activity+json') {
      return kp_ap_fetch_actor($l['href']);
    }
  }
  return null;
}

function kp_ap_send_accept(array $p, array $follow): void {
  $base = kp_ap_base();
  $actor = "$base/users/{$p['slug']}";
  $accept = [
    '@context' => 'https://www.w3.org/ns/activitystreams',
    'id' => "$actor/accepts/" . bin2hex(random_bytes(8)),
    'type' => 'Accept', 'actor' => $actor, 'object' => $follow,
  ];
  $inbox = kp_ap_fetch_actor($follow['actor'])['inbox'] ?? null;
  if ($inbox) {
    try {
      $oid = kp_exec("INSERT INTO ap_outbox (podcast_id,activity_id,type,object_json) VALUES (?,?,?,?)", [$p['id'], $accept['id'], 'Accept', json_encode($accept, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
      kp_db()->prepare("INSERT INTO ap_delivery (outbox_id, inbox) VALUES (?, ?)")->execute([$oid, $inbox]);
    } catch (Throwable $e) {}
  }
}

function kp_ap_dm_admin(array $p, string $commentUrl, string $authorHandle): void {
  require_once __DIR__ . '/includes/helpers.php';
  $adminActor = kp_setting('admin_mastodon_actor');
  $adminInbox = kp_setting('admin_mastodon_inbox');
  if (!$adminActor || !$adminInbox) return;

  $base = kp_ap_base();
  $actor = "$base/users/{$p['slug']}";
  $noteId = "$actor/notes/dm_" . bin2hex(random_bytes(8));
  $actId = "$actor/activities/dm_" . bin2hex(random_bytes(8));

  $note = [
    'id' => $noteId, 'type' => 'Note',
    'attributedTo' => $actor,
    'to' => [$adminActor], // Direct message (Solo al admin)
    'cc' => [],
    'published' => gmdate('c'),
    'content' => "<p><span class=\"h-card\" translate=\"no\"><a href=\"{$adminActor}\" class=\"u-url mention\">@<span>admin</span></a></span> Tienes un nuevo comentario de <strong>@{$authorHandle}</strong> en tu podcast.</p><p><a href=\"{$commentUrl}\">Ver comentario original</a></p>",
    'tag' => [
      [
        'type' => 'Mention',
        'href' => $adminActor,
        'name' => '@admin' // Mastodon looks for this to correctly link the mention
      ]
    ]
  ];

  $activity = [
    '@context' => 'https://www.w3.org/ns/activitystreams',
    'id' => $actId, 'type' => 'Create',
    'actor' => $actor,
    'published' => $note['published'],
    'to' => $note['to'], 'cc' => $note['cc'],
    'object' => $note,
  ];

  $oid = kp_exec("INSERT INTO ap_outbox (podcast_id,activity_id,type,object_json) VALUES (?,?,?,?)",
    [$p['id'], $actId, 'Create', json_encode($activity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
    
  kp_db()->prepare("INSERT INTO ap_delivery (outbox_id, inbox) VALUES (?, ?)")->execute([$oid, $adminInbox]);
}

// ---------------------------------------------------------------------------
// Note · Permite a Mastodon consultar la nota original por ID
// ---------------------------------------------------------------------------
function kp_ap_note(string $slug, string $epSlug): void {
  $originalEpSlug = preg_replace('/-\d+$/', '', $epSlug);
  $p = kp_one("SELECT * FROM podcasts WHERE slug = ?", [$slug]);
  $e = kp_one("SELECT * FROM episodes WHERE podcast_id = ? AND slug = ?", [$p['id'] ?? 0, $originalEpSlug]);
  if (!$p || !$e) { http_response_code(404); exit; }
  
  $base = kp_ap_base();
  $actor = "$base/users/{$p['slug']}";
  $noteId = "$actor/notes/{$epSlug}";
  
  $notes_md = $e['notes_md'] ?? '';
  $truncated_md = mb_substr($notes_md, 0, 400);
  if (mb_strlen($notes_md) > 400) {
      $truncated_md .= '...';
  }
  $notes_html = kp_md_to_html($truncated_md);

  $note = [
    '@context' => 'https://www.w3.org/ns/activitystreams',
    'id' => $noteId, 'type' => 'Note',
    'attributedTo' => $actor,
    'to' => ['https://www.w3.org/ns/activitystreams#Public'],
    'cc' => ["$actor/followers"],
    'published' => gmdate('Y-m-d\TH:i:s\Z', strtotime($e['published_at'] ?: 'now')),
    'url' => "$base/@{$p['slug']}/{$e['slug']}",
    'content' => "<p><strong>" . htmlspecialchars($e['title']) . "</strong></p>"
                 . $notes_html
                 . "<p><a href=\"$base/@{$p['slug']}/{$e['slug']}\">Escuchar episodio</a></p>",
    'attachment' => $e['audio_url'] ? [[
      'type' => 'Audio',
      'mediaType' => $e['audio_mime'] ?: 'audio/mpeg',
      'url' => $base . $e['audio_url'],
      'name' => $e['title'],
    ]] : [],
  ];
  kp_ap_json($note);
}

// ---------------------------------------------------------------------------
// Obtener likes del Fediverso para un episodio (con sincronización retroactiva y avatares)
// ---------------------------------------------------------------------------
function kp_ap_get_episode_likes($episodeId): array {
  static $cache = [];
  $cacheKey = (string)$episodeId;
  if (isset($cache[$cacheKey])) {
    return $cache[$cacheKey];
  }
  
  $ep = null;
  if (is_numeric($episodeId) && (int)$episodeId > 0) {
    $ep = kp_one("SELECT id, slug, title, podcast_id FROM episodes WHERE id = ?", [(int)$episodeId]);
  }
  if (!$ep && !empty($episodeId)) {
    $ep = kp_one("SELECT id, slug, title, podcast_id FROM episodes WHERE slug = ? LIMIT 1", [(string)$episodeId]);
  }
  if (!$ep) {
    return [];
  }
  $numericEpId = (int)$ep['id'];

  $rows = kp_q("SELECT * FROM ap_likes WHERE episode_id = ? ORDER BY id DESC", [$numericEpId]) ?: [];
  
  // Sincronizar con alertas históricas de tipo 'Like' si es necesario
  $p = kp_one("SELECT id, slug, title FROM podcasts WHERE id = ?", [$ep['podcast_id']]);
  $podcastId = (int)($p['id'] ?? 0);
  $epSlug = strtolower(trim($ep['slug'] ?? ''));
  $epTitle = strtolower(trim($ep['title'] ?? ''));
  $pTitle = strtolower(trim($p['title'] ?? ''));

  // Buscar posibles alertas de me gusta en el sistema
  $alertLikes = kp_q("SELECT * FROM alerts WHERE (action_type = 'Like' OR action_type = 'like' OR title LIKE '%Me Gusta%' OR title LIKE '%like%' OR body LIKE '%like%') ORDER BY id DESC") ?: [];

  if (!empty($alertLikes)) {
    $ins = kp_db()->prepare("INSERT OR IGNORE INTO ap_likes (podcast_id, episode_id, actor_url, actor_name, actor_handle, actor_avatar, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $newFound = false;

    foreach ($alertLikes as $al) {
      $actorUrl = trim($al['actor_url'] ?? '');
      if (!$actorUrl) continue;

      $lnk = strtolower(urldecode($al['link'] ?? ''));
      $matched = false;

      // 1. Coincidencia directa por slug en el link (/slug, /notes/slug, /@show/slug)
      if ($epSlug !== '') {
        if (str_contains($lnk, '/' . $epSlug) || str_contains($lnk, 'notes/' . $epSlug) || str_contains($lnk, $epSlug)) {
          $matched = true;
        }
      }

      // 2. Coincidencia por regex en URLs ActivityPub (/notes/cleanSlug o /@show/cleanSlug)
      if (!$matched && preg_match('#/(?:notes|@[^/]+)/([^/?#]+)#', $lnk, $nm)) {
        $cleanLinkEp = preg_replace('/-\d+$/', '', $nm[1]);
        if ($cleanLinkEp === $epSlug) {
          $matched = true;
        }
      }

      // 3. Coincidencia por título del episodio en el texto de la alerta
      if (!$matched && $epTitle !== '') {
        $alText = strtolower(($al['title'] ?? '') . ' ' . ($al['body'] ?? ''));
        if (str_contains($alText, $epTitle)) {
          $matched = true;
        }
      }

      // 4. Coincidencia por título del podcast si este es el episodio más reciente o único
      if (!$matched && $pTitle !== '') {
        $alText = strtolower(($al['title'] ?? '') . ' ' . ($al['body'] ?? ''));
        if (str_contains($alText, $pTitle)) {
          $epCount = (int)(kp_one("SELECT count(*) c FROM episodes WHERE podcast_id = ?", [$podcastId])['c'] ?? 0);
          if ($epCount === 1) {
            $matched = true;
          } else {
            // Si la alerta contiene el enlace a este episodio o es el último publicado
            $latestEp = kp_one("SELECT id FROM episodes WHERE podcast_id = ? ORDER BY id DESC LIMIT 1", [$podcastId]);
            if ($latestEp && (int)$latestEp['id'] === $numericEpId) {
              $matched = true;
            }
          }
        }
      }

      if ($matched) {
        $actorName = $al['actor_name'] ?: 'Usuario del Fediverso';
        $handle = preg_match('#@([^@/]+)$#', $actorUrl, $hm) ? $hm[1] : (parse_url($actorUrl, PHP_URL_PATH) ? basename(parse_url($actorUrl, PHP_URL_PATH)) : 'user');
        $avatar = $al['actor_avatar'] ?? '';

        if (empty($avatar) && function_exists('kp_ap_fetch_actor')) {
          try {
            $info = kp_ap_fetch_actor($actorUrl);
            if (!empty($info['icon']['url'])) {
              $avatar = $info['icon']['url'];
            }
            if (!empty($info['name'])) {
              $actorName = $info['name'];
            }
            if (!empty($info['preferredUsername'])) {
              $handle = $info['preferredUsername'];
            }
          } catch (Throwable $e) {}
        }

        $ins->execute([$podcastId, $ep['id'], $actorUrl, $actorName, $handle, $avatar, $al['created_at'] ?? date('Y-m-d H:i:s')]);
        $newFound = true;
      }
    }

    if ($newFound || empty($rows)) {
      $rows = kp_q("SELECT * FROM ap_likes WHERE episode_id = ? ORDER BY id DESC", [$numericEpId]) ?: [];
    }
  }

  // Asegurar que los avatares existentes se resuelvan si estaban vacíos
  foreach ($rows as &$r) {
    if (empty($r['actor_avatar']) && !empty($r['actor_url']) && function_exists('kp_ap_fetch_actor')) {
      try {
        $info = kp_ap_fetch_actor($r['actor_url']);
        if (!empty($info['icon']['url'])) {
          $r['actor_avatar'] = $info['icon']['url'];
          kp_db()->prepare("UPDATE ap_likes SET actor_avatar = ? WHERE id = ?")->execute([$r['actor_avatar'], $r['id']]);
        }
        if (!empty($info['name']) && empty($r['actor_name'])) {
          $r['actor_name'] = $info['name'];
          kp_db()->prepare("UPDATE ap_likes SET actor_name = ? WHERE id = ?")->execute([$r['actor_name'], $r['id']]);
        }
      } catch (Throwable $e) {}
    }
  }
  unset($r);

  $cache[$cacheKey] = $rows;
  return $rows;
}

// ---------------------------------------------------------------------------
// Mini-router (solo si se ejecuta directamente)
// ---------------------------------------------------------------------------
if ((isset($_SERVER['SCRIPT_FILENAME']) && realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'])) || isset($_GET['ap'])) {
  $ap = $_GET['ap'] ?? '';
  $slug = strtolower($_GET['slug'] ?? '');
  
  if (strpos($ap, 'notes/') === 0) {
    $_GET['ep'] = substr($ap, 6);
    $ap = 'note';
  }
  
  $is_get = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET';

  // Negociación de contenido: si la petición proviene de un navegador web humano (pide text/html
  // y no especifica ActivityPub JSON), redirigir a la vista web pública en vez de escupir JSON crudo
  $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
  $wants_html = str_contains($accept, 'text/html');
  $wants_ap = str_contains($accept, 'application/activity+json') || str_contains($accept, 'application/ld+json') || str_contains($accept, 'application/jrd+json');
  
  if ($is_get && $wants_html && !$wants_ap) {
    if ($ap === 'actor' || $ap === 'outbox' || $ap === 'followers') {
      header('Location: ' . kp_ap_base() . '/@' . $slug, true, 302);
      exit;
    }
    if ($ap === 'note') {
      $cleanEp = preg_replace('/-\d+$/', '', $_GET['ep'] ?? '');
      header('Location: ' . kp_ap_base() . '/@' . $slug . '/' . $cleanEp, true, 302);
      exit;
    }
  }

  $cache_key = 'ap_' . $ap . '_' . md5(json_encode($_GET));
  
  if ($is_get && $ap !== 'inbox' && $ap !== '') {
    require_once __DIR__ . '/includes/cache.php';
    $cached = kp_cache_get($cache_key);
    if ($cached) {
      header('Content-Type: ' . ($ap === 'webfinger' ? 'application/jrd+json' : 'application/activity+json') . '; charset=utf-8');
      echo is_array($cached) ? json_encode($cached, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : $cached;
      exit;
    }
    ob_start();
  }

  switch ($ap) {
    case 'webfinger': kp_ap_webfinger(); break;
    case 'actor':     kp_ap_actor($slug); break;
    case 'outbox':    kp_ap_outbox($slug); break;
    case 'inbox':     kp_ap_inbox($slug); break;
    case 'followers': kp_ap_followers($slug); break;
    case 'note':      kp_ap_note($slug, $_GET['ep'] ?? ''); break;
    default: http_response_code(404); exit('AP endpoint desconocido');
  }

  if ($is_get && $ap !== 'inbox' && $ap !== '') {
    $out = ob_get_clean();
    kp_cache_set($cache_key, $out, 600); // 10 minutes TTL
    echo $out;
  }
}
