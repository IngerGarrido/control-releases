<?php
/**
 * Recibe errores capturados por ErrorBoundary del frontend.
 * No requiere auth (queremos capturar errores incluso de la pantalla de login),
 * pero está rate-limiteado y solo loggea — no devuelve nada útil al cliente.
 */
require_once __DIR__ . '/../config.php';
cors();

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') err('Método no permitido', 405);

    rateLimit('client_errors', 30, 60); // 30 errores/min por IP máximo

    $b = body();
    $payload = [
        'message'        => substr((string)($b['message'] ?? ''), 0, 2000),
        'stack'          => substr((string)($b['stack'] ?? ''), 0, 8000),
        'componentStack' => substr((string)($b['componentStack'] ?? ''), 0, 4000),
        'url'            => substr((string)($b['url'] ?? ''), 0, 500),
        'userAgent'      => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300),
        'section'        => substr((string)($b['section'] ?? ''), 0, 80),
    ];

    // Intentar identificar al usuario si hay sesión
    // (requireAuth() termina la petición con 401 si no hay sesión, así que los
    // errores del login y de las páginas públicas nunca se registraban.)
    $tok = getToken();
    if ($tok) {
        $st = getDB()->prepare("SELECT usuario_id FROM sesiones WHERE token = ? AND expira_en > NOW() LIMIT 1");
        $st->execute([tokenHash($tok)]);
        if ($uidErr = $st->fetchColumn()) $payload['user_id'] = (int)$uidErr;
    }

    if (function_exists('logError')) {
        logError('Client error', $payload);
    } else {
        error_log('CLIENT_ERROR: ' . json_encode($payload));
    }

    ok(['received' => true]);
} catch (Throwable $e) {
    errSafe($e, 500, 'client_errors.php');
}
