-- ============================================================================
-- KutPod · esquema SQLite
-- ============================================================================
-- Crear con: sqlite3 php/storage/kutpod.db < php/storage/schema.sql
-- O lo crea db.php automáticamente en el primer arranque.

PRAGMA foreign_keys = ON;
PRAGMA journal_mode = WAL;

-- Usuarios y roles
CREATE TABLE IF NOT EXISTS users (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  email         TEXT NOT NULL UNIQUE,
  name          TEXT NOT NULL,
  password_hash TEXT NOT NULL,
  role          TEXT NOT NULL CHECK(role IN ('owner','admin','editor','author')),
  avatar        TEXT,
  created_at    TEXT NOT NULL DEFAULT (datetime('now')),
  last_login    TEXT
);

CREATE TABLE IF NOT EXISTS sessions (
  token       TEXT PRIMARY KEY,
  user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  ip          TEXT,
  user_agent  TEXT,
  created_at  TEXT NOT NULL DEFAULT (datetime('now')),
  expires_at  TEXT NOT NULL
);

-- Podcasts
CREATE TABLE IF NOT EXISTS podcasts (
  id              INTEGER PRIMARY KEY AUTOINCREMENT,
  slug            TEXT NOT NULL UNIQUE,
  title           TEXT NOT NULL,
  description     TEXT,
  cover           TEXT,
  banner          TEXT,
  color           TEXT,
  language        TEXT NOT NULL DEFAULT 'es',
  category        TEXT,
  subcategory     TEXT,
  type            TEXT NOT NULL DEFAULT 'episodic' CHECK(type IN ('episodic','serial')),
  parental        TEXT NOT NULL DEFAULT 'clean' CHECK(parental IN ('clean','explicit')),
  author          TEXT,
  publisher       TEXT,
  owner_email     TEXT,
  copyright       TEXT,
  license         TEXT,
  update_frequency TEXT,
  remove_email    INTEGER NOT NULL DEFAULT 0,
  fediverse_handle TEXT,
  federate        INTEGER NOT NULL DEFAULT 1,
  premium         INTEGER NOT NULL DEFAULT 0,
  premium_price   REAL,
  premium_provider TEXT,
  guid            TEXT,
  op3             INTEGER NOT NULL DEFAULT 1,
  location_name   TEXT,
  osm_id          TEXT,
  lat             REAL,
  lon             REAL,
  custom_tags     TEXT,
  ownership_txt   TEXT,
  locked          INTEGER NOT NULL DEFAULT 0,
  hidden          INTEGER NOT NULL DEFAULT 0,
  complete        INTEGER NOT NULL DEFAULT 0,
  status          TEXT NOT NULL DEFAULT 'published',
  feed_redirect_slug TEXT,
  meta_json       TEXT,
  created_at      TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at      TEXT NOT NULL DEFAULT (datetime('now'))
);

-- Permisos por podcast
CREATE TABLE IF NOT EXISTS podcast_users (
  podcast_id  INTEGER NOT NULL REFERENCES podcasts(id) ON DELETE CASCADE,
  user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  role        TEXT NOT NULL DEFAULT 'editor',
  PRIMARY KEY (podcast_id, user_id)
);

-- Episodios
CREATE TABLE IF NOT EXISTS episodes (
  id              INTEGER PRIMARY KEY AUTOINCREMENT,
  podcast_id      INTEGER NOT NULL REFERENCES podcasts(id) ON DELETE CASCADE,
  guid            TEXT NOT NULL UNIQUE,
  slug            TEXT NOT NULL,
  title           TEXT NOT NULL,
  season          INTEGER,
  number          INTEGER,
  ep_type         TEXT NOT NULL DEFAULT 'full' CHECK(ep_type IN ('full','trailer','bonus')),
  parental        TEXT NOT NULL DEFAULT 'clean' CHECK(parental IN ('clean','explicit')),
  guest           TEXT,
  notes_md        TEXT,
  audio_url       TEXT,
  audio_bytes     INTEGER,
  audio_mime      TEXT,
  duration_secs   INTEGER,
  cover           TEXT,
  premium         INTEGER NOT NULL DEFAULT 0,
  location_name   TEXT,
  lat             REAL,
  lon             REAL,
  transcript_url  TEXT,
  chapters_url    TEXT,
  meta_json       TEXT,
  custom_tags     TEXT,
  hidden          INTEGER NOT NULL DEFAULT 0,
  status          TEXT NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','scheduled','published','live')),
  publish_at      TEXT,
  published_at    TEXT,
  downloads       INTEGER NOT NULL DEFAULT 0,
  created_by      INTEGER REFERENCES users(id),
  created_at      TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at      TEXT NOT NULL DEFAULT (datetime('now')),
  UNIQUE(podcast_id, slug)
);

