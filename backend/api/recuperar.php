<?php
/**
 * Recuperación de Contraseña - Genera token de recuperación
 * POST /recuperar.php
 * Body: { email: string }
 */
require __DIR__ . '/../config.php';
cors();

// Rate limit: 3 intentos por IP cada 15 min
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$rateKey = 'rate_recuperar_' . md5($ip);
$cached = cacheGet($rateKey);
$count = $cached['count'] ?? 0;
if ($count >= 3) {
    err('Demasiados intentos. Espera 15 minutos.', 429);
}
cacheSet($rateKey, ['count' => $count + 1], 900);

// Obtener conexión a la base de datos
$db = getDB();

// Obtener datos del body
$data = json_decode(file_get_contents('php://input'), true);
$email = isset($data['email']) ? trim($data['email']) : '';

if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Email inválido']);
    exit;
}

try {
    // Verificar que el email existe
    $stmt = $db->prepare("SELECT id, nombre, email FROM usuarios WHERE email = ? AND activo = 1 LIMIT 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        // Por seguridad, no revelar si el email existe o no
        echo json_encode(['ok' => true, 'message' => 'Si el email existe, recibirás instrucciones.']);
        exit;
    }
    
    // Generar token único. En la BD se guarda su hash (como las sesiones):
    // un respaldo filtrado no sirve para cambiar contraseñas.
    $token = bin2hex(random_bytes(32));
    $expira = date('Y-m-d H:i:s', strtotime('+1 hour'));
    $hash = tokenHash($token);
    $stmt = $db->prepare("
        INSERT INTO password_resets (usuario_id, token, expira_en, created_at)
        VALUES (?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE token = ?, expira_en = ?, created_at = NOW()
    ");
    $stmt->execute([$user['id'], $hash, $expira, $hash, $expira]);

    // URL de la app = origen + carpeta donde está instalada (raíz del dominio o
    // subcarpeta). El origen sale de FRONTEND_URL del .env (lo escribe el
    // instalador; puede traer varios separados por coma) o, si falta, de esta
    // misma petición. La carpeta sale de la ruta de este script, salvo que
    // FRONTEND_URL ya la traiga.
    $script  = (string)($_SERVER['SCRIPT_NAME'] ?? '');
    $pos     = strpos($script, '/backend/');
    $carpeta = $pos !== false ? substr($script, 0, $pos) : '';
    $front   = rtrim(trim(explode(',', (string)(getenv('FRONTEND_URL') ?: ''))[0]), '/');
    if (preg_match('#^https?://#i', $front)) {
        $conRuta = trim((string)parse_url($front, PHP_URL_PATH), '/') !== '';
        $baseUrl = $conRuta ? $front : $front . $carpeta;
    } else {
        $https   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $baseUrl = ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $carpeta;
    }
    $resetLink = $baseUrl . '/#/reset-password?token=' . $token;

    // Envío directo por SMTP (Configuración → Correo). Antes iba a una cola que
    // solo salía con un cron cada minuto: sin cron, el correo nunca llegaba.
    require_once __DIR__ . '/../mailer.php';
    $html = correoPlantilla(
        'Restablecer tu contraseña',
        '<p>Hola ' . htmlspecialchars((string)$user['nombre'], ENT_QUOTES, 'UTF-8') . ',</p>'
        . '<p>Recibimos una solicitud para restablecer tu contraseña. El enlace vence en 1 hora. '
        . 'Si no la pediste, ignora este correo.</p>',
        'Crear contraseña nueva', $resetLink
    );
    [$enviado, $errorEnvio] = enviarCorreo($db, $email, 'Restablecer tu contraseña', $html, $user['nombre']);
    if (!$enviado) logError('No se pudo enviar el correo de recuperación', ['usuario_id' => $user['id'], 'error' => $errorEnvio]);
    else logInfo('Correo de recuperación enviado', ['usuario_id' => $user['id']]);

    // Misma respuesta exista o no el correo (no revela usuarios).
    echo json_encode([
        'ok' => true,
        'message' => 'Si el email existe, recibirás instrucciones.',
        'debug_link' => (APP_ENV === 'development' && APP_DEBUG) ? $resetLink : null,
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    logError('Error en recuperar password', ['message' => $e->getMessage()]);
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error al procesar la solicitud']);
}
