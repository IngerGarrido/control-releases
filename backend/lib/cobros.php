<?php
/**
 * Cobro pendiente automático: UNA sola forma de crearlo (proyecto nuevo,
 * cotización convertida, mantención periódica, renovación de dominio/hosting).
 *
 * - factura (por defecto): $monto es el NETO; se le suma el IVA de
 *   Configuración → Finanzas y la cuota guarda neto + iva + total.
 * - boleta / directo: $monto es el total tal cual.
 * La cuota queda Pendiente y sin número: aparece como «por emitir» hasta que
 * se pongan el número y la fecha del documento (lib/saldos.php).
 *
 * @return int id del pago creado (0 si el monto es 0)
 */
function crearCobroPendiente(PDO $db, int $clienteId, int $proyectoId, string $descripcion, int $monto, string $tipo = 'factura'): int {
    if ($monto <= 0) return 0;
    if (!in_array($tipo, ['factura', 'boleta', 'directo'], true)) $tipo = 'factura';
    $neto = $iva = null; $total = $monto;
    if ($tipo === 'factura') {
        $ivaPct = (float)($db->query("SELECT valor FROM configuracion WHERE clave='iva_pct'")->fetchColumn() ?: 19);
        $neto = $monto; $iva = (int)round($monto * $ivaPct / 100); $total = $neto + $iva;
    }
    $db->prepare("INSERT INTO pagos (cliente_id, proyecto_id, descripcion, monto_total, tipo_pago) VALUES (?, ?, ?, ?, ?)")
       ->execute([$clienteId, $proyectoId ?: null, mb_substr($descripcion, 0, 255), $total, $tipo]);
    $pagoId = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO pago_cuotas (pago_id, numero_cuota, boleta_monto, neto, iva, pago_monto, estado, tipo_pago)
                  VALUES (?, 1, ?, ?, ?, 0, 'Pendiente', ?)")
       ->execute([$pagoId, $total, $neto, $iva, $tipo]);
    return $pagoId;
}

/** Primera etapa del tablero (para que un proyecto nuevo aparezca en el kanban). */
function etapaInicial(PDO $db): ?int {
    $id = $db->query("SELECT id FROM etapas ORDER BY orden ASC, id ASC LIMIT 1")->fetchColumn();
    return $id ? (int)$id : null;
}
