<?php
// ============================================================================
// KutPod · Módulo de Suscripciones Premium
// ============================================================================

// Evitar acceso directo
if (!defined('KUTPOD_VERSION')) {
    exit;
}

// ── Inicialización de Esquema ───────────────────────────────────────────────

function kp_premium_ensure_schema(): void {
    $pdo = kp_db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS premium_tokens (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        podcast_id  INTEGER NOT NULL REFERENCES podcasts(id) ON DELETE CASCADE,
        subscriber_email TEXT NOT NULL,
        token       TEXT NOT NULL UNIQUE,
        provider    TEXT NOT NULL DEFAULT 'stripe', -- 'stripe' | 'manual'
        status      TEXT NOT NULL DEFAULT 'active', -- 'active' | 'suspended' | 'cancelled'
        expires_at  TEXT,                           -- NULL para ilimitado
        created_at  TEXT NOT NULL DEFAULT (datetime('now'))
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_premium_tokens_val ON premium_tokens(token, status)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS premium_access_logs (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        token_id    INTEGER NOT NULL REFERENCES premium_tokens(id) ON DELETE CASCADE,
        ip          TEXT NOT NULL,
        user_agent  TEXT,
        request_type TEXT NOT NULL,                  -- 'feed' | 'audio'
        created_at  TEXT NOT NULL DEFAULT (datetime('now'))
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_premium_access_24h ON premium_access_logs(token_id, created_at)");
}

if (file_exists(__DIR__ . '/../../storage/kutpod.db')) {
    try {
        kp_premium_ensure_schema();
    } catch (Throwable $e) {}
}


// ── Registro de Ganchos (Hooks) ─────────────────────────────────────────────

// Modifica la lista de secciones del formulario de podcast para incluir "Premium"
kp_add_filter('podcast_form_sections', 'kp_premium_plugin_form_sections', 10, 1);

// Renderiza visualmente la sección Premium en el formulario de podcast
kp_add_action('podcast_form_sections_render', 'kp_premium_plugin_render_podcast_sections', 10, 1);

// Guarda la configuración del podcast Premium en meta_json
kp_add_action('podcast_saved', 'kp_premium_plugin_save_podcast', 10, 2);

// Guarda el estado Premium del episodio en meta_json y sincroniza con base de datos
kp_add_action('episode_saved', 'kp_premium_plugin_save_episode', 10, 2);

// Valida el token al cargar el feed RSS
kp_add_action('feed_rss_init', 'kp_premium_plugin_feed_rss_init', 10, 1);

// Oculta episodios premium si no hay token activo en la petición de feed
kp_add_filter('feed_rss_episodes', 'kp_premium_plugin_feed_rss_episodes', 10, 2);

// Modifica las URLs del enclosure del feed para inyectar el token activo
kp_add_filter('feed_rss_enclosure_url', 'kp_premium_plugin_feed_rss_enclosure_url', 10, 3);

// Intercepta solicitudes en el tracker para validar y servir audios premium
kp_add_action('op3_tracker_init', 'kp_premium_plugin_op3_tracker_init', 10, 2);

// Intercepta peticiones del sistema para recibir los webhooks de Stripe
kp_add_action('init', 'kp_premium_plugin_handle_webhooks');

// Renderiza el meta de podcast premium en el hero público del show
kp_add_action('public_show_hero_meta', 'kp_premium_plugin_public_show_hero_meta', 10, 1);

// Filtra el botón de play en el listado público de episodios del show
kp_add_filter('public_show_episode_play_button', 'kp_premium_plugin_public_show_episode_play_button', 10, 3);

// Filtra el bloque de reproducción/paywall en la página pública del episodio
kp_add_filter('public_episode_play_control', 'kp_premium_plugin_public_episode_play_control', 10, 3);


// ── Callbacks de Ganchos ────────────────────────────────────────────────────

/**
 * Inserta la sección Premium en el índice de secciones.
 */
function kp_premium_plugin_form_sections(array $sections): array {
    $new_sections = [];
    foreach ($sections as $k => $v) {
        $new_sections[$k] = $v;
        if ($k === 's1-3') {
            $new_sections['s1-5'] = 'Premium';
        }
    }
    return $new_sections;
}

/**
 * Renderiza los campos de suscripción Premium a nivel de Podcast.
 */
function kp_premium_plugin_render_podcast_sections(?array $p): void {
    $premium_enabled = 0;
    $premium_price = 5.00;
    $premium_price_year = 0.00;
    $premium_provider = 'Stripe';

    if ($p) {
        $premium_enabled = (int)kp_get_podcast_meta($p, 'premium_enabled', 0);
        $premium_price = (float)kp_get_podcast_meta($p, 'premium_price', 5.00);
        $premium_price_year = (float)kp_get_podcast_meta($p, 'premium_price_year', 0.00);
        $premium_provider = kp_get_podcast_meta($p, 'premium_provider', 'Stripe');
    }
    ?>
    <!-- 1.5 Suscripción Premium -->
    <section id="s1-5" class="card card-lg">
      <header class="section-head"><h2>Suscripción Premium</h2></header>
      <label class="toggle-row">
        <span><strong>Activar Premium</strong><br><span class="help">Permite cobrar suscripción por este show o marcar episodios individuales como de pago.</span></span>
        <label class="toggle">
          <input type="checkbox" name="premium_enabled" value="1" <?= $premium_enabled ? 'checked' : '' ?>>
          <span class="toggle-track"></span>
        </label>
      </label>
      <div class="field-row" style="margin-top:14px">
        <div class="field">
          <label class="label">Precio mensual (USD)</label>
          <input class="input tabular" type="number" min="0" step="0.01" name="premium_price" value="<?= e($premium_price) ?>">
        </div>
        <div class="field">
          <label class="label">Precio anual (USD) <span class="help" style="font-weight:normal;display:inline">(Opcional)</span></label>
          <input class="input tabular" type="number" min="0" step="0.01" name="premium_price_year" value="<?= $premium_price_year > 0 ? e($premium_price_year) : '' ?>" placeholder="Ej: 50.00">
        </div>
        <div class="field">
          <label class="label">Proveedor de pagos</label>
          <select class="select" name="premium_provider">
            <option value="Stripe" <?= $premium_provider === 'Stripe' ? 'selected' : '' ?>>Stripe Checkout</option>
            <option value="PayPal" <?= $premium_provider === 'PayPal' ? 'selected' : '' ?>>PayPal Subscription</option>
            <option value="Manual" <?= $premium_provider === 'Manual' ? 'selected' : '' ?>>Solo Manual / Cortesía</option>
          </select>
        </div>
      </div>
    </section>
    <?php
}

/**
 * Guarda los campos Premium del podcast.
 */
function kp_premium_plugin_save_podcast(int $db_id, array $postData): void {
    $enabled = isset($postData['premium_enabled']) ? 1 : 0;
    $price = isset($postData['premium_price']) ? (float)$postData['premium_price'] : 5.00;
    $price_year = isset($postData['premium_price_year']) ? (float)$postData['premium_price_year'] : 0.00;
    $provider = isset($postData['premium_provider']) ? trim($postData['premium_provider']) : 'Stripe';

    kp_update_podcast_meta($db_id, 'premium_enabled', $enabled);
    kp_update_podcast_meta($db_id, 'premium_price', $price);
    kp_update_podcast_meta($db_id, 'premium_price_year', $price_year);
    kp_update_podcast_meta($db_id, 'premium_provider', $provider);

    if ($enabled) {
        // Desactivar premium individual en todos los episodios de este podcast en BD
        $pdo = kp_db();
        $pdo->prepare("UPDATE episodes SET premium = 0 WHERE podcast_id = ?")->execute([$db_id]);
        
        // También limpiar el meta_json de cada episodio
        $episodes = kp_q("SELECT id, meta_json FROM episodes WHERE podcast_id = ?", [$db_id]) ?: [];
        foreach ($episodes as $ep) {
            $meta = !empty($ep['meta_json']) ? json_decode($ep['meta_json'], true) : [];
            if (isset($meta['premium_enabled'])) {
                unset($meta['premium_enabled']);
                $pdo->prepare("UPDATE episodes SET meta_json = ? WHERE id = ?")
                    ->execute([json_encode($meta, JSON_UNESCAPED_UNICODE), $ep['id']]);
            }
        }
    }
}

/**
 * Renderiza el switch Premium en la edición de episodios.
 */
function kp_premium_plugin_render_episode_fields(?array $e): void {
    $premium_enabled = 0;
    $is_podcast_premium = 0;
    if ($e) {
        $premium_enabled = (int)kp_get_episode_meta($e, 'premium_enabled', 0);
        $podcast_id = (int)($e['podcast_id'] ?? 0);
        if ($podcast_id > 0) {
            $p = kp_one("SELECT * FROM podcasts WHERE id = ?", [$podcast_id]);
            if ($p) {
                $is_podcast_premium = (int)kp_get_podcast_meta($p, 'premium_enabled', 0);
            }
        }
    }
    ?>
    <label class="toggle-row" style="margin-top:14px">
      <span><strong>Episodio Premium</strong><br><span class="help"><?= $is_podcast_premium ? 'El podcast completo está marcado como Premium.' : 'Marcar este episodio como exclusivo para suscriptores de pago.' ?></span></span>
      <label class="toggle">
        <input type="checkbox" name="premium_enabled" value="1" <?= ($is_podcast_premium || $premium_enabled) ? 'checked' : '' ?> <?= $is_podcast_premium ? 'disabled' : '' ?>>
        <span class="toggle-track"></span>
      </label>
    </label>
    <?php
}

/**
 * Guarda el estado Premium del episodio.
 */
function kp_premium_plugin_save_episode(int $episode_id, array $postData): void {
    $episode = kp_one("SELECT podcast_id FROM episodes WHERE id = ?", [$episode_id]);
    $is_podcast_premium = 0;
    if ($episode) {
        $podcast_id = (int)$episode['podcast_id'];
        $p = kp_one("SELECT * FROM podcasts WHERE id = ?", [$podcast_id]);
        if ($p) {
            $is_podcast_premium = (int)kp_get_podcast_meta($p, 'premium_enabled', 0);
        }
    }

    $enabled = (!$is_podcast_premium && (isset($postData['premium']) || isset($postData['premium_enabled']))) ? 1 : 0;
    kp_update_episode_meta($episode_id, 'premium_enabled', $enabled);

    if ($episode) {
        $podcast_id = (int)$episode['podcast_id'];
        if (isset($postData['premium_price'])) {
            kp_update_podcast_meta($podcast_id, 'premium_price', (float)$postData['premium_price']);
        }
        if (isset($postData['premium_price_year'])) {
            kp_update_podcast_meta($podcast_id, 'premium_price_year', (float)$postData['premium_price_year']);
        }
    }
}

/**
 * Intercepta y valida el acceso en el arranque del feed RSS.
 */
function kp_premium_plugin_feed_rss_init(array $p): void {
    $podcast_premium = (int)kp_get_podcast_meta($p, 'premium_enabled', 0);
    $tokenStr = trim($_GET['token'] ?? '');

    if ($podcast_premium) {
        if (empty($tokenStr)) {
            http_response_code(403);
            exit('Este feed es privado y requiere un token de suscripción activo.');
        }

        $token = kp_premium_validate_and_log_token($tokenStr, (int)$p['id'], 'feed');
        if (!$token || $token['status'] !== 'active') {
            http_response_code(403);
            exit('Acceso denegado: El token de suscripción no es válido o está suspendido.');
        }

        // Almacenar el token activo en el contexto global
        $GLOBALS['kp_current_premium_token'] = $tokenStr;
    } else {
        // Podcast público pero puede tener episodios individuales premium
        if (!empty($tokenStr)) {
            $token = kp_premium_validate_and_log_token($tokenStr, (int)$p['id'], 'feed');
            if ($token && $token['status'] === 'active') {
                $GLOBALS['kp_current_premium_token'] = $tokenStr;
            }
        }
    }
}

/**
 * Filtra los episodios premium en feeds sin autenticación válida.
 */
function kp_premium_plugin_feed_rss_episodes(array $episodes, array $p): array {
    $podcast_premium = (int)kp_get_podcast_meta($p, 'premium_enabled', 0);
    $has_valid_token = isset($GLOBALS['kp_current_premium_token']);

    if (!$podcast_premium) {
        $filtered = [];
        foreach ($episodes as $e) {
            $episode_premium = (int)kp_get_episode_meta($e, 'premium_enabled', 0);
            if ($episode_premium && !$has_valid_token) {
                // Ocultar del feed público
                continue;
            }
            $filtered[] = $e;
        }
        return $filtered;
    }

    return $episodes;
}

/**
 * Inyecta el token en la URL de enclosure en el XML.
 */
function kp_premium_plugin_feed_rss_enclosure_url(string $url, array $e, array $p): string {
    if (isset($GLOBALS['kp_current_premium_token'])) {
        $sep = (strpos($url, '?') !== false) ? '&' : '?';
        return $url . $sep . 'token=' . urlencode($GLOBALS['kp_current_premium_token']);
    }
    return $url;
}

/**
 * Intercepta descargas del tracker y sirve el archivo físicamente.
 */
function kp_premium_plugin_op3_tracker_init(string $showSlug, string $episodeSlug): void {
    $p = kp_one("SELECT * FROM podcasts WHERE LOWER(slug) = LOWER(?)", [$showSlug]);
    if (!$p) return;

    $e = kp_one("SELECT * FROM episodes WHERE LOWER(slug) = LOWER(?) AND podcast_id = ?", [$episodeSlug, $p['id']]);
    if (!$e) return;

    $podcast_premium = (int)kp_get_podcast_meta($p, 'premium_enabled', 0);
    $episode_premium = (int)kp_get_episode_meta($e, 'premium_enabled', 0);

    if (!$podcast_premium && !$episode_premium) {
        return; // Descarga pública normal
    }

    // Propietario / Administrador bypass
    if (!function_exists('kp_current_user')) {
        require_once __DIR__ . '/../../includes/auth.php';
    }
    $u = kp_current_user();
    if ($u && in_array($u['role'], ['owner', 'admin'], true)) {
        kp_premium_serve_audio_file($e, $p);
        exit;
    }

    // Validar token en audios Premium
    $tokenStr = trim($_GET['token'] ?? '');
    if (empty($tokenStr)) {
        http_response_code(403);
        exit('Acceso denegado: Este archivo de audio requiere un token de suscripción activo.');
    }

    $token = kp_premium_validate_and_log_token($tokenStr, (int)$p['id'], 'audio');
    if (!$token || $token['status'] !== 'active') {
        http_response_code(403);
        exit('Acceso denegado: El token de suscripción no es válido o está suspendido.');
    }

    // Servir físicamente de forma protegida
    kp_premium_serve_audio_file($e, $p);
    exit;
}

/**
 * Intercepta peticiones para escuchar Webhooks de Stripe.
 */
function kp_premium_plugin_handle_webhooks(): void {
    $request_uri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    if ($request_uri === '/premium/webhook/stripe') {
        kp_premium_process_stripe_webhook();
        exit;
    }
    if ($request_uri === '/premium/webhook/paypal') {
        kp_premium_process_paypal_webhook();
        exit;
    }
    if ($request_uri === '/premium/checkout/stripe') {
        kp_premium_create_stripe_checkout();
        exit;
    }
    if ($request_uri === '/premium/checkout/paypal') {
        kp_premium_create_paypal_checkout();
        exit;
    }
}

/**
 * Envía un correo electrónico al suscriptor notificándole sobre el estado de su suscripción.
 * $type: 'welcome' | 'suspended' | 'cancelled'
 */
function kp_premium_send_status_email(string $email, int $podcast_id, string $type, string $tokenStr = ''): void {
    try {
        require_once __DIR__ . '/../../includes/mail.php';
        
        $p = kp_one("SELECT * FROM podcasts WHERE id = ?", [$podcast_id]);
        if (!$p) return;
        
        $title = $p['title'];
        $instance_name = kp_setting('instance_name', 'KutPod');
        $domain = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
        
        if ($type === 'welcome' && empty($tokenStr)) {
            $t = kp_one("SELECT token FROM premium_tokens WHERE podcast_id = ? AND subscriber_email = ? AND status = 'active'", [$podcast_id, $email]);
            $tokenStr = $t ? $t['token'] : '';
        }
        
        $feed_url = $protocol . $domain . '/feed.php?slug=' . urlencode($p['slug']) . '&token=' . urlencode($tokenStr);
        
        if ($type === 'welcome') {
            $subject = "¡Suscripción Activa! Tu feed privado de " . $title;
            $body = "¡Hola!\n\n"
                  . "Tu suscripción para el podcast \"" . $title . "\" se ha activado correctamente.\n\n"
                  . "Aquí tienes tu feed RSS personalizado y privado. Cópialo y agrégalo en tu aplicación de podcasts preferida (Apple Podcasts, Pocket Casts, etc.):\n\n"
                  . $feed_url . "\n\n"
                  . "Recordatorio importante: Este enlace de feed es único e intransferible. Está diseñado para tu uso personal exclusivamente. Si el sistema detecta accesos sospechosos o compartidos desde múltiples ubicaciones no habituales, tu suscripción y acceso al feed podrán ser cancelados permanentemente sin derecho a reembolso.\n\n"
                  . "¡Gracias por apoyar nuestro contenido!\n\n"
                  . "Atentamente,\n"
                  . "El Equipo de " . $instance_name;
        } elseif ($type === 'suspended') {
            $subject = "Suscripción Suspendida: Fallo de pago en " . $title;
            $body = "¡Hola!\n\n"
                  . "Lamentamos informarte que el último cobro de tu suscripción para el podcast \"" . $title . "\" ha fallado o ha sido denegado.\n\n"
                  . "Por este motivo, tu acceso premium ha sido suspendido temporalmente. Por favor, revisa tu método de pago para restablecer el acceso.\n\n"
                  . "Atentamente,\n"
                  . "El Equipo de " . $instance_name;
        } elseif ($type === 'cancelled') {
            $subject = "Suscripción Cancelada: " . $title;
            $body = "¡Hola!\n\n"
                  . "Te confirmamos que tu suscripción para el podcast \"" . $title . "\" ha sido cancelada. Tu feed personalizado dejará de estar activo.\n\n"
                  . "Gracias por haber apoyado nuestro contenido durante este tiempo.\n\n"
                  . "Atentamente,\n"
                  . "El Equipo de " . $instance_name;
        } else {
            return;
        }
        
        kp_send_mail($email, $subject, $body);
    } catch (Throwable $e) {
        error_log("Error al enviar correo de suscripción premium: " . $e->getMessage());
    }
}


// ── Métodos Auxiliares ───────────────────────────────────────────────────────

/**
 * Valida un token de acceso y registra el log del hit con protección de abuso.
 */
function kp_premium_validate_and_log_token(string $tokenStr, int $podcastId, string $reqType): ?array {
    $token = kp_one("SELECT * FROM premium_tokens WHERE token = ? AND podcast_id = ?", [$tokenStr, $podcastId]);
    if (!$token) {
        return null;
    }
    if ($token['status'] !== 'active') {
        return $token;
    }

    $ip = kp_premium_get_ip();
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    kp_exec("INSERT INTO premium_access_logs (token_id, ip, user_agent, request_type) VALUES (?, ?, ?, ?)", [
        $token['id'], $ip, $ua, $reqType
    ]);

    // Validación de IPs simultáneas en 24 horas
    $ip_limit = (int)kp_setting('premium_abuse_ip_limit', 5);
    if ($ip_limit > 0) {
        $recent_ips = (int)kp_one("SELECT COUNT(DISTINCT ip) c FROM premium_access_logs WHERE token_id = ? AND created_at >= datetime('now', '-24 hours')", [$token['id']])['c'];
        if ($recent_ips > $ip_limit) {
            kp_exec("UPDATE premium_tokens SET status = 'suspended' WHERE id = ?", [$token['id']]);
            $token['status'] = 'suspended';
            kp_alert('system', 'Token Premium Suspendido', "El token de {$token['subscriber_email']} fue suspendido automáticamente al registrar accesos desde {$recent_ips} direcciones IP distintas en 24h.", '');
        }
    }

    return $token;
}

/**
 * Resuelve el IP real del cliente.
 */
function kp_premium_get_ip(): string {
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $h) {
        if (!empty($_SERVER[$h])) {
            $ip = trim(explode(',', $_SERVER[$h])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    return '0.0.0.0';
}

/**
 * Escanea y devuelve la ruta física local del audio.
 */
function kp_premium_get_physical_audio_path(array $e, array $p): ?string {
    if (empty($e['audio_url'])) {
        return null;
    }
    $filePath = realpath(__DIR__ . '/../../' . ltrim($e['audio_url'], '/'));
    if ($filePath && file_exists($filePath)) {
        return $filePath;
    }
    return null;
}

/**
 * Transmite el archivo de audio con cabeceras de rango HTTP para reproductores.
 */
function kp_premium_serve_audio_file(array $e, array $p): void {
    $filePath = kp_premium_get_physical_audio_path($e, $p);
    if (!$filePath || !file_exists($filePath)) {
        http_response_code(404);
        exit('Archivo de audio no encontrado.');
    }

    $mime = $e['audio_mime'] ?: 'audio/mpeg';
    $fileName = basename($filePath);
    $fileSize = filesize($filePath);

    if (ob_get_level()) {
        ob_end_clean();
    }

    header("Content-Type: $mime");
    header("Content-Disposition: inline; filename=\"$fileName\"");
    header("Accept-Ranges: bytes");

    // X-Accel-Redirect (Nginx)
    $nginx_redirect = kp_setting('premium_nginx_accel_dir', '');
    if ($nginx_redirect !== '') {
        $relPath = $p['slug'] . '/' . basename($filePath);
        header("X-Accel-Redirect: " . rtrim($nginx_redirect, '/') . '/' . $relPath);
        exit;
    }

    // X-Sendfile (Apache)
    $apache_sendfile = (int)kp_setting('premium_apache_sendfile', 0);
    if ($apache_sendfile) {
        header("X-Sendfile: " . $filePath);
        exit;
    }

    // PHP Streaming Gateway (HTTP 206 Ranges)
    $fp = @fopen($filePath, 'rb');
    if (!$fp) {
        http_response_code(500);
        exit('Error al leer el recurso de audio.');
    }

    $start = 0;
    $end = $fileSize - 1;

    if (isset($_SERVER['HTTP_RANGE'])) {
        $c_start = $start;
        $c_end = $end;

        list(, $range) = explode('=', $_SERVER['HTTP_RANGE'], 2);
        if (strpos($range, ',') !== false) {
            header('HTTP/1.1 416 Requested Range Not Satisfiable');
            header("Content-Range: bytes $start-$end/$fileSize");
            exit;
        }

        if ($range == '-') {
            $c_start = 0;
        } else {
            $range = explode('-', $range);
            $c_start = $range[0];
            $c_end = (isset($range[1]) && is_numeric($range[1])) ? $range[1] : $fileSize - 1;
        }

        $c_end = ($c_end > $end) ? $end : $c_end;
        if ($c_start > $c_end || $c_start > $fileSize - 1 || $c_end >= $fileSize) {
            header('HTTP/1.1 416 Requested Range Not Satisfiable');
            header("Content-Range: bytes $start-$end/$fileSize");
            exit;
        }

        $start = $c_start;
        $end = $c_end;
        $length = $end - $start + 1;
        fseek($fp, $start);
        header('HTTP/1.1 206 Partial Content');
        header("Content-Range: bytes $start-$end/$fileSize");
        header("Content-Length: $length");
    } else {
        header("Content-Length: $fileSize");
    }

    $buffer = 8192;
    while (!feof($fp) && ($pos = ftell($fp)) <= $end) {
        if ($pos + $buffer > $end) {
            $buffer = $end - $pos + 1;
        }
        echo fread($fp, $buffer);
        flush();
    }
    fclose($fp);
    exit;
}

/**
 * Obtiene el correo electrónico de un cliente de Stripe usando la API REST básica.
 */
function kp_premium_get_stripe_customer_email(string $customerId): string {
    $secret = kp_setting('premium_stripe_secret_key', '');
    if (!$secret || !$customerId) return '';
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.stripe.com/v1/customers/' . urlencode($customerId));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERPWD, $secret . ':');
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $res = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($res, true);
    return $data['email'] ?? '';
}

/**
 * Procesa la notificación del webhook de Stripe Checkout y suscripciones.
 */
function kp_premium_process_stripe_webhook(): void {
    $payload = file_get_contents('php://input');
    $event = json_decode($payload, true);

    if (!$event || empty($event['type'])) {
        http_response_code(400);
        exit('Payload inválido');
    }

    // Validación estricta con clave secreta del webhook
    $webhook_secret = kp_setting('premium_stripe_webhook_secret', '');
    if ($webhook_secret !== '') {
        $sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
        if (empty($sig_header)) {
            http_response_code(401);
            exit('Firma de Stripe ausente');
        }

        // Parsear los elementos de la firma (t=timestamp, v1=signature)
        $sig_elements = [];
        foreach (explode(',', $sig_header) as $part) {
            $kv = explode('=', trim($part), 2);
            if (count($kv) === 2) {
                $sig_elements[$kv[0]] = $kv[1];
            }
        }

        $sig_timestamp = $sig_elements['t'] ?? '';
        $sig_v1 = $sig_elements['v1'] ?? '';

        if (empty($sig_timestamp) || empty($sig_v1)) {
            http_response_code(401);
            exit('Firma de Stripe malformada');
        }

        // Verificar tolerancia de tiempo (5 minutos)
        if (abs(time() - (int)$sig_timestamp) > 300) {
            http_response_code(401);
            exit('Timestamp de firma fuera de rango');
        }

        // Calcular HMAC-SHA256 esperado
        $signed_payload = $sig_timestamp . '.' . $payload;
        $expected_signature = hash_hmac('sha256', $signed_payload, $webhook_secret);

        if (!hash_equals($expected_signature, $sig_v1)) {
            http_response_code(401);
            exit('Firma HMAC de Stripe inválida');
        }
    }

    $event_type = $event['type'];

    if ($event_type === 'checkout.session.completed') {
        $session = $event['data']['object'];
        $email = $session['customer_details']['email'] ?? $session['customer_email'] ?? '';
        $podcast_id = (int)($session['metadata']['podcast_id'] ?? 0);

        if ($email && $podcast_id > 0) {
            $existing = kp_one("SELECT id, token FROM premium_tokens WHERE podcast_id = ? AND subscriber_email = ?", [$podcast_id, $email]);
            if ($existing) {
                $tokenStr = $existing['token'];
                kp_exec("UPDATE premium_tokens SET status = 'active', provider = 'stripe' WHERE id = ?", [$existing['id']]);
            } else {
                $tokenStr = bin2hex(random_bytes(16));
                kp_exec("INSERT INTO premium_tokens (podcast_id, subscriber_email, token, provider, status) VALUES (?, ?, ?, 'stripe', 'active')", [
                    $podcast_id, $email, $tokenStr
                ]);
            }

            kp_alert('system', 'Nueva Suscripción Premium', "El usuario $email se ha suscrito mediante Stripe Checkout.", '');
            kp_premium_send_status_email($email, $podcast_id, 'welcome', $tokenStr);
        }
    } 
    elseif ($event_type === 'customer.subscription.deleted') {
        $subscription = $event['data']['object'];
        $customer_id = $subscription['customer'] ?? '';
        $email = kp_premium_get_stripe_customer_email($customer_id);
        $podcast_id = (int)($subscription['metadata']['podcast_id'] ?? 0);
        
        if ($email) {
            if ($podcast_id > 0) {
                kp_exec("UPDATE premium_tokens SET status = 'cancelled' WHERE podcast_id = ? AND subscriber_email = ?", [$podcast_id, $email]);
                kp_premium_send_status_email($email, $podcast_id, 'cancelled');
            } else {
                $tokens = kp_q("SELECT t.* FROM premium_tokens t WHERE t.subscriber_email = ? AND t.provider = 'stripe'", [$email]) ?: [];
                kp_exec("UPDATE premium_tokens SET status = 'cancelled' WHERE subscriber_email = ? AND provider = 'stripe'", [$email]);
                foreach ($tokens as $tk) {
                    kp_premium_send_status_email($email, (int)$tk['podcast_id'], 'cancelled');
                }
            }
            kp_alert('system', 'Suscripción Cancelada (Stripe)', "La suscripción de $email ha sido cancelada en Stripe.", '');
        }
    } 
    elseif ($event_type === 'customer.subscription.paused') {
        $subscription = $event['data']['object'];
        $customer_id = $subscription['customer'] ?? '';
        $email = kp_premium_get_stripe_customer_email($customer_id);
        $podcast_id = (int)($subscription['metadata']['podcast_id'] ?? 0);
        
        if ($email) {
            if ($podcast_id > 0) {
                kp_exec("UPDATE premium_tokens SET status = 'suspended' WHERE podcast_id = ? AND subscriber_email = ?", [$podcast_id, $email]);
                kp_premium_send_status_email($email, $podcast_id, 'suspended');
            } else {
                $tokens = kp_q("SELECT t.* FROM premium_tokens t WHERE t.subscriber_email = ? AND t.provider = 'stripe'", [$email]) ?: [];
                kp_exec("UPDATE premium_tokens SET status = 'suspended' WHERE subscriber_email = ? AND provider = 'stripe'", [$email]);
                foreach ($tokens as $tk) {
                    kp_premium_send_status_email($email, (int)$tk['podcast_id'], 'suspended');
                }
            }
            kp_alert('system', 'Suscripción Pausada (Stripe)', "La suscripción de $email ha sido pausada en Stripe.", '');
        }
    } 
    elseif ($event_type === 'customer.subscription.resumed') {
        $subscription = $event['data']['object'];
        $customer_id = $subscription['customer'] ?? '';
        $email = kp_premium_get_stripe_customer_email($customer_id);
        $podcast_id = (int)($subscription['metadata']['podcast_id'] ?? 0);
        
        if ($email) {
            if ($podcast_id > 0) {
                $token = kp_one("SELECT token FROM premium_tokens WHERE podcast_id = ? AND subscriber_email = ?", [$podcast_id, $email]);
                kp_exec("UPDATE premium_tokens SET status = 'active' WHERE podcast_id = ? AND subscriber_email = ?", [$podcast_id, $email]);
                kp_premium_send_status_email($email, $podcast_id, 'welcome', $token['token'] ?? '');
            } else {
                $tokens = kp_q("SELECT t.* FROM premium_tokens t WHERE t.subscriber_email = ? AND t.provider = 'stripe'", [$email]) ?: [];
                kp_exec("UPDATE premium_tokens SET status = 'active' WHERE subscriber_email = ? AND provider = 'stripe'", [$email]);
                foreach ($tokens as $tk) {
                    kp_premium_send_status_email($email, (int)$tk['podcast_id'], 'welcome', $tk['token']);
                }
            }
            kp_alert('system', 'Suscripción Reactivada (Stripe)', "La suscripción de $email ha sido reactivada en Stripe.", '');
        }
    } 
    elseif ($event_type === 'invoice.payment_failed') {
        $invoice = $event['data']['object'];
        $customer_id = $invoice['customer'] ?? '';
        $email = kp_premium_get_stripe_customer_email($customer_id);
        
        if ($email) {
            $tokens = kp_q("SELECT t.* FROM premium_tokens t WHERE t.subscriber_email = ? AND t.provider = 'stripe'", [$email]) ?: [];
            kp_exec("UPDATE premium_tokens SET status = 'suspended' WHERE subscriber_email = ? AND provider = 'stripe'", [$email]);
            foreach ($tokens as $tk) {
                kp_premium_send_status_email($email, (int)$tk['podcast_id'], 'suspended');
            }
            kp_alert('system', 'Fallo de Pago (Stripe)', "El cobro de la suscripción de $email ha fallado en Stripe. Acceso suspendido temporalmente.", '');
        }
    } 
    elseif ($event_type === 'invoice.paid') {
        $invoice = $event['data']['object'];
        $customer_id = $invoice['customer'] ?? '';
        $email = kp_premium_get_stripe_customer_email($customer_id);
        
        if ($email) {
            $tokens = kp_q("SELECT t.* FROM premium_tokens t WHERE t.subscriber_email = ? AND t.provider = 'stripe'", [$email]) ?: [];
            kp_exec("UPDATE premium_tokens SET status = 'active' WHERE subscriber_email = ? AND provider = 'stripe'", [$email]);
            foreach ($tokens as $tk) {
                kp_premium_send_status_email($email, (int)$tk['podcast_id'], 'welcome', $tk['token']);
            }
        }
    }

    http_response_code(200);
    exit('Webhook de Stripe procesado con éxito.');
}

/**
 * Renderiza el badge Premium en el encabezado del podcast.
 */
function kp_premium_plugin_public_show_hero_meta(array $p): void {
    $premium_enabled = (int)kp_get_podcast_meta($p, 'premium_enabled', 0);
    if (!$premium_enabled) return;

    $price = (float)kp_get_podcast_meta($p, 'premium_price', 5.00);
    $price_year = (float)kp_get_podcast_meta($p, 'premium_price_year', 0.00);
    $stripe_active = (kp_setting('premium_stripe_secret_key', '') !== '');
    $paypal_active = (kp_setting('premium_paypal_client_id', '') !== '');
    
    $podcast_id = $p['db_id'] ?? 0;
    if ($podcast_id === 0 && isset($p['id'])) {
        $found = kp_one("SELECT id FROM podcasts WHERE LOWER(slug) = LOWER(?)", [$p['id']]);
        $podcast_id = $found ? (int)$found['id'] : 0;
    }

    $tokenStr = trim($_GET['token'] ?? '');
    $has_valid_token = false;
    if (!empty($tokenStr) && $podcast_id > 0) {
        $token = kp_one("SELECT * FROM premium_tokens WHERE token = ? AND podcast_id = ? AND status = 'active'", [$tokenStr, $podcast_id]);
        if ($token) {
            $has_valid_token = true;
        }
    }

    // Propietario / Administrador bypass
    if (!function_exists('kp_current_user')) {
        require_once __DIR__ . '/../../includes/auth.php';
    }
    $u = kp_current_user();
    $is_owner_admin = ($u && in_array($u['role'], ['owner', 'admin'], true));
    ?>
    <div style="margin-top:8px; margin-bottom:14px; display:flex; flex-direction:column; gap:12px; align-items:flex-start">
      <div style="display:inline-flex; align-items:center; gap:8px; background:var(--accent-soft); border:1px solid rgba(255, 95, 126, 0.2); padding:6px 12px; border-radius:100px; color:var(--accent); font-size:13px; font-weight:600">
        <?= icon($is_owner_admin ? 'shield' : 'lock', 14) ?> Podcast Premium · $<?= number_format($price, 2) ?> USD / mes<?= $price_year > 0 ? ' o $' . number_format($price_year, 2) . ' USD / año' : '' ?>
      </div>
      
      <?php if ($is_owner_admin): ?>
        <div style="font-size:13px; color:#3b82f6; font-weight:600; display:flex; align-items:center; gap:6px">
          <?= icon('shield', 14) ?> Acceso de Administrador / Propietario Autorizado.
        </div>
      <?php elseif ($has_valid_token): ?>
        <div style="font-size:13px; color:#10b981; font-weight:600; display:flex; align-items:center; gap:6px">
          <?= icon('check', 14) ?> Tienes una suscripción premium activa.
        </div>
      <?php else: ?>
        <?php if ($price_year > 0): ?>
          <?php $savings = ($price * 12) - $price_year; ?>
          <div style="display:flex; gap:16px; flex-wrap:wrap; margin-top:8px; width:100%">
            <?php if ($stripe_active): ?>
              <div style="flex:1; min-width:220px; max-width:280px; border:1px solid rgba(0,0,0,0.08); border-radius:12px; overflow:hidden; background:var(--pub-surface, #fff); box-shadow:0 4px 12px rgba(0,0,0,0.03); display:flex; flex-direction:column">
                <div style="background:#635bff; color:#fff; padding:10px 16px; font-weight:700; text-align:center; font-size:13px; letter-spacing:0.5px">
                  Stripe
                </div>
                <div style="padding:16px; display:flex; flex-direction:column; gap:12px; align-items:center; flex-grow:1; justify-content:center">
                  <a class="pub-btn pub-btn-primary" href="/premium/checkout/stripe?podcast_id=<?= (int)$podcast_id ?>&podcast=<?= urlencode($p['id'] ?? '') ?>&billing=monthly" style="text-decoration:none; width:100%; text-align:center; font-weight:700; background:#f3f4f6; color:#1f2937; padding:10px 16px; font-size:12.5px; cursor:pointer; border-radius:8px; display:block; border:1px solid #e5e7eb">
                    Mensual ($<?= number_format($price, 2) ?>)
                  </a>
                  <div style="width:100%; display:flex; flex-direction:column; gap:4px; align-items:center">
                    <a class="pub-btn pub-btn-primary" href="/premium/checkout/stripe?podcast_id=<?= (int)$podcast_id ?>&podcast=<?= urlencode($p['id'] ?? '') ?>&billing=annual" style="text-decoration:none; width:100%; text-align:center; font-weight:700; background:#635bff; color:#fff; padding:10px 16px; font-size:12.5px; cursor:pointer; border-radius:8px; display:block; border:none">
                      Anual ($<?= number_format($price_year, 2) ?>)
                    </a>
                    <?php if ($savings > 0): ?>
                      <span style="font-size:11px; color:#10b981; font-weight:700; margin-top:2px">
                        Ahorras $<?= number_format($savings, 2) ?>
                      </span>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            <?php endif; ?>

            <?php if ($paypal_active): ?>
              <div style="flex:1; min-width:220px; max-width:280px; border:1px solid rgba(0,0,0,0.08); border-radius:12px; overflow:hidden; background:var(--pub-surface, #fff); box-shadow:0 4px 12px rgba(0,0,0,0.03); display:flex; flex-direction:column">
                <div style="background:#003087; color:#fff; padding:10px 16px; font-weight:700; text-align:center; font-size:13px; letter-spacing:0.5px">
                  PayPal
                </div>
                <div style="padding:16px; display:flex; flex-direction:column; gap:12px; align-items:center; flex-grow:1; justify-content:center">
                  <a class="pub-btn pub-btn-primary" href="/premium/checkout/paypal?podcast_id=<?= (int)$podcast_id ?>&podcast=<?= urlencode($p['id'] ?? '') ?>&billing=monthly" style="text-decoration:none; width:100%; text-align:center; font-weight:700; background:#f3f4f6; color:#1f2937; padding:10px 16px; font-size:12.5px; cursor:pointer; border-radius:8px; display:block; border:1px solid #e5e7eb">
                    Mensual ($<?= number_format($price, 2) ?>)
                  </a>
                  <div style="width:100%; display:flex; flex-direction:column; gap:4px; align-items:center">
                    <a class="pub-btn pub-btn-primary" href="/premium/checkout/paypal?podcast_id=<?= (int)$podcast_id ?>&podcast=<?= urlencode($p['id'] ?? '') ?>&billing=annual" style="text-decoration:none; width:100%; text-align:center; font-weight:700; background:#ffc439; color:#111; padding:10px 16px; font-size:12.5px; cursor:pointer; border-radius:8px; display:block; border:none">
                      Anual ($<?= number_format($price_year, 2) ?>)
                    </a>
                    <?php if ($savings > 0): ?>
                      <span style="font-size:11px; color:#10b981; font-weight:700; margin-top:2px">
                        Ahorras $<?= number_format($savings, 2) ?>
                      </span>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            <?php endif; ?>

            <?php if (!$stripe_active && !$paypal_active): ?>
              <button class="pub-btn pub-btn-primary" onclick="alert('Esta suscripción premium se gestiona de forma manual. Por favor, ponte en contacto con el administrador.')" style="font-weight:700; background:var(--accent); border-color:var(--accent); padding:8px 18px; font-size:13px; cursor:pointer; border-radius:8px; border:none">
                <?= icon('users', 14) ?> Contactar Administrador
              </button>
            <?php endif; ?>
          </div>
        <?php else: ?>
          <div style="display:flex; gap:8px; flex-wrap:wrap; margin-top:4px">
            <?php if ($stripe_active): ?>
              <a class="pub-btn pub-btn-primary" href="/premium/checkout/stripe?podcast_id=<?= (int)$podcast_id ?>&podcast=<?= urlencode($p['id'] ?? '') ?>" style="text-decoration:none; font-weight:700; background:#635bff; border-color:#635bff; color:#fff; padding:8px 18px; font-size:13px; cursor:pointer; border-radius:8px; display:inline-flex; align-items:center; gap:6px; border:none">
                Suscribirse con Stripe
              </a>
            <?php endif; ?>
            <?php if ($paypal_active): ?>
              <a class="pub-btn pub-btn-primary" href="/premium/checkout/paypal?podcast_id=<?= (int)$podcast_id ?>&podcast=<?= urlencode($p['id'] ?? '') ?>" style="text-decoration:none; font-weight:700; background:#ffc439; border-color:#ffc439; color:#111; padding:8px 18px; font-size:13px; cursor:pointer; border-radius:8px; display:inline-flex; align-items:center; gap:6px; border:none">
                Suscribirse con PayPal
              </a>
            <?php endif; ?>
            <?php if (!$stripe_active && !$paypal_active): ?>
              <button class="pub-btn pub-btn-primary" onclick="alert('Esta suscripción premium se gestiona de forma manual. Por favor, ponte en contacto con el administrador.')" style="font-weight:700; background:var(--accent); border-color:var(--accent); padding:8px 18px; font-size:13px; cursor:pointer; border-radius:8px; border:none">
                <?= icon('users', 14) ?> Contactar Administrador
              </button>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      <?php endif; ?>
    </div>
    <?php
}

/**
 * Filtra el botón de play en el listado de episodios para bloquear accesos no autorizados.
 */
function kp_premium_plugin_public_show_episode_play_button(string $html, array $ep, array $p): string {
    $podcast_premium = (int)kp_get_podcast_meta($p, 'premium_enabled', 0);
    $episode_premium = (int)kp_get_episode_meta($ep, 'premium_enabled', 0);

    if (!$podcast_premium && !$episode_premium) {
        return $html;
    }

    // Propietario / Administrador bypass
    if (!function_exists('kp_current_user')) {
        require_once __DIR__ . '/../../includes/auth.php';
    }
    $u = kp_current_user();
    if ($u && in_array($u['role'], ['owner', 'admin'], true)) {
        return $html;
    }

    $tokenStr = trim($_GET['token'] ?? '');
    $has_valid_token = false;
    if (!empty($tokenStr)) {
        $token = kp_one("SELECT * FROM premium_tokens WHERE token = ? AND podcast_id = ? AND status = 'active'", [$tokenStr, $p['id']]);
        if ($token) {
            $has_valid_token = true;
        }
    }

    if ($has_valid_token) {
        return str_replace('audio.mp3', 'audio.mp3?token=' . urlencode($tokenStr), $html);
    }

    $label = 'Premium' . (!empty($ep['duration']) ? ' · ' . e($ep['duration']) : '');
    return '<button class="pub-ep-play-pill" style="background:#4b5563; cursor:not-allowed; color:#fff" title="Contenido Premium" onclick="alert(\'Este episodio es exclusivo para suscriptores Premium. Por favor, suscríbete para acceder al contenido.\')">
        ' . icon('lock', 13) . '
        <span>' . $label . '</span>
    </button>';
}

/**
 * Muestra el reproductor de audio autorizado (con token) o la tarjeta de Paywall de Stripe en la vista del episodio.
 */
function kp_premium_plugin_public_episode_play_control(string $html, array $ep, array $p): string {
    $podcast_premium = (int)kp_get_podcast_meta($p, 'premium_enabled', 0);
    $episode_premium = (int)kp_get_episode_meta($ep, 'premium_enabled', 0);

    if (!$podcast_premium && !$episode_premium) {
        return $html;
    }

    // Propietario / Administrador bypass
    if (!function_exists('kp_current_user')) {
        require_once __DIR__ . '/../../includes/auth.php';
    }
    $u = kp_current_user();
    if ($u && in_array($u['role'], ['owner', 'admin'], true)) {
        return '<div style="margin-bottom:12px; font-size:12.5px; color:#3b82f6; font-weight:600; display:flex; align-items:center; gap:6px">'
            . icon('shield', 14) . ' Acceso de Administrador / Propietario Autorizado
        </div>' . $html;
    }

    $tokenStr = trim($_GET['token'] ?? '');
    $has_valid_token = false;
    if (!empty($tokenStr)) {
        $token = kp_one("SELECT * FROM premium_tokens WHERE token = ? AND podcast_id = ? AND status = 'active'", [$tokenStr, $p['id']]);
        if ($token) {
            $has_valid_token = true;
        }
    }

    if ($has_valid_token) {
        $html_with_token = str_replace('audio.mp3', 'audio.mp3?token=' . urlencode($tokenStr), $html);
        return '<div style="margin-bottom:12px; font-size:12.5px; color:#10b981; font-weight:600; display:flex; align-items:center; gap:6px">'
            . icon('check', 14) . ' Suscripción Premium Activa
        </div>' . $html_with_token;
    }

    $stripe_active = (kp_setting('premium_stripe_secret_key', '') !== '');
    $paypal_active = (kp_setting('premium_paypal_client_id', '') !== '');

    $price = (float)kp_get_podcast_meta($p, 'premium_price', 5.00);
    $price_year = (float)kp_get_podcast_meta($p, 'premium_price_year', 0.00);

    ob_start();
    ?>
    <div style="background:var(--pub-surface); border:1px dashed var(--pub-border); padding:24px; border-radius:16px; margin:20px 0; display:flex; flex-direction:column; gap:16px">
      <div style="display:flex; align-items:center; gap:12px">
        <div style="width:44px; height:44px; border-radius:50%; background:var(--accent-soft); color:var(--accent); display:grid; place-items:center; flex-shrink:0">
          <?= icon('lock', 20) ?>
        </div>
        <div>
          <h3 style="margin:0; font-size:15px; font-weight:700; color:var(--pub-text)">Contenido Premium Exclusivo</h3>
          <p style="margin:4px 0 0 0; font-size:12.5px; color:var(--pub-muted)">Suscríbete para desbloquear este episodio y acceder a todo el catálogo privado.</p>
        </div>
      </div>
      
      <div style="display:flex; flex-direction:column; gap:16px; padding-top:14px; border-top:1px solid var(--pub-border)">
        <div style="font-size:13.5px; color:var(--pub-text)">
          <?php if ($price_year > 0): ?>
            Suscripción: <strong style="color:var(--accent)">$<?= number_format($price, 2) ?> USD/mes</strong> o <strong style="color:var(--accent)">$<?= number_format($price_year, 2) ?> USD/año</strong>
          <?php else: ?>
            Precio mensual: <strong style="font-size:16px; color:var(--accent)">$<?= number_format($price, 2) ?> USD</strong>
          <?php endif; ?>
        </div>
        
        <?php
        $podcast_id = $p['db_id'] ?? 0;
        if ($podcast_id === 0 && isset($p['id'])) {
            $found = kp_one("SELECT id FROM podcasts WHERE LOWER(slug) = LOWER(?)", [$p['id']]);
            $podcast_id = $found ? (int)$found['id'] : 0;
        }
        ?>

        <?php if ($price_year > 0): ?>
          <?php $savings = ($price * 12) - $price_year; ?>
          <div style="display:flex; gap:16px; flex-wrap:wrap; margin-top:8px; width:100%">
            <?php if ($stripe_active): ?>
              <div style="flex:1; min-width:220px; max-width:280px; border:1px solid rgba(0,0,0,0.08); border-radius:12px; overflow:hidden; background:var(--pub-surface, #fff); box-shadow:0 4px 12px rgba(0,0,0,0.03); display:flex; flex-direction:column">
                <div style="background:#635bff; color:#fff; padding:10px 16px; font-weight:700; text-align:center; font-size:13px; letter-spacing:0.5px">
                  Stripe
                </div>
                <div style="padding:16px; display:flex; flex-direction:column; gap:12px; align-items:center; flex-grow:1; justify-content:center">
                  <a class="pub-btn pub-btn-primary" href="/premium/checkout/stripe?podcast_id=<?= (int)$podcast_id ?>&podcast=<?= urlencode($p['id'] ?? '') ?>&billing=monthly" style="text-decoration:none; width:100%; text-align:center; font-weight:700; background:#f3f4f6; color:#1f2937; padding:10px 16px; font-size:12.5px; cursor:pointer; border-radius:8px; display:block; border:1px solid #e5e7eb">
                    Mensual ($<?= number_format($price, 2) ?>)
                  </a>
                  <div style="width:100%; display:flex; flex-direction:column; gap:4px; align-items:center">
                    <a class="pub-btn pub-btn-primary" href="/premium/checkout/stripe?podcast_id=<?= (int)$podcast_id ?>&podcast=<?= urlencode($p['id'] ?? '') ?>&billing=annual" style="text-decoration:none; width:100%; text-align:center; font-weight:700; background:#635bff; color:#fff; padding:10px 16px; font-size:12.5px; cursor:pointer; border-radius:8px; display:block; border:none">
                      Anual ($<?= number_format($price_year, 2) ?>)
                    </a>
                    <?php if ($savings > 0): ?>
                      <span style="font-size:11px; color:#10b981; font-weight:700; margin-top:2px">
                        Ahorras $<?= number_format($savings, 2) ?>
                      </span>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            <?php endif; ?>

            <?php if ($paypal_active): ?>
              <div style="flex:1; min-width:220px; max-width:280px; border:1px solid rgba(0,0,0,0.08); border-radius:12px; overflow:hidden; background:var(--pub-surface, #fff); box-shadow:0 4px 12px rgba(0,0,0,0.03); display:flex; flex-direction:column">
                <div style="background:#003087; color:#fff; padding:10px 16px; font-weight:700; text-align:center; font-size:13px; letter-spacing:0.5px">
                  PayPal
                </div>
                <div style="padding:16px; display:flex; flex-direction:column; gap:12px; align-items:center; flex-grow:1; justify-content:center">
                  <a class="pub-btn pub-btn-primary" href="/premium/checkout/paypal?podcast_id=<?= (int)$podcast_id ?>&podcast=<?= urlencode($p['id'] ?? '') ?>&billing=monthly" style="text-decoration:none; width:100%; text-align:center; font-weight:700; background:#f3f4f6; color:#1f2937; padding:10px 16px; font-size:12.5px; cursor:pointer; border-radius:8px; display:block; border:1px solid #e5e7eb">
                    Mensual ($<?= number_format($price, 2) ?>)
                  </a>
                  <div style="width:100%; display:flex; flex-direction:column; gap:4px; align-items:center">
                    <a class="pub-btn pub-btn-primary" href="/premium/checkout/paypal?podcast_id=<?= (int)$podcast_id ?>&podcast=<?= urlencode($p['id'] ?? '') ?>&billing=annual" style="text-decoration:none; width:100%; text-align:center; font-weight:700; background:#ffc439; color:#111; padding:10px 16px; font-size:12.5px; cursor:pointer; border-radius:8px; display:block; border:none">
                      Anual ($<?= number_format($price_year, 2) ?>)
                    </a>
                    <?php if ($savings > 0): ?>
                      <span style="font-size:11px; color:#10b981; font-weight:700; margin-top:2px">
                        Ahorras $<?= number_format($savings, 2) ?>
                      </span>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            <?php endif; ?>

            <?php if (!$stripe_active && !$paypal_active): ?>
              <button class="pub-btn pub-btn-primary" onclick="alert('Esta suscripción premium se gestiona de forma manual. Por favor, ponte en contacto con el administrador.')" style="font-weight:700; background:var(--accent); border-color:var(--accent); padding:8px 18px; font-size:13px; cursor:pointer; border-radius:8px; border:none">
                <?= icon('users', 14) ?> Contactar Administrador
              </button>
            <?php endif; ?>
          </div>
        <?php else: ?>
          <div style="display:flex; gap:8px; flex-wrap:wrap; margin-top:4px">
            <?php if ($stripe_active): ?>
              <a class="pub-btn pub-btn-primary" href="/premium/checkout/stripe?podcast_id=<?= (int)$podcast_id ?>&podcast=<?= urlencode($p['id'] ?? '') ?>" style="text-decoration:none; font-weight:700; background:#635bff; border-color:#635bff; color:#fff; padding:8px 18px; font-size:13px; cursor:pointer; border-radius:8px; display:inline-flex; align-items:center; gap:6px; border:none">
                Suscribirse con Stripe
              </a>
            <?php endif; ?>
            <?php if ($paypal_active): ?>
              <a class="pub-btn pub-btn-primary" href="/premium/checkout/paypal?podcast_id=<?= (int)$podcast_id ?>&podcast=<?= urlencode($p['id'] ?? '') ?>" style="text-decoration:none; font-weight:700; background:#ffc439; border-color:#ffc439; color:#111; padding:8px 18px; font-size:13px; cursor:pointer; border-radius:8px; display:inline-flex; align-items:center; gap:6px; border:none">
                Suscribirse con PayPal
              </a>
            <?php endif; ?>
            <?php if (!$stripe_active && !$paypal_active): ?>
              <button class="pub-btn pub-btn-primary" onclick="alert('Esta suscripción premium se gestiona de forma manual. Por favor, ponte en contacto con el administrador.')" style="font-weight:700; background:var(--accent); border-color:var(--accent); padding:8px 18px; font-size:13px; cursor:pointer; border-radius:8px; border:none">
                <?= icon('users', 14) ?> Contactar Administrador
              </button>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * Procesa la notificación del webhook de PayPal.
 */
function kp_premium_process_paypal_webhook(): void {
    $client_id = kp_setting('premium_paypal_client_id', '');
    $client_secret = kp_setting('premium_paypal_client_secret', '');
    $webhook_id = kp_setting('premium_paypal_webhook_id', '');
    $mode = kp_setting('premium_paypal_mode', 'sandbox');

    if (empty($client_id) || empty($client_secret) || empty($webhook_id)) {
        http_response_code(400);
        exit('Credenciales de PayPal no configuradas');
    }

    $raw_body = file_get_contents('php://input');
    $event = json_decode($raw_body, true);

    if (!$event || empty($event['event_type'])) {
        http_response_code(400);
        exit('Payload de PayPal inválido');
    }

    // 1. Obtener Access Token de PayPal
    $api_url = ($mode === 'live') ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $api_url . '/v1/oauth2/token');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_USERPWD, $client_id . ':' . $client_secret);
    curl_setopt($ch, CURLOPT_POSTFIELDS, 'grant_type=client_credentials');
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: application/json',
        'Accept-Language: en_US'
    ]);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200) {
        http_response_code(500);
        exit('Error al obtener access token de PayPal');
    }

    $token_data = json_decode($response, true);
    $access_token = $token_data['access_token'] ?? '';
    if (empty($access_token)) {
        http_response_code(500);
        exit('Token de acceso de PayPal vacío');
    }

    // Helper interno para obtener cabeceras
    $get_header = function(string $name): string {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (isset($_SERVER[$key])) {
            return $_SERVER[$key];
        }
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            foreach ($headers as $k => $v) {
                if (strcasecmp($k, $name) === 0) {
                    return $v;
                }
            }
        }
        return '';
    };

    // 2. Verificar la firma del webhook
    $verify_payload = [
        'auth_algo'         => $get_header('PAYPAL-AUTH-ALGO'),
        'cert_url'          => $get_header('PAYPAL-CERT-URL'),
        'transmission_id'   => $get_header('PAYPAL-TRANSMISSION-ID'),
        'transmission_sig'  => $get_header('PAYPAL-TRANSMISSION-SIG'),
        'transmission_time' => $get_header('PAYPAL-TRANSMISSION-TIME'),
        'webhook_id'        => $webhook_id,
        'webhook_event'     => $event
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $api_url . '/v1/notifications/verify-webhook-signature');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($verify_payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $access_token,
        'Content-Type: application/json'
    ]);
    $verify_response = curl_exec($ch);
    $verify_http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($verify_http_code !== 200) {
        http_response_code(400);
        exit('Error en el servicio de verificación de firmas de PayPal');
    }

    $verify_data = json_decode($verify_response, true);
    $status = $verify_data['verification_status'] ?? '';
    if (strpos(strtoupper($status), 'SUCCESS') === false) {
        http_response_code(400);
        exit('Firma del webhook de PayPal no es válida');
    }

    // 3. Procesar eventos de pago/suscripción
    $event_type = $event['event_type'];
    $resource = $event['resource'] ?? [];
    
    // El podcast ID es transmitido como custom_id o custom en el objeto de la transacción/suscripción
    $podcast_id = (int)($resource['custom_id'] ?? $resource['custom'] ?? 0);
    
    // Correo del suscriptor/comprador
    $email = '';
    if (isset($resource['subscriber']['email_address'])) {
        $email = $resource['subscriber']['email_address'];
    } elseif (isset($resource['payer']['email_address'])) {
        $email = $resource['payer']['email_address'];
    } elseif (isset($event['payer']['email_address'])) {
        $email = $event['payer']['email_address'];
    }

    if ($email) {
        if ($event_type === 'BILLING.SUBSCRIPTION.ACTIVATED' || $event_type === 'PAYMENT.SALE.COMPLETED') {
            if ($podcast_id > 0) {
                $existing = kp_one("SELECT id, token FROM premium_tokens WHERE podcast_id = ? AND subscriber_email = ?", [$podcast_id, $email]);
                if ($existing) {
                    $tokenStr = $existing['token'];
                    kp_exec("UPDATE premium_tokens SET status = 'active', provider = 'paypal' WHERE id = ?", [$existing['id']]);
                } else {
                    $tokenStr = bin2hex(random_bytes(16));
                    kp_exec("INSERT INTO premium_tokens (podcast_id, subscriber_email, token, provider, status) VALUES (?, ?, ?, 'paypal', 'active')", [
                        $podcast_id, $email, $tokenStr
                    ]);
                }
                kp_alert('system', 'Nueva Suscripción Premium (PayPal)', "El usuario $email se ha suscrito mediante PayPal.", '');
                kp_premium_send_status_email($email, $podcast_id, 'welcome', $tokenStr);
            }
        } 
        elseif ($event_type === 'BILLING.SUBSCRIPTION.CANCELLED') {
            if ($podcast_id > 0) {
                kp_exec("UPDATE premium_tokens SET status = 'cancelled' WHERE podcast_id = ? AND subscriber_email = ?", [$podcast_id, $email]);
                kp_premium_send_status_email($email, $podcast_id, 'cancelled');
            } else {
                $tokens = kp_q("SELECT t.* FROM premium_tokens t WHERE t.subscriber_email = ? AND t.provider = 'paypal'", [$email]) ?: [];
                kp_exec("UPDATE premium_tokens SET status = 'cancelled' WHERE subscriber_email = ? AND provider = 'paypal'", [$email]);
                foreach ($tokens as $tk) {
                    kp_premium_send_status_email($email, (int)$tk['podcast_id'], 'cancelled');
                }
            }
            kp_alert('system', 'Suscripción Cancelada (PayPal)', "La suscripción de $email ha sido cancelada en PayPal.", '');
        } 
        elseif ($event_type === 'BILLING.SUBSCRIPTION.SUSPENDED') {
            if ($podcast_id > 0) {
                kp_exec("UPDATE premium_tokens SET status = 'suspended' WHERE podcast_id = ? AND subscriber_email = ?", [$podcast_id, $email]);
                kp_premium_send_status_email($email, $podcast_id, 'suspended');
            } else {
                $tokens = kp_q("SELECT t.* FROM premium_tokens t WHERE t.subscriber_email = ? AND t.provider = 'paypal'", [$email]) ?: [];
                kp_exec("UPDATE premium_tokens SET status = 'suspended' WHERE subscriber_email = ? AND provider = 'paypal'", [$email]);
                foreach ($tokens as $tk) {
                    kp_premium_send_status_email($email, (int)$tk['podcast_id'], 'suspended');
                }
            }
            kp_alert('system', 'Suscripción Suspendida (PayPal)', "La suscripción de $email ha sido suspendida en PayPal.", '');
        } 
        elseif ($event_type === 'BILLING.SUBSCRIPTION.RE-ACTIVATED') {
            if ($podcast_id > 0) {
                $token = kp_one("SELECT token FROM premium_tokens WHERE podcast_id = ? AND subscriber_email = ?", [$podcast_id, $email]);
                kp_exec("UPDATE premium_tokens SET status = 'active' WHERE podcast_id = ? AND subscriber_email = ?", [$podcast_id, $email]);
                kp_premium_send_status_email($email, $podcast_id, 'welcome', $token['token'] ?? '');
            } else {
                $tokens = kp_q("SELECT t.* FROM premium_tokens t WHERE t.subscriber_email = ? AND t.provider = 'paypal'", [$email]) ?: [];
                kp_exec("UPDATE premium_tokens SET status = 'active' WHERE subscriber_email = ? AND provider = 'paypal'", [$email]);
                foreach ($tokens as $tk) {
                    kp_premium_send_status_email($email, (int)$tk['podcast_id'], 'welcome', $tk['token']);
                }
            }
            kp_alert('system', 'Suscripción Reactivada (PayPal)', "La suscripción de $email ha sido reactivada en PayPal.", '');
        } 
        elseif ($event_type === 'PAYMENT.SALE.DENIED') {
            if ($podcast_id > 0) {
                kp_exec("UPDATE premium_tokens SET status = 'suspended' WHERE podcast_id = ? AND subscriber_email = ?", [$podcast_id, $email]);
                kp_premium_send_status_email($email, $podcast_id, 'suspended');
            } else {
                $tokens = kp_q("SELECT t.* FROM premium_tokens t WHERE t.subscriber_email = ? AND t.provider = 'paypal'", [$email]) ?: [];
                kp_exec("UPDATE premium_tokens SET status = 'suspended' WHERE subscriber_email = ? AND provider = 'paypal'", [$email]);
                foreach ($tokens as $tk) {
                    kp_premium_send_status_email($email, (int)$tk['podcast_id'], 'suspended');
                }
            }
            kp_alert('system', 'Fallo de Pago (PayPal)', "El cobro de PayPal para $email ha sido denegado. Acceso suspendido temporalmente.", '');
        }
    }

    http_response_code(200);
    exit('Webhook de PayPal procesado con éxito.');
}

