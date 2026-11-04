<?php
// ============================================================================
// KutPod · OP3 nativo · Tracker + IAB v2 dedupe + bot detection + geo
// ============================================================================
// Diseño basado en el código real de OP3 (op3.dev), reescrito en PHP/SQLite.
//   - Tracker:   redirige al audio real y registra hit en op3_hits (async-ish)
//   - Worker:    cada hora computa downloads aplicando dedupe IAB v2
//   - Bot rules: tabla op3_botip_rules sincronizable desde GitHub
//   - Geo:       Cloudflare headers → MaxMind GeoLite2 → blank

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/geoip2-autoload.php';

// ---------------------------------------------------------------------------
// Esquema (autocreado)
// ---------------------------------------------------------------------------
function kp_op3_ensure_schema(): void {
  static $ensured = false;
  if ($ensured) return;
  $ensured = true;

  $pdo = kp_db();
  $has = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='op3_hits'")->fetch();
  if ($has) return;
  $pdo->exec("CREATE TABLE IF NOT EXISTS op3_hits (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    ts           TEXT NOT NULL DEFAULT (datetime('now')),
    hour         TEXT NOT NULL,
    episode_id   INTEGER REFERENCES episodes(id) ON DELETE CASCADE,
    podcast_id   INTEGER REFERENCES podcasts(id) ON DELETE CASCADE,
    server_url   TEXT NOT NULL,
    hashed_ip    TEXT NOT NULL,
    user_agent   TEXT,
    referer      TEXT,
    range_header TEXT,
    method       TEXT NOT NULL DEFAULT 'GET',
    country      TEXT, region TEXT, region_name TEXT, timezone TEXT,
    metro_code   TEXT, asn TEXT, continent TEXT,
    processed    INTEGER NOT NULL DEFAULT 0
  )");
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_hits_unproc ON op3_hits(processed, hour)");
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_hits_ep ON op3_hits(episode_id, ts)");

  $pdo->exec("CREATE TABLE IF NOT EXISTS op3_downloads (
    download_hash TEXT NOT NULL,
    date          TEXT NOT NULL,
    ts            TEXT NOT NULL,
    episode_id    INTEGER REFERENCES episodes(id) ON DELETE CASCADE,
    podcast_id    INTEGER REFERENCES podcasts(id) ON DELETE CASCADE,
    audience_id   TEXT NOT NULL,
    hashed_ip     TEXT,
    agent_type    TEXT, agent_name TEXT,
    device_type   TEXT, device_name TEXT,
    referrer_type TEXT, referrer_name TEXT,
    country TEXT, region TEXT, region_name TEXT,
    timezone TEXT, metro_code TEXT, asn TEXT, continent TEXT,
    bot_type      TEXT,
    tags          TEXT,
    PRIMARY KEY (download_hash, date)
  )");
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_dl_ep_date ON op3_downloads(episode_id, date)");
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_dl_pod_date ON op3_downloads(podcast_id, date)");

  $pdo->exec("CREATE TABLE IF NOT EXISTS op3_stats_daily (
    date         TEXT NOT NULL,
    episode_id   INTEGER NOT NULL,
    podcast_id   INTEGER NOT NULL,
    downloads    INTEGER NOT NULL DEFAULT 0,
    unique_listeners INTEGER NOT NULL DEFAULT 0,
    bot_downloads INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (date, episode_id)
  )");

  $pdo->exec("CREATE TABLE IF NOT EXISTS op3_geo_daily (
    date TEXT NOT NULL, episode_id INTEGER NOT NULL,
    country TEXT, region TEXT, downloads INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (date, episode_id, country, region)
  )");

  $pdo->exec("CREATE TABLE IF NOT EXISTS op3_apps_daily (
    date TEXT NOT NULL, episode_id INTEGER NOT NULL,
    app_name TEXT, device_type TEXT, downloads INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (date, episode_id, app_name, device_type)
  )");

  $pdo->exec("CREATE TABLE IF NOT EXISTS op3_botip_rules (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    kind TEXT NOT NULL,          -- 'hash' | 'asn-pattern' | 'preload-widget'
    rule_json TEXT NOT NULL,
    source TEXT NOT NULL DEFAULT 'manual', -- 'op3-sync' | 'manual'
    note TEXT,
    updated_at TEXT NOT NULL DEFAULT (datetime('now'))
  )");
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_botip_kind ON op3_botip_rules(kind)");

  // Seed inicial mínimo si está vacío. La sincronización real se hace via /op3-sync.
  $count = (int) kp_one("SELECT count(*) c FROM op3_botip_rules")['c'];
  if ($count === 0) kp_op3_seed_bot_rules();
}

