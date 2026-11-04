<?php
// ============================================================================
// KutPod · conexión SQLite + auto-migración (SIN seed demo)
// ============================================================================
// La BD se crea vacía. El usuario owner y la configuración inicial los crea
// el instalador (cli/install.php). Si alguien abre el panel sin instalar,
// .htaccess redirige a /install primero.

if (!function_exists('kp_db')) {
function kp_db(): PDO {
  static $pdo = null;
  if ($pdo) return $pdo;

  $dir = __DIR__ . '/../storage';
  if (!is_dir($dir)) @mkdir($dir, 0755, true);
  $path = $dir . '/kutpod.db';

  $pdo = new PDO('sqlite:' . $path, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_TIMEOUT => 5,
  ]);
  $pdo->exec('PRAGMA foreign_keys = ON');
  $pdo->exec('PRAGMA journal_mode = WAL');
  $pdo->exec('PRAGMA synchronous = NORMAL');
  $pdo->exec('PRAGMA cache_size = -32000');
  $pdo->exec('PRAGMA temp_store = MEMORY');
  $pdo->exec('PRAGMA mmap_size = 268435456');
  $pdo->exec('PRAGMA busy_timeout = 5000');

  // Schema base si falta. Sin seed: la BD queda limpia.
  $has = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'")->fetch();
  if (!$has) {
    $sqlPath = __DIR__ . '/../storage/schema.sql';
    if (file_exists($sqlPath)) $pdo->exec(file_get_contents($sqlPath));
  }
  
  // Verificar versión de esquema para evitar ejecuciones repetidas e hilos bloqueados en SQLite
  $current_version = 0;
  try {
    $stmt = $pdo->query("SELECT v FROM settings WHERE k = 'db_schema_version' LIMIT 1");
    if ($stmt) {
      $row = $stmt->fetch();
      if ($row && isset($row['v'])) {
        $current_version = (int)trim((string)$row['v'], "'\"");
      }
    }
  } catch (Throwable $e) {
    // La tabla settings o la clave no existen todavía
  }

  if ($current_version < 3) {
    if ($current_version < 2) {
      if ($current_version < 1) {
        // Asegurar que la tabla settings existe para registrar la versión
        try {
          $pdo->exec("CREATE TABLE IF NOT EXISTS settings (k TEXT PRIMARY KEY, v TEXT, updated_at TEXT DEFAULT (datetime('now')))");
        } catch (Throwable $e) {}

        // Auto-migraciones simples
        try { $pdo->exec("ALTER TABLE podcasts ADD COLUMN apple_url TEXT"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE podcasts ADD COLUMN spotify_url TEXT"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE podcasts ADD COLUMN broadcast_links TEXT"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE podcasts ADD COLUMN flarum_tag_slug TEXT"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE podcasts ADD COLUMN flarum_enabled INTEGER NOT NULL DEFAULT 0"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE podcasts ADD COLUMN fixed_notes TEXT"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE podcasts ADD COLUMN guid TEXT"); } catch (Throwable $e) {}
        
        // Migraciones para Personas y Avatares
        try { $pdo->exec("ALTER TABLE users ADD COLUMN url TEXT"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE users ADD COLUMN bio TEXT"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE users ADD COLUMN is_guest INTEGER NOT NULL DEFAULT 0"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE users ADD COLUMN reset_token TEXT"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE users ADD COLUMN reset_expires INTEGER"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE users ADD COLUMN recovery_codes TEXT"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE episodes ADD COLUMN persons_json TEXT"); } catch (Throwable $e) {}
        
        // Migración Fediverso: unificar nombres
        try { $pdo->exec("ALTER TABLE fediverse_followers RENAME TO ap_followers"); } catch (Throwable $e) {}
        
        // Tabla de Alertas (Dashboard) ampliada para Fediverso (estilo Castopod)
        try {
          $pdo->exec("CREATE TABLE IF NOT EXISTS alerts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            kind TEXT NOT NULL,
            title TEXT NOT NULL,
            body TEXT,
            link TEXT,
            actor_url TEXT,
            action_type TEXT,
            actor_avatar TEXT,
            actor_name TEXT,
            created_at TEXT NOT NULL DEFAULT (datetime('now')),
            read_at TEXT
          )");
        } catch (Throwable $e) {}
        
        // Si la tabla ya existía sin las nuevas columnas, añadirlas
        try { $pdo->exec("ALTER TABLE alerts ADD COLUMN actor_url TEXT"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE alerts ADD COLUMN action_type TEXT"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE alerts ADD COLUMN actor_avatar TEXT"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE alerts ADD COLUMN actor_name TEXT"); } catch (Throwable $e) {}
        
        // Tabla de Likes de ActivityPub (Fediverso)
        try {
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
        } catch (Throwable $e) {}
        
        // Migraciones para api_tokens (Kut Editor) - Unificación definitiva
        try {
          $cols = $pdo->query("PRAGMA table_info(api_tokens)")->fetchAll();
          $hasName = false;
          foreach($cols as $c) if ($c['name'] === 'name') $hasName = true;

          if ($hasName) {
            // Reconstruimos para eliminar la restricción NOT NULL de 'name'
            $pdo->exec("CREATE TABLE api_tokens_new (
              id            INTEGER PRIMARY KEY AUTOINCREMENT,
              user_id       INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
              label         TEXT NOT NULL,
              token_hash    TEXT NOT NULL UNIQUE,
              prefix        TEXT,
              last_used_at  INTEGER,
              created_at    INTEGER NOT NULL
            )");
            // Intentar migrar datos existentes
            try {
              $pdo->exec("INSERT INTO api_tokens_new (id, user_id, label, token_hash, created_at)
                          SELECT id, user_id, name, token_hash, strftime('%s', created_at) FROM api_tokens");
            } catch (Throwable $e) {}
            
            $pdo->exec("DROP TABLE api_tokens");
            $pdo->exec("ALTER TABLE api_tokens_new RENAME TO api_tokens");
          }
        } catch (Throwable $e) {}

        // Asegurar columnas si por alguna razón no se hizo la reconstrucción
        try { $pdo->exec("ALTER TABLE api_tokens ADD COLUMN label TEXT"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE api_tokens ADD COLUMN prefix TEXT"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE api_tokens ADD COLUMN last_used_at INTEGER"); } catch (Throwable $e) {}
        
        // Tabla de historial de actualizaciones
        try {
          $pdo->exec("CREATE TABLE IF NOT EXISTS updates (
            id             INTEGER PRIMARY KEY AUTOINCREMENT,
            from_version   TEXT NOT NULL,
            to_version     TEXT NOT NULL,
            status         TEXT NOT NULL DEFAULT 'applied',
            backup_path    TEXT,
            applied_at     TEXT NOT NULL DEFAULT (datetime('now')),
            rolled_back_at TEXT
          )");
        } catch (Throwable $e) {}

        // Guardar versión de esquema en settings
        try {
          $pdo->exec("INSERT INTO settings (k,v) VALUES ('db_schema_version', '1') ON CONFLICT(k) DO UPDATE SET v=excluded.v, updated_at=datetime('now')");
        } catch (Throwable $e) {}
      }

      // Versión 2: Agregar columna flarum_discussion_id en la tabla episodes
      try {
        $pdo->exec("ALTER TABLE episodes ADD COLUMN flarum_discussion_id TEXT");
      } catch (Throwable $e) {}

      // Guardar versión de esquema en settings
      try {
        $pdo->exec("INSERT INTO settings (k,v) VALUES ('db_schema_version', '2') ON CONFLICT(k) DO UPDATE SET v=excluded.v, updated_at=datetime('now')");
      } catch (Throwable $e) {}
    }

    // Versión 3: Agregar columna meta_json en podcasts y episodes
    try {
      $pdo->exec("ALTER TABLE podcasts ADD COLUMN meta_json TEXT");
    } catch (Throwable $e) {}
    try {
      $pdo->exec("ALTER TABLE episodes ADD COLUMN meta_json TEXT");
    } catch (Throwable $e) {}

    // Activar Flarum y Fediverso automáticamente para mantener compatibilidad
    try {
      $active = ['fediverse'];
      $has_flarum = $pdo->query("SELECT v FROM settings WHERE k = 'flarum_url' LIMIT 1")->fetch();
      if ($has_flarum && !empty($has_flarum['v'])) {
        $active[] = 'flarum';
      }
      $pdo->prepare("INSERT INTO settings (k,v) VALUES ('active_plugins', ?) ON CONFLICT(k) DO NOTHING")
          ->execute([json_encode($active)]);
    } catch (Throwable $e) {}

    // Migrar datos de columnas antiguas de Flarum a meta_json
    try {
      $podcasts = $pdo->query("SELECT id, flarum_enabled, flarum_tag_slug, meta_json FROM podcasts")->fetchAll();
      foreach ($podcasts as $pod) {
        if (!empty($pod['flarum_enabled']) || !empty($pod['flarum_tag_slug'])) {
          $meta = !empty($pod['meta_json']) ? json_decode($pod['meta_json'], true) : [];
          $meta['flarum_enabled'] = (int)($pod['flarum_enabled'] ?? 0);
          $meta['flarum_tag_slug'] = $pod['flarum_tag_slug'] ?? '';
          $pdo->prepare("UPDATE podcasts SET meta_json = ? WHERE id = ?")
              ->execute([json_encode($meta, JSON_UNESCAPED_UNICODE), $pod['id']]);
        }
      }
    } catch (Throwable $e) {}

    try {
      $episodes = $pdo->query("SELECT id, flarum_discussion_id, meta_json FROM episodes")->fetchAll();
      foreach ($episodes as $ep) {
        if (!empty($ep['flarum_discussion_id'])) {
          $meta = !empty($ep['meta_json']) ? json_decode($ep['meta_json'], true) : [];
          $meta['flarum_discussion_id'] = $ep['flarum_discussion_id'];
          $pdo->prepare("UPDATE episodes SET meta_json = ? WHERE id = ?")
              ->execute([json_encode($meta, JSON_UNESCAPED_UNICODE), $ep['id']]);
        }
      }
    } catch (Throwable $e) {}

    // Guardar versión de esquema en settings
    try {
      $pdo->exec("INSERT INTO settings (k,v) VALUES ('db_schema_version', '3') ON CONFLICT(k) DO UPDATE SET v=excluded.v, updated_at=datetime('now')");
    } catch (Throwable $e) {}
  }

  if ($current_version < 4) {
    // Versión 4: Agregar columna totp_secret en la tabla users
    try {
      $pdo->exec("ALTER TABLE users ADD COLUMN totp_secret TEXT");
    } catch (Throwable $e) {}

    // Guardar versión de esquema en settings
    try {
      $pdo->exec("INSERT INTO settings (k,v) VALUES ('db_schema_version', '4') ON CONFLICT(k) DO UPDATE SET v=excluded.v, updated_at=datetime('now')");
    } catch (Throwable $e) {}
  }

  if ($current_version < 5) {
    // Versión 5: Agregar columna feed_redirect_slug en la tabla podcasts
    try {
      $pdo->exec("ALTER TABLE podcasts ADD COLUMN feed_redirect_slug TEXT");
    } catch (Throwable $e) {}

    // Guardar versión de esquema en settings
    try {
      $pdo->exec("INSERT INTO settings (k,v) VALUES ('db_schema_version', '5') ON CONFLICT(k) DO UPDATE SET v=excluded.v, updated_at=datetime('now')");
    } catch (Throwable $e) {}
  }

  if ($current_version < 6) {
    // Versión 6: Índices de alto rendimiento para analytics OP3, episodios y alertas
    try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_stats_daily_ep ON op3_stats_daily(episode_id, downloads)"); } catch (Throwable $e) {}
    try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_stats_daily_pod ON op3_stats_daily(podcast_id, date)"); } catch (Throwable $e) {}
    try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_geo_daily_ep ON op3_geo_daily(episode_id, date)"); } catch (Throwable $e) {}
    try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_apps_daily_ep ON op3_apps_daily(episode_id, date)"); } catch (Throwable $e) {}
    try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_episodes_status_pub ON episodes(status, published_at DESC)"); } catch (Throwable $e) {}
    try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_episodes_slug ON episodes(slug)"); } catch (Throwable $e) {}
    try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_alerts_unread ON alerts(read_at)"); } catch (Throwable $e) {}

    // Guardar versión de esquema en settings
    try {
      $pdo->exec("INSERT INTO settings (k,v) VALUES ('db_schema_version', '6') ON CONFLICT(k) DO UPDATE SET v=excluded.v, updated_at=datetime('now')");
    } catch (Throwable $e) {}
  }

  if ($current_version < 7) {
    // Versión 7: Sincronizar published_at en episodios publicados que tienen published_at NULL o vacío
    try {
      $pdo->exec("UPDATE episodes SET published_at = COALESCE(publish_at, created_at, datetime('now')) WHERE status = 'published' AND (published_at IS NULL OR published_at = '')");
    } catch (Throwable $e) {}

    // Guardar versión de esquema en settings
    try {
      $pdo->exec("INSERT INTO settings (k,v) VALUES ('db_schema_version', '7') ON CONFLICT(k) DO UPDATE SET v=excluded.v, updated_at=datetime('now')");
    } catch (Throwable $e) {}
  }

  if ($current_version < 8) {
    // Versión 8: Tabla de likes del Fediverso (ActivityPub)
    try {
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
    } catch (Throwable $e) {}

    // Guardar versión de esquema en settings
    try {
      $pdo->exec("INSERT INTO settings (k,v) VALUES ('db_schema_version', '8') ON CONFLICT(k) DO UPDATE SET v=excluded.v, updated_at=datetime('now')");
    } catch (Throwable $e) {}
  }

  if ($current_version < 9) {
    // Versión 9: Columna license para Podcasting 2.0
    try {
      $pdo->exec("ALTER TABLE podcasts ADD COLUMN license TEXT");
    } catch (Throwable $e) {}

    // Guardar versión de esquema en settings
    try {
      $pdo->exec("INSERT INTO settings (k,v) VALUES ('db_schema_version', '9') ON CONFLICT(k) DO UPDATE SET v=excluded.v, updated_at=datetime('now')");
    } catch (Throwable $e) {}
  }

  if ($current_version < 10) {
    // Versión 10: Columna update_frequency para Podcasting 2.0
    try {
      $pdo->exec("ALTER TABLE podcasts ADD COLUMN update_frequency TEXT");
    } catch (Throwable $e) {}

    // Guardar versión de esquema en settings
    try {
      $pdo->exec("INSERT INTO settings (k,v) VALUES ('db_schema_version', '10') ON CONFLICT(k) DO UPDATE SET v=excluded.v, updated_at=datetime('now')");
    } catch (Throwable $e) {}
  }

  if ($current_version < 11) {
    // Versión 11: Normalización de tipos de alertas (downloads, milestones) e índices de consulta
    try {
      $pdo->exec("UPDATE alerts SET kind = 'milestones' WHERE kind = 'op3' AND (title LIKE '%alcanzadas%' OR title LIKE '%meta%')");
      $pdo->exec("UPDATE alerts SET kind = 'downloads' WHERE kind = 'op3'");
      $pdo->exec("CREATE INDEX IF NOT EXISTS idx_alerts_kind_read ON alerts(kind, read_at)");
    } catch (Throwable $e) {}

    // Guardar versión de esquema en settings
    try {
      $pdo->exec("INSERT INTO settings (k,v) VALUES ('db_schema_version', '11') ON CONFLICT(k) DO UPDATE SET v=excluded.v, updated_at=datetime('now')");
    } catch (Throwable $e) {}
  }
  
  return $pdo;
}
}

if (!function_exists('kp_q')) {
function kp_q(string $sql, array $params = []): array {
  $st = kp_db()->prepare($sql); $st->execute($params);
  return $st->fetchAll();
}
function kp_one(string $sql, array $params = []): ?array {
  $st = kp_db()->prepare($sql); $st->execute($params);
  $r = $st->fetch(); return $r ?: null;
}
function kp_exec(string $sql, array $params = []): int {
  $st = kp_db()->prepare($sql); $st->execute($params);
  return (int) kp_db()->lastInsertId();
}
}