/**
 * Crea una sesión de Stripe Checkout y redirige al usuario.
 */
function kp_premium_create_stripe_checkout(): void {
    $podcast_id = (int)($_GET['podcast_id'] ?? 0);
    $slug = trim($_GET['podcast'] ?? '');
    $billing = trim($_GET['billing'] ?? '');

    // Fallback: parsear directamente de REQUEST_URI por si Nginx sobrescribió $_GET en el rewrite
    if ($podcast_id <= 0 && $slug === '') {
        $query_str = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_QUERY);
        if ($query_str) {
            parse_str($query_str, $parsed);
            $podcast_id = (int)($parsed['podcast_id'] ?? 0);
            $slug = trim($parsed['podcast'] ?? '');
            if (empty($billing) && isset($parsed['billing'])) {
                $billing = trim($parsed['billing']);
            }
        }
    }

    if ($podcast_id <= 0 && $slug !== '') {
        $found = kp_one("SELECT id FROM podcasts WHERE LOWER(slug) = LOWER(?)", [$slug]);
        $podcast_id = $found ? (int)$found['id'] : 0;
    }
    if ($podcast_id <= 0) {
        http_response_code(400);
        exit('Podcast ID requerido');
    }

    $p = kp_one("SELECT * FROM podcasts WHERE id = ?", [$podcast_id]);
    if (!$p) {
        http_response_code(404);
        exit('Podcast no encontrado');
    }

    $secret = kp_setting('premium_stripe_secret_key', '');
    if (empty($secret)) {
        exit('Error: Clave secreta de Stripe no configurada en el panel administrativo.');
    }

    $billing = $billing === 'annual' ? 'annual' : 'monthly';
    if ($billing === 'annual') {
        $price = (float)kp_get_podcast_meta($p, 'premium_price_year', 0.00);
        if ($price <= 0) {
            $price = (float)kp_get_podcast_meta($p, 'premium_price', 5.00) * 12;
        }
        $name = 'Suscripción Premium Anual - ' . $p['title'];
    } else {
        $price = (float)kp_get_podcast_meta($p, 'premium_price', 5.00);
        $name = 'Suscripción Premium Mensual - ' . $p['title'];
    }
    $price_in_cents = round($price * 100);

    $domain = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
    $success_url = $protocol . $domain . '/@' . urlencode($p['slug']) . '?status=success';
    $cancel_url = $protocol . $domain . '/@' . urlencode($p['slug']) . '?status=cancel';

    $post_fields = [
        'success_url' => $success_url,
        'cancel_url' => $cancel_url,
        'mode' => 'payment',
        'payment_method_types[0]' => 'card',
        'line_items[0][price_data][currency]' => 'usd',
        'line_items[0][price_data][product_data][name]' => $name,
        'line_items[0][price_data][unit_amount]' => $price_in_cents,
        'line_items[0][quantity]' => 1,
        'metadata[podcast_id]' => $podcast_id
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.stripe.com/v1/checkout/sessions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_USERPWD, $secret . ':');
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_fields));
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200) {
        $err = json_decode($response, true);
        $errMsg = $err['error']['message'] ?? 'Código HTTP ' . $http_code;
        kp_alert('system', 'Error de Stripe Checkout', "Error al crear sesión de checkout: $errMsg", '');
        http_response_code(500);
        exit('Error al procesar el pago. Por favor, contacta al administrador del sitio.');
    }

    $session = json_decode($response, true);
    $checkout_url = $session['url'] ?? '';

    if ($checkout_url) {
        header("Location: $checkout_url", true, 303);
        exit;
    }

    exit('Error al obtener URL de checkout');
}