// ---------------------------------------------------------------------------
// Tracker · redirige al audio real y registra el hit
// Llamado por /r.php (rewrite a /r/{show}/{episode}/{file})
// ---------------------------------------------------------------------------
function kp_op3_track_and_redirect(string $showSlug, string $episodeSlug): void {
  kp_do_action('op3_tracker_init', $showSlug, $episodeSlug);
  kp_op3_ensure_schema();

  $ep = kp_one("SELECT e.*, p.id as p_id, p.slug as p_slug, p.op3 as p_op3
                FROM episodes e JOIN podcasts p ON p.id = e.podcast_id
                WHERE LOWER(p.slug) = LOWER(?) AND LOWER(e.slug) = LOWER(?) AND e.status = 'published'",
                [$showSlug, $episodeSlug]);

  if (!$ep) {
    // 1) Lógica de rescate: Intentar quitando el timestamp de Castopod
    $cleanSlug = preg_replace('/-[0-9]{8,12}$/', '', $episodeSlug);
    if ($cleanSlug !== $episodeSlug) {
      $ep = kp_one("SELECT e.*, p.id as p_id, p.slug as p_slug, p.op3 as p_op3
                    FROM episodes e JOIN podcasts p ON p.id = e.podcast_id
                    WHERE LOWER(p.slug) = LOWER(?) AND LOWER(e.slug) = LOWER(?) AND e.status = 'published'",
                    [$showSlug, $cleanSlug]);
    }
    
    // 2) Lógica de rescate extrema: Buscar por prefijo (por si el slug se recortó)
    if (!$ep) {
      $ep = kp_one("SELECT e.*, p.id as p_id, p.slug as p_slug, p.op3 as p_op3
                    FROM episodes e JOIN podcasts p ON p.id = e.podcast_id
                    WHERE LOWER(p.slug) = LOWER(?) AND e.slug LIKE ? AND e.status = 'published'
                    ORDER BY length(e.slug) DESC LIMIT 1",
                    [$showSlug, $episodeSlug . '%']);
    }

    // 3) Rescate por IDs numéricos si los parámetros son enteros
    if (!$ep && is_numeric($showSlug) && is_numeric($episodeSlug)) {
      $ep = kp_one("SELECT e.*, p.id as p_id, p.slug as p_slug, p.op3 as p_op3
                    FROM episodes e JOIN podcasts p ON p.id = e.podcast_id
                    WHERE p.id = ? AND e.id = ? AND e.status = 'published'",
                    [(int)$showSlug, (int)$episodeSlug]);
    }
  }

  if (!$ep || !$ep['audio_url']) { http_response_code(404); exit('Not found'); }

  // Construir URL absoluta del audio real (puede ser interno o externo)
  $audio = $ep['audio_url'];
  if (!preg_match('#^https?://#i', $audio)) {
    $audio = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $audio;
  }

  // Si el podcast tiene OP3 desactivado, redirige directo sin trackear
  if (!$ep['p_op3']) {
    header("Location: $audio", true, 302);
    exit;
  }

  // 302 inmediato — el cliente arranca la descarga antes de que terminemos de loggear
  header("Location: $audio", true, 302);
  header('Cache-Control: no-cache, no-store, must-revalidate');

  // FastCGI: cierra la conexión HTTP y sigue ejecutando
  if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
  ignore_user_abort(true);

  // Logging (puede tomar 5-50ms, pero el cliente ya no espera)
  try {
    $geo = kp_op3_resolve_geo();
    $ip = kp_op3_real_ip();
    $hash = hash('sha1', $ip . '|' . date('Y-m-d')); // hash rota cada día → privacy
    $hour = gmdate('Y-m-d\TH');

    kp_db()->prepare(
      "INSERT INTO op3_hits (hour,episode_id,podcast_id,server_url,hashed_ip,
        user_agent,referer,range_header,method,country,region,region_name,
        timezone,metro_code,asn,continent)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
    )->execute([
      $hour, $ep['id'], $ep['p_id'], $audio, $hash,
      substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 400),
      substr($_SERVER['HTTP_REFERER'] ?? '', 0, 400),
      $_SERVER['HTTP_RANGE'] ?? '', $_SERVER['REQUEST_METHOD'] ?? 'GET',
      $geo['country'], $geo['region'], $geo['region_name'],
      $geo['timezone'], $geo['metro_code'], $geo['asn'], $geo['continent']
    ]);
  } catch (Throwable $e) {
    error_log('[OP3 tracker] ' . $e->getMessage());
  }
  exit;
}

// ---------------------------------------------------------------------------
// IP + Geo · cascada CF headers → MaxMind GeoLite2 → blank
// ---------------------------------------------------------------------------
function kp_op3_real_ip(): string {
  foreach (['HTTP_CF_CONNECTING_IP','HTTP_X_FORWARDED_FOR','HTTP_X_REAL_IP','REMOTE_ADDR'] as $h) {
    if (!empty($_SERVER[$h])) {
      $ip = explode(',', $_SERVER[$h])[0];
      $ip = trim($ip);
      if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
    }
  }
  return '0.0.0.0';
}

function kp_op3_resolve_geo(): array {
  // 1) Cloudflare headers (gratis si estás detrás de CF)
  $cf = [
    'country'     => $_SERVER['HTTP_CF_IPCOUNTRY']  ?? '',
    'region'      => $_SERVER['HTTP_CF_REGION_CODE']?? '',
    'region_name' => $_SERVER['HTTP_CF_REGION']     ?? '',
    'timezone'    => $_SERVER['HTTP_CF_TIMEZONE']   ?? '',
    'metro_code'  => $_SERVER['HTTP_CF_METRO_CODE'] ?? '',
    'asn'         => $_SERVER['HTTP_CF_ASN']        ?? '',
    'continent'   => $_SERVER['HTTP_CF_CONTINENT']  ?? '',
  ];
  if ($cf['country']) return $cf;

  // 2) MaxMind GeoLite2
  $mmdb = __DIR__ . '/../storage/geoip/GeoLite2-City.mmdb';
  $asnDb = __DIR__ . '/../storage/geoip/GeoLite2-ASN.mmdb';
  if (file_exists($mmdb) && class_exists('GeoIp2\\Database\\Reader')) {
    try {
      $reader = new GeoIp2\Database\Reader($mmdb);
      $r = $reader->city(kp_op3_real_ip());
      $out = [
        'country'     => $r->country->isoCode ?? '',
        'region'      => $r->mostSpecificSubdivision->isoCode ?? '',
        'region_name' => $r->mostSpecificSubdivision->name ?? '',
        'timezone'    => $r->location->timeZone ?? '',
        'metro_code'  => (string)($r->location->metroCode ?? ''),
        'asn'         => '',
        'continent'   => $r->continent->code ?? '',
      ];
      if (file_exists($asnDb)) {
        try {
          $asn = (new GeoIp2\Database\Reader($asnDb))->asn(kp_op3_real_ip());
          $out['asn'] = (string)($asn->autonomousSystemNumber ?? '');
        } catch (Throwable $e) {}
      }
      return $out;
    } catch (Throwable $e) { /* IP no en base, etc. */ }
  }

  // 3) blank
  return ['country'=>'','region'=>'','region_name'=>'','timezone'=>'',
          'metro_code'=>'','asn'=>'','continent'=>''];
}

