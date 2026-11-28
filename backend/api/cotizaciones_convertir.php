<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/cobros.php';
require_once __DIR__ . '/../lib/cotizacion_totales.php';
cors();
$db = getDB();
$user = requireAuth();
requireGestor($user); // admin o comercial

$input = body();
$cotizacion_id = $input['id'] ?? null;

if (!$cotizacion_id) {
    err('ID de cotización requerido');
}

try {
    // 1. Cabecera de la cotización
    // FOR UPDATE dentro de la transacción: dos clics seguidos no crean dos proyectos.
    $db->beginTransaction();
    $stmt = $db->prepare("SELECT * FROM cotizaciones WHERE id = ? AND deleted_at IS NULL FOR UPDATE");
    $stmt->execute([(int)$cotizacion_id]);
    $cot = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$cot)                   err('Cotización no encontrada');
    if (in_array($cot['estado'], ['Rechazada', 'Expirada'], true))
        err('Esta cotización está ' . mb_strtolower($cot['estado']) . '. Cámbiale el estado o duplícala antes de convertirla.', 409);
    if (!empty($cot['proyecto_id']))
        err('Esta cotización ya fue convertida a un proyecto anteriormente.');

    // 2. Ítems de la cotización: para el total real y la descripción del proyecto
    $si = $db->prepare("SELECT servicio_id, descripcion, cantidad, precio_unitario FROM cotizacion_items WHERE cotizacion_id = ? ORDER BY orden");
    $si->execute([$cotizacion_id]);
    $items = $si->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Neto = Σ cantidad × precio; Total = neto × (1 − %descuento_global)
    $tot   = cotizacionTotales($db, (int)$cotizacion_id, $cot); // única fuente
    $total = $tot['neto'];

    // 3. Descripción del proyecto = listado de ítems (para no perder el detalle
    //    cuando la cotización tiene varios servicios y proyectos sólo tiene 1).
    // Sin montos ni descuento: la descripción la ve el equipo (el trabajador
    // no ve dinero). Los precios quedan en las líneas de servicio.
    $linesDesc = [];
    foreach ($items as $it) {
        $cant = (float)$it['cantidad'];
        $linesDesc[] = '• ' . (rtrim(rtrim((string)$cant, '0'), '.') ?: '0') . ' × ' . trim((string)$it['descripcion']);
    }
    $descProyecto = "Generado desde cotización " . ($cot['numero'] ?? ('#' . $cot['id'])) . "\n\n" . implode("\n", $linesDesc);

    // 4. Nombre del proyecto
    $nombreProyecto = !empty($cot['titulo'])
        ? $cot['titulo']
        : (!empty($cot['numero']) ? ('Proyecto ' . $cot['numero']) : ('Proyecto desde Cotización #' . $cot['id']));

    // Total que paga el cliente: neto con descuento + IVA si la cotización lo aplica.
    $aplicaIva = (int)($cot['aplica_iva'] ?? 1) === 1;
    $iva = $tot['iva'];

    // Etapa inicial del tablero (para que el proyecto aparezca en el kanban).
    $etapaId = etapaInicial($db);

    // 5. Crear el proyecto (presupuesto = neto con descuento).
    $stmt = $db->prepare("
        INSERT INTO proyectos (nombre, cliente_id, presupuesto, estado, etapa_id, fecha_inicio, descripcion)
        VALUES (?, ?, ?, 'En curso', ?, CURDATE(), ?)
    ");
    $stmt->execute([$nombreProyecto, (int)$cot['cliente_id'], $total, $etapaId, $descProyecto]);
    $nuevo_proyecto_id = (int)$db->lastInsertId();

    // 6. Líneas de servicio con los precios COTIZADOS (no los del catálogo):
    //    ítems con servicio → línea de catálogo con precio propio; sin servicio
    //    → línea libre. (Portado de sistema-local.)
    $ins = $db->prepare("INSERT INTO proyecto_servicios (proyecto_id, servicio_id, nombre, cantidad, precio_unitario, orden) VALUES (?, ?, ?, ?, ?, ?)");
    $vistos = []; $orden = 0; $primero = null;
    foreach ($items as $it) {
        $sid = (int)($it['servicio_id'] ?? 0);
        $cant = max(1, (int)round((float)$it['cantidad']));
        $precio = (int)round((float)$it['precio_unitario']);
        if ($sid > 0 && !isset($vistos[$sid])) {
            $vistos[$sid] = true;
            $primero ??= $sid;
            $ins->execute([$nuevo_proyecto_id, $sid, null, $cant, $precio, $orden++]);
        } elseif (trim((string)$it['descripcion']) !== '') {
            $ins->execute([$nuevo_proyecto_id, null, mb_substr(trim((string)$it['descripcion']), 0, 200), $cant, $precio, $orden++]);
        }
    }
    if ($primero) $db->prepare("UPDATE proyectos SET servicio_id = ? WHERE id = ?")->execute([$primero, $nuevo_proyecto_id]);

    // 7. Cobro pendiente por el total cotizado (aparece en Pagos y en "Por
    //    emitir" hasta que se emita la factura con número y fecha).
    crearCobroPendiente($db, (int)$cot['cliente_id'], $nuevo_proyecto_id, $nombreProyecto, $total, $aplicaIva ? 'factura' : 'boleta');
    $totalCobro = $total + $iva;

    // 8. Vincular la cotización al proyecto y dejarla en Aceptada
    $db->prepare("UPDATE cotizaciones SET proyecto_id = ?, estado = 'Aceptada' WHERE id = ?")
       ->execute([$nuevo_proyecto_id, $cotizacion_id]);

    $db->commit();
    audit($db, $user['id'], 'CREATE', 'proyectos', "Creó el proyecto #{$nuevo_proyecto_id} desde la cotización " . ($cot['numero'] ?? $cotizacion_id), $nuevo_proyecto_id);
    ok(['proyecto_id' => $nuevo_proyecto_id, 'total' => $totalCobro]);

} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    errSafe($e, 500);
}