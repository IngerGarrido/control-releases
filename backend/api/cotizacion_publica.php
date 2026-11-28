<?php
/**
 * Cotización en línea (PÚBLICO): el cliente abre /#/cotizacion/{token}, la ve,
 * descarga el PDF, la aprueba, comenta o avisa que no seguirá.
 * (Adaptado de sistema-local/backend/publico/cotizacion.php al estilo del
 * portal de control: el frontend es React y esto solo entrega/recibe JSON.)
 *
 * GET  ?t=TOKEN                       → datos de la cotización (sin datos internos)
 * POST ?t=TOKEN {accion:'aprobar', nombre, rut, acepta}
 *               {accion:'comentar'|'rechazar', texto}
 *
 * Aprobar deja un sello: fecha, nombre, RUT, IP, navegador y hash SHA-256 del
 * contenido aprobado (ítems, totales, términos). La agencia recibe el aviso
 * en la campana (y por correo si está configurado).
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);

try {
    require_once __DIR__ . '/../config.php';
    require_once __DIR__ . '/../lib/cotizacion_totales.php';
    cors();
    $db = getDB();

    $token = (string)($_GET['t'] ?? '');
    if (!preg_match('/^[A-Za-z0-9_-]{43}$/', $token)) err('Link inválido', 404);
    rateLimit('cot.publica', 120, 3600);

    $st = $db->prepare("SELECT c.*, COALESCE(NULLIF(cl.nombre, ''), cl.razon_social) AS cliente_nombre, cl.email AS cliente_email
                        FROM cotizaciones c LEFT JOIN clientes cl ON cl.id = c.cliente_id
                        WHERE c.token = ? AND c.deleted_at IS NULL LIMIT 1");
    $st->execute([$token]);
    $cot = $st->fetch(PDO::FETCH_ASSOC);
    if (!$cot) err('Esta cotización no existe o ya no está disponible.', 404);
    $id = (int)$cot['id'];

    // ── Contenido (lo que se muestra y lo que se sella al aprobar) ──
    $it = $db->prepare("SELECT descripcion, cantidad, unidad, precio_unitario FROM cotizacion_items WHERE cotizacion_id = ? ORDER BY orden, id");
    $it->execute([$id]);
    $items = array_map(fn($r) => [
        'descripcion' => (string)$r['descripcion'], 'cantidad' => (float)$r['cantidad'],
        'unidad' => (string)($r['unidad'] ?? ''), 'precio' => (int)round((float)$r['precio_unitario']),
        'subtotal' => (int)round((float)$r['cantidad'] * (float)$r['precio_unitario']),
    ], $it->fetchAll(PDO::FETCH_ASSOC));
    // Totales desde la única fuente (lib/cotizacion_totales.php).
    $tot = cotizacionTotales($db, $id, $cot);
    $sub = $tot['subtotal']; $dctoPct = $tot['descuento_pct']; $descuento = $tot['descuento'];
    $neto = $tot['neto']; $iva = $tot['iva'];
    $aplicaIva = (int)($cot['aplica_iva'] ?? 1) === 1;
    $ivaPct = $aplicaIva ? $tot['iva_pct'] : ivaPctConfig($db);

    // Términos: de la plantilla elegida o los generales. HTML del editor,
    // saneado a etiquetas de texto (nada de scripts ni atributos).
    $cfg = $db->query("SELECT clave, valor FROM configuracion")->fetchAll(PDO::FETCH_KEY_PAIR);
    $terminos = ['obligaciones' => $cfg['terminos_obligaciones'] ?? '', 'desarrollo' => $cfg['terminos_desarrollo'] ?? '', 'condiciones' => $cfg['terminos_condiciones'] ?? ''];
    if (!empty($cot['plantilla_id'])) {
        $pt = $db->prepare("SELECT terminos_obligaciones, terminos_desarrollo, terminos_condiciones FROM plantillas_terminos WHERE id = ? AND activa = 1");
        $pt->execute([(int)$cot['plantilla_id']]);
        if ($p = $pt->fetch(PDO::FETCH_ASSOC)) {
            $terminos = ['obligaciones' => $p['terminos_obligaciones'] ?? '', 'desarrollo' => $p['terminos_desarrollo'] ?? '', 'condiciones' => $p['terminos_condiciones'] ?? ''];
        }
    }
    $limpiarHtml = fn($h) => trim(preg_replace('/<(\w+)\s[^>]*>/', '<$1>', strip_tags((string)$h, '<p><br><strong><b><em><i><u><ol><ul><li><h1><h2><h3>')));
    $terminos = array_map($limpiarHtml, $terminos);

    $contenido = ['numero' => $cot['numero'], 'items' => $items, 'descuento_pct' => $dctoPct, 'neto' => $neto,
                  'iva' => $iva, 'total' => $neto + $iva, 'notas' => (string)($cot['notas'] ?? ''), 'terminos' => $terminos];
    $hashContenido = hash('sha256', json_encode($contenido, JSON_UNESCAPED_UNICODE));

    $vencida = !empty($cot['fecha_validez']) && $cot['fecha_validez'] < date('Y-m-d');
    $puedeResponder = in_array($cot['estado'], ['Pendiente', 'Enviada'], true) && empty($cot['aprobada_at']) && !$vencida;

    $avisar = function (string $msg, string $tipo = 'info') use ($db) {
        try {
            $db->prepare("INSERT INTO notificaciones (usuario_id, tipo, mensaje, link) VALUES (NULL, ?, ?, 'cotizaciones')")
               ->execute([$tipo, mb_substr($msg, 0, 500)]);
        } catch (Throwable $e) { /* el aviso nunca rompe la respuesta del cliente */ }
    };
    // Correo a la agencia (si el SMTP está configurado). Best-effort.
    $correoAgencia = function (string $asunto, string $html) use ($db, $cfg) {
        try {
            require_once __DIR__ . '/../mailer.php';
            $dest = trim((string)($cfg['email'] ?? ''));
            if ($dest !== '' && filter_var($dest, FILTER_VALIDATE_EMAIL)) enviarCorreo($db, $dest, $asunto, $html);
        } catch (Throwable $e) { logError('Correo de cotización a la agencia', ['message' => $e->getMessage()]); }
    };

    // ── POST: respuesta del cliente ──
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        rateLimit('cot.publica.post', 20, 3600);
        $b = body();
        $accion = (string)($b['accion'] ?? '');
        if (!$puedeResponder) err($cot['aprobada_at'] ? 'Esta cotización ya fue aprobada.' : ($vencida ? 'Esta cotización venció. Pide una actualizada a la agencia.' : 'Esta cotización ya no admite respuestas.'), 409);

        if ($accion === 'aprobar') {
            $nombre = mb_substr(trim((string)($b['nombre'] ?? '')), 0, 150);
            $rut = mb_substr(trim((string)($b['rut'] ?? '')), 0, 12);
            if ($nombre === '' || empty($b['acepta'])) err('Escribe tu nombre y acepta la cotización.', 422);
            if ($rut !== '' && !validateRut($rut)) err('El RUT no es válido.', 422);
            $up = $db->prepare("UPDATE cotizaciones SET estado = 'Aceptada', aprobada_at = NOW(), aprobada_nombre = ?, aprobada_rut = ?,
                                       aprobada_ip = ?, aprobada_user_agent = ?, aprobada_hash = ?, aprobada_iva_pct = ?
                                WHERE id = ? AND aprobada_at IS NULL AND estado IN ('Pendiente', 'Enviada')");
            $up->execute([$nombre, $rut ?: null, $_SERVER['REMOTE_ADDR'] ?? null,
                          mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255), $hashContenido, $ivaPct, $id]);
            if ($up->rowCount() === 0) err('Esta cotización ya fue respondida.', 409);
            $sinProyecto = empty($cot['proyecto_id']) ? ' Falta crear su proyecto: usa «Convertir en proyecto».' : '';
            $avisar("{$nombre} aprobó la cotización {$cot['numero']} ({$cot['cliente_nombre']}).{$sinProyecto}", 'success');
            $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
            $correoAgencia("Cotización {$cot['numero']} aprobada",
                "<p><strong>{$h($nombre)}</strong> aprobó la cotización <strong>{$h($cot['numero'])}</strong> de {$h($cot['cliente_nombre'])} por \$" . number_format($neto + $iva, 0, ',', '.') . ".</p>");
            ok(['estado' => 'Aceptada']);
        }

        if ($accion === 'comentar' || $accion === 'rechazar') {
            $texto = trim((string)($b['texto'] ?? ''));
            if ($accion === 'comentar' && $texto === '') err('Escribe tu comentario.', 422);
            if (mb_strlen($texto) > 5000) err('El texto es muy largo (máx. 5.000 caracteres).', 422);
            $tipo = $accion === 'rechazar' ? 'rechazo' : 'comentario';
            $sql = "UPDATE cotizaciones SET respuesta_tipo = ?, respuesta_texto = ?, respuesta_at = NOW()"
                 . ($accion === 'rechazar' ? ", estado = 'Rechazada'" : '') . " WHERE id = ?";
            $previo = trim((string)($cot['respuesta_texto'] ?? ''));
            $guardar = $texto;
            if ($texto !== '' && $previo !== '') $guardar = mb_substr($previo . "\n\n— " . date('d-m-Y H:i') . " —\n" . $texto, 0, 20000);
            elseif ($texto === '') $guardar = $previo;
            $db->prepare($sql)->execute([$tipo, $guardar !== '' ? $guardar : null, $id]);
            $msg = $accion === 'rechazar'
                ? "El cliente avisó que no seguirá con la cotización {$cot['numero']} ({$cot['cliente_nombre']})."
                : "Comentario del cliente en la cotización {$cot['numero']} ({$cot['cliente_nombre']}).";
            $avisar($msg, $accion === 'rechazar' ? 'warning' : 'info');
            $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
            $correoAgencia(($accion === 'rechazar' ? 'Cotización no aceptada: ' : 'Comentario en la cotización ') . $cot['numero'],
                '<p>' . $h($msg) . '</p>' . ($texto !== '' ? '<blockquote>' . nl2br($h($texto)) . '</blockquote>' : ''));
            ok(['estado' => $accion === 'rechazar' ? 'Rechazada' : $cot['estado']]);
        }
        err('Acción inválida', 400);
    }

    // ── GET: registrar la visita (primera vez = vista_at) ──
    $db->prepare("UPDATE cotizaciones SET vistas = vistas + 1, vista_at = COALESCE(vista_at, NOW()) WHERE id = ?")->execute([$id]);

    ok([
        'agencia' => [
            'nombre' => $cfg['nombre_empresa'] ?? '', 'logo_url' => $cfg['logo_cotizaciones_url'] ?? ($cfg['logo_url'] ?? ''),
            'email' => $cfg['email'] ?? '', 'telefono' => $cfg['telefono'] ?? '',
            'color' => preg_match('/^#[0-9a-fA-F]{6}$/', (string)($cfg['color_primario'] ?? '')) ? $cfg['color_primario'] : null,
        ],
        'cotizacion' => [
            'numero' => $cot['numero'], 'fecha' => $cot['fecha'], 'fecha_validez' => $cot['fecha_validez'],
            'estado' => $cot['estado'], 'cliente_nombre' => $cot['cliente_nombre'],
            'items' => $items, 'subtotal' => $sub, 'descuento' => $descuento, 'descuento_pct' => $dctoPct,
            'neto' => $neto, 'iva' => $iva, 'iva_pct' => $aplicaIva ? $ivaPct : 0, 'total' => $neto + $iva,
            'notas' => (string)($cot['notas'] ?? ''), 'terminos' => $terminos,
            'vencida' => $vencida, 'puede_responder' => $puedeResponder,
            'aprobada' => $cot['aprobada_at'] ? ['at' => $cot['aprobada_at'], 'nombre' => $cot['aprobada_nombre'], 'codigo' => substr((string)$cot['aprobada_hash'], 0, 12)] : null,
            'respuesta' => $cot['respuesta_at'] ? ['tipo' => $cot['respuesta_tipo'], 'at' => $cot['respuesta_at']] : null,
        ],
    ]);
} catch (Throwable $e) {
    errSafe($e, 500);
}