// ---------------------------------------------------------------------------
// Worker · procesa los hits no procesados, aplica IAB v2, escribe downloads
// Invocar desde cron: php cli/op3-worker.php   (cada hora)
// ---------------------------------------------------------------------------
function kp_op3_process_pending(int $batch = 5000): array {
  kp_op3_ensure_schema();
  $pdo = kp_db();

  // Trae lote de hits sin procesar
  $hits = kp_q("SELECT * FROM op3_hits WHERE processed = 0 ORDER BY id LIMIT ?", [$batch]);
  if (!$hits) return ['processed' => 0, 'inserted' => 0, 'duplicates' => 0, 'by_podcast' => [], 'by_podcast_episode' => []];

  // Para dedupe necesitamos saber qué download_hash YA está contado en las últimas 24h.
  // Lo cargamos en memoria una vez (clave: download_hash → ['date'=>..., 'is_first_two'=>bool])
  $minDate = substr($hits[0]['ts'], 0, 10);
  $existing = [];
  foreach (kp_q("SELECT download_hash, date, tags FROM op3_downloads
                 WHERE date >= date(?, '-1 day')", [$minDate]) as $r) {
    $existing[$r['download_hash']] = $r;
  }

  $inserted = 0; $dups = 0; $by_podcast = []; $by_podcast_episode = [];
  $insert = $pdo->prepare(
    "INSERT INTO op3_downloads (download_hash,date,ts,episode_id,podcast_id,audience_id,
       hashed_ip,agent_type,agent_name,device_type,device_name,referrer_type,referrer_name,
       country,region,region_name,timezone,metro_code,asn,continent,bot_type,tags)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
  );
  $markProcessed = $pdo->prepare("UPDATE op3_hits SET processed = 1 WHERE id = ?");

  $pdo->beginTransaction();
  foreach ($hits as $h) {
    $markProcessed->execute([$h['id']]);

    // --- Filtros IAB v2 ---
    if ($h['method'] !== 'GET') continue; // solo GET cuenta

    $ranges = kp_op3_parse_range($h['range_header']);
    $isFirstTwoBytes = ($ranges && count($ranges) === 1
        && $ranges[0]['start'] === 0 && $ranges[0]['end'] === 1);
    if (!$isFirstTwoBytes && $ranges) {
      // Acepta solo si algún range pide >2 bytes
      $ok = false;
      foreach ($ranges as $r) {
        if (($r['end'] - $r['start']) > 2) { $ok = true; break; }
      }
      if (!$ok) continue;
    }

    // --- Identificación de agent ---
    $ua = kp_op3_analyze_ua($h['user_agent'], $h['referer']);

    // --- audience_id y download_hash ---
    $serverUrl = kp_op3_normalize_url($h['server_url']);
    $audience  = hash('sha256', $h['hashed_ip'] . '|' . ($h['user_agent']??'') . '|' . ($h['referer']??''));
    $download  = hash('sha1', $serverUrl . '|' . $audience);
    $date = substr($h['ts'], 0, 10);

    // --- Dedupe 24h ---
    if (isset($existing[$download])) {
      $prev = $existing[$download];
      // OP3 trick: si lo anterior era first-two y este NO, lo reemplazamos
      if (strpos($prev['tags'] ?? '', 'first-two') !== false && !$isFirstTwoBytes) {
        $pdo->prepare("DELETE FROM op3_downloads WHERE download_hash = ? AND date = ?")
            ->execute([$download, $prev['date']]);
        unset($existing[$download]);
      } else {
        $dups++;
        continue;
      }
    }

    // --- Tags ---
    $tags = [];
    if ($isFirstTwoBytes) $tags[] = 'first-two';
    if ($ua['is_streaming']) $tags[] = 'streaming';
    if ($ua['is_web_widget']) $tags[] = 'web-widget';

    // --- Bot detection (3 capas) ---
    $botType = kp_op3_classify_bot([
      'agent_type' => $ua['agent_type'], 'agent_name' => $ua['agent_name'],
      'device_type' => $ua['device_type'], 'referrer_name' => $ua['referrer_name'],
      'asn' => $h['asn'], 'region' => $h['region'], 'device_name' => $ua['device_name'],
      'server_url' => $serverUrl, 'date' => $date, 'tags' => implode(',', $tags),
      'hashed_ip' => $h['hashed_ip'],
    ]);
    if ($botType === 'bot-ip') $tags[] = 'bot-ip';

    $insert->execute([
      $download, $date, $h['ts'], $h['episode_id'], $h['podcast_id'], $audience,
      $h['hashed_ip'], $ua['agent_type'], $ua['agent_name'], $ua['device_type'], $ua['device_name'],
      $ua['referrer_type'], $ua['referrer_name'],
      $h['country'], $h['region'], $h['region_name'], $h['timezone'],
      $h['metro_code'], $h['asn'], $h['continent'],
      $botType, implode(',', $tags),
    ]);
    
    $podId = (int)$h['podcast_id'];
    $epId  = (int)($h['episode_id'] ?? 0);
    $by_podcast[$podId] = ($by_podcast[$podId] ?? 0) + 1;
    if ($epId > 0) {
      $by_podcast_episode[$podId][$epId] = ($by_podcast_episode[$podId][$epId] ?? 0) + 1;
    }
    
    $existing[$download] = ['date' => $date, 'tags' => implode(',', $tags)];
    $inserted++;
  }
  $pdo->commit();

  // Recompute agregados diarios para las fechas tocadas
  $datesTouched = array_unique(array_map(fn($h) => substr($h['ts'], 0, 10), $hits));
  foreach ($datesTouched as $d) kp_op3_recompute_daily($d);

  return [
    'processed' => count($hits),
    'inserted' => $inserted,
    'duplicates' => $dups,
    'by_podcast' => $by_podcast,
    'by_podcast_episode' => $by_podcast_episode,
  ];
}

