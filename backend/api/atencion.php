<?php
/**
 * «Necesita tu atención» del Panel + pasos de puesta en marcha (solo admin).
 *
 * GET  → { items: [{tipo, tono, titulo, detalle, accion, panel, id}], onboarding }
 * POST { ocultar_onboarding: true } → deja de mostrar los pasos iniciales.
 *
 * Reúne en una sola lista lo que hoy estaba repartido en cinco pantallas:
 * cobros vencidos, clientes esperando respuesta, cotizaciones por vencer o
 * aprobadas sin proyecto, proyectos atrasados y renovaciones de sitios.
 * Los montos salen de la única fuente (lib/saldos.php).
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);

try {
    require_once __DIR__ . '/../config.php';
    require_once __DIR__ . '/../lib/saldos.php';
    cors();
    $db = getDB();
    $user = requireAuth();
    requireAdmin($user);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $b = body();
        if (!empty($b['ocultar_onboarding'])) {
            $db->prepare("INSERT INTO configuracion (clave, valor) VALUES ('onboarding_oculto', '1') ON DUPLICATE KEY UPDATE valor = '1'")->execute();
        }
        ok(['ok' => true]);
    }

    $clp = fn($n) => '$ ' . number_format((int)$n, 0, ',', '.');
    $dias = fn(int $d) => $d === 0 ? 'hoy' : ($d === 1 ? 'mañana' : "en {$d} días");
    $items = [];

    // 1) Cobros vencidos (por pago)
    $porPago = [];
    foreach (saldosCuotas($db) as $c) {
        if (!$c['vencida']) continue;
        $k = $c['pago_id'];
        $porPago[$k] ??= ['saldo' => 0, 'cuotas' => 0, 'c' => $c];
        $porPago[$k]['saldo'] += $c['saldo'];
        $porPago[$k]['cuotas']++;
    }
    // Cuándo se recordó cada cuota vencida (la más antigua de cada pago).
    $recordado = [];
    if ($porPago) {
        $idsC = implode(',', array_map(fn($g) => (int)$g['c']['cuota_id'], $porPago));
        foreach ($db->query("SELECT id, recordado_at FROM pago_cuotas WHERE id IN ($idsC)") as $r) $recordado[(int)$r['id']] = $r['recordado_at'];
    }
    foreach ($porPago as $pagoId => $g) {
        $c = $g['c'];
        $rec = $recordado[(int)$c['cuota_id']] ?? null;
        // Lo habitual con un cobro vencido es insistir: la acción es «Recordar
        // cobro». Si ya se le recordó hace poco (3 días), toca esperar el pago.
        $reciente = $rec && strtotime($rec) > strtotime('-3 days');
        $items[] = ['tipo' => 'cobro_vencido', 'tono' => 'bad', 'etiqueta' => 'Cobro vencido', 'prioridad' => 1,
            'titulo' => $c['proyecto_nombre'] ?: ($c['pago_descripcion'] ?: 'Pago #' . $pagoId),
            'detalle' => $c['cliente_nombre'] . ' · falta ' . $clp($g['saldo']) . ($g['cuotas'] > 1 ? " ({$g['cuotas']} cuotas)" : '')
                       . ($rec ? ' · recordado el ' . date('d-m', strtotime($rec)) : ''),
            'accion' => $reciente ? 'Registrar pago' : 'Recordar cobro',
            'recordar_cuota' => $reciente ? null : (int)$c['cuota_id'],
            'panel' => 'pagos', 'id' => (int)$pagoId];
    }

    // 2) Cliente esperando respuesta: el último mensaje visible del hilo es suyo
    $st = $db->query("SELECT pc.proyecto_id, p.nombre, pc.autor_nombre, pc.texto
                      FROM proyecto_comentarios pc
                      JOIN (SELECT proyecto_id, MAX(id) AS mid FROM proyecto_comentarios WHERE interno = 0 GROUP BY proyecto_id) u ON u.mid = pc.id
                      JOIN proyectos p ON p.id = pc.proyecto_id
                      WHERE pc.autor = 'cliente' AND p.deleted_at IS NULL AND p.estado NOT IN ('Cancelado')
                      ORDER BY pc.id DESC LIMIT 10");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $items[] = ['tipo' => 'cliente_espera', 'tono' => 'info', 'etiqueta' => 'Cliente espera', 'prioridad' => 2,
            'titulo' => ($r['autor_nombre'] ?: 'El cliente') . ' escribió en ' . $r['nombre'],
            'detalle' => '«' . mb_substr(preg_replace('/\s+/', ' ', (string)$r['texto']), 0, 110) . '»',
            'accion' => 'Responder', 'panel' => 'proyectos', 'id' => (int)$r['proyecto_id']];
    }

    // 3) Cotizaciones: aprobada sin proyecto, con consulta, o por vencer
    $st = $db->query("SELECT c.id, c.numero, c.estado, c.fecha_validez, c.proyecto_id, c.aprobada_at, c.respuesta_tipo, c.vistas,
                             DATEDIFF(c.fecha_validez, CURDATE()) AS dias,
                             COALESCE(NULLIF(cl.nombre, ''), cl.razon_social) AS cliente
                      FROM cotizaciones c LEFT JOIN clientes cl ON cl.id = c.cliente_id
                      WHERE c.deleted_at IS NULL AND (
                            (c.estado = 'Aceptada' AND c.proyecto_id IS NULL AND c.aprobada_at IS NOT NULL)
                         OR (c.estado IN ('Pendiente', 'Enviada') AND c.respuesta_tipo = 'comentario')
                         OR (c.estado IN ('Pendiente', 'Enviada') AND c.fecha_validez IS NOT NULL
                             AND DATEDIFF(c.fecha_validez, CURDATE()) BETWEEN 0 AND 7))
                      ORDER BY c.id DESC LIMIT 15");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ($r['estado'] === 'Aceptada') {
            $items[] = ['tipo' => 'cot_sin_proyecto', 'tono' => 'ok', 'etiqueta' => 'Aprobada', 'prioridad' => 2,
                'titulo' => "{$r['cliente']} aprobó la cotización {$r['numero']}", 'detalle' => 'Falta crear su proyecto',
                'accion' => 'Crear proyecto', 'panel' => 'cotizaciones', 'id' => (int)$r['id']];
        } elseif ($r['respuesta_tipo'] === 'comentario') {
            $items[] = ['tipo' => 'cot_consulta', 'tono' => 'info', 'etiqueta' => 'Cliente espera', 'prioridad' => 2,
                'titulo' => "Consulta en la cotización {$r['numero']}", 'detalle' => (string)$r['cliente'],
                'accion' => 'Ver consulta', 'panel' => 'cotizaciones', 'id' => (int)$r['id']];
        } else {
            $items[] = ['tipo' => 'cot_por_vencer', 'tono' => 'warn', 'etiqueta' => 'Por vencer', 'prioridad' => 3,
                'titulo' => "Cotización {$r['numero']} vence " . $dias((int)$r['dias']),
                'detalle' => $r['cliente'] . ((int)$r['vistas'] > 0 ? " · la vio {$r['vistas']} " . ((int)$r['vistas'] === 1 ? 'vez' : 'veces') . ', sin responder' : ' · aún no la abre'),
                'accion' => 'Ver cotización', 'panel' => 'cotizaciones', 'id' => (int)$r['id']];
        }
    }

    // 4) Proyectos atrasados (activos, no automáticos)
    $st = $db->query("SELECT p.id, p.nombre, DATEDIFF(CURDATE(), p.fecha_termino) AS atraso,
                             COALESCE(NULLIF(c.nombre, ''), c.razon_social) AS cliente
                      FROM proyectos p LEFT JOIN clientes c ON c.id = p.cliente_id
                      WHERE p.deleted_at IS NULL AND p.fecha_entrega_real IS NULL AND p.automatico = 0
                        AND p.estado NOT IN ('Terminado', 'Cancelado', 'Pausado')
                        AND p.fecha_termino IS NOT NULL AND p.fecha_termino < CURDATE()
                      ORDER BY p.fecha_termino ASC LIMIT 8");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $items[] = ['tipo' => 'proyecto_atrasado', 'tono' => 'bad', 'etiqueta' => 'Atrasado', 'prioridad' => 1,
            'titulo' => $r['nombre'], 'detalle' => $r['cliente'] . ' · debía entregarse hace ' . (int)$r['atraso'] . ((int)$r['atraso'] === 1 ? ' día' : ' días'),
            'accion' => 'Abrir proyecto', 'panel' => 'proyectos', 'id' => (int)$r['id']];
    }

    // 5) Renovaciones de dominio / hosting en los próximos 15 días
    $st = $db->query("SELECT s.id, s.nombre, s.dominio_vence, s.hosting_vence,
                             COALESCE(NULLIF(c.nombre, ''), c.razon_social) AS cliente
                      FROM sitios s LEFT JOIN clientes c ON c.id = s.cliente_id
                      WHERE s.deleted_at IS NULL AND (
                            s.dominio_vence BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 15 DAY)
                         OR s.hosting_vence BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 15 DAY))
                      LIMIT 10");
    $hoy = new DateTimeImmutable('today');
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        foreach (['dominio_vence' => 'Dominio', 'hosting_vence' => 'Hosting'] as $col => $nombreCol) {
            if (empty($r[$col])) continue;
            $d = (int)$hoy->diff(new DateTimeImmutable($r[$col]))->format('%r%a');
            if ($d < 0 || $d > 15) continue;
            $items[] = ['tipo' => 'renovacion', 'tono' => 'warn', 'etiqueta' => 'Por vencer', 'prioridad' => 3,
                'titulo' => "{$nombreCol} de {$r['nombre']} se renueva " . $dias($d), 'detalle' => (string)$r['cliente'],
                'accion' => 'Ver sitio', 'panel' => 'sitios', 'id' => (int)$r['id']];
        }
    }

    usort($items, fn($a, $b) => $a['prioridad'] <=> $b['prioridad']);
    $total = count($items);
    $items = array_slice($items, 0, 8);

    // Puesta en marcha: qué falta para que una agencia nueva pueda trabajar.
    $cfg = $db->query("SELECT clave, valor FROM configuracion")->fetchAll(PDO::FETCH_KEY_PAIR);
    $tiene = fn(string $k) => trim((string)($cfg[$k] ?? '')) !== '';
    $pasos = [
        ['id' => 'cuenta',   'titulo' => 'Crear tu cuenta de administración', 'detalle' => '', 'hecho' => true, 'accion' => '', 'panel' => '', 'tab' => ''],
        ['id' => 'empresa',  'titulo' => 'Datos y logo de tu agencia', 'detalle' => 'Salen en las cotizaciones, el portal del cliente y los correos.',
         'hecho' => $tiene('nombre_empresa') && ($tiene('logo_url') || $tiene('logo_cotizaciones_url')), 'accion' => 'Completar', 'panel' => 'configuracion', 'tab' => 'empresa'],
        ['id' => 'correo',   'titulo' => 'Correo saliente', 'detalle' => 'Para enviar cotizaciones, links del portal y recuperar contraseñas.',
         'hecho' => $tiene('smtp_host'), 'accion' => 'Configurar', 'panel' => 'configuracion', 'tab' => 'email'],
        ['id' => 'finanzas', 'titulo' => 'Impuestos y meta mensual', 'detalle' => 'IVA, si cobras con factura o boleta, y cuánto quieres facturar al mes.',
         'hecho' => $tiene('meta_mensual') && $tiene('iva_pct'), 'accion' => 'Revisar', 'panel' => 'configuracion', 'tab' => 'finanzas'],
        ['id' => 'cliente',  'titulo' => 'Tu primer cliente', 'detalle' => 'Con un cliente ya puedes cotizar y abrir un proyecto.',
         'hecho' => (int)$db->query("SELECT COUNT(*) FROM clientes WHERE deleted_at IS NULL")->fetchColumn() > 0, 'accion' => 'Crear cliente', 'panel' => 'clientes', 'tab' => ''],
    ];
    $hechos = count(array_filter($pasos, fn($p) => $p['hecho']));

    ok(['items' => $items, 'total' => $total,
        'onboarding' => ['pasos' => $pasos, 'hechos' => $hechos, 'completo' => $hechos === count($pasos),
                         'oculto' => ($cfg['onboarding_oculto'] ?? '') === '1']]);
} catch (Throwable $e) {
    errSafe($e, 500, 'atencion.php');
}
