<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Dashboard financiero predictivo: cashflow por nivel de certeza + calendario
// de cobros futuros. Solo admin (datos financieros).
try {
    require_once __DIR__ . '/../config.php';
    cors();
    $db = getDB();
    $user = requireAuth();
    if ($user['rol'] !== 'admin') { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Acceso denegado']); exit; }
    $db->exec("SET NAMES utf8mb4");

    $out = [];

    // Saldo pendiente de una cuota (Parcial = lo que falta; resto = total).
    $saldoExpr = "CASE WHEN pc.estado='Parcial' THEN GREATEST(pc.boleta_monto - pc.pago_monto, 0) ELSE pc.boleta_monto END";

    // 1) POR COBRAR (asegurado): facturas/documentos ya emitidos, pendientes
    //    de cobro. Total con IVA = flujo de caja real que va a entrar.
    $out['por_cobrar'] = (int)$db->query("
        SELECT COALESCE(SUM($saldoExpr), 0)
        FROM pago_cuotas pc INNER JOIN pagos pg ON pg.id = pc.pago_id
        WHERE pc.estado IN ('Pendiente','Parcial') AND pg.deleted_at IS NULL
    ")->fetchColumn();

    // 2) y 3) Cotizaciones por estado → comprometido (Aceptada) y potencial
    //    (Pendiente). Monto neto con su descuento global aplicado.
    $rows = $db->query("
        SELECT c.estado, c.descuento_global,
               COALESCE((SELECT SUM(ci.cantidad * ci.precio_unitario)
                         FROM cotizacion_items ci WHERE ci.cotizacion_id = c.id), 0) AS subtotal
        FROM cotizaciones c
        WHERE c.deleted_at IS NULL AND c.estado IN ('Pendiente','Aceptada')
    ")->fetchAll(PDO::FETCH_ASSOC);

    $comprometido = 0; $comprometido_n = 0;
    $potencial = 0;    $potencial_n = 0;
    foreach ($rows as $r) {
        $sub = (float)$r['subtotal'];
        $desc = (float)$r['descuento_global'];
        $total = (int)round($sub * (1 - $desc / 100));
        if ($r['estado'] === 'Aceptada') { $comprometido += $total; $comprometido_n++; }
        else                             { $potencial += $total;    $potencial_n++; }
    }
    $out['comprometido'] = $comprometido;            // cotizaciones aceptadas (neto)
    $out['comprometido_n'] = $comprometido_n;
    $out['potencial'] = $potencial;                  // cotizaciones pendientes (neto)
    $out['potencial_n'] = $potencial_n;

    // 4) CALENDARIO DE COBROS: saldo pendiente por mes (próximos meses), según
    //    la fecha del documento. Incluye vencidos (mes pasado) para visibilidad.
    $cal = $db->query("
        SELECT DATE_FORMAT(pc.boleta_fecha, '%Y-%m') AS mes,
               COALESCE(SUM($saldoExpr), 0) AS monto,
               COUNT(*) AS cantidad
        FROM pago_cuotas pc INNER JOIN pagos pg ON pg.id = pc.pago_id
        WHERE pc.estado IN ('Pendiente','Parcial') AND pg.deleted_at IS NULL
          AND pc.boleta_fecha IS NOT NULL
        GROUP BY mes ORDER BY mes ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
    $out['calendario'] = array_map(fn($r) => [
        'mes' => $r['mes'],
        'monto' => (int)$r['monto'],
        'cantidad' => (int)$r['cantidad'],
    ], $cal);

    // 5) Cobrado en lo que va del mes (contexto de la proyección).
    $out['cobrado_mes'] = (int)$db->query("
        SELECT COALESCE(SUM(pa.monto), 0)
        FROM pago_abonos pa
        INNER JOIN pago_cuotas pc ON pc.id = pa.cuota_id
        INNER JOIN pagos pg ON pg.id = pc.pago_id
        WHERE pg.deleted_at IS NULL
          AND DATE_FORMAT(pa.fecha, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')
    ")->fetchColumn();

    echo json_encode(['ok' => true, 'data' => $out], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    if (function_exists('logError')) logError('Error en finanzas.php', ['message' => $e->getMessage(), 'line' => $e->getLine()]);
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error en finanzas: ' . $e->getMessage()]);
}