function kp_op3_recompute_daily(string $date): void {
  $pdo = kp_db();
  $pdo->prepare("DELETE FROM op3_stats_daily WHERE date = ?")->execute([$date]);
  $pdo->prepare("DELETE FROM op3_geo_daily WHERE date = ?")->execute([$date]);
  $pdo->prepare("DELETE FROM op3_apps_daily WHERE date = ?")->execute([$date]);

  $pdo->prepare(
    "INSERT INTO op3_stats_daily (date,episode_id,podcast_id,downloads,unique_listeners,bot_downloads)
     SELECT date, episode_id, podcast_id,
            sum(CASE WHEN bot_type IS NULL THEN 1 ELSE 0 END),
            count(DISTINCT CASE WHEN bot_type IS NULL THEN audience_id END),
            sum(CASE WHEN bot_type IS NOT NULL THEN 1 ELSE 0 END)
       FROM op3_downloads WHERE date = ? GROUP BY date, episode_id, podcast_id"
  )->execute([$date]);

  $pdo->prepare(
    "INSERT INTO op3_geo_daily (date,episode_id,country,region,downloads)
     SELECT date, episode_id, country, region, count(*)
       FROM op3_downloads WHERE date = ? AND bot_type IS NULL
      GROUP BY date, episode_id, country, region"
  )->execute([$date]);

  $pdo->prepare(
    "INSERT INTO op3_apps_daily (date,episode_id,app_name,device_type,downloads)
     SELECT date, episode_id, agent_name, device_type, count(*)
       FROM op3_downloads WHERE date = ? AND bot_type IS NULL
      GROUP BY date, episode_id, agent_name, device_type"
  )->execute([$date]);
}

