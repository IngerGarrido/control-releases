<?php
/**
 * finance.php — Cálculos financieros PUROS (sin BD, sin estado global).
 *
 * Extraídos de pagos.php para poder testearlos de forma aislada. Toda la
 * matemática de dinero delicada (neto/IVA/total, abonos parciales, estado de
 * cobro) vive acá y se cubre con tests en tests/FinanceTest.php.
 *
 * Convención Chile:
 *  - factura: se ingresa el NETO → iva = neto × %, total = neto + iva
 *  - boleta / directo: el monto ingresado ya es el total (sin IVA desglosado)
 */

/**
 * Normaliza un monto que puede venir formateado ("$95.000", "1.200,50") a entero.
 * Quita separadores de miles/decimales, símbolo y espacios. Redondea.
 */
function finance_clean_int($valor): int
{
    if (is_null($valor) || $valor === '') return 0;
    $v = str_replace(['.', ',', '$', ' '], '', (string)$valor);
    return (int)round((float)$v);
}

/**
 * Calcula el documento de una cuota según su tipo.
 * @param array $cuota  Espera 'tipo_pago' y 'neto'|'boleta_monto'.
 * @param float $ivaPct Porcentaje de IVA (ej 19).
 * @return array{tipo:string, neto:?int, iva:?int, total:int}
 */
function finance_calc_doc(array $cuota, float $ivaPct = 19.0): array
{
    $tipo = $cuota['tipo_pago'] ?? 'factura';
    if (!in_array($tipo, ['factura', 'boleta', 'directo'], true)) $tipo = 'factura';

    if ($tipo === 'factura') {
        $neto = finance_clean_int($cuota['neto'] ?? $cuota['boleta_monto'] ?? 0);
        $iva  = (int) round($neto * $ivaPct / 100);
        return ['tipo' => $tipo, 'neto' => $neto, 'iva' => $iva, 'total' => $neto + $iva];
    }

    $total = finance_clean_int($cuota['boleta_monto'] ?? $cuota['neto'] ?? 0);
    return ['tipo' => $tipo, 'neto' => null, 'iva' => null, 'total' => $total];
}

/**
 * Normaliza los abonos de una cuota y deriva suma/estado/última fecha.
 * Los abonos (cobros parciales con fecha) son la fuente única de verdad;
 * pago_monto/pago_fecha/estado se derivan de ellos.
 *
 * Estado: 0 → Pendiente; 0<suma<boleta (solo boleta) → Parcial; si no → Pagado.
 * (Un pago 'directo' nunca queda Parcial: es todo-o-nada.)
 *
 * Compatibilidad: si no llega abonos[] pero hay pago_monto (cliente viejo),
 * se construye un abono desde pago_monto/pago_fecha.
 *
 * @return array{abonos:array, suma:int, maxFecha:?string, estado:string}
 */
function finance_procesar_abonos(array $cuota, ?string $hoy = null): array
{
    $hoy  = $hoy ?: date('Y-m-d');
    $tipo = (($cuota['tipo_pago'] ?? 'boleta') === 'directo') ? 'directo' : 'boleta';
    $boletaMonto = finance_clean_int($cuota['boleta_monto'] ?? 0);
    $raw = $cuota['abonos'] ?? null;

    $abonos = [];
    if (is_array($raw)) {
        foreach ($raw as $a) {
            $monto = finance_clean_int($a['monto'] ?? 0);
            $fecha = !empty($a['fecha']) ? substr((string)$a['fecha'], 0, 10) : null;
            if ($monto > 0 && $fecha) $abonos[] = ['monto' => $monto, 'fecha' => $fecha];
        }
    } else {
        $pm = finance_clean_int($cuota['pago_monto'] ?? 0);
        if ($pm > 0) {
            $fecha = !empty($cuota['pago_fecha']) ? substr((string)$cuota['pago_fecha'], 0, 10) : $hoy;
            $abonos[] = ['monto' => $pm, 'fecha' => $fecha];
        }
    }

    $suma = 0; $maxFecha = null;
    foreach ($abonos as $a) {
        $suma += $a['monto'];
        if ($maxFecha === null || $a['fecha'] > $maxFecha) $maxFecha = $a['fecha'];
    }

    if ($suma <= 0)                                                          $estado = 'Pendiente';
    elseif ($tipo === 'boleta' && $boletaMonto > 0 && $suma < $boletaMonto)  $estado = 'Parcial';
    else                                                                     $estado = 'Pagado';

    return ['abonos' => $abonos, 'suma' => $suma, 'maxFecha' => $maxFecha, 'estado' => $estado];
}

/**
 * Retención SII de honorarios sobre boletas: se descuenta del bruto para
 * obtener el líquido. retencion = round(bruto × %). Ej 2026: 15.25%.
 * @return array{bruto:int, retencion:int, liquido:int}
 */
function finance_retencion_honorarios(int $bruto, float $retPct): array
{
    $retencion = (int) round($bruto * $retPct / 100);
    return ['bruto' => $bruto, 'retencion' => $retencion, 'liquido' => $bruto - $retencion];
}
