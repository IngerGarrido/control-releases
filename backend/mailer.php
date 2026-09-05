<?php
/**
 * mailer.php — Configuración y envío de correo por SMTP.
 *
 * La configuración vive en la tabla `configuracion` (no en .env) para que cada
 * agencia la edite desde la interfaz sin tocar archivos. La contraseña se
 * guarda CIFRADA con AES-256-GCM (mismo mecanismo que las credenciales de
 * sitios); nunca se devuelve al frontend, solo si existe o no.
 *
 * El envío usa PHPMailer (vendorizado en lib/PHPMailer, sin Composer).
 */

require_once __DIR__ . '/crypto.php';

/** Claves de configuración usadas por el módulo de correo. */
const SMTP_KEYS = [
    'smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass_cipher',
    'smtp_secure', 'smtp_from', 'smtp_from_name',
];

/**
 * Lee la configuración SMTP desde la BD.
 * @param bool $conPassword Si true incluye la contraseña descifrada (solo para enviar).
 */
function smtpConfig(PDO $db, bool $conPassword = false): array
{
    $ph = implode(',', array_fill(0, count(SMTP_KEYS), '?'));
    $st = $db->prepare("SELECT clave, valor FROM configuracion WHERE clave IN ($ph)");
    $st->execute(SMTP_KEYS);
    $raw = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $raw[$r['clave']] = $r['valor'];

    // OJO: resolver el default ANTES de validar, si no un valor ausente se
    // devuelve como null (pasaba el in_array por el ?? pero retornaba el crudo).
    $secure = (string)($raw['smtp_secure'] ?? 'tls');
    $cfg = [
        'host'      => trim((string)($raw['smtp_host'] ?? '')),
        'port'      => (int)($raw['smtp_port'] ?? 587),
        'user'      => trim((string)($raw['smtp_user'] ?? '')),
        'secure'    => in_array($secure, ['tls', 'ssl', 'none'], true) ? $secure : 'tls',
        'from'      => trim((string)($raw['smtp_from'] ?? '')),
        'from_name' => trim((string)($raw['smtp_from_name'] ?? '')),
        'tiene_password' => !empty($raw['smtp_pass_cipher']),
    ];
    if ($cfg['port'] <= 0) $cfg['port'] = 587;
    // Si no hay remitente explícito, usamos el usuario (lo habitual en cPanel).
    if ($cfg['from'] === '') $cfg['from'] = $cfg['user'];

    if ($conPassword) {
        $cfg['password'] = '';
        if (!empty($raw['smtp_pass_cipher'])) {
            try { $cfg['password'] = (string)decryptStr($raw['smtp_pass_cipher']); }
            catch (Throwable $e) { $cfg['password'] = ''; }
        }
    }
    return $cfg;
}

/** ¿Está el correo configurado como para intentar un envío? */
function smtpConfigurado(array $cfg): bool
{
    return $cfg['host'] !== '' && $cfg['user'] !== '' && !empty($cfg['tiene_password']);
}

/** Guarda un valor de configuración (upsert). */
function smtpSet(PDO $db, string $clave, string $valor): void
{
    $db->prepare("INSERT INTO configuracion (clave, valor) VALUES (?, ?)
                  ON DUPLICATE KEY UPDATE valor = VALUES(valor)")
       ->execute([$clave, $valor]);
}

/**
 * Envía un correo por SMTP. Devuelve [ok(bool), error(string)].
 * No lanza excepciones: el llamador decide qué hacer con el fallo.
 */
function enviarCorreo(PDO $db, string $to, string $asunto, string $htmlBody, ?string $toName = null): array
{
    $cfg = smtpConfig($db, true);
    if (!smtpConfigurado($cfg)) {
        return [false, 'El correo no está configurado. Ve a Configuración → Email.'];
    }
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return [false, 'Destinatario inválido: ' . $to];
    }

    require_once __DIR__ . '/lib/PHPMailer/Exception.php';
    require_once __DIR__ . '/lib/PHPMailer/PHPMailer.php';
    require_once __DIR__ . '/lib/PHPMailer/SMTP.php';

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = $cfg['host'];
        $mail->Port       = $cfg['port'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $cfg['user'];
        $mail->Password   = $cfg['password'];
        $mail->CharSet    = 'UTF-8';
        $mail->Timeout    = 15;
        if ($cfg['secure'] === 'ssl')      $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        elseif ($cfg['secure'] === 'tls')  $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        else                               $mail->SMTPSecure = false;

        $mail->setFrom($cfg['from'], $cfg['from_name'] ?: $cfg['from']);
        $mail->addAddress($to, $toName ?: $to);
        $mail->isHTML(true);
        $mail->Subject = $asunto;
        $mail->Body    = $htmlBody;
        $mail->AltBody = trim(strip_tags(str_replace(['<br>', '<br/>', '</p>'], "\n", $htmlBody)));
        $mail->send();
        return [true, ''];
    } catch (Throwable $e) {
        $msg = $mail->ErrorInfo ?: $e->getMessage();
        if (function_exists('logError')) logError('Fallo al enviar correo', ['error' => $msg, 'to' => $to]);
        return [false, $msg];
    }
}

/**
 * Plantilla HTML mínima y neutra para los correos del sistema.
 * Sin imágenes externas ni CSS remoto (mejor entregabilidad).
 */
function correoPlantilla(string $titulo, string $cuerpoHtml, ?string $ctaTexto = null, ?string $ctaUrl = null): string
{
    $cta = '';
    if ($ctaTexto && $ctaUrl) {
        $u = htmlspecialchars($ctaUrl, ENT_QUOTES, 'UTF-8');
        $t = htmlspecialchars($ctaTexto, ENT_QUOTES, 'UTF-8');
        $cta = '<p style="margin:26px 0 6px"><a href="' . $u . '" style="background:#5b53e0;color:#fff;'
             . 'text-decoration:none;padding:12px 22px;border-radius:999px;font-weight:600;'
             . 'display:inline-block">' . $t . '</a></p>'
             . '<p style="font-size:12px;color:#8a8f98;margin:10px 0 0">Si el botón no funciona, copia este enlace:<br>'
             . '<span style="color:#5b53e0">' . $u . '</span></p>';
    }
    $h = htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8');
    return '<div style="font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;'
         . 'max-width:560px;margin:0 auto;padding:28px 24px;color:#1f2430;line-height:1.6">'
         . '<h2 style="margin:0 0 14px;font-size:19px;color:#1f2430">' . $h . '</h2>'
         . $cuerpoHtml . $cta
         . '</div>';
}
