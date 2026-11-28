<?php
/**
 * Recordar un cobro al cliente por correo (lib/cobro_correo.php). Solo admin.
 *   GET  ?cuota=N  → vista previa: a quién va y qué dice
 *   POST ?cuota=N  → lo envía { to?, texto? }
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);

try {
    require_once __DIR__ . '/../config.php';
    cors();
    $db = getDB();
    $user = requireAuth();
    requireAdmin($user); // montos y datos de clientes: no es del trabajador
    require_once __DIR__ . '/../lib/cobro_correo.php';

    $cuotaId = (int)($_GET['cuota'] ?? 0);
    $metodo = $_SERVER['REQUEST_METHOD'];

    if ($metodo === 'GET') {
        $r = cobroRecordatorio($db, $cuotaId);
        if (!$r) err('Esa cuota ya no tiene saldo por cobrar.', 404);
        ok($r + ['correo_configurado' => smtpConfigurado(smtpConfig($db, true))]);
    }

    if ($metodo === 'POST') {
        rateLimit('cobro.recordar', 20, 3600);
        $b = body();
        try {
            $r = cobroRecordar($db, $cuotaId, isset($b['to']) ? (string)$b['to'] : null, isset($b['texto']) ? (string)$b['texto'] : null);
        } catch (RuntimeException $e) {
            err($e->getMessage(), 422);
        }
        audit($db, (int)$user['id'], 'UPDATE', 'pagos',
            'Recordó por correo ' . ($r['numero'] !== '' ? "la {$r['documento']} {$r['numero']}" : 'un pago') . " a {$r['cliente']} ({$r['enviado_a']})", (int)$r['pago_id']);
        ok(['enviado_a' => $r['enviado_a']]);
    }

    err('Método no permitido', 405);
} catch (Throwable $e) {
    errSafe($e, 500);
}
