<?php
/**
 * Procesos automáticos: TODO lo que el sistema hace solo, en un único lugar.
 * (Portado de sistema-local y adaptado a control.)
 *
 * procesosDiarios() es idempotente: se llama al abrir el Panel y desde el cron
 * (backend/procesos_diarios.php) sin duplicar nada.
 *
 *  0. Sitios: mantención periódica y renovaciones de dominio/hosting con monto →
 *     proyecto + cobro pendiente (lib/mantenciones.php)
 *  0b. Actas de entrega sin respuesta → recibidas por plazo; aviso de garantías
 *     por terminar (lib/entregas.php)
 *  1. Cotizaciones Pendiente con la validez vencida → Expirada
 *  2. Avisos de la campana que dependen de datos (cuotas vencidas, respaldo):
 *     se crean una vez y se borran solos cuando ya no aplican.
 *  3. Papelera: lo que lleva más de PAPELERA_DIAS días se borra para siempre
 *     (solo si no tiene nada activo colgando).
 *  4. Links del portal vencidos: aviso para renovarlos si el proyecto sigue en curso.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/saldos.php';
require_once __DIR__ . '/mantenciones.php';
require_once __DIR__ . '/entregas.php';

/** Días sin respaldo antes de avisar. */
const PROC_BACKUP_DIAS = 7;
/** Días en la papelera antes de borrarse para siempre. */
const PAPELERA_DIAS = 30;

function procesosDiarios(PDO $db, ?string $hoy = null): array {
    $hoy = $hoy ?: date('Y-m-d');
    $r = ['mantenciones' => 0, 'renovaciones' => 0, 'entregas_por_plazo' => [], 'garantias' => [], 'cotizaciones_expiradas' => [], 'papelera_vaciada' => 0, 'errores' => []];
    $paso = function (string $nombre, callable $fn) use (&$r) {
        try { $fn(); } catch (Throwable $e) { $r['errores'][] = "$nombre: " . $e->getMessage(); }
    };

    $paso('mantenciones', function () use ($db, $hoy, &$r) {
        $uid = procUsuarioSistema($db);
        $r['mantenciones'] = generarMantencionesMensuales($db, $uid, $hoy);
        $r['renovaciones'] = generarRenovaciones($db, $uid, $hoy);
        $n = $r['mantenciones'] + $r['renovaciones'];
        if ($n > 0) procNotificar($db, 'info', "Se crearon {$n} " . ($n === 1 ? 'cobro automático' : 'cobros automáticos')
            . ' de mantención o renovación de sitios (' . date('d-m-Y', strtotime($hoy)) . ').', 'pagos');
    });
    $paso('entregas', function () use ($db, $hoy, &$r) {
        $r['entregas_por_plazo'] = entProcesarPlazos($db, $hoy);
        foreach ($r['entregas_por_plazo'] as $nombre) procNotificar($db, 'info', "«{$nombre}»: el cliente no respondió el acta de entrega y quedó recibida por plazo.", 'proyectos');
        foreach (entGarantiasPorTerminar($db, $hoy) as $g) {
            procNotificar($db, 'warning', "La garantía de «{$g['nombre']}» termina el " . date('d-m-Y', strtotime($g['hasta'])) . ': ¿ofrecer mantención?', 'proyectos:' . $g['id']);
            $db->prepare("UPDATE proyectos SET garantia_aviso_at = ? WHERE id = ?")->execute([$hoy, $g['id']]);
            $r['garantias'][] = $g['nombre'];
        }
    });
    $paso('cotizaciones', function () use ($db, $hoy, &$r) { $r['cotizaciones_expiradas'] = procExpirarCotizaciones($db, $hoy); });
    $paso('aviso_vencidas', function () use ($db, $hoy) { procAvisoCuotasVencidas($db, $hoy); });
    $paso('aviso_backup', function () use ($db) { procAvisoBackup($db); });
    $paso('portal', function () use ($db, $hoy) { procAvisoPortalVencido($db, $hoy); });
    $paso('papelera', function () use ($db, $hoy, &$r) {
        // La primera vez solo se avisa: así nadie pierde de sorpresa lo que
        // tenía en la papelera al actualizar. Desde el día siguiente se vacía.
        $avisoDado = procConfig($db, 'papelera_auto_desde');
        if ($avisoDado === null) {
            $n = procContarPapeleraAntigua($db, $hoy);
            procConfigSet($db, 'papelera_auto_desde', date('Y-m-d', strtotime("$hoy +1 day")));
            if ($n > 0) {
                procNotificar($db, 'warning', "La papelera ahora se vacía sola: desde mañana se borrarán para siempre {$n} "
                    . ($n === 1 ? 'elemento' : 'elementos') . ' con más de ' . PAPELERA_DIAS . ' días. Restaura hoy lo que quieras conservar.', 'papelera');
            }
            return;
        }
        if ($hoy < $avisoDado) return;
        $r['papelera_vaciada'] = procVaciarPapeleraAntigua($db, $hoy);
        if ($r['papelera_vaciada'] > 0) {
            $n = $r['papelera_vaciada'];
            procNotificar($db, 'info', "Se borraron para siempre {$n} " . ($n === 1 ? 'elemento' : 'elementos')
                . ' con más de ' . PAPELERA_DIAS . ' días en la papelera.', 'papelera');
        }
    });
    procConfigSet($db, 'procesos_ultima_fecha', $hoy);
    return $r;
}

