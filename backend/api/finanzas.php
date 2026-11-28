<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Dashboard financiero predictivo: cashflow por nivel de certeza + calendario
// de cobros futuros. Solo admin (datos financieros).
try {
    require_once __DIR__ . '/../config.php';
    require_once __DIR__ . '/../lib/saldos.php';
    cors();
    $db = getDB();
    $user = requireAuth();
    if ($user['rol'] !== 'admin') { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Acceso denegado']); exit; }
    $db->exec("SET NAMES utf8mb4");

    $out = [];

    // Saldos desde la única fuente (lib/saldos.php): mismo "Por cobrar" que
    // el Panel, Reportes y la ficha del cliente.
    $cuotas = saldosCuotas($db);
    $saldo = saldoResumen($cuotas);

    // 1) POR COBRAR (asegurado): documentos emitidos con saldo (total con IVA).
    $out['por_cobrar'] = $saldo['por_cobrar'];
    // Acordado pero aún sin emitir (factura/boleta sin número o con fecha futura).
    $out['por_emitir'] = $saldo['por_emitir'];
    $out['vencido'] = $saldo['vencido'];

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

    // 4) CALENDARIO DE COBROS: saldo por mes según la fecha del documento.
    //    Incluye vencidos (meses pasados) para visibilidad.
    $porMes = [];
    foreach ($cuotas as $c) {
        if ($c['saldo'] <= 0 || empty($c['boleta_fecha'])) continue;
        $mes = substr($c['boleta_fecha'], 0, 7);
        $porMes[$mes] ??= ['mes' => $mes, 'monto' => 0, 'cantidad' => 0];
        $porMes[$mes]['monto'] += $c['saldo'];
        $porMes[$mes]['cantidad']++;
    }
    ksort($porMes);
    $out['calendario'] = array_values($porMes);

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
    errSafe($e, 500);
}
