<?php
/**
 * Datos de ejemplo: 5 clientes con el flujo completo y TODOS los estados
 * (cotizaciones, proyectos, fases, tareas, cuotas, actas, sitios), para
 * probar el sistema sin inventar datos a mano.
 *
 * - Marca: clientes.notas empieza con EJEMPLOS_MARCA. ejemplosBorrar() quita
 *   esos clientes con todo lo suyo (incluido lo que generaron los procesos).
 * - Fechas relativas: el guion está escrito para el 2026-11-25 y se corre
 *   tantos días como haya entre esa fecha y hoy, así siempre hay una cuota
 *   vencida, un acta esperando respuesta, una garantía por terminar, etc.
 * - Cotizaciones con prefijo DEMO-: no consumen la numeración real.
 * - Usa lo que exista en la instalación (etapas, trabajador, briefing,
 *   etiquetas). Al final corre las mantenciones/renovaciones reales.
 * Lo usa api/admin/ejemplos.php (Configuración → Sistema → Datos de ejemplo).
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../finance.php';
require_once __DIR__ . '/mantenciones.php';   // + cobros.php

const EJEMPLOS_MARCA = '[Ejemplo]';
const EJEMPLOS_BASE  = '2026-11-25';

/** Cuántos clientes de ejemplo hay. */
function ejemplosHay(PDO $db): int {
    $st = $db->prepare("SELECT COUNT(*) FROM clientes WHERE notas LIKE ?");
    $st->execute([EJEMPLOS_MARCA . '%']);
    return (int)$st->fetchColumn();
}

