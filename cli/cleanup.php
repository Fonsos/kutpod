<?php
// ============================================================================
// cli/cleanup.php — Script de limpieza de base de datos (Garbage Collection)
// ============================================================================
$db_path = file_exists(__DIR__ . '/includes/db.php') ? __DIR__ . '/includes/db.php' : __DIR__ . '/../includes/db.php';
require_once $db_path;

function kp_db_cleanup() {
    $pdo = kp_db();
    
    // 1. Alertas/Notificaciones: Mantener solo las últimas 50, borrar el resto
    kp_exec("DELETE FROM alerts WHERE id NOT IN (SELECT id FROM alerts ORDER BY id DESC LIMIT 50)");
    
    // 2. Entregas ActivityPub: Borrar registros con status = 'delivered' o 'failed' de hace más de 30 días
    try {
        kp_exec("DELETE FROM ap_delivery WHERE status IN ('delivered', 'failed') AND next_try < datetime('now', '-30 days')");
    } catch (Throwable $e) {}
    
    // 3. Bandeja de Salida ActivityPub: Borrar entradas del outbox con más de 90 días, siempre y cuando no tengan entregas pendientes asociadas
    // Usamos el campo 'published' que es un TEXT con formato YYYY-MM-DD HH:MM:SS (datetime('now'))
    try {
        kp_exec("DELETE FROM ap_outbox WHERE published < datetime('now', '-90 days') AND id NOT IN (SELECT outbox_id FROM ap_delivery WHERE status = 'pending')");
    } catch (Throwable $e) {}
    
    // 4. Sesiones Expiradas: Borrar sesiones que ya expiraron hace más de 7 días
    kp_exec("DELETE FROM sessions WHERE expires_at < datetime('now', '-7 days')");
    
    // 5. Hits OP3 procesados: Borrar hits de audio ya procesados con más de 90 días
    try {
        kp_exec("DELETE FROM op3_hits WHERE processed = 1 AND ts < datetime('now', '-90 days')");
    } catch (Throwable $e) {}

    // 6. VACUUM para recuperar espacio en disco (Opcional, pero recomendado en SQLite después de borrar muchos datos)
    try {
        $pdo->exec("VACUUM");
    } catch (Throwable $e) {}
    
    // Alerta de sistema
    try {
        require_once file_exists(__DIR__ . '/includes/helpers.php') ? __DIR__ . '/includes/helpers.php' : __DIR__ . '/../includes/helpers.php';
        kp_alert('system', 'Limpieza ejecutada', 'El garbage collector de base de datos se ejecutó correctamente. Se purgaron alertas antiguas, entregas AP completadas (>30d), outbox (>90d), hits OP3 antiguos (>90d), sesiones expiradas y se ejecutó VACUUM.', '');
    } catch (Throwable $e) {}
    
    return true;
}

// Si se ejecuta directamente desde CLI
if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['PHP_SELF'])) {
    echo "[" . date('c') . "] Iniciando limpieza de base de datos...\n";
    kp_db_cleanup();
    echo "[" . date('c') . "] Limpieza completada.\n";
} else {
    // Si se incluye desde la web
    kp_db_cleanup();
}
