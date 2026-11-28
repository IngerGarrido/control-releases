<?php
/**
 * Saldos de cobro: ÚNICA fuente de "por cobrar", "cobrado", "vencida" y tramos.
 *
 * Antes cada pantalla (Panel, Finanzas, Reportes, Clientes, ficha del cliente)
 * tenía su propia consulta y la misma deuda salía con cinco montos distintos.
 * Todas deben usar estas funciones. (Portado de sistema-local y adaptado a
 * factura/boleta/directo.)
 *
 * Reglas:
 *  - `boleta_monto` es el total del documento: en factura ya incluye el IVA
 *    (finance_calc_doc), en boleta/directo es el monto tal cual.
 *  - Una cuota vale max(boleta_monto, pago_monto): el monto acordado, o lo
 *    cobrado si es un pago directo sin monto acordado.
 *  - Saldo de una cuota = boleta_monto − cobrado (solo Pendiente/Parcial).
 *  - Cuota EMITIDA = documento (factura o boleta) con número Y fecha de emisión
 *    de hoy o antes, o pago directo (no espera documento). Solo número (ej.
 *    «Fase 2») no basta.
 *    "Por cobrar" = saldo de cuotas emitidas (el cliente ya lo debe).
 *    "Por emitir" = saldo de documentos acordados aún sin emitir.
 *    "Falta" = ambos.
 *  - Cuota VENCIDA = emitida, con saldo y más de cobroPlazoDias() desde la
 *    fecha del documento (Configuración → Finanzas → «Plazo de pago»).
 *  - Cuenta solo lo "vivo": pago, proyecto (si tiene) y cliente sin borrar. El
 *    cliente es el del proyecto (si lo hay), no el guardado en el pago.
 */

/** Plazo de pago por defecto, si no se configuró otro. */
const COBRO_PLAZO_DIAS = 30;

/**
 * Días que tiene el cliente para pagar desde la fecha del documento
 * (configuracion.cobro_plazo_dias; por defecto COBRO_PLAZO_DIAS). Se lee una
 * vez por petición.
 */
function cobroPlazoDias(PDO $db): int {
    static $v = null;
    if ($v === null) {
        try {
            $raw = $db->query("SELECT valor FROM configuracion WHERE clave = 'cobro_plazo_dias'")->fetchColumn();
            $v = ($raw === false || $raw === null || trim((string)$raw) === '') ? COBRO_PLAZO_DIAS : (int)$raw;
        } catch (Throwable $e) {
            $v = COBRO_PLAZO_DIAS;
        }
        $v = max(0, min(365, $v));
    }
    return $v;
}

/** Saldo de una cuota en SQL (alias pc). Misma regla que saldoCuota(). */
const SALDO_CUOTA_SQL = "CASE WHEN pc.estado IN ('Pendiente', 'Parcial')
    THEN GREATEST(COALESCE(pc.boleta_monto, 0) - COALESCE(pc.pago_monto, 0), 0) ELSE 0 END";

/** Tramos de antigüedad (días desde el documento) en el orden en que se muestran. */
const COBRO_TRAMOS = ['vigente', '1-30', '31-60', '61-90', '+90'];

/** Valor de una cuota: el monto acordado, o lo cobrado si es mayor (directo sin monto). */
function cuotaMonto(array $c): int {
    return max((int)($c['boleta_monto'] ?? 0), (int)($c['pago_monto'] ?? 0));
}

/** Saldo de una cuota (fila de pago_cuotas). Misma regla que SALDO_CUOTA_SQL. */
function saldoCuota(array $c): int {
    if (!in_array($c['estado'] ?? '', ['Pendiente', 'Parcial'], true)) return 0;
    return max(0, (int)($c['boleta_monto'] ?? 0) - (int)($c['pago_monto'] ?? 0));
}

/** ¿Cuota emitida? Pago directo, o documento con número y fecha ya cumplida. */
function cuotaEmitida(array $c, ?string $hoy = null): bool {
    if (($c['tipo_pago'] ?? 'factura') === 'directo') return true;
    $fecha = substr((string)($c['boleta_fecha'] ?? ''), 0, 10);
    return trim((string)($c['boleta_num'] ?? '')) !== ''
        && $fecha !== '' && $fecha !== '0000-00-00'
        && $fecha <= ($hoy ?? date('Y-m-d'));
}

/** ¿Cuota vencida? Emitida, con saldo y más de $plazo días desde el documento. */
function cuotaVencida(array $c, ?string $hoy = null, int $plazo = COBRO_PLAZO_DIAS): bool {
    if (!cuotaEmitida($c, $hoy) || saldoCuota($c) <= 0 || empty($c['boleta_fecha'])) return false;
    $limite = (new DateTimeImmutable($hoy ?? 'today'))->modify('-' . $plazo . ' days')->format('Y-m-d');
    return substr((string)$c['boleta_fecha'], 0, 10) < $limite;
}

/** Tramo de antigüedad según los días desde el documento (null = sin fecha). */
function saldoTramo(?int $dias): string {
    if ($dias === null || $dias <= 0) return 'vigente';
    if ($dias <= 30) return '1-30';
    if ($dias <= 60) return '31-60';
    if ($dias <= 90) return '61-90';
    return '+90';
}