/** Borra para siempre los clientes de ejemplo y todo lo suyo. @return int clientes borrados */
function ejemplosBorrar(PDO $db): int {
    $st = $db->prepare("SELECT id FROM clientes WHERE notas LIKE ?");
    $st->execute([EJEMPLOS_MARCA . '%']);
    $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    if (!$ids) return 0;
    $in = implode(',', $ids);
    $proy = array_map('intval', $db->query("SELECT id FROM proyectos WHERE cliente_id IN ($in)")->fetchAll(PDO::FETCH_COLUMN));
    $pin = $proy ? implode(',', $proy) : '0';
    $pagos = "SELECT id FROM pagos WHERE cliente_id IN ($in) OR proyecto_id IN ($pin)";
    $db->beginTransaction();
    try {
        $db->exec("DELETE FROM pago_abonos WHERE cuota_id IN (SELECT id FROM pago_cuotas WHERE pago_id IN ($pagos))");
        $db->exec("DELETE FROM pago_cuotas WHERE pago_id IN (SELECT id FROM ($pagos) x)");
        $db->exec("DELETE FROM pagos WHERE cliente_id IN ($in) OR proyecto_id IN ($pin)");
        foreach (['proyecto_tareas', 'proyecto_fases', 'proyecto_comentarios', 'proyecto_contratos', 'proyecto_etiquetas',
                  'proyecto_miembros', 'proyecto_servicios', 'briefing_respuestas', 'proyecto_adjuntos'] as $t) {
            $db->exec("DELETE FROM $t WHERE proyecto_id IN ($pin)");
        }
        $db->exec("DELETE FROM cotizacion_items WHERE cotizacion_id IN (SELECT id FROM cotizaciones WHERE cliente_id IN ($in))");
        $db->exec("DELETE FROM cotizaciones WHERE cliente_id IN ($in)");
        $db->exec("DELETE FROM proyectos WHERE id IN ($pin)");
        $db->exec("DELETE FROM sitios WHERE cliente_id IN ($in)");
        $db->exec("DELETE FROM clientes WHERE id IN ($in)");
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    return count($ids);
}

/**
 * Carga los 5 clientes de ejemplo. Falla si ya hay (borrarlos antes).
 * @return array{clientes:int, mantenciones:int, renovaciones:int}
 */
function ejemplosCargar(PDO $db, int $adminId, ?string $hoy = null): array {
    if (ejemplosHay($db) > 0) throw new RuntimeException('Ya hay datos de ejemplo. Bórralos antes de volver a cargarlos.');
    $hoy = $hoy ?: date('Y-m-d');
    $MARCA = EJEMPLOS_MARCA;
    $IVA = (float)($db->query("SELECT valor FROM configuracion WHERE clave='iva_pct'")->fetchColumn() ?: 19);
    $EMPRESA = (string)($db->query("SELECT valor FROM configuracion WHERE clave='nombre_empresa'")->fetchColumn() ?: 'la agencia');

    // Corrimiento de fechas: el guion está escrito para EJEMPLOS_BASE.
    $dias = (int)(new DateTimeImmutable(EJEMPLOS_BASE))->diff(new DateTimeImmutable($hoy))->format('%r%a');
    $D = function (string $f) use ($dias): string {
        $d = (new DateTimeImmutable($f))->modify("$dias days");
        return strlen($f) > 10 ? $d->format('Y-m-d H:i:s') : $d->format('Y-m-d');
    };
    $mes = fn(string $f) => MESES_ES[(int)date('n', strtotime($D($f))) - 1] . ' ' . date('Y', strtotime($D($f)));

    // Lo que exista en esta instalación
    $E = array_map('intval', $db->query("SELECT id FROM etapas ORDER BY orden, id")->fetchAll(PDO::FETCH_COLUMN));
    $etapa = fn(float $pos) => $E ? $E[(int)round($pos * (count($E) - 1))] : null;   // 0 = primera, 1 = última
    $trab = $db->query("SELECT id FROM usuarios WHERE rol <> 'admin' AND activo = 1 ORDER BY id LIMIT 1")->fetchColumn();
    $trab = $trab ? (int)$trab : null;
    $ET = array_map('intval', $db->query("SELECT id FROM etiquetas ORDER BY id LIMIT 2")->fetchAll(PDO::FETCH_COLUMN));
    $BT = array_map('intval', $db->query("SELECT id FROM briefing_tipos ORDER BY id LIMIT 2")->fetchAll(PDO::FETCH_COLUMN));
    $preguntas = function (int $i) use ($db, $BT): array {
        if (!isset($BT[$i])) return [];
        $st = $db->prepare("SELECT id FROM briefing_preguntas WHERE tipo_id = ? AND activa = 1 AND formato <> 'archivo' ORDER BY orden, id");
        $st->execute([$BT[$i]]);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    };

    $year = substr($hoy, 0, 4);
    $n = (int)$db->query("SELECT MAX(CAST(SUBSTRING_INDEX(numero,'-',-1) AS UNSIGNED)) FROM cotizaciones WHERE numero LIKE 'DEMO-%'")->fetchColumn();

    $ins = function (string $t, array $row) use ($db): int {
        $db->prepare("INSERT INTO $t (" . implode(',', array_keys($row)) . ") VALUES (" . implode(',', array_fill(0, count($row), '?')) . ")")
           ->execute(array_values($row));
        return (int)$db->lastInsertId();
    };
    $tokCot = fn() => rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $tokPortal = fn() => bin2hex(random_bytes(24));
    $cot = function (int $cli, array $c, array $items) use ($ins, &$n, $year): int {
        $num = 'DEMO-' . $year . '-' . str_pad((string)(++$n), 3, '0', STR_PAD_LEFT);
        $c += ['numero' => $num, 'cliente_id' => $cli];
        if (!empty($c['aprobada_at'])) $c['aprobada_hash'] = hash('sha256', $num . '|' . ($c['aprobada_nombre'] ?? '') . '|' . bin2hex(random_bytes(8)));
        $id = $ins('cotizaciones', $c);
        foreach ($items as $i => [$d, $q, $p]) $ins('cotizacion_items', ['cotizacion_id' => $id, 'descripcion' => $d, 'cantidad' => $q, 'precio_unitario' => $p, 'orden' => $i]);
        return $id;
    };
    $proy = function (int $cli, array $p, array $lineas = [], ?int $cotId = null) use ($ins, $db): int {
        $id = $ins('proyectos', $p + ['cliente_id' => $cli]);
        foreach ($lineas as $i => [$nom, $q, $pr]) $ins('proyecto_servicios', ['proyecto_id' => $id, 'nombre' => $nom, 'cantidad' => $q, 'precio_unitario' => $pr, 'orden' => $i]);
        if ($cotId) $db->prepare("UPDATE cotizaciones SET proyecto_id = ? WHERE id = ?")->execute([$id, $cotId]);
        return $id;
    };
    $fases = function (int $pid, array $fs) use ($ins): array {
        $ids = [];
        foreach ($fs as $i => $f) $ids[] = $ins('proyecto_fases', ['proyecto_id' => $pid, 'orden' => $i] + $f);
        return $ids;
    };
    $tareas = function (int $pid, ?int $fase, array $ts) use ($ins): void {
        foreach ($ts as $i => [$t, $e]) $ins('proyecto_tareas', ['proyecto_id' => $pid, 'fase_id' => $fase, 'titulo' => $t, 'estado' => $e, 'orden' => $i]);
    };
    $miembro = function (int $pid) use ($ins, $trab): void { if ($trab) $ins('proyecto_miembros', ['proyecto_id' => $pid, 'usuario_id' => $trab]); };
    $etiquetas = function (int $pid, array $cuales) use ($ins, $ET): void {
        foreach ($cuales as $i) if (isset($ET[$i])) $ins('proyecto_etiquetas', ['proyecto_id' => $pid, 'etiqueta_id' => $ET[$i]]);
    };
    $briefing = function (int $pid, int $tipo, array $valores) use ($ins, $preguntas): void {
        foreach (array_slice($preguntas($tipo), 0, count($valores)) as $i => $q) $ins('briefing_respuestas', ['proyecto_id' => $pid, 'pregunta_id' => $q, 'valor' => $valores[$i]]);
    };
    /* Pago con cuotas: ['neto'|'monto', 'num'?, 'fecha'?, 'abonos'?=[[monto, fecha]]].
       factura = neto + IVA; boleta/directo = monto. Estado y cobrado salen de los abonos. */
    $pago = function (int $cli, ?int $pid, string $tipo, string $desc, array $cuotas) use ($ins, $IVA): int {
        $calc = array_map(fn($c) => [$c, finance_calc_doc(['tipo_pago' => $tipo, 'neto' => $c['neto'] ?? null, 'boleta_monto' => $c['monto'] ?? null], $IVA)], $cuotas);
        $pagoId = $ins('pagos', ['cliente_id' => $cli, 'proyecto_id' => $pid, 'tipo_pago' => $tipo, 'descripcion' => $desc,
                                 'monto_total' => array_sum(array_map(fn($x) => $x[1]['total'], $calc))]);
        foreach ($calc as $i => [$c, $doc]) {
            $abonos = array_map(fn($a) => ['monto' => $a[0] === 'total' ? $doc['total'] : $a[0], 'fecha' => $a[1]], $c['abonos'] ?? []);
            $r = finance_procesar_abonos(['tipo_pago' => $tipo, 'boleta_monto' => $doc['total'], 'abonos' => $abonos]);
            $cid = $ins('pago_cuotas', ['pago_id' => $pagoId, 'numero_cuota' => $i + 1, 'tipo_pago' => $tipo,
                'boleta_num' => $c['num'] ?? null, 'boleta_fecha' => $c['fecha'] ?? null,
                'boleta_monto' => $doc['total'], 'neto' => $doc['neto'], 'iva' => $doc['iva'],
                'pago_monto' => $r['suma'], 'pago_fecha' => $r['maxFecha'], 'estado' => $r['estado']]);
            foreach ($r['abonos'] as $a) $ins('pago_abonos', ['cuota_id' => $cid, 'monto' => $a['monto'], 'fecha' => $a['fecha']]);
        }
        return $pagoId;
    };
    // HTML como el que deja el editor del contrato (Quill).
    $h = fn(string $t) => htmlspecialchars($t, ENT_QUOTES, 'UTF-8');
    $contrato = fn(string $cli) => "<h2>Contrato de prestación de servicios</h2>"
        . "<p>Entre <strong>{$h($EMPRESA)}</strong> (el prestador) y <strong>{$h($cli)}</strong> (el cliente) se acuerda el desarrollo del proyecto según la cotización aprobada.</p>"
        . "<ol><li><strong>Alcance:</strong> lo indicado en la cotización. Lo que no esté ahí se cotiza aparte.</li>"
        . "<li><strong>Plazos:</strong> se cuentan desde que el cliente entrega el material pedido en el briefing.</li>"
        . "<li><strong>Pagos:</strong> en las cuotas indicadas en la cotización. Cada fase comienza con su cuota al día.</li>"
        . "<li><strong>Revisiones:</strong> hasta <u>2 rondas de cambios por fase</u>, que el cliente aprueba en este portal.</li>"
        . "<li><strong>Entrega y garantía:</strong> al terminar, el cliente confirma la recepción en el portal. La garantía cubre errores de funcionamiento, no cambios nuevos.</li></ol>";
    $exp = $D('2027-02-28 23:59:59');

    $db->beginTransaction();
    try {
        /* ═══ 1. Panadería Los Andes — flujo completo terminado + mantención mensual ═══ */
        $c1 = $ins('clientes', ['tipo' => 'Empresa', 'nombre' => 'Panadería Los Andes', 'razon_social' => 'Panadería Los Andes SpA',
            'rut' => '76.543.210-K', 'giro' => 'Elaboración de pan y pastelería', 'email' => 'contacto@panaderia.example.com',
            'telefono' => '+56 9 8765 4321', 'direccion' => 'Av. Los Andes 1234', 'comuna' => 'Puente Alto', 'ciudad' => 'Santiago',
            'contacto_nombre' => 'María José Soto', 'contacto_cargo' => 'Gerenta', 'url' => 'https://panaderia.example.com',
            'notas' => "$MARCA Flujo completo: cotización aprobada en línea → proyecto terminado → acta confirmada → mantención mensual (una vencida)."]);
        $s1 = $ins('sitios', ['cliente_id' => $c1, 'nombre' => 'panaderia.example.com', 'url_principal' => 'https://panaderia.example.com',
            'descripcion' => 'WordPress + Elementor', 'dominio_vence' => $D('2027-03-10'), 'dominio_monto' => 15000,
            'hosting_vence' => $D('2027-09-15'), 'hosting_monto' => 90000,
            'mantencion_monto' => 45000, 'mantencion_frecuencia' => 1, 'mantencion_dia' => (int)substr($D('2026-11-01'), 8, 2),
            'mantencion_proxima' => $D('2026-11-01'), 'mantencion_ultimo_periodo' => substr($D('2026-10-01'), 0, 7)]);   // la de este mes la genera el proceso
        $lin1 = [['Sitio web corporativo (5 secciones)', 1, 850000], ['Fotografía de productos (sesión)', 1, 180000]];
        $q1 = $cot($c1, ['fecha' => $D('2026-06-20'), 'fecha_validez' => $D('2026-07-05'), 'estado' => 'Aceptada', 'aplica_iva' => 1,
            'token' => $tokCot(), 'enviada_at' => $D('2026-06-20 10:05:00'), 'vista_at' => $D('2026-06-20 18:30:00'), 'vistas' => 4,
            'aprobada_at' => $D('2026-06-22 11:12:00'), 'aprobada_nombre' => 'María José Soto', 'aprobada_rut' => '15.432.198-7',
            'aprobada_ip' => '190.100.20.30', 'aprobada_user_agent' => 'Mozilla/5.0 (iPhone)', 'notas' => 'Incluye 2 rondas de cambios por fase.'], $lin1);
        $p1 = $proy($c1, ['nombre' => 'Sitio web Panadería Los Andes', 'tipo_proyecto' => 'Sitio web', 'estado' => 'Terminado', 'etapa_id' => $etapa(1),
            'sitio_id' => $s1, 'briefing_tipo_id' => $BT[0] ?? null, 'descripcion' => 'Sitio corporativo con catálogo de productos y pedidos por WhatsApp.',
            'fecha_inicio' => $D('2026-07-01'), 'fecha_termino' => $D('2026-09-12'), 'fecha_entrega_real' => $D('2026-09-15'), 'garantia_meses' => 3,
            'entrega_confirmada_at' => $D('2026-09-17 09:40:00'), 'entrega_confirmada_nombre' => 'María José Soto', 'entrega_confirmada_ip' => '190.100.20.30',
            'presupuesto' => 1030000, 'portal_token' => $tokPortal(), 'portal_token_exp' => $exp, 'created_at' => $D('2026-06-22 11:15:00')], $lin1, $q1);
        $f = $fases($p1, [
            ['nombre' => 'Descubrimiento', 'estado' => 'Completada', 'fecha_objetivo' => $D('2026-07-10'), 'aprobada_at' => $D('2026-07-09 12:00:00')],
            ['nombre' => 'Diseño', 'estado' => 'Completada', 'fecha_objetivo' => $D('2026-08-01'), 'aprobada_at' => $D('2026-07-31 16:20:00')],
            ['nombre' => 'Desarrollo', 'estado' => 'Completada', 'fecha_objetivo' => $D('2026-09-05'), 'aprobada_at' => $D('2026-09-04 10:00:00')],
            ['nombre' => 'Lanzamiento', 'estado' => 'Completada', 'fecha_objetivo' => $D('2026-09-12'), 'aprobada_at' => $D('2026-09-12 18:00:00')]]);
        $tareas($p1, $f[1], [['Moodboard y paleta', 'Aprobada'], ['Diseño home', 'Aprobada'], ['Diseño catálogo', 'Aprobada']]);
        $tareas($p1, $f[2], [['Maquetar en Elementor', 'Aprobada'], ['Botón de pedido por WhatsApp', 'Aprobada']]);
        $ins('proyecto_contratos', ['proyecto_id' => $p1, 'contenido' => $contrato('Panadería Los Andes SpA'), 'firmado_at' => $D('2026-06-25 10:00:00'),
            'firma_nombre' => 'María José Soto', 'firma_rut' => '15.432.198-7', 'firma_ip' => '190.100.20.30', 'contenido_firmado' => $contrato('Panadería Los Andes SpA')]);
        $ins('proyecto_comentarios', ['proyecto_id' => $p1, 'fase_id' => $f[1], 'autor' => 'cliente', 'autor_nombre' => 'María José Soto',
            'texto' => '¡Nos encantó el diseño! Solo cambiar la foto del pan amasado.', 'created_at' => $D('2026-07-30 19:02:00')]);
        $ins('proyecto_comentarios', ['proyecto_id' => $p1, 'fase_id' => $f[1], 'autor' => 'admin', 'autor_nombre' => $EMPRESA,
            'texto' => 'Listo, ya está cambiada. Quedó en la versión 2.', 'created_at' => $D('2026-07-31 09:15:00')]);
        $briefing($p1, 0, ['Panadería Los Andes', 'Vender más pedidos de tortas y mostrar el catálogo.', 'Familias de Puente Alto, 25–55 años.', 'https://www.example.com']);
        $etiquetas($p1, [0, 1]);
        $pago($c1, $p1, 'factura', 'Sitio web Panadería Los Andes', [
            ['neto' => 515000, 'num' => 'F-1021', 'fecha' => $D('2026-07-01'), 'abonos' => [['total', $D('2026-07-03')]]],
            ['neto' => 515000, 'num' => 'F-1047', 'fecha' => $D('2026-09-15'), 'abonos' => [['total', $D('2026-09-30')]]]]);
        foreach ([['2026-09-01', 'F-1052', [['total', $D('2026-09-05')]]], ['2026-10-01', 'F-1061', []]] as [$fd, $nf, $ab]) {   // pagada / vencida
            $nom = 'Mantención ' . $mes($fd);
            $pm = $proy($c1, ['nombre' => $nom, 'tipo_proyecto' => 'Mantención', 'estado' => 'Terminado', 'etapa_id' => $etapa(1), 'sitio_id' => $s1,
                'descripcion' => 'Generado automáticamente: mantención mensual del sitio panaderia.example.com.',
                'fecha_inicio' => $D($fd), 'fecha_termino' => date('Y-m-t', strtotime($D($fd))), 'presupuesto' => 45000, 'created_at' => $D("$fd 08:00:00")],
                [['Mantención mensual', 1, 45000]]);
            $pago($c1, $pm, 'factura', $nom, [['neto' => 45000, 'num' => $nf, 'fecha' => $D($fd), 'abonos' => $ab]]);
        }
        $cot($c1, ['fecha' => $D('2026-11-24'), 'fecha_validez' => $D('2026-12-09'), 'estado' => 'Pendiente', 'aplica_iva' => 1,
            'notas' => 'Borrador: falta confirmar pasarela de pago.'],
            [['Tienda online WooCommerce', 1, 1200000], ['Carga de productos', 50, 3000]]);

        /* ═══ 2. Estudio Jurídico Pérez — en curso, fase por aprobar, cuota parcial vencida ═══ */
        $c2 = $ins('clientes', ['tipo' => 'Empresa', 'nombre' => 'Estudio Jurídico Pérez & Asociados', 'razon_social' => 'Pérez y Asociados Ltda.',
            'rut' => '77.112.334-5', 'giro' => 'Servicios jurídicos', 'email' => 'rperez@estudioperez.example.com', 'telefono' => '+56 2 2345 6789',
            'direccion' => 'Huérfanos 1160, of. 804', 'comuna' => 'Santiago', 'ciudad' => 'Santiago', 'contacto_nombre' => 'Rodrigo Pérez', 'contacto_cargo' => 'Socio director',
            'notas' => "$MARCA En curso: fase esperando aprobación en el portal, tareas en todos los estados, cuota parcial vencida y otra por emitir."]);
        $s2 = $ins('sitios', ['cliente_id' => $c2, 'nombre' => 'estudioperez.example.com', 'url_principal' => 'https://estudioperez.example.com',
            'descripcion' => 'En desarrollo', 'dominio_vence' => $D('2027-05-01'), 'dominio_monto' => 15000]);
        $lin2 = [['Sitio web con blog', 1, 1400000], ['Redacción SEO de artículos', 6, 35000]];
        $q2 = $cot($c2, ['fecha' => $D('2026-09-01'), 'fecha_validez' => $D('2026-09-16'), 'estado' => 'Aceptada', 'aplica_iva' => 1,
            'enviada_at' => $D('2026-09-01 12:00:00')], $lin2);
        $p2 = $proy($c2, ['nombre' => 'Sitio web Estudio Pérez', 'tipo_proyecto' => 'Sitio web', 'estado' => 'En curso', 'etapa_id' => $etapa(0.75), 'sitio_id' => $s2,
            'briefing_tipo_id' => $BT[0] ?? null, 'descripcion' => 'Sitio institucional con áreas de práctica y blog.',
            'fecha_inicio' => $D('2026-09-15'), 'fecha_termino' => $D('2026-12-18'), 'presupuesto' => 1610000, 'garantia_meses' => 6,
            'portal_token' => $tokPortal(), 'portal_token_exp' => $exp, 'created_at' => $D('2026-09-10 10:00:00')], $lin2, $q2);
        $f = $fases($p2, [
            ['nombre' => 'Descubrimiento', 'estado' => 'Completada', 'fecha_objetivo' => $D('2026-09-25'), 'aprobada_at' => $D('2026-09-24 11:00:00')],
            ['nombre' => 'Diseño', 'estado' => 'Completada', 'fecha_objetivo' => $D('2026-10-20'), 'aprobada_at' => $D('2026-10-21 17:30:00')],
            ['nombre' => 'Maquetación', 'estado' => 'En revisión', 'fecha_objetivo' => $D('2026-11-28')],
            ['nombre' => 'Desarrollo', 'estado' => 'En curso', 'fecha_objetivo' => $D('2026-12-11')],
            ['nombre' => 'Lanzamiento', 'estado' => 'Pendiente', 'fecha_objetivo' => $D('2026-12-18')]]);
        $tareas($p2, $f[2], [['Maquetar home', 'Revisión cliente'], ['Maquetar áreas de práctica', 'QA interna']]);
        $tareas($p2, $f[3], [['Blog con categorías', 'En curso'], ['Formulario de contacto con reCAPTCHA', 'Pendiente'], ['Ficha de cada abogado', 'Aprobada']]);
        $tareas($p2, $f[4], [['Migrar DNS y SSL', 'Pendiente']]);
        $miembro($p2);
        $ins('proyecto_contratos', ['proyecto_id' => $p2, 'contenido' => $contrato('Pérez y Asociados Ltda.'), 'firmado_at' => $D('2026-09-12 15:20:00'),
            'firma_nombre' => 'Rodrigo Pérez', 'firma_rut' => '12.345.678-9', 'firma_ip' => '200.83.10.4', 'contenido_firmado' => $contrato('Pérez y Asociados Ltda.')]);
        $ins('proyecto_comentarios', ['proyecto_id' => $p2, 'fase_id' => $f[2], 'autor' => 'admin', 'autor_nombre' => $EMPRESA,
            'texto' => 'Subí la maquetación del home. ¿La revisas y apruebas la fase?', 'created_at' => $D('2026-11-22 10:00:00')]);
        $ins('proyecto_comentarios', ['proyecto_id' => $p2, 'fase_id' => $f[2], 'autor' => 'cliente', 'autor_nombre' => 'Rodrigo Pérez',
            'texto' => 'Se ve bien. ¿Podemos poner el teléfono más grande en el encabezado?', 'created_at' => $D('2026-11-24 09:12:00')]);
        $briefing($p2, 0, ['Estudio Jurídico Pérez & Asociados', 'Captar clientes de derecho laboral y de familia.', 'Personas y pymes de la RM.']);
        $etiquetas($p2, [1]);
        $pago($c2, $p2, 'factura', 'Sitio web Estudio Pérez', [
            ['neto' => 644000, 'num' => 'F-1040', 'fecha' => $D('2026-09-15'), 'abonos' => [['total', $D('2026-09-20')]]],   // pagada
            ['neto' => 483000, 'num' => 'F-1055', 'fecha' => $D('2026-10-20'), 'abonos' => [[300000, $D('2026-11-05')]]],    // parcial y vencida
            ['neto' => 483000]]);                                                                                           // por emitir
        $cot($c2, ['fecha' => $D('2026-11-10'), 'fecha_validez' => $D('2026-11-30'), 'estado' => 'Enviada', 'aplica_iva' => 1, 'token' => $tokCot(),
            'enviada_at' => $D('2026-11-10 16:00:00'), 'vista_at' => $D('2026-11-12 08:45:00'), 'vistas' => 3,
            'respuesta_tipo' => 'comentario', 'respuesta_texto' => '¿Se puede integrar con Google Calendar? Si es así lo aprobamos.',
            'respuesta_at' => $D('2026-11-12 08:52:00')], [['Módulo de agenda de horas online', 1, 380000]]);

        /* ═══ 3. Camila Rojas (persona, boletas) — acta esperando respuesta + proyecto cotizado ═══ */
        $c3 = $ins('clientes', ['tipo' => 'Persona', 'nombre' => 'Camila Rojas', 'rut' => '17.654.321-2', 'email' => 'camila.rojas@example.com',
            'telefono' => '+56 9 5555 1234', 'comuna' => 'Providencia', 'ciudad' => 'Santiago',
            'notas' => "$MARCA Persona con boletas: acta de entrega esperando respuesta y proyecto «Cotizado» con contrato por firmar."]);
        $lin3 = [['Tarjetas de presentación (diseño)', 1, 70000], ['Firma de correo HTML', 1, 50000]];
        $q3 = $cot($c3, ['fecha' => $D('2026-10-20'), 'fecha_validez' => $D('2026-11-04'), 'estado' => 'Aceptada', 'aplica_iva' => 0,
            'enviada_at' => $D('2026-10-20 11:00:00')], $lin3);
        $p3 = $proy($c3, ['nombre' => 'Tarjetas y firma de correo', 'tipo_proyecto' => 'Diseño', 'estado' => 'Terminado', 'etapa_id' => $etapa(1),
            'fecha_inicio' => $D('2026-10-22'), 'fecha_termino' => $D('2026-11-20'), 'fecha_entrega_real' => $D('2026-11-23'), 'garantia_meses' => 1,
            'presupuesto' => 120000, 'portal_token' => $tokPortal(), 'portal_token_exp' => $exp, 'created_at' => $D('2026-10-21 09:00:00')], $lin3, $q3);
        $f = $fases($p3, [
            ['nombre' => 'Propuestas', 'estado' => 'Completada', 'fecha_objetivo' => $D('2026-11-05'), 'aprobada_at' => $D('2026-11-06 20:00:00')],
            ['nombre' => 'Archivos finales', 'estado' => 'Completada', 'fecha_objetivo' => $D('2026-11-20'), 'aprobada_at' => $D('2026-11-20 13:00:00')]]);
        $tareas($p3, $f[1], [['Exportar PDF para imprenta', 'Aprobada'], ['Firma HTML para Gmail', 'Aprobada']]);
        $etiquetas($p3, [0]);
        $pago($c3, $p3, 'boleta', 'Tarjetas y firma de correo', [
            ['monto' => 60000, 'num' => 'BH-245', 'fecha' => $D('2026-10-22'), 'abonos' => [['total', $D('2026-10-22')]]],   // pagada
            ['monto' => 60000, 'num' => 'BH-251', 'fecha' => $D('2026-11-23')]]);                                             // por cobrar
        $lin3b = [['Diseño de logo (3 propuestas)', 1, 250000], ['Manual de marca básico', 1, 100000]];
        $p3b = $proy($c3, ['nombre' => 'Logo Camila Rojas Psicología', 'tipo_proyecto' => 'Diseño de logo', 'estado' => 'Cotizado', 'etapa_id' => $etapa(0),
            'briefing_tipo_id' => $BT[1] ?? null, 'descripcion' => 'Logo y manual de marca básico.', 'fecha_inicio' => $D('2026-12-01'), 'fecha_termino' => $D('2026-12-22'),
            'presupuesto' => 350000, 'portal_token' => $tokPortal(), 'portal_token_exp' => $exp, 'created_at' => $D('2026-11-18 10:00:00')], $lin3b);
        $cot($c3, ['proyecto_id' => $p3b, 'fecha' => $D('2026-11-18'), 'fecha_validez' => $D('2026-12-03'), 'estado' => 'Enviada', 'aplica_iva' => 0,
            'token' => $tokCot(), 'enviada_at' => $D('2026-11-18 10:30:00'), 'vista_at' => $D('2026-11-19 21:10:00'), 'vistas' => 2], $lin3b);
        $ins('proyecto_contratos', ['proyecto_id' => $p3b, 'contenido' => $contrato('Camila Rojas'), 'requiere_firma' => 1]);   // por firmar
        $briefing($p3b, 1, ['Camila Rojas Psicología']);                                                                   // a medias

        /* ═══ 4. Ferretería El Clavo — acta observada, pausado, rechazada, hosting por renovar ═══ */
        $c4 = $ins('clientes', ['tipo' => 'Empresa', 'nombre' => 'Ferretería El Clavo', 'razon_social' => 'Comercial El Clavo Ltda.',
            'rut' => '78.901.234-6', 'giro' => 'Venta de artículos de ferretería', 'email' => 'jfuentes@elclavo.example.com', 'telefono' => '+56 9 7777 8888',
            'direccion' => 'Camino a Melipilla 4560', 'comuna' => 'Maipú', 'ciudad' => 'Santiago', 'contacto_nombre' => 'Jorge Fuentes', 'contacto_cargo' => 'Dueño',
            'url' => 'https://elclavo.example.com',
            'notas' => "$MARCA Proyecto pausado, acta con observaciones, cotización rechazada y hosting por renovar (lo genera el proceso diario)."]);
        $s4 = $ins('sitios', ['cliente_id' => $c4, 'nombre' => 'elclavo.example.com', 'url_principal' => 'https://elclavo.example.com', 'descripcion' => 'Catálogo WordPress',
            'dominio_vence' => $D('2027-06-30'), 'dominio_monto' => 15000, 'hosting_vence' => $D('2026-12-05'), 'hosting_monto' => 90000]);
        $lin4 = [['Catálogo web de productos', 1, 900000]];
        $q4 = $cot($c4, ['fecha' => $D('2026-09-20'), 'fecha_validez' => $D('2026-10-05'), 'estado' => 'Aceptada', 'aplica_iva' => 1,
            'enviada_at' => $D('2026-09-20 10:00:00')], $lin4);
        $p4 = $proy($c4, ['nombre' => 'Catálogo web Ferretería El Clavo', 'tipo_proyecto' => 'Sitio web', 'estado' => 'Terminado', 'etapa_id' => $etapa(1), 'sitio_id' => $s4,
            'fecha_inicio' => $D('2026-10-01'), 'fecha_termino' => $D('2026-11-15'), 'fecha_entrega_real' => $D('2026-11-18'), 'garantia_meses' => 3,
            'entrega_observacion' => 'Faltan los precios en la sección de herramientas eléctricas y el logo se ve pixelado en celular.',
            'entrega_observacion_at' => $D('2026-11-20 17:45:00'),
            'presupuesto' => 900000, 'portal_token' => $tokPortal(), 'portal_token_exp' => $exp, 'created_at' => $D('2026-09-25 10:00:00')], $lin4, $q4);
        $fases($p4, [
            ['nombre' => 'Diseño', 'estado' => 'Completada', 'fecha_objetivo' => $D('2026-10-15'), 'aprobada_at' => $D('2026-10-16 10:00:00')],
            ['nombre' => 'Desarrollo', 'estado' => 'Completada', 'fecha_objetivo' => $D('2026-11-15'), 'aprobada_at' => $D('2026-11-14 12:00:00')]]);
        $pago($c4, $p4, 'factura', 'Catálogo web Ferretería El Clavo', [['neto' => 900000, 'num' => 'F-1066', 'fecha' => $D('2026-11-18')]]);   // por cobrar
        $lin4b = [['Tienda online con stock sincronizado', 1, 1800000]];
        $q4b = $cot($c4, ['fecha' => $D('2026-08-01'), 'fecha_validez' => $D('2026-08-16'), 'estado' => 'Aceptada', 'aplica_iva' => 1,
            'enviada_at' => $D('2026-08-01 10:00:00')], $lin4b);
        $p4b = $proy($c4, ['nombre' => 'Tienda online Ferretería El Clavo', 'tipo_proyecto' => 'Ecommerce', 'estado' => 'Pausado', 'etapa_id' => $etapa(0),
            'descripcion' => 'Pausado a pedido del cliente: está cambiando de proveedor de stock. Retomar en enero.',
            'fecha_inicio' => $D('2026-08-15'), 'fecha_termino' => $D('2026-11-30'), 'presupuesto' => 1800000, 'created_at' => $D('2026-08-05 10:00:00')], $lin4b, $q4b);
        $f = $fases($p4b, [
            ['nombre' => 'Descubrimiento', 'estado' => 'Completada', 'fecha_objetivo' => $D('2026-08-25'), 'aprobada_at' => $D('2026-08-26 10:00:00')],
            ['nombre' => 'Diseño', 'estado' => 'En curso', 'fecha_objetivo' => $D('2026-09-20')],
            ['nombre' => 'Integración de stock', 'estado' => 'Pendiente'],
            ['nombre' => 'Lanzamiento', 'estado' => 'Pendiente']]);
        $tareas($p4b, $f[1], [['Diseño ficha de producto', 'En curso'], ['Diseño carrito', 'Pendiente']]);
        $miembro($p4b);
        $pago($c4, $p4b, 'factura', 'Tienda online Ferretería El Clavo', [
            ['neto' => 900000, 'num' => 'F-1043', 'fecha' => $D('2026-08-15'), 'abonos' => [['total', $D('2026-08-20')]]],   // anticipo pagado
            ['neto' => 900000]]);                                                                                           // por emitir
        $cot($c4, ['fecha' => $D('2026-10-01'), 'fecha_validez' => $D('2026-10-16'), 'estado' => 'Rechazada', 'aplica_iva' => 1, 'token' => $tokCot(),
            'enviada_at' => $D('2026-10-01 09:00:00'), 'vista_at' => $D('2026-10-02 19:00:00'), 'vistas' => 1,
            'respuesta_tipo' => 'rechazo', 'respuesta_texto' => 'Por ahora no tenemos presupuesto para publicidad.', 'respuesta_at' => $D('2026-10-05 12:30:00')],
            [['Campaña Google Ads (configuración)', 1, 250000], ['Gestión mensual Google Ads', 3, 120000]]);

        /* ═══ 5. Andrés Muñoz (persona, pago directo) — recibido por plazo, cancelado, expirada ═══ */
        $c5 = $ins('clientes', ['tipo' => 'Persona', 'nombre' => 'Andrés Muñoz', 'rut' => '16.234.567-8', 'email' => 'andres@example.com',
            'telefono' => '+56 9 4444 3210', 'comuna' => 'Ñuñoa', 'ciudad' => 'Santiago', 'url' => 'https://andres.example.com',
            'notas' => "$MARCA Fotógrafo: acta recibida por plazo con garantía por terminar, proyecto cancelado, pago directo pendiente y cotización expirada."]);
        $s5 = $ins('sitios', ['cliente_id' => $c5, 'nombre' => 'andres.example.com', 'url_principal' => 'https://andres.example.com', 'descripcion' => 'Portafolio',
            'mantencion_monto' => 30000, 'mantencion_frecuencia' => 3, 'mantencion_dia' => (int)substr($D('2027-01-15'), 8, 2),
            'mantencion_proxima' => $D('2027-01-15'), 'mantencion_ultimo_periodo' => substr($D('2026-10-15'), 0, 7)]);
        $lin5 = [['Portafolio fotográfico web', 1, 480000]];
        $q5 = $cot($c5, ['fecha' => $D('2026-07-05'), 'fecha_validez' => $D('2026-07-20'), 'estado' => 'Aceptada', 'aplica_iva' => 0,
            'enviada_at' => $D('2026-07-05 10:00:00')], $lin5);
        $p5 = $proy($c5, ['nombre' => 'Portafolio Andrés Muñoz', 'tipo_proyecto' => 'Sitio web', 'estado' => 'Terminado', 'etapa_id' => $etapa(1), 'sitio_id' => $s5,
            'fecha_inicio' => $D('2026-07-10'), 'fecha_termino' => $D('2026-08-25'), 'fecha_entrega_real' => $D('2026-08-28'), 'garantia_meses' => 3,
            'entrega_confirmada_at' => $D('2026-09-05 08:00:00'), 'entrega_confirmada_nombre' => 'Recibida por plazo', 'entrega_por_plazo' => 1,
            'presupuesto' => 480000, 'created_at' => $D('2026-07-06 10:00:00')], $lin5, $q5);
        $fases($p5, [
            ['nombre' => 'Diseño', 'estado' => 'Completada', 'aprobada_at' => $D('2026-07-25 10:00:00')],
            ['nombre' => 'Desarrollo', 'estado' => 'Completada', 'aprobada_at' => $D('2026-08-25 10:00:00')]]);
        $etiquetas($p5, [1]);
        $pago($c5, $p5, 'directo', 'Portafolio Andrés Muñoz', [
            ['monto' => 240000, 'abonos' => [['total', $D('2026-07-06')]]],   // pagado
            ['monto' => 240000]]);                                            // directo pendiente → por cobrar
        $lin5b = [['Tienda de impresiones fotográficas', 1, 650000]];
        $q5b = $cot($c5, ['fecha' => $D('2026-10-01'), 'fecha_validez' => $D('2026-10-16'), 'estado' => 'Aceptada', 'aplica_iva' => 0,
            'enviada_at' => $D('2026-10-01 10:00:00')], $lin5b);
        $p5b = $proy($c5, ['nombre' => 'Tienda de impresiones', 'tipo_proyecto' => 'Ecommerce', 'estado' => 'Cancelado', 'etapa_id' => $etapa(0),
            'descripcion' => 'Cancelado: el cliente decidió vender por Instagram. Se queda el anticipo por el trabajo de descubrimiento.',
            'fecha_inicio' => $D('2026-10-10'), 'fecha_termino' => $D('2026-12-15'), 'presupuesto' => 650000, 'created_at' => $D('2026-10-05 10:00:00')], $lin5b, $q5b);
        $fases($p5b, [['nombre' => 'Descubrimiento', 'estado' => 'Completada', 'aprobada_at' => $D('2026-10-20 10:00:00')], ['nombre' => 'Diseño', 'estado' => 'Pendiente']]);
        $pago($c5, $p5b, 'directo', 'Anticipo tienda de impresiones', [['monto' => 200000, 'abonos' => [['total', $D('2026-10-10')]]]]);
        $cot($c5, ['fecha' => $D('2026-09-15'), 'fecha_validez' => $D('2026-09-30'), 'estado' => 'Expirada', 'aplica_iva' => 0, 'token' => $tokCot(),
            'enviada_at' => $D('2026-09-15 10:00:00'), 'vista_at' => $D('2026-09-16 22:00:00'), 'vistas' => 1],
            [['Sesión de fotos de producto para web', 1, 180000]]);

        audit($db, $adminId, 'CREATE', 'clientes', 'Cargó los datos de ejemplo (5 clientes con todo el flujo)', $c1);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }

    // Flujos automáticos reales: mantención del mes (Panadería) y renovación de hosting (Ferretería).
    return [
        'clientes'     => 5,
        'mantenciones' => generarMantencionesMensuales($db, $adminId, $hoy),
        'renovaciones' => generarRenovaciones($db, $adminId, $hoy),
    ];
}