// ---------------------------------------------------------------------------
// User-agent analysis (versión simplificada de OP3 user_agents.ts)
// ---------------------------------------------------------------------------
function kp_op3_analyze_ua(?string $ua, ?string $referer): array {
  $ua = $ua ?? '';
  $r = ['agent_type'=>'unknown','agent_name'=>$ua,'device_type'=>'','device_name'=>'',
        'referrer_type'=>$referer?'domain':'','referrer_name'=>$referer ? parse_url($referer, PHP_URL_HOST) : '',
        'is_streaming' => false, 'is_web_widget' => false];

  // Apps de podcast más comunes
  $apps = [
    'Apple Podcasts'   => 'iTunes|AppleCoreMedia|Podcasts/',
    'Spotify'          => 'Spotify',
    'Overcast'         => 'Overcast',
    'Pocket Casts'     => 'PocketCasts',
    'Castro'           => 'Castro',
    'Castbox'          => 'Castbox',
    'Podcast Addict'   => 'PodcastAddict',
    'Podcast Republic' => 'PodcastRepublic',
    'AntennaPod'       => 'AntennaPod',
    'iHeartRadio'      => 'iHeartRadio',
    'Stitcher'         => 'Stitcher',
    'TuneIn'           => 'TuneIn',
    'Amazon Music'     => 'AmazonMusic',
    'Google Podcasts'  => 'GoogleAndroid|GooglePodcasts',
    'YouTube Music'    => 'com.google.android.apps.youtube.music',
    'Fountain'         => 'Fountain',
    'Podverse'         => 'Podverse',
    'Podfriend'        => 'Podfriend',
    'Breez'            => 'Breez',
    'Snipd'            => 'Snipd',
  ];
  foreach ($apps as $name => $regex) {
    if (preg_match("#($regex)#i", $ua)) {
      $r['agent_type'] = 'app'; $r['agent_name'] = $name;
      if (preg_match('#AppleCoreMedia#i', $ua)) $r['is_streaming'] = true;
      break;
    }
  }
  // Librerías y bots conocidos
  if ($r['agent_type'] === 'unknown') {
    if (preg_match('#(curl|wget|python-requests|libwww-perl|Go-http-client|Java/|axios|node-fetch)#i', $ua, $m)) {
      $r['agent_type'] = 'library'; $r['agent_name'] = $m[1];
    } elseif (preg_match('#(bot|crawler|spider|facebookexternalhit|Twitterbot|Slackbot)#i', $ua, $m)) {
      $r['agent_type'] = 'bot'; $r['agent_name'] = $m[1];
    } elseif (preg_match('#(Chrome|Safari|Firefox|Edge|Opera)/[\d.]+#i', $ua, $m)) {
      $r['agent_type'] = 'browser';
      $r['agent_name'] = preg_split('#/#', $m[0])[0];
    }
  }
  // Device
  if (preg_match('#iPhone#i', $ua))   { $r['device_type']='phone';    $r['device_name']='iPhone'; }
  elseif (preg_match('#iPad#i', $ua)) { $r['device_type']='tablet';   $r['device_name']='iPad'; }
  elseif (preg_match('#Android#i', $ua)) {
    $r['device_type'] = preg_match('#Mobile#i',$ua) ? 'phone' : 'tablet';
    $r['device_name'] = 'Android';
  }
  elseif (preg_match('#Macintosh#i', $ua)) { $r['device_type']='computer'; $r['device_name']='Apple Computer'; }
  elseif (preg_match('#Windows#i', $ua))   { $r['device_type']='computer'; $r['device_name']='Windows Computer'; }
  elseif (preg_match('#Linux#i', $ua))     { $r['device_type']='computer'; $r['device_name']='Linux Computer'; }

  // Web widgets conocidos
  if ($referer && preg_match('#widget\\.(podfriend|justcast)\\.com#i', $referer)) {
    $r['is_web_widget'] = true;
    $r['referrer_name'] = preg_match('#podfriend#i', $referer) ? 'Podfriend' : 'JustCast';
  }
  return $r;
}

