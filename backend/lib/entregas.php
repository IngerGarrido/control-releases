<?php
/**
 * Acta de entrega y garantía (adaptado de sistema-local lib/entregas.php).
 *
 * Al marcar la entrega final (proyectos.php ?entregar), el cliente ve en su
 * portal el acta: confirma la recepción o deja observaciones. Si no responde
 * en `entrega_plazo_dias` días hábiles (lun–vie) se da por recibida
 * (entrega_por_plazo = 1). La garantía corre desde la fecha de entrega por
 * `garantia_meses` y se avisa ENT_GARANTIA_AVISO_DIAS antes de que termine.
 */

require_once __DIR__ . '/../config.php';

const ENT_GARANTIA_AVISO_DIAS = 15;

/** Fecha que resulta de sumar $n días hábiles (lunes a viernes) a $fecha. */
function entDiasHabiles(string $fecha, int $n): string {
    $d = new DateTimeImmutable(substr($fecha, 0, 10));
    while ($n > 0) {
        $d = $d->modify('+1 day');
        if ((int)$d->format('N') <= 5) $n--;
    }
    return $d->format('Y-m-d');
}

/** Último día de garantía: entrega + meses (o null sin garantía). */
function entGarantiaHasta(?string $entrega, ?int $meses): ?string {
    if (!$entrega || !$meses) return null;
    return (new DateTimeImmutable(substr($entrega, 0, 10)))->modify("+{$meses} months")->format('Y-m-d');
}

/** Resumen del acta de un proyecto (para el portal y la ficha). */
function entResumen(array $p, int $plazoDias): ?array {
    if (empty($p['fecha_entrega_real'])) return null;
    $hasta = entGarantiaHasta($p['fecha_entrega_real'], isset($p['garantia_meses']) ? (int)$p['garantia_meses'] : null);
    return [
        'fecha'           => substr($p['fecha_entrega_real'], 0, 10),
        'garantia_meses'  => isset($p['garantia_meses']) ? (int)$p['garantia_meses'] : null,
        'garantia_hasta'  => $hasta,
        'confirmada_at'   => $p['entrega_confirmada_at'] ?? null,
        'confirmada_nombre' => $p['entrega_confirmada_nombre'] ?? null,
        'por_plazo'       => !empty($p['entrega_por_plazo']),
        'observacion'     => $p['entrega_observacion'] ?? null,
        'observacion_at'  => $p['entrega_observacion_at'] ?? null,
        'plazo_hasta'     => entDiasHabiles($p['fecha_entrega_real'], $plazoDias),
    ];
}

/** Días hábiles para responder el acta (Configuración; 5 por defecto). */
function entPlazoDias(PDO $db): int {
    $v = (int)($db->query("SELECT valor FROM configuracion WHERE clave = 'entrega_plazo_dias'")->fetchColumn() ?: 5);
    return max(1, min(30, $v));
}

/**
 * Actas sin respuesta cuyo plazo venció → recibidas por plazo. Una
 * observación pendiente detiene el plazo (hay algo que resolver).
 * @return string[] nombres de los proyectos marcados
 */
function entProcesarPlazos(PDO $db, string $hoy): array {
    $plazo = entPlazoDias($db);
    $rows = $db->query("SELECT id, nombre, fecha_entrega_real FROM proyectos
                        WHERE deleted_at IS NULL AND fecha_entrega_real IS NOT NULL
                          AND entrega_confirmada_at IS NULL AND entrega_observacion_at IS NULL")->fetchAll(PDO::FETCH_ASSOC);
    $up = $db->prepare("UPDATE proyectos SET entrega_confirmada_at = NOW(), entrega_confirmada_nombre = 'Recibida por plazo', entrega_por_plazo = 1
                        WHERE id = ? AND entrega_confirmada_at IS NULL");
    $hechos = [];
    foreach ($rows as $p) {
        if (entDiasHabiles($p['fecha_entrega_real'], $plazo) >= $hoy) continue;
        $up->execute([(int)$p['id']]);
        if ($up->rowCount() === 1) $hechos[] = $p['nombre'];
    }
    return $hechos;
}

/**
 * Garantías que terminan en ENT_GARANTIA_AVISO_DIAS días o menos (y no
 * terminaron): un aviso por proyecto.
 * @return array<int, array{id:int, nombre:string, hasta:string}>
 */
function entGarantiasPorTerminar(PDO $db, string $hoy): array {
    $rows = $db->query("SELECT id, nombre, fecha_entrega_real, garantia_meses FROM proyectos
                        WHERE deleted_at IS NULL AND fecha_entrega_real IS NOT NULL
                          AND garantia_meses > 0 AND garantia_aviso_at IS NULL")->fetchAll(PDO::FETCH_ASSOC);
    $limite = date('Y-m-d', strtotime("$hoy +" . ENT_GARANTIA_AVISO_DIAS . ' days'));
    $out = [];
    foreach ($rows as $p) {
        $hasta = entGarantiaHasta($p['fecha_entrega_real'], (int)$p['garantia_meses']);
        if ($hasta && $hasta >= $hoy && $hasta <= $limite) $out[] = ['id' => (int)$p['id'], 'nombre' => $p['nombre'], 'hasta' => $hasta];
    }
    return $out;
}
