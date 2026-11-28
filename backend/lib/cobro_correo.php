<?php
/**
 * Recordatorio de cobro por correo: un documento emitido (factura o boleta) o
 * un pago directo con saldo que el cliente aún no paga. Lo envía el admin con
 * un clic desde Pagos (nunca sale solo). El saldo y la regla de «emitida»
 * vienen de lib/saldos.php. (Portado de sistema-local y adaptado a factura.)
 */

require_once __DIR__ . '/saldos.php';
require_once __DIR__ . '/../mailer.php';

/** $ 12.345 */
function cobroClp(int $n): string { return '$ ' . number_format($n, 0, ',', '.'); }

/** 2026-10-01 → 01-10-2026 */
function cobroFecha(?string $f): string {
    $f = substr((string)$f, 0, 10);
    return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $f, $m) ? "{$m[3]}-{$m[2]}-{$m[1]}" : '';
}

/** Nombre del documento de una cuota: «factura», «boleta» o '' (pago directo). */
function cobroDocumento(string $tipo): string {
    return $tipo === 'directo' ? '' : ($tipo === 'boleta' ? 'boleta' : 'factura');
}

/**
 * Datos del recordatorio de una cuota: a quién, qué documento, cuánto y el texto.
 * @return ?array null si la cuota no existe, no está emitida o no tiene saldo
 */
function cobroRecordatorio(PDO $db, int $cuotaId): ?array {
    $q = saldosCuotas($db, 'pc.id = ?', [$cuotaId])[0] ?? null;
    if (!$q || !$q['emitida'] || $q['saldo'] <= 0) return null;

    $st = $db->prepare("SELECT email, nombre, contacto_nombre FROM clientes WHERE id = ?");
    $st->execute([$q['cliente_id']]);
    $cli = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $st = $db->prepare("SELECT recordado_at, recordatorios FROM pago_cuotas WHERE id = ?");
    $st->execute([$cuotaId]);
    $rec = $st->fetch(PDO::FETCH_ASSOC) ?: ['recordado_at' => null, 'recordatorios' => 0];

    $cfg = [];
    foreach ($db->query("SELECT clave, valor FROM configuracion WHERE clave = 'nombre_empresa'") as $r) $cfg[$r['clave']] = trim((string)$r['valor']);

    $doc = cobroDocumento((string)$q['tipo_pago']);
    $num = trim((string)$q['boleta_num']);
    $trabajo = trim((string)($q['proyecto_nombre'] ?: $q['pago_descripcion']));
    $contacto = trim((string)($cli['contacto_nombre'] ?? '')) ?: (trim((string)($cli['nombre'] ?? '')) ?: (string)$q['cliente_nombre']);
    // Saludo con el primer nombre: «Hola María José:» suena raro con apellido.
    $saludo = trim((string)(explode(' ', $contacto)[0] ?? $contacto)) ?: $contacto;

    $texto = ($doc !== '' && $num !== '' ? "Te escribo por la $doc N.º $num" : 'Te escribo por el pago')
           . ($doc !== '' && $q['boleta_fecha'] ? ' del ' . cobroFecha($q['boleta_fecha']) : '')
           . ($trabajo !== '' ? ", de «{$trabajo}»" : '')
           . ', que tiene un saldo pendiente de ' . cobroClp($q['saldo']) . '.'
           . "\n\nSi ya " . ($doc !== '' ? 'la' : 'lo') . " pagaste, avísame para registrarlo. Y si necesitas los datos de transferencia o prefieres coordinar otra fecha, respóndeme a este correo.";

    return [
        'cuota_id' => $cuotaId, 'pago_id' => $q['pago_id'], 'cliente_id' => $q['cliente_id'],
        'to' => trim((string)($cli['email'] ?? '')), 'contacto' => $contacto, 'saludo' => $saludo,
        'cliente' => $q['cliente_nombre'], 'trabajo' => $trabajo,
        'documento' => $doc, 'numero' => $num, 'fecha' => $q['boleta_fecha'],
        'saldo' => $q['saldo'], 'dias' => $q['dias'], 'vencida' => $q['vencida'],
        'texto' => $texto,
        'empresa' => $cfg['nombre_empresa'] ?? '',
        'recordado_at' => $rec['recordado_at'] ?? null, 'recordatorios' => (int)($rec['recordatorios'] ?? 0),
    ];
}

/** Envía el recordatorio. Lanza RuntimeException con un mensaje para mostrar si no se puede. */
function cobroRecordar(PDO $db, int $cuotaId, ?string $to = null, ?string $texto = null): array {
    $r = cobroRecordatorio($db, $cuotaId);
    if (!$r) throw new RuntimeException('Esa cuota ya no tiene saldo por cobrar.');
    $to = trim((string)($to ?? $r['to']));
    if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) throw new RuntimeException('Escribe el correo del cliente.');
    $texto = trim((string)($texto ?? '')) ?: $r['texto'];
    if (mb_strlen($texto) > 2000) throw new RuntimeException('El mensaje es muy largo (máximo 2.000 caracteres).');

    $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $html = '<p>Hola ' . $e($r['saludo']) . ':</p>';
    foreach (preg_split('/\n\s*\n/', str_replace("\r", '', $texto)) as $p) {
        if (trim($p) !== '') $html .= '<p>' . nl2br($e(trim($p))) . '</p>';
    }
    $filas = [];
    if ($r['documento'] !== '' && $r['numero'] !== '') $filas[] = [ucfirst($r['documento']), $r['numero']];
    if ($r['documento'] !== '' && $r['fecha']) $filas[] = ['Emitida el', cobroFecha($r['fecha'])];
    if ($r['trabajo'] !== '') $filas[] = ['Trabajo', $r['trabajo']];
    $filas[] = ['Saldo pendiente', cobroClp($r['saldo'])];
    $html .= '<table style="border-collapse:collapse;margin:18px 0;font-size:14px">';
    foreach ($filas as [$k, $v]) {
        $html .= '<tr><td style="padding:6px 16px 6px 0;color:#5e5e72">' . $e($k) . '</td><td style="padding:6px 0;font-weight:600">' . $e($v) . '</td></tr>';
    }
    $html .= '</table>';
    // Firma: el nombre de la empresa. (nombre_propietario es «nombre / cargo» y
    // muchas veces trae solo el cargo: no sirve para firmar.)
    if ($r['empresa'] !== '') $html .= '<p>Saludos,<br>' . $e($r['empresa']) . '</p>';

    $asunto = ($r['documento'] !== '' && $r['numero'] !== '' ? ucfirst($r['documento']) . ' ' . $r['numero'] : 'Pago')
            . ' pendiente' . ($r['empresa'] !== '' ? ' · ' . $r['empresa'] : '');
    [$ok, $error] = enviarCorreo($db, $to, $asunto, correoPlantilla('Pago pendiente', $html), $r['contacto']);
    if (!$ok) throw new RuntimeException('No se pudo enviar: ' . $error);

    $db->prepare("UPDATE pago_cuotas SET recordado_at = NOW(), recordatorios = recordatorios + 1 WHERE id = ?")->execute([$cuotaId]);
    return $r + ['enviado_a' => $to];
}