CREATE INDEX IF NOT EXISTS idx_ep_podcast ON episodes(podcast_id, status, published_at DESC);

-- Páginas estáticas
CREATE TABLE IF NOT EXISTS pages (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  slug        TEXT NOT NULL UNIQUE,
  title       TEXT NOT NULL,
  blocks_json TEXT NOT NULL DEFAULT '[]',
  published   INTEGER NOT NULL DEFAULT 0,
  updated_at  TEXT NOT NULL DEFAULT (datetime('now'))
);

-- Estadísticas agregadas por día (alimentadas por worker OP3)
CREATE TABLE IF NOT EXISTS op3_stats (
  episode_id  INTEGER NOT NULL REFERENCES episodes(id) ON DELETE CASCADE,
  date        TEXT NOT NULL,
  country     TEXT,
  app         TEXT,
  downloads   INTEGER NOT NULL DEFAULT 0,
  unique_listeners INTEGER NOT NULL DEFAULT 0,
  PRIMARY KEY (episode_id, date, country, app)
);

-- Suscripciones Premium
CREATE TABLE IF NOT EXISTS subscriptions (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  podcast_id    INTEGER NOT NULL REFERENCES podcasts(id) ON DELETE CASCADE,
  email         TEXT NOT NULL,
  token         TEXT NOT NULL UNIQUE,
  provider      TEXT NOT NULL,
  provider_ref  TEXT,
  status        TEXT NOT NULL DEFAULT 'active',
  started_at    TEXT NOT NULL DEFAULT (datetime('now')),
  expires_at    TEXT
);

-- Respaldos
CREATE TABLE IF NOT EXISTS backups (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  podcast_id  INTEGER REFERENCES podcasts(id) ON DELETE SET NULL,
  kind        TEXT NOT NULL,
  path        TEXT NOT NULL,
  bytes       INTEGER NOT NULL DEFAULT 0,
  created_at  TEXT NOT NULL DEFAULT (datetime('now'))
);

-- Federación (seguidores ActivityPub)
CREATE TABLE IF NOT EXISTS fediverse_followers (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  podcast_id  INTEGER NOT NULL REFERENCES podcasts(id) ON DELETE CASCADE,
  actor_url   TEXT NOT NULL,
  handle      TEXT NOT NULL,
  inbox       TEXT,
  followed_at TEXT NOT NULL DEFAULT (datetime('now')),
  UNIQUE(podcast_id, actor_url)
);

CREATE TABLE IF NOT EXISTS fediverse_blocks (
  domain      TEXT PRIMARY KEY,
  reason      TEXT,
  kind        TEXT NOT NULL DEFAULT 'manual',
  created_at  TEXT NOT NULL DEFAULT (datetime('now'))
);

-- API tokens (Kut Editor & terceros)
CREATE TABLE IF NOT EXISTS api_tokens (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id       INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  label         TEXT NOT NULL,
  token_hash    TEXT NOT NULL UNIQUE,
  prefix        TEXT,
  last_used_at  INTEGER,
  created_at    INTEGER NOT NULL
);

-- Índices de alto rendimiento
CREATE INDEX IF NOT EXISTS idx_episodes_podcast ON episodes(podcast_id);
CREATE INDEX IF NOT EXISTS idx_episodes_slug ON episodes(slug);
CREATE INDEX IF NOT EXISTS idx_episodes_status_pub ON episodes(status, published_at DESC);
CREATE INDEX IF NOT EXISTS idx_alerts_unread ON alerts(read_at);
CREATE INDEX IF NOT EXISTS idx_alerts_kind_read ON alerts(kind, read_at);
CREATE INDEX IF NOT EXISTS idx_stats_daily_ep ON op3_stats_daily(episode_id, downloads);
CREATE INDEX IF NOT EXISTS idx_stats_daily_pod ON op3_stats_daily(podcast_id, date);
CREATE INDEX IF NOT EXISTS idx_geo_daily_ep ON op3_geo_daily(episode_id, date);
CREATE INDEX IF NOT EXISTS idx_apps_daily_ep ON op3_apps_daily(episode_id, date);