/**
 * Cuotas vivas con su saldo. $where agrega condiciones (alias pc, pg, pr, c).
 * @return array<int, array> filas con: cuota_id, pago_id, proyecto_id, cliente_id,
 *   numero_cuota, boleta_num, boleta_fecha, tipo_pago, estado, monto, neto, iva,
 *   cobrado, saldo, emitida, dias (null sin fecha), vencida, tramo,
 *   proyecto_nombre, sitio_nombre, cliente_nombre.
 */
function saldosCuotas(PDO $db, string $where = '', array $params = [], ?string $hoy = null): array {
    $sql = "
        SELECT pc.id AS cuota_id, pc.pago_id, pg.proyecto_id,
               COALESCE(pr.cliente_id, pg.cliente_id) AS cliente_id,
               pc.numero_cuota, pc.boleta_num, pc.boleta_fecha, pc.tipo_pago, pc.estado,
               pc.boleta_monto, pc.pago_monto, pc.neto, pc.iva,
               GREATEST(COALESCE(pc.boleta_monto, 0), COALESCE(pc.pago_monto, 0)) AS monto,
               COALESCE(pc.pago_monto, 0) AS cobrado,
               " . SALDO_CUOTA_SQL . " AS saldo,
               DATEDIFF(" . ($hoy ? $db->quote($hoy) : 'CURDATE()') . ", pc.boleta_fecha) AS dias,
               pg.descripcion AS pago_descripcion,
               pr.nombre AS proyecto_nombre, s.nombre AS sitio_nombre,
               COALESCE(NULLIF(c.nombre, ''), c.razon_social) AS cliente_nombre
        FROM pago_cuotas pc
        INNER JOIN pagos pg     ON pg.id = pc.pago_id AND pg.deleted_at IS NULL
        LEFT  JOIN proyectos pr ON pr.id = pg.proyecto_id
        LEFT  JOIN sitios s     ON s.id = pr.sitio_id
        INNER JOIN clientes c   ON c.id = COALESCE(pr.cliente_id, pg.cliente_id)
        WHERE (pr.id IS NULL OR pr.deleted_at IS NULL)
          AND c.deleted_at IS NULL
          " . ($where !== '' ? "AND ($where)" : '') . "
        ORDER BY (pc.boleta_fecha IS NULL), pc.boleta_fecha, pc.pago_id, pc.numero_cuota";
    $st = $db->prepare($sql);
    $st->execute($params);
    $out = [];
    $plazo = cobroPlazoDias($db);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        foreach (['cuota_id', 'pago_id', 'cliente_id', 'numero_cuota', 'monto', 'cobrado', 'saldo'] as $k) $r[$k] = (int)$r[$k];
        $r['proyecto_id'] = $r['proyecto_id'] !== null ? (int)$r['proyecto_id'] : null;
        $r['dias'] = $r['boleta_fecha'] === null ? null : (int)$r['dias'];
        $r['emitida'] = cuotaEmitida($r, $hoy);
        $r['vencida'] = $r['emitida'] && $r['saldo'] > 0 && $r['dias'] !== null && $r['dias'] > $plazo;
        $r['tramo'] = saldoTramo($r['dias']);
        $out[] = $r;
    }
    return $out;
}

/**
 * Resumen de un conjunto de cuotas (de saldosCuotas).
 * por_cobrar/cuotas_pendientes/tramos/vencidas = solo cuotas emitidas;
 * por_emitir = documentos acordados sin emitir; falta = por_cobrar + por_emitir.
 */
function saldoResumen(array $cuotas): array {
    $r = ['total' => 0, 'cobrado' => 0, 'falta' => 0, 'por_cobrar' => 0, 'por_emitir' => 0,
          'cuotas_pendientes' => 0, 'cuotas_por_emitir' => 0,
          'vencidas' => 0, 'vencido' => 0, 'pagos_pendientes' => 0,
          'tramos' => array_fill_keys(COBRO_TRAMOS, ['cantidad' => 0, 'total' => 0])];
    $pagosConSaldo = [];
    foreach ($cuotas as $c) {
        $r['total']   += $c['monto'];
        $r['cobrado'] += $c['cobrado'];
        if ($c['saldo'] <= 0) continue;
        $r['falta'] += $c['saldo'];
        if (!($c['emitida'] ?? true)) {
            $r['por_emitir'] += $c['saldo'];
            $r['cuotas_por_emitir']++;
            continue;
        }
        $r['por_cobrar'] += $c['saldo'];
        $r['cuotas_pendientes']++;
        $pagosConSaldo[$c['pago_id']] = true;
        $r['tramos'][$c['tramo']]['cantidad']++;
        $r['tramos'][$c['tramo']]['total'] += $c['saldo'];
        if ($c['vencida']) { $r['vencidas']++; $r['vencido'] += $c['saldo']; }
    }
    $r['pagos_pendientes'] = count($pagosConSaldo);
    return $r;
}

/** Agrupa saldoResumen() por una clave de la cuota (ej: 'cliente_id', 'pago_id'). */
function saldoResumenPor(array $cuotas, string $clave): array {
    $grupos = [];
    foreach ($cuotas as $c) {
        if ($c[$clave] === null) continue;
        $grupos[$c[$clave]][] = $c;
    }
    return array_map('saldoResumen', $grupos);
}
