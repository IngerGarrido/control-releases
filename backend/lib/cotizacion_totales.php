<?php
/**
 * Totales de una cotización: ÚNICA fuente de neto, descuento, IVA y total.
 *
 * Antes se calculaban en cuatro lugares (lista, página pública, PDF y al
 * convertir a proyecto), cada uno redondeando distinto: la misma cotización
 * podía diferir en $1 según dónde se mirara. Todos deben usar esto.
 *
 * Reglas:
 *  - subtotal de un ítem = round(cantidad × precio_unitario)
 *  - descuento = round(subtotal × %descuento_global / 100)
 *  - neto = subtotal − descuento
 *  - IVA = round(neto × iva% / 100) solo si la cotización lo aplica
 *  - Si el cliente ya la aprobó, mandan los montos SELLADOS al aprobar
 *    (aprobada_neto / aprobada_iva / aprobada_iva_pct): cambiar después el IVA
 *    de Configuración no altera lo que el cliente aceptó.
 */

/** Cálculo puro (sin BD), testeable. $items: [{cantidad, precio_unitario}]. */
function cotizacionCalcular(array $items, float $descuentoPct, bool $aplicaIva, float $ivaPct): array {
    $subtotal = 0;
    foreach ($items as $it) {
        $subtotal += (int)round((float)($it['cantidad'] ?? 0) * (float)($it['precio_unitario'] ?? 0));
    }
    $descuentoPct = max(0.0, min(100.0, $descuentoPct));
    $descuento = (int)round($subtotal * $descuentoPct / 100);
    $neto = $subtotal - $descuento;
    $iva = $aplicaIva ? (int)round($neto * $ivaPct / 100) : 0;
    return ['subtotal' => $subtotal, 'descuento' => $descuento, 'descuento_pct' => $descuentoPct,
            'neto' => $neto, 'iva' => $iva, 'iva_pct' => $aplicaIva ? $ivaPct : 0.0, 'total' => $neto + $iva];
}

/** % de IVA de Configuración → Finanzas (19 por defecto). */
function ivaPctConfig(PDO $db): float {
    return (float)($db->query("SELECT valor FROM configuracion WHERE clave='iva_pct'")->fetchColumn() ?: 19);
}

/** Totales de la cotización $id (o de la fila ya cargada en $cot). */
function cotizacionTotales(PDO $db, int $id, ?array $cot = null): array {
    if ($cot === null) {
        $st = $db->prepare("SELECT * FROM cotizaciones WHERE id = ?");
        $st->execute([$id]);
        $cot = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    }
    $it = $db->prepare("SELECT cantidad, precio_unitario FROM cotizacion_items WHERE cotizacion_id = ?");
    $it->execute([$id]);
    $aplicaIva = (int)($cot['aplica_iva'] ?? 1) === 1;
    // Aprobada: el % de IVA quedó sellado con la aprobación.
    $ivaPct = (!empty($cot['aprobada_at']) && isset($cot['aprobada_iva_pct']) && $cot['aprobada_iva_pct'] !== null)
        ? (float)$cot['aprobada_iva_pct'] : ivaPctConfig($db);
    return cotizacionCalcular($it->fetchAll(PDO::FETCH_ASSOC), (float)($cot['descuento_global'] ?? 0), $aplicaIva, $ivaPct);
}