// ---------------------------------------------------------------------------
// Bot classification (3 capas: UA → pattern → curated rules)
// ---------------------------------------------------------------------------
function kp_op3_classify_bot(array $ctx): ?string {
  if ($ctx['agent_type'] === 'bot') return 'bot';
  if ($ctx['agent_type'] === 'library') return 'bot-lib';
  if ($ctx['agent_type'] === 'unknown' && preg_match('#(bot|crawler|spider)#i', $ctx['agent_name'])) return 'unknown-bot';
  if ($ctx['agent_name'] === '') return 'no-ua';

  // Capa C: reglas curadas en BD (sync desde OP3)
  static $rules = null;
  if ($rules === null) {
    $rules = ['hashes' => [], 'patterns' => []];
    foreach (kp_q("SELECT kind, rule_json FROM op3_botip_rules") as $row) {
      $j = json_decode($row['rule_json'], true);
      if ($row['kind'] === 'hash') $rules['hashes'][$j['hash']] = true;
      elseif ($row['kind'] === 'asn-pattern') $rules['patterns'][] = $j;
    }
  }
  if (!empty($rules['hashes'][$ctx['hashed_ip']])) return 'bot-ip';
  foreach ($rules['patterns'] as $p) {
    if (kp_op3_match_pattern($p, $ctx)) return 'bot-ip';
  }
  // Preload widgets
  if ($ctx['agent_type'] === 'browser' && $ctx['referrer_name'] === 'Podverse') return 'podverse-web-preload';
  if (strpos($ctx['tags'], 'web-widget') !== false) return 'web-widget-preload';
  return null;
}

function kp_op3_match_pattern(array $p, array $c): bool {
  foreach ($p as $k => $v) {
    if ($k === 'asn' && (string)$c['asn'] !== (string)$v) return false;
    if ($k === 'agent_name' && $c['agent_name'] !== $v) return false;
    if ($k === 'agent_type' && $c['agent_type'] !== $v) return false;
    if ($k === 'region' && $c['region'] !== $v) return false;
    if ($k === 'device_name' && $c['device_name'] !== $v) return false;
    if ($k === 'date_from' && $c['date'] < $v) return false;
    if ($k === 'date_to' && $c['date'] > $v) return false;
  }
  return true;
}

// ---------------------------------------------------------------------------
// Range header parser (RFC 7233)
// ---------------------------------------------------------------------------
function kp_op3_parse_range(?string $h): ?array {
  if (!$h || !preg_match('#^bytes=(.+)$#', $h, $m)) return null;
  $out = [];
  foreach (explode(',', $m[1]) as $r) {
    $r = trim($r);
    if (preg_match('#^(\d+)-(\d+)?$#', $r, $mm)) {
      $out[] = ['start' => (int)$mm[1], 'end' => isset($mm[2]) ? (int)$mm[2] : PHP_INT_MAX];
    } elseif (preg_match('#^-(\d+)$#', $r, $mm)) {
      $out[] = ['start' => 0, 'end' => (int)$mm[1]];
    }
  }
  return $out ?: null;
}

// Quita query params de tracking (ref, source, fbclid, etc.)
function kp_op3_normalize_url(string $url): string {
  $p = parse_url($url);
  if (empty($p['query'])) return $url;
  parse_str($p['query'], $q);
  $strip = ['source','ref','referrer','from','src','fbclid','rn','utm_source','utm_medium','utm_campaign','aid'];
  foreach ($strip as $k) unset($q[$k]);
  $base = ($p['scheme'] ?? 'https') . '://' . $p['host'] . ($p['path'] ?? '');
  return $q ? $base . '?' . http_build_query($q) : $base;
}

