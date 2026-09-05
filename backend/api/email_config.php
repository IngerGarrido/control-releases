<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Configuración de correo (SMTP). Solo admin.
//   GET                      -> configuración SIN la contraseña (solo si existe)
//   PUT                      -> guarda la configuración (cifra la contraseña)
//   POST ?accion=test        -> envía un correo de prueba
//
// La contraseña NUNCA se devuelve al frontend: se guarda cifrada (AES-256-GCM)
// y solo se descifra en el momento de enviar.
try {
    require_once __DIR__ . '/../config.php';
    require_once __DIR__ . '/../mailer.php';
    cors();
    $db = getDB();
    $user = requireAuth();
    $db->exec("SET NAMES utf8mb4");
    $method = $_SERVER['REQUEST_METHOD'];
    if ($method === 'POST' && isset($_GET['_method'])) $method = strtoupper($_GET['_method']);

    // Toda la configuración de correo es sensible → solo admin.
    requireAdmin($user);

    if ($method === 'GET') {
        $cfg = smtpConfig($db);
        $cfg['configurado'] = smtpConfigurado($cfg);
        echo json_encode(['ok' => true, 'data' => $cfg], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($method === 'PUT') {
        $b = body();
        $clean = fn($s) => trim((string)($s ?? ''));

        $host = $clean($b['host'] ?? '');
        $user_smtp = $clean($b['user'] ?? '');
        $from = $clean($b['from'] ?? '');
        if ($from !== '' && !filter_var($from, FILTER_VALIDATE_EMAIL)) {
            err('El remitente debe ser un email válido', 422);
        }
        $port = (int)($b['port'] ?? 587);
        if ($port <= 0 || $port > 65535) $port = 587;
        $secure = in_array($b['secure'] ?? 'tls', ['tls', 'ssl', 'none'], true) ? $b['secure'] : 'tls';

        smtpSet($db, 'smtp_host', $host);
        smtpSet($db, 'smtp_port', (string)$port);
        smtpSet($db, 'smtp_user', $user_smtp);
        smtpSet($db, 'smtp_secure', $secure);
        smtpSet($db, 'smtp_from', $from);
        smtpSet($db, 'smtp_from_name', $clean($b['from_name'] ?? ''));

        // La contraseña solo se toca si viene una nueva (no vacía). Así editar
        // el host no borra la clave guardada.
        if (array_key_exists('password', $b) && $clean($b['password']) !== '') {
            smtpSet($db, 'smtp_pass_cipher', (string)encryptStr($clean($b['password'])));
        }
        // Permitir borrarla explícitamente.
        if (!empty($b['borrar_password'])) {
            smtpSet($db, 'smtp_pass_cipher', '');
        }

        audit($db, $user['id'], 'UPDATE', 'configuracion', 'Actualizó la configuración de correo (SMTP)');
        echo json_encode(['ok' => true]);
        exit;
    }

    // --- POST ?accion=test: envío de prueba ---
    if ($method === 'POST' && ($_GET['accion'] ?? '') === 'test') {
        // Evita que un error de config se convierta en spam de reintentos.
        rateLimit('email.test', 5, 300);

        $b = body();
        $destino = trim((string)($b['to'] ?? '')) ?: ($user['email'] ?? '');
        if (!filter_var($destino, FILTER_VALIDATE_EMAIL)) err('Email de destino inválido', 422);

        $html = correoPlantilla(
            'Prueba de configuración',
            '<p>Si estás leyendo esto, el correo del sistema quedó configurado correctamente.</p>'
            . '<p style="color:#6b7280;font-size:13px">Enviado desde tu instalación como prueba.</p>'
        );
        [$ok, $error] = enviarCorreo($db, $destino, 'Prueba de correo · Sistema', $html);

        if (!$ok) {
            echo json_encode(['ok' => false, 'error' => 'No se pudo enviar: ' . $error], JSON_UNESCAPED_UNICODE);
            exit;
        }
        audit($db, $user['id'], 'CREATE', 'configuracion', "Envió correo de prueba a {$destino}");
        echo json_encode(['ok' => true, 'message' => 'Correo enviado a ' . $destino], JSON_UNESCAPED_UNICODE);
        exit;
    }

    err('Método no permitido', 405);

} catch (Throwable $e) {
    if (function_exists('logError')) logError('Error en email_config.php', ['message' => $e->getMessage(), 'line' => $e->getLine()]);
    errSafe($e, 500);
}