/**
 * Corre los procesos una vez por día como respaldo del cron (lo llama el
 * Panel). Si el cron ya corrió hoy, no hace nada.
 */
function procesosDiariosSiFalta(PDO $db): ?array {
    if (procConfig($db, 'procesos_ultima_fecha') === date('Y-m-d')) return null;
    return procesosDiarios($db);
}

/**
 * Borra para siempre lo que lleva más de PAPELERA_DIAS días en la papelera, sin
 * llevarse cosas activas por cascada (un cliente borrado con un proyecto activo
 * no se toca). Los archivos de los proyectos borrados se eliminan del disco.
 * @return int registros borrados
 */
function procVaciarPapeleraAntigua(PDO $db, ?string $hoy = null): int {
    $hoy = $hoy ?: date('Y-m-d');
    $limite = date('Y-m-d H:i:s', strtotime("$hoy -" . PAPELERA_DIAS . ' days'));
    $viejo = "deleted_at IS NOT NULL AND deleted_at < " . $db->quote($limite);
    $n = 0;
    // Pagos (sus cuotas y abonos caen por cascada).
    $n += $db->exec("DELETE FROM pagos WHERE $viejo");
    // Cotizaciones (ítems por cascada).
    $n += $db->exec("DELETE FROM cotizaciones WHERE $viejo");
    // Proyectos sin pagos activos. Fases, tareas, adjuntos, comentarios,
    // contrato y briefing caen por cascada; proyecto_servicios no tiene FK.
    $ids = $db->query("SELECT p.id FROM proyectos p WHERE p.$viejo
                       AND NOT EXISTS (SELECT 1 FROM pagos pg WHERE pg.proyecto_id = p.id AND pg.deleted_at IS NULL)")
              ->fetchAll(PDO::FETCH_COLUMN);
    if ($ids) {
        $in = implode(',', array_map('intval', $ids));
        $archivos = $db->query("SELECT url FROM proyecto_adjuntos WHERE tipo = 'archivo' AND proyecto_id IN ($in)")->fetchAll(PDO::FETCH_COLUMN);
        $db->exec("DELETE FROM proyecto_servicios WHERE proyecto_id IN ($in)");
        $n += $db->exec("DELETE FROM proyectos WHERE id IN ($in)");
        procBorrarArchivos($archivos);
    }
    // Sitios (accesos por cascada; proyectos quedan sin sitio).
    $db->exec("UPDATE proyectos SET sitio_id = NULL WHERE sitio_id IN (SELECT id FROM (SELECT id FROM sitios WHERE $viejo) t)");
    $n += $db->exec("DELETE FROM sitios WHERE $viejo");
    // Servicios que ya no usa ningún proyecto ni cotización.
    $n += $db->exec("DELETE FROM servicios WHERE $viejo
                     AND NOT EXISTS (SELECT 1 FROM proyecto_servicios ps WHERE ps.servicio_id = servicios.id)
                     AND NOT EXISTS (SELECT 1 FROM cotizacion_items ci WHERE ci.servicio_id = servicios.id)");
    // Clientes sin nada (activo o no) que la cascada se llevaría.
    $n += $db->exec("DELETE FROM clientes WHERE $viejo
                     AND NOT EXISTS (SELECT 1 FROM proyectos p WHERE p.cliente_id = clientes.id)
                     AND NOT EXISTS (SELECT 1 FROM sitios s WHERE s.cliente_id = clientes.id)
                     AND NOT EXISTS (SELECT 1 FROM cotizaciones c WHERE c.cliente_id = clientes.id)
                     AND NOT EXISTS (SELECT 1 FROM pagos pg WHERE pg.cliente_id = clientes.id)");
    return $n;
}

/** Cuántos registros llevan más de PAPELERA_DIAS días en la papelera (aprox., sin las excepciones). */
function procContarPapeleraAntigua(PDO $db, string $hoy): int {
    $limite = $db->quote(date('Y-m-d H:i:s', strtotime("$hoy -" . PAPELERA_DIAS . ' days')));
    $n = 0;
    foreach (['pagos', 'cotizaciones', 'proyectos', 'sitios', 'servicios', 'clientes'] as $t) {
        $n += (int)$db->query("SELECT COUNT(*) FROM $t WHERE deleted_at IS NOT NULL AND deleted_at < $limite")->fetchColumn();
    }
    return $n;
}

/** Borra del disco archivos subidos a proyectos (solo dentro de /uploads/proyectos/). */
function procBorrarArchivos(array $urls): void {
    foreach ($urls as $url) {
        if (strpos((string)$url, '/uploads/proyectos/') !== 0 || strpos((string)$url, '..') !== false) continue;
        $f = __DIR__ . '/../..' . $url;
        if (is_file($f)) @unlink($f);
    }
}

/** Usuario para la auditoría cuando corre el cron: el primer admin activo. */
function procUsuarioSistema(PDO $db): int {
    return (int)($db->query("SELECT id FROM usuarios WHERE rol = 'admin' AND activo = 1 ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
}

/**
 * Cotizaciones Pendiente/Enviada (sin aprobar) cuya validez ya pasó → Expirada. No toca las que se
 * cambiaron a mano DESPUÉS de vencer (updated_at posterior a la validez): así
 * se puede reactivar o aceptar una vencida sin que el sistema la vuelva a
 * expirar. Avisa una vez en la campana.
 * @return string[] números de las cotizaciones expiradas
 */
function procExpirarCotizaciones(PDO $db, string $hoy): array {
    $st = $db->prepare("SELECT id, numero FROM cotizaciones
                        WHERE deleted_at IS NULL AND estado IN ('Pendiente', 'Enviada')
                          AND fecha_validez IS NOT NULL AND fecha_validez < ?
                          AND (updated_at IS NULL OR updated_at < DATE_ADD(fecha_validez, INTERVAL 1 DAY))
                          AND aprobada_at IS NULL");
    $st->execute([$hoy]);
    $filas = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$filas) return [];
    // updated_at no se toca: así se distingue la expiración automática de un cambio a mano.
    $up = $db->prepare("UPDATE cotizaciones SET estado = 'Expirada', updated_at = updated_at WHERE id = ? AND estado IN ('Pendiente', 'Enviada')");
    $nums = [];
    foreach ($filas as $f) {
        $up->execute([(int)$f['id']]);
        if ($up->rowCount() === 1) $nums[] = $f['numero'];
    }
    if ($nums) {
        $n = count($nums);
        procNotificar($db, 'info',
            ($n === 1 ? "La cotización {$nums[0]} expiró" : "$n cotizaciones expiraron") . ' sin respuesta del cliente: ¿hacer seguimiento?',
            'cotizaciones');
    }
    return $nums;
}

/**
 * "N cuotas vencidas por cobrar" (misma regla que el Panel, lib/saldos.php).
 * Una vez por día; si ya no hay vencidas se borra el aviso sin leer.
 */
function procAvisoCuotasVencidas(PDO $db, string $hoy): void {
    $res = saldoResumen(saldosCuotas($db));
    $patron = '%vencida% por cobrar%';
    if ($res['vencidas'] <= 0) {
        $db->prepare("DELETE FROM notificaciones WHERE leida = 0 AND link = 'pagos' AND mensaje LIKE ?")->execute([$patron]);
        return;
    }
    if (procConfig($db, 'aviso_vencidas_fecha') === $hoy) return;
    $n = $res['vencidas'];
    $msg = "$n cuota" . ($n === 1 ? ' vencida' : 's vencidas') . ' por cobrar ($' . number_format($res['vencido'], 0, ',', '.') . ')';
    // Reemplaza el aviso anterior sin leer (no se acumulan)
    $db->prepare("DELETE FROM notificaciones WHERE leida = 0 AND link = 'pagos' AND mensaje LIKE ?")->execute([$patron]);
    procNotificar($db, 'warning', $msg, 'pagos');
    procConfigSet($db, 'aviso_vencidas_fecha', $hoy);
}

/**
 * Avisa si no hay un respaldo de la base en los últimos PROC_BACKUP_DIAS días
 * (reemplaza el "Recuerda: haz backup" semanal, que avisaba aunque hubiera).
 */
function procAvisoBackup(PDO $db): void {
    $dir = __DIR__ . '/../backups';
    $ultimo = 0;
    foreach (glob($dir . '/*.sql') ?: [] as $f) $ultimo = max($ultimo, (int)filemtime($f));
    $msg = 'No hay un respaldo de la base de datos en los últimos ' . PROC_BACKUP_DIAS . ' días';
    if ($ultimo >= time() - PROC_BACKUP_DIAS * 86400) {
        $db->prepare("DELETE FROM notificaciones WHERE leida = 0 AND mensaje = ?")->execute([$msg]);
        return;
    }
    procNotificar($db, 'warning', $msg, 'configuracion:sistema');
}

/** Link del portal vencido en un proyecto en curso: avisar para renovarlo. */
function procAvisoPortalVencido(PDO $db, string $hoy): void {
    $st = $db->prepare("SELECT id, nombre FROM proyectos
                        WHERE deleted_at IS NULL AND estado = 'En curso'
                          AND portal_token IS NOT NULL AND portal_token_exp IS NOT NULL AND portal_token_exp < ?");
    $st->execute([$hoy . ' 23:59:59']);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
        procNotificar($db, 'warning', "El link del portal de «{$p['nombre']}» venció: vuelve a compartirlo para renovarlo.", 'proyectos:' . (int)$p['id']);
    }
}

/** Crea un aviso en la campana si no hay uno igual sin leer. */
function procNotificar(PDO $db, string $tipo, string $msg, ?string $link = null): void {
    $msg = mb_substr($msg, 0, 500);
    $st = $db->prepare("SELECT 1 FROM notificaciones WHERE leida = 0 AND mensaje = ? LIMIT 1");
    $st->execute([$msg]);
    if ($st->fetchColumn()) return;
    $db->prepare("INSERT INTO notificaciones (usuario_id, tipo, mensaje, link) VALUES (NULL, ?, ?, ?)")->execute([$tipo, $msg, $link]);
}

function procConfig(PDO $db, string $clave): ?string {
    $st = $db->prepare("SELECT valor FROM configuracion WHERE clave = ?");
    $st->execute([$clave]);
    $v = $st->fetchColumn();
    return $v === false ? null : (string)$v;
}

function procConfigSet(PDO $db, string $clave, string $valor): void {
    $db->prepare("INSERT INTO configuracion (clave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = ?")->execute([$clave, $valor, $valor]);
}