// ---------------------------------------------------------------------------
// Sincronizar reglas de bot desde el repo de OP3 en GitHub
// ---------------------------------------------------------------------------
function kp_op3_sync_bot_rules_from_github(): array {
  $url = 'https://raw.githubusercontent.com/skymethod/op3/master/worker/backend/bots.ts';
  $src = @file_get_contents($url);
  if (!$src) throw new RuntimeException('No se pudo descargar bots.ts de GitHub');

  $hashes = []; $patterns = [];

  // 1) Extraer hashes de la constante botIpHashes
  if (preg_match('/const botIpHashes = new Set\(\[(.+?)\]\);/s', $src, $m)) {
    preg_match_all("/'([a-f0-9]{40})'/", $m[1], $mm);
    $hashes = array_unique($mm[1]);
  }

  // 2) Extraer reglas asn === '...' del isBotIpHash (best-effort)
  if (preg_match('/function isBotIpHash\(.*?\) \{(.*?)^}/sm', $src, $m)) {
    $body = $m[1];
    // Patrón simple: asn === '12345' && agentType === 'browser' && date >= '2026-01-01'
    if (preg_match_all('/asn === \'(\d+)\'(.*?)(?=\|\||;|\})/s', $body, $blocks, PREG_SET_ORDER)) {
      foreach ($blocks as $b) {
        $p = ['asn' => $b[1]];
        if (preg_match("/agentType === '([^']+)'/", $b[2], $x)) $p['agent_type'] = $x[1];
        if (preg_match("/agentName === '([^']+)'/", $b[2], $x)) $p['agent_name'] = $x[1];
        if (preg_match("/regionCode === '([^']+)'/", $b[2], $x)) $p['region'] = $x[1];
        if (preg_match("/deviceName === '([^']+)'/", $b[2], $x)) $p['device_name'] = $x[1];
        if (preg_match("/date >= '(\d{4}-\d{2}-\d{2})'/", $b[2], $x)) $p['date_from'] = $x[1];
        if (preg_match("/date === '(\d{4}-\d{2}-\d{2})'/", $b[2], $x)) { $p['date_from']=$x[1]; $p['date_to']=$x[1]; }
        $patterns[] = $p;
      }
    }
  }

  // Persistir: borrar las de origen 'op3-sync' y reinsertar
  $pdo = kp_db();
  $pdo->beginTransaction();
  $pdo->prepare("DELETE FROM op3_botip_rules WHERE source = 'op3-sync'")->execute();
  $insH = $pdo->prepare("INSERT INTO op3_botip_rules (kind,rule_json,source) VALUES ('hash',?,'op3-sync')");
  $insP = $pdo->prepare("INSERT INTO op3_botip_rules (kind,rule_json,source) VALUES ('asn-pattern',?,'op3-sync')");
  foreach ($hashes as $h)   $insH->execute([json_encode(['hash' => $h])]);
  foreach ($patterns as $p) $insP->execute([json_encode($p, JSON_UNESCAPED_SLASHES)]);
  $pdo->commit();

  return ['hashes' => count($hashes), 'patterns' => count($patterns)];
}

function kp_op3_seed_bot_rules(): void {
  // Seed mínimo offline · la sincronización real llena el resto desde GitHub
  $patterns = [
    ['asn'=>'14618','region'=>'VA','agent_type'=>'browser'],     // Amazon Virginia
    ['asn'=>'24940','agent_type'=>'browser'],                    // Hetzner
    ['asn'=>'19148','agent_type'=>'browser'],                    // Leaseweb
    ['asn'=>'132203','agent_type'=>'browser'],                   // Tencent
    ['asn'=>'150436','agent_type'=>'browser'],                   // ByteDance
  ];
  $ins = kp_db()->prepare("INSERT INTO op3_botip_rules (kind,rule_json,source,note) VALUES ('asn-pattern',?,'manual',?)");
  foreach ($patterns as $p) $ins->execute([json_encode($p), 'seed inicial · ejecuta sync para lista completa']);
}