/**
 * Crea una orden de PayPal y redirige al usuario a la aprobación de pago.
 */
function kp_premium_create_paypal_checkout(): void {
    $podcast_id = (int)($_GET['podcast_id'] ?? 0);
    $slug = trim($_GET['podcast'] ?? '');
    $billing = trim($_GET['billing'] ?? '');

    // Fallback: parsear directamente de REQUEST_URI por si Nginx sobrescribió $_GET en el rewrite
    if ($podcast_id <= 0 && $slug === '') {
        $query_str = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_QUERY);
        if ($query_str) {
            parse_str($query_str, $parsed);
            $podcast_id = (int)($parsed['podcast_id'] ?? 0);
            $slug = trim($parsed['podcast'] ?? '');
            if (empty($billing) && isset($parsed['billing'])) {
                $billing = trim($parsed['billing']);
            }
        }
    }

    if ($podcast_id <= 0 && $slug !== '') {
        $found = kp_one("SELECT id FROM podcasts WHERE LOWER(slug) = LOWER(?)", [$slug]);
        $podcast_id = $found ? (int)$found['id'] : 0;
    }
    if ($podcast_id <= 0) {
        http_response_code(400);
        exit('Podcast ID requerido');
    }

    $p = kp_one("SELECT * FROM podcasts WHERE id = ?", [$podcast_id]);
    if (!$p) {
        http_response_code(404);
        exit('Podcast no encontrado');
    }

    $client_id = kp_setting('premium_paypal_client_id', '');
    $client_secret = kp_setting('premium_paypal_client_secret', '');
    $mode = kp_setting('premium_paypal_mode', 'sandbox');

    if (empty($client_id) || empty($client_secret)) {
        exit('Error: Credenciales de PayPal no configuradas en el panel administrativo.');
    }

    $billing = $billing === 'annual' ? 'annual' : 'monthly';
    if ($billing === 'annual') {
        $price = (float)kp_get_podcast_meta($p, 'premium_price_year', 0.00);
        if ($price <= 0) {
            $price = (float)kp_get_podcast_meta($p, 'premium_price', 5.00) * 12;
        }
        $description = 'Suscripción Premium Anual - ' . $p['title'];
    } else {
        $price = (float)kp_get_podcast_meta($p, 'premium_price', 5.00);
        $description = 'Suscripción Premium Mensual - ' . $p['title'];
    }

    $domain = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
    $success_url = $protocol . $domain . '/@' . urlencode($p['slug']) . '?status=success';
    $cancel_url = $protocol . $domain . '/@' . urlencode($p['slug']) . '?status=cancel';

    $api_url = ($mode === 'live') ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';

    // 1. Obtener Access Token
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $api_url . '/v1/oauth2/token');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_USERPWD, $client_id . ':' . $client_secret);
    curl_setopt($ch, CURLOPT_POSTFIELDS, 'grant_type=client_credentials');
    $res = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200) {
        exit('Error de autenticación con PayPal');
    }

    $token_data = json_decode($res, true);
    $access_token = $token_data['access_token'] ?? '';

    // 2. Crear Orden de Pago
    $order_data = [
        'intent' => 'CAPTURE',
        'purchase_units' => [[
            'amount' => [
                'currency_code' => 'USD',
                'value' => number_format($price, 2, '.', '')
            ],
            'custom_id' => (string)$podcast_id,
            'description' => $description
        ]],
        'application_context' => [
            'return_url' => $success_url,
            'cancel_url' => $cancel_url,
            'user_action' => 'PAY_NOW'
        ]
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $api_url . '/v2/checkout/orders');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($order_data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $access_token,
        'Content-Type: application/json'
    ]);
    $res = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 201) {
        $err = json_decode($res, true);
        exit('Error al crear orden en PayPal: ' . ($err['message'] ?? 'Desconocido'));
    }

    $order = json_decode($res, true);
    $approve_url = '';
    foreach ($order['links'] ?? [] as $link) {
        if ($link['rel'] === 'approve') {
            $approve_url = $link['href'];
            break;
        }
    }

    if ($approve_url) {
        header("Location: $approve_url", true, 303);
        exit;
    }

    exit('Error al obtener URL de aprobación de PayPal');
}


