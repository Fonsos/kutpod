<?php
// ============================================================================
// KutPod · Simple SQLite Cache System
// ============================================================================

if (!function_exists('kp_cache_db')) {
    function kp_cache_db(): ?PDO {
        static $pdo = null;
        if ($pdo) return $pdo;

        // Si la caché no está activada, retornar null para bypass
        if (function_exists('kp_setting') && !kp_setting('enable_cache', false)) {
            return null;
        }

        $dir = __DIR__ . '/../storage';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $path = $dir . '/cache.db';

        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA synchronous = NORMAL'); // Mayor velocidad
        
        $has = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='cache'")->fetch();
        if (!$has) {
            $pdo->exec("CREATE TABLE cache (
                key TEXT PRIMARY KEY,
                value TEXT,
                expires_at INTEGER
            )");
            $pdo->exec("CREATE INDEX idx_cache_expires ON cache(expires_at)");
        }
        
        // Limpieza periódica de expirados (garbage collection probabilística, 5%)
        if (rand(1, 100) <= 5) {
            $pdo->exec("DELETE FROM cache WHERE expires_at < " . time());
        }

        return $pdo;
    }
}

function kp_cache_get(string $key, $default = null) {
    $db = kp_cache_db();
    if (!$db) return $default;

    $st = $db->prepare("SELECT value, expires_at FROM cache WHERE key = ?");
    $st->execute([$key]);
    $row = $st->fetch();

    if ($row) {
        if ($row['expires_at'] >= time()) {
            if (str_starts_with($row['value'], '__JSON__')) {
                return json_decode(substr($row['value'], 8), true);
            }
            return $row['value'];
        } else {
            // Expiró
            $del = $db->prepare("DELETE FROM cache WHERE key = ?");
            $del->execute([$key]);
        }
    }
    return $default;
}

function kp_cache_set(string $key, $value, int $ttl = 3600): void {
    $db = kp_cache_db();
    if (!$db) return;

    $valStr = (is_array($value) || is_object($value)) ? ('__JSON__' . json_encode($value)) : (string)$value;
    $expires = time() + $ttl;

    $st = $db->prepare("INSERT INTO cache (key, value, expires_at) VALUES (?, ?, ?) 
                        ON CONFLICT(key) DO UPDATE SET value = excluded.value, expires_at = excluded.expires_at");
    $st->execute([$key, $valStr, $expires]);
}

function kp_cache_clear(): void {
    $path = __DIR__ . '/../storage/cache.db';
    if (file_exists($path)) {
        try {
            $pdo = new PDO('sqlite:' . $path, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
            ]);
            $pdo->exec("DELETE FROM cache");
            $pdo->exec("VACUUM");
        } catch (Throwable $e) {}
    }
}
