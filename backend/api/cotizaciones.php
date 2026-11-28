<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

function nextNumero(PDO $db) {
    // Asume que la transacción está abierta por el caller.
    $pre = (string)$db->query("SELECT valor FROM configuracion WHERE clave = 'cotizacion_prefijo'")->fetchColumn();
    if ($pre === false || $pre === '') $pre = 'COT';
    $year = date('Y');

    // 1) Lee el contador (FOR UPDATE: bloquea para evitar duplicados en POSTs paralelos)
    $st = $db->prepare("SELECT CAST(valor AS UNSIGNED) AS num FROM configuracion WHERE clave = 'cotizacion_siguiente' FOR UPDATE");
    $st->execute();
    $confNum = (int)($st->fetchColumn() ?: 1);

    // 2) Mira el mayor número realmente existente este año en cotizaciones.
    //    Si el contador quedó atrasado (p.ej. se reseteó a 1 pero ya había
    //    cotizaciones), usamos max+1 para no chocar con la clave UNIQUE.
    $st2 = $db->prepare("SELECT MAX(CAST(SUBSTRING_INDEX(numero, '-', -1) AS UNSIGNED)) AS maxn
                         FROM cotizaciones
                         WHERE numero LIKE ?");
    $st2->execute([$pre . '-' . $year . '-%']);
    $maxn = (int)($st2->fetchColumn() ?: 0);

    $num = max($confNum, $maxn + 1);

    return $pre . '-' . $year . '-' . str_pad($num, 3, '0', STR_PAD_LEFT);
}

try {
    require_once __DIR__ . '/../config.php';
    require_once __DIR__ . '/../lib/cotizacion_totales.php';
    cors();
    $db = getDB();
    $user = requireAuth();
    $method = $_SERVER['REQUEST_METHOD'];
    // Soporte para method override via query string (POST con _method=PUT)
    if ($method === 'POST' && isset($_GET['_method'])) {
        $method = strtoupper($_GET['_method']);
    }

    requireGestor($user); // admin o comercial

    $id = isset($_GET['id']) ? (int)$_GET['id'] : null;
    if (!$id) {
        $path = explode('/', parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
        $last = end($path);
        $id = is_numeric($last) ? (int)$last : null;
    }

    // Detectar acción especial
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    $esRestore = strpos($uri, 'restore') !== false || isset($_GET['restore']);
    $esHard = isset($_GET['hard']) && $_GET['hard'] == '1';
    $esPapelera = isset($_GET['papelera']) && $_GET['papelera'] == '1';
    // La papelera y el borrado definitivo son del admin (el comercial envía
    // a la papelera y puede deshacer, nada más).
    if (($esPapelera || $esHard) && !esAdmin($user)) err('Acceso denegado', 403);

    $limpiar = function($str) {
        return trim(str_replace("\0", "", (string)($str ?? '')));
    };

    // --- GET: LISTAR O VER DETALLE ---
   if ($method === 'GET') {
        if ($id) {
            // (Esta parte para ver una sola cotización está bien)
            $sql = "SELECT * FROM cotizaciones WHERE id = ?";
            $s = $db->prepare($sql);
            $s->execute([$id]);
            $cot = $s->fetch(PDO::FETCH_ASSOC);

            if (!$cot) {
                echo json_encode(['ok' => false, 'error' => 'No encontrada']);
                exit;
            }

            $cot['id'] = (int)$cot['id'];
            $cot['cliente_id'] = (int)$cot['cliente_id'];
            $cot['proyecto_id'] = !empty($cot['proyecto_id']) ? (int)$cot['proyecto_id'] : null;
            $cot['plantilla_id'] = !empty($cot['plantilla_id']) ? (int)$cot['plantilla_id'] : null;

            $si = $db->prepare("SELECT * FROM cotizacion_items WHERE cotizacion_id = ? ORDER BY orden");
            $si->execute([$id]);
            $items = $si->fetchAll(PDO::FETCH_ASSOC);

            foreach ($items as &$it) {
                $it['id'] = (int)$it['id'];
                $it['servicio_id'] = !empty($it['servicio_id']) ? (int)$it['servicio_id'] : null;
                $it['cantidad'] = (float)($it['cantidad'] ?? 1);
                $it['precio_unitario'] = (float)($it['precio_unitario'] ?? 0);
            }
            
            $cot['items'] = $items;
            echo json_encode(['ok' => true, 'data' => $cot]);
            exit;
        }

        // --- LISTADO GENERAL CON PAGINACIÓN ---
        $where = $esPapelera ? "WHERE c.deleted_at IS NOT NULL" : "WHERE c.deleted_at IS NULL";

        // Búsqueda server-side
        $q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
        $params = [];
        if ($q !== '') {
            $where .= " AND (c.numero LIKE ? OR cl.nombre LIKE ? OR cl.razon_social LIKE ? OR p.nombre LIKE ?)";
            $like = '%' . escapeLike($q) . '%';
            $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
        }

        // Conteo por pestaña (respeta la búsqueda, no el filtro de estado).
        $cntSt = $db->prepare("SELECT c.estado, COUNT(*) n,
                                      SUM(c.estado = 'Pendiente' AND c.fecha_validez IS NOT NULL
                                          AND c.fecha_validez <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)) por_vencer
                               FROM cotizaciones c
                               LEFT JOIN clientes cl ON cl.id = c.cliente_id
                               LEFT JOIN proyectos p ON p.id = c.proyecto_id
                               {$where} GROUP BY c.estado");
        $cntSt->execute($params);
        $conteo = ['Todos' => 0, 'Por vencer' => 0];
        foreach ($cntSt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $conteo[$r['estado']] = (int)$r['n'];
            $conteo['Todos'] += (int)$r['n'];
            $conteo['Por vencer'] += (int)$r['por_vencer'];
        }

        // Filtro por estado (server-side). "Por vencer" es un filtro virtual:
        // cotizaciones pendientes cuya validez está próxima o ya vencida.
        $estado = isset($_GET['estado']) ? trim((string)$_GET['estado']) : '';
        if ($estado === 'Por vencer') {
            $where .= " AND c.estado = 'Pendiente'
                        AND c.fecha_validez IS NOT NULL
                        AND c.fecha_validez <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)";
        } elseif ($estado !== '' && $estado !== 'Todos') {
            $where .= " AND c.estado = ?";
            $params[] = $estado;
        }

        // 1. Lógica de Paginación con cap a 100 (consistencia con otros endpoints)
        $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
        $per_page = isset($_GET['per_page']) ? max(1, min(100, (int)$_GET['per_page'])) : 10;
        $offset = ($page - 1) * $per_page;

        // 2. Contar Total (con JOINs por filtro server-side)
        $countSql = "SELECT COUNT(*) FROM cotizaciones c
                     LEFT JOIN clientes cl ON cl.id = c.cliente_id
                     LEFT JOIN proyectos p ON p.id = c.proyecto_id
                     {$where}";
        $countStmt = $db->prepare($countSql);
        $countStmt->execute($params);
        $totalItems = $countStmt->fetchColumn();
        $totalPages = (int)ceil($totalItems / $per_page);

        // 3. Consulta con LIMIT y OFFSET
        $sql = "SELECT c.id, c.numero, c.fecha, c.fecha_validez, c.estado, c.proyecto_id, c.cliente_id, c.deleted_at,
                       c.aplica_iva, (c.token IS NOT NULL) AS tiene_link, c.enviada_at, c.vista_at, c.vistas,
                       c.aprobada_at, c.aprobada_nombre, c.respuesta_tipo, c.respuesta_texto, c.respuesta_at,
                       cl.email AS cliente_email,
                       cl.nombre as cliente_nom,
                       cl.razon_social as cliente_age, p.nombre as proyecto_nom,
                       c.descuento_global, c.aprobada_iva_pct
                FROM cotizaciones c
                LEFT JOIN clientes cl ON cl.id = c.cliente_id
                LEFT JOIN proyectos p ON p.id = c.proyecto_id
                {$where}
                ORDER BY c.id DESC
                LIMIT $per_page OFFSET $offset";

        // % de IVA configurable (una agencia cotiza con factura).
        $ivaPct = ivaPctConfig($db);

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // Ítems de toda la página en una sola consulta (sin N+1); los totales
        // salen de la única fuente: lib/cotizacion_totales.php.
        $itemsPor = [];
        if ($filas) {
            $idsPag = array_map(fn($f) => (int)$f['id'], $filas);
            $stIt = $db->prepare("SELECT cotizacion_id, cantidad, precio_unitario FROM cotizacion_items WHERE cotizacion_id IN (" . implode(',', array_fill(0, count($idsPag), '?')) . ")");
            $stIt->execute($idsPag);
            foreach ($stIt->fetchAll(PDO::FETCH_ASSOC) as $itRow) $itemsPor[(int)$itRow['cotizacion_id']][] = $itRow;
        }
        $data = [];
        foreach ($filas as $r) {
            // Nombre comercial; si está vacío, la razón social (cliente_age).
            $cliente_display = $limpiar($r['cliente_nom']) ?: $limpiar($r['cliente_age']);

            // Desglose: el monto de los ítems es el NETO; el IVA se suma encima.
            $aplicaIva = (int)($r['aplica_iva'] ?? 1) === 1;
            $pctFila   = (!empty($r['aprobada_at']) && $r['aprobada_iva_pct'] !== null) ? (float)$r['aprobada_iva_pct'] : $ivaPct;
            $tot       = cotizacionCalcular($itemsPor[(int)$r['id']] ?? [], (float)($r['descuento_global'] ?? 0), $aplicaIva, $pctFila);
            $neto      = $tot['neto'];
            $iva       = $tot['iva'];

            $data[] = [
                'id'              => (int)$r['id'],
                'numero'          => $limpiar($r['numero']),
                'fecha'           => $r['fecha'],
                'fecha_validez'   => $r['fecha_validez'],
                'estado'          => $limpiar($r['estado']),
                'proyecto_id'     => !empty($r['proyecto_id']) ? (int)$r['proyecto_id'] : 0,
                'cliente_nombre'  => $cliente_display,
                'proyecto_nombre' => $limpiar($r['proyecto_nom'] ?? 'Sin proyecto'),
                'aplica_iva'      => $aplicaIva ? 1 : 0,
                'iva_pct'         => $pctFila,
                'neto'            => $neto,
                'iva'             => $iva,
                // 'total' = lo que el cliente paga (con IVA si aplica).
                'total'           => $neto + $iva,
                'cliente_id'      => (int)$r['cliente_id'],
                'cliente_email'   => $r['cliente_email'],
                // Seguimiento del link al cliente (cotización en línea)
                'tiene_link'      => (int)$r['tiene_link'] === 1,
                'enviada_at'      => $r['enviada_at'],
                'vista_at'        => $r['vista_at'],
                'vistas'          => (int)$r['vistas'],
                'aprobada_at'     => $r['aprobada_at'],
                'aprobada_nombre' => $r['aprobada_nombre'],
                'respuesta'       => $r['respuesta_at'] ? ['tipo' => $r['respuesta_tipo'], 'texto' => $r['respuesta_texto'], 'at' => $r['respuesta_at']] : null,
                'deleted_at'      => $r['deleted_at']
            ];
        }

        // 4. Respuesta con el objeto pagination (Exactamente como lo espera tu React)
        echo json_encode([
            'ok' => true, 
            'data' => $data,
            'conteo' => $conteo,
            'pagination' => [
                'total' => (int)$totalItems,
                'page' => (int)$page,
                'per_page' => (int)$per_page,
                'total_pages' => (int)$totalPages
            ]
        ]);
        exit;
    }

    // --- RESTAURAR COTIZACIÓN ---
    // DEBE IR ANTES de POST/PUT para que no se confunda
    if ($method === 'POST' && $esRestore && $id) {
        $stmt = $db->prepare("UPDATE cotizaciones SET deleted_at = NULL WHERE id = ? AND deleted_at IS NOT NULL");
        $stmt->execute([$id]);
        
        if ($stmt->rowCount() > 0) {
            audit($db, $user['id'], 'RESTORE', 'cotizaciones', "Restauró cotización #{$id}", $id);
            echo json_encode(['ok' => true, 'message' => 'Cotización restaurada']);
        } else {
            echo json_encode(['ok' => false, 'error' => 'Cotización no encontrada o no estaba eliminada']);
        }
        exit;
    }

    // --- PUT ?link=1: link privado para el cliente (cotización en línea) ---
    //     &enviar=1: además lo manda por correo y la deja en 'Enviada'.
    if ($method === 'PUT' && $id && isset($_GET['link'])) {
        $st = $db->prepare("SELECT c.*, COALESCE(NULLIF(cl.nombre, ''), cl.razon_social) AS cliente_nombre, cl.email AS cliente_email
                            FROM cotizaciones c LEFT JOIN clientes cl ON cl.id = c.cliente_id
                            WHERE c.id = ? AND c.deleted_at IS NULL");
        $st->execute([$id]);
        $cot = $st->fetch(PDO::FETCH_ASSOC);
        if (!$cot) err('Cotización no encontrada', 404);
        $tok = $cot['token'];
        if (!$tok) {
            $tok = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='); // 43 caracteres
            $db->prepare("UPDATE cotizaciones SET token = ? WHERE id = ?")->execute([$tok, $id]);
            audit($db, $user['id'], 'UPDATE', 'cotizaciones', "Generó el link para el cliente de la cotización {$cot['numero']}", $id);
        }
        $b = body();
        // base = origen + ruta del SPA tal como la manda el navegador (con su «/» final).
        $base = trim((string)($b['base_url'] ?? ''));
        $link = ($base !== '' ? $base : '') . '#/cotizacion/' . $tok;

        if (!empty($_GET['enviar'])) {
            require_once __DIR__ . '/../mailer.php';
            if ($base === '') err('Falta la URL base', 422);
            // Varios correos separados por coma (como en sistema-local).
            $dest = array_values(array_filter(array_map('trim', explode(',', (string)($b['email'] ?? $cot['cliente_email'] ?? '')))));
            $dest = array_values(array_filter($dest, fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));
            if (!$dest) err('El cliente no tiene un correo válido. Escríbelo o agrégalo en su ficha.', 422);
            $nombreEmp = (string)($db->query("SELECT valor FROM configuracion WHERE clave='nombre_empresa'")->fetchColumn() ?: '');
            $h = fn($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
            $html = correoPlantilla(
                'Cotización ' . $cot['numero'],
                '<p>Hola,</p><p>Te enviamos la cotización <strong>' . $h($cot['numero']) . '</strong>'
                . ($nombreEmp !== '' ? ' de ' . $h($nombreEmp) : '') . '. En el enlace puedes revisarla, descargarla en PDF y aprobarla en línea'
                . (!empty($cot['fecha_validez']) ? ' (válida hasta el ' . date('d-m-Y', strtotime($cot['fecha_validez'])) . ')' : '') . '.</p>',
                'Ver cotización', $link
            );
            $errores = [];
            foreach ($dest as $e) {
                [$okMail, $error] = enviarCorreo($db, $e, 'Cotización ' . $cot['numero'] . ($nombreEmp !== '' ? ' · ' . $nombreEmp : ''), $html, $cot['cliente_nombre'] ?? null);
                if (!$okMail) $errores[] = "$e: $error";
            }
            if (count($errores) === count($dest)) err('No se pudo enviar: ' . implode('; ', $errores), 502);
            $db->prepare("UPDATE cotizaciones SET enviada_at = NOW(), estado = IF(estado = 'Pendiente', 'Enviada', estado) WHERE id = ?")->execute([$id]);
            // Guardar el correo en la ficha si el cliente no tenía.
            if (empty($cot['cliente_email']) && !empty($cot['cliente_id'])) {
                $db->prepare("UPDATE clientes SET email = ? WHERE id = ? AND (email IS NULL OR email = '')")->execute([$dest[0], (int)$cot['cliente_id']]);
            }
            audit($db, $user['id'], 'UPDATE', 'cotizaciones', "Envió la cotización {$cot['numero']} a " . implode(', ', $dest), $id);
            ok(['token' => $tok, 'link' => $link, 'enviado_a' => $dest, 'errores' => $errores]);
        }
        ok(['token' => $tok, 'link' => $link]);
    }

   // --- POST (CREAR) O PUT (EDITAR) ---
    if ($method === 'POST' || ($method === 'PUT' && $id)) {
        $b = body();

        // PUT parcial: los campos que NO vienen conservan su valor actual, y
        // los ítems solo se reemplazan si la petición los trae. Antes, cambiar
        // el estado desde la insignia de la lista (que manda solo la fila del
        // listado) fallaba por falta de cliente_id y, de haber pasado, borraba
        // los ítems y reseteaba notas/descuento/plantilla. (Portado de sistema-local.)
        $itemsVienen = array_key_exists('items', $b);
        if ($method === 'PUT') {
            $cur = $db->prepare("SELECT * FROM cotizaciones WHERE id = ? AND deleted_at IS NULL");
            $cur->execute([$id]);
            $actual = $cur->fetch(PDO::FETCH_ASSOC);
            if (!$actual) err('Cotización no encontrada', 404);
            foreach (['numero', 'proyecto_id', 'cliente_id', 'fecha', 'fecha_validez', 'estado', 'notas',
                      'descuento_global', 'descuento_global_tipo', 'plantilla_id'] as $k) {
                if (!array_key_exists($k, $b) && array_key_exists($k, $actual)) $b[$k] = $actual[$k];
            }
            // Aprobada en línea por el cliente: el contenido quedó sellado
            // (hash). Se puede cambiar el estado o el proyecto, no los montos.
            if (!empty($actual['aprobada_at'])) {
                $cambiaContenido = $itemsVienen
                    || (float)$b['descuento_global'] !== (float)$actual['descuento_global']
                    || (string)($b['notas'] ?? '') !== (string)($actual['notas'] ?? '')
                    || (int)($b['plantilla_id'] ?? 0) !== (int)($actual['plantilla_id'] ?? 0)
                    || (int)$b['cliente_id'] !== (int)$actual['cliente_id']
                    || (array_key_exists('aplica_iva', $b) && (int)!empty($b['aplica_iva']) !== (int)$actual['aplica_iva']);
                if ($cambiaContenido) err('El cliente ya aprobó esta cotización: sus ítems, montos, IVA y términos no se pueden cambiar. Crea una cotización nueva o duplícala.', 409);
            }
        }

        if (empty($b['cliente_id'])) err('Debes seleccionar un Cliente.', 400);
        $esFecha = fn($f) => is_string($f) && preg_match('/^\d{4}-\d{2}-\d{2}/', $f);
        if (!$esFecha($b['fecha'] ?? null)) $b['fecha'] = date('Y-m-d');
        if (!empty($b['fecha_validez']) && !$esFecha($b['fecha_validez'])) err('La fecha de validez no es válida.', 422);
        if (!empty($b['numero'])) {
            $dup = $db->prepare("SELECT 1 FROM cotizaciones WHERE numero = ? AND id <> ?");
            $dup->execute([trim((string)$b['numero']), (int)($id ?? 0)]);
            if ($dup->fetchColumn()) err('Ya existe una cotización con el número ' . trim((string)$b['numero']) . '.', 409);
        }

        $ESTADOS = ['Pendiente', 'Enviada', 'Aceptada', 'Rechazada', 'Expirada'];
        $estado = in_array($b['estado'] ?? '', $ESTADOS, true) ? $b['estado'] : 'Pendiente';
        $dctoTipo = (($b['descuento_global_tipo'] ?? '') === 'fijo') ? 'fijo' : 'porcentaje';

        $db->beginTransaction();

        if ($method === 'POST') {
            if (empty($b['numero'])) {
                $b['numero'] = nextNumero($db);
                // Avanzar el contador al número ACTUAL + 1 (no a +1 del valor
                // anterior). Así si el contador estaba atrasado, queda
                // sincronizado con la realidad y no vuelve a generar duplicados.
                $usado = (int)substr($b['numero'], strrpos($b['numero'], '-') + 1);
                $db->prepare("INSERT INTO configuracion (clave, valor) VALUES ('cotizacion_siguiente', ?) ON DUPLICATE KEY UPDATE valor = ?")
                   ->execute([$usado + 1, $usado + 1]);
            }

            $db->prepare("INSERT INTO cotizaciones (numero, proyecto_id, cliente_id, fecha, fecha_validez, estado, notas, descuento_global, descuento_global_tipo, plantilla_id)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
               ->execute([
                    $limpiar($b['numero']),
                    !empty($b['proyecto_id']) ? (int)$b['proyecto_id'] : null,
                    (int)$b['cliente_id'],
                    $b['fecha'],
                    !empty($b['fecha_validez']) ? $b['fecha_validez'] : null,
                    $estado,
                    $limpiar($b['notas'] ?? null),
                    (float)($b['descuento_global'] ?? 0),
                    $dctoTipo,
                    !empty($b['plantilla_id']) ? (int)$b['plantilla_id'] : null,
               ]);
            $cot_id = (int)$db->lastInsertId();
        } else {
            $db->prepare("UPDATE cotizaciones SET
                            numero=?, proyecto_id=?, cliente_id=?, fecha=?, fecha_validez=?,
                            estado=?, notas=?, descuento_global=?, descuento_global_tipo=?,
                            plantilla_id=?, updated_at=NOW()
                          WHERE id=? AND deleted_at IS NULL")
               ->execute([
                    $b['numero'],
                    !empty($b['proyecto_id']) ? (int)$b['proyecto_id'] : null,
                    (int)$b['cliente_id'],
                    $b['fecha'],
                    !empty($b['fecha_validez']) ? $b['fecha_validez'] : null,
                    $estado,
                    ($b['notas'] ?? '') !== '' ? $b['notas'] : null,
                    (float)($b['descuento_global'] ?? 0),
                    $dctoTipo,
                    !empty($b['plantilla_id']) ? (int)$b['plantilla_id'] : null,
                    $id,
               ]);
            $cot_id = $id;

            // Reemplazar ítems SOLO si la petición los trae (edición completa
            // desde el formulario). Un cambio de estado no los toca.
            if ($itemsVienen) {
                $db->prepare("DELETE FROM cotizacion_items WHERE cotizacion_id = ?")->execute([$cot_id]);
            }
        }

        // Afecta a IVA (default sí: una agencia cotiza con factura). Se setea
        // aparte para no tocar las ramas condicionales del INSERT/UPDATE.
        if (array_key_exists('aplica_iva', $b)) {
            $db->prepare("UPDATE cotizaciones SET aplica_iva = ? WHERE id = ?")
               ->execute([$b['aplica_iva'] ? 1 : 0, $cot_id]);
        }

        // --- INSERTAR ÍTEMS (Ahora $cot_id siempre tiene valor) ---
        if (($method === 'POST' || $itemsVienen) && !empty($b['items']) && is_array($b['items'])) {
            $si = $db->prepare("INSERT INTO cotizacion_items (cotizacion_id, servicio_id, descripcion, cantidad, unidad, precio_unitario, orden) VALUES (?, ?, ?, ?, ?, ?, ?)");
            foreach ($b['items'] as $k => $it) {
                $si->execute([
                    $cot_id, 
                    !empty($it['servicio_id']) ? (int)$it['servicio_id'] : null,
                    $limpiar($it['descripcion']), 
                    (float)($it['cantidad'] ?? 1), 
                    $limpiar($it['unidad'] ?? 'Servicio'), 
                    (float)($it['precio_unitario'] ?? 0), 
                    $k
                ]);
            }
        }

        $db->commit();
        
        // Auditoría
        $accion = ($method === 'POST') ? 'CREATE' : 'UPDATE';
        $detalle = ($method === 'POST') ? "Creó cotización #{$b['numero']}" : "Editó cotización ID {$id}";
        audit($db, $user['id'], $accion, 'cotizaciones', $detalle, $cot_id);
        
        echo json_encode(['ok' => true, 'id' => $cot_id]);
        exit;
    }

    // --- DELETE ---
    if ($method === 'DELETE' && $id) {
        $info = $db->prepare("SELECT numero FROM cotizaciones WHERE id = ?");
        $info->execute([$id]);
        $cot = $info->fetch();
        
        if (!$cot) {
            echo json_encode(['ok' => false, 'error' => 'Cotización no encontrada']);
            exit;
        }
        
        if ($esHard) {
            $db->prepare("DELETE FROM cotizaciones WHERE id = ?")->execute([$id]);
            audit($db, $user['id'], 'HARD_DELETE', 'cotizaciones', "Eliminó PERMANENTEMENTE cotización #{$cot['numero']}", $id);
            echo json_encode(['ok' => true, 'message' => 'Cotización eliminada permanentemente']);
        } else {
            $db->prepare("UPDATE cotizaciones SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL")->execute([$id]);
            audit($db, $user['id'], 'SOFT_DELETE', 'cotizaciones', "Envió a papelera cotización #{$cot['numero']}", $id);
            echo json_encode(['ok' => true, 'message' => 'Cotización enviada a papelera']);
        }
        exit;
    }

} catch (Throwable $e) {
    // Defensivo: si algo dentro de errSafe falla (poco probable) igual
    // devolvemos JSON con el detalle del error real, para no terminar con
    // body vacío y "Respuesta inválida" en el frontend.
    try { if (isset($db) && $db->inTransaction()) $db->rollBack(); } catch (Throwable $_) { /* ignorar */ }
    try {
        if (function_exists('logError')) {
            logError('Error en cotizaciones.php', ['message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine(), 'trace' => $e->getTraceAsString()]);
        } else {
            error_log('cotizaciones.php: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        }
    } catch (Throwable $_) { /* ignorar */ }
    while (ob_get_level() > 0) @ob_end_clean();
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    // El detalle (mensaje SQL, archivo, línea) queda en el log; al navegador
    // solo un mensaje genérico, salvo en modo debug.
    echo json_encode([
        'ok'    => false,
        'error' => APP_DEBUG ? $e->getMessage() : 'Error al procesar la cotización',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}