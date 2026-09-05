<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

try {
    require_once __DIR__ . '/../config.php';
    cors();
    $db = getDB();
    $user = requireAuth();
    $method = $_SERVER['REQUEST_METHOD'];
    // Soporte para method override via query string (POST con _method=PUT)
    if ($method === 'POST' && isset($_GET['_method'])) {
        $method = strtoupper($_GET['_method']);
    }
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

    // Permisos: el trabajador (no admin) solo puede LEER (GET), MOVER etapas
    // en el tablero y MARCAR ENTREGA (acciones de flujo del trabajo).
    // Crear/editar/borrar/restaurar proyectos es solo admin.
    $esMoverEtapa = ($method === 'PUT' && isset($_GET['mover_etapa']));
    $esEntregar   = ($method === 'PUT' && isset($_GET['entregar']));
    if ($method !== 'GET' && !$esMoverEtapa && !$esEntregar && $user['rol'] !== 'admin') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Acceso denegado']);
        exit;
    }

    // Helper para limpiar strings
    $clean = function($str) { return trim((string)($str ?? '')); };

    // --- GET: LISTAR ---
    // Soporta paginación: ?page=X&per_page=Y
    if ($method === 'GET') {
        $where = $esPapelera ? "WHERE p.deleted_at IS NOT NULL" : "WHERE p.deleted_at IS NULL";

        // Búsqueda server-side
        $q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
        $params = [];
        if ($q !== '') {
            $where .= " AND (p.nombre LIKE ? OR p.tipo_proyecto LIKE ? OR c.nombre LIKE ? OR c.razon_social LIKE ?)";
            $like = '%' . escapeLike($q) . '%';
            $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
        }

        // Filtro por estado (server-side, para que aplique a todo el dataset)
        $estado = isset($_GET['estado']) ? trim((string)$_GET['estado']) : '';
        if ($estado !== '' && $estado !== 'Todos') {
            $where .= " AND p.estado = ?";
            $params[] = $estado;
        }

        // Seguridad/visibilidad: si NO es admin y la config "tablero_solo_mis"
        // está activa, solo ve proyectos donde es miembro asignado.
        if ($user['rol'] !== 'admin') {
            $soloMis = $db->query("SELECT valor FROM configuracion WHERE clave='tablero_solo_mis'")->fetchColumn();
            if ($soloMis === '1') {
                $where .= " AND EXISTS (SELECT 1 FROM proyecto_miembros pm WHERE pm.proyecto_id = p.id AND pm.usuario_id = ?)";
                $params[] = (int)$user['id'];
            }
        }

        // Parámetros de paginación
        $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : null;
        $per_page = isset($_GET['per_page']) ? max(1, min(100, (int)$_GET['per_page'])) : null;

        // Contar total (necesita JOIN si filtramos por cliente)
        $countSql = "SELECT COUNT(*) FROM proyectos p LEFT JOIN clientes c ON p.cliente_id = c.id {$where}";
        $countStmt = $db->prepare($countSql);
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $sql = "SELECT p.*, c.nombre as cliente_nombre_real,
                       s.nombre AS sitio_nombre, s.url_principal AS sitio_url
                FROM proyectos p
                LEFT JOIN clientes c ON p.cliente_id = c.id
                LEFT JOIN sitios s ON p.sitio_id = s.id
                {$where}
                ORDER BY p.id DESC";

        // Aplicar paginación
        if ($page && $per_page) {
            $offset = ($page - 1) * $per_page;
            $sql .= " LIMIT {$per_page} OFFSET {$offset}";
        }

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Formatear para el frontend
        foreach ($data as &$r) {
            $r['id'] = (int)$r['id'];
            $r['cliente_id'] = (int)$r['cliente_id'];
            $r['sitio_id'] = $r['sitio_id'] ? (int)$r['sitio_id'] : null;
            $r['servicio_id'] = $r['servicio_id'] ? (int)$r['servicio_id'] : null;
            $r['etapa_id'] = !empty($r['etapa_id']) ? (int)$r['etapa_id'] : null;
            $r['briefing_tipo_id'] = !empty($r['briefing_tipo_id']) ? (int)$r['briefing_tipo_id'] : null;
            $r['fecha_entrega_real'] = $r['fecha_entrega_real'] ?? null;
            $r['presupuesto'] = (int)$r['presupuesto'];
            $r['cliente_nombre'] = $r['cliente_nombre_real'];
        }
        unset($r);

        // Cargar TODOS los servicios asociados (pivote) en una sola query
        // para evitar N+1. Si un proyecto no tiene filas en el pivote pero sí
        // tiene servicio_id legacy, lo respaldamos.
        $ids = array_map(fn($x) => (int)$x['id'], $data);
        $svcByProy = [];
        if (!empty($ids)) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $st = $db->prepare("SELECT proyecto_id, servicio_id FROM proyecto_servicios WHERE proyecto_id IN ($ph) ORDER BY orden ASC, id ASC");
            $st->execute($ids);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $svcByProy[(int)$row['proyecto_id']][] = (int)$row['servicio_id'];
            }
        }
        foreach ($data as &$r) {
            $lista = $svcByProy[$r['id']] ?? [];
            if (empty($lista) && !empty($r['servicio_id'])) $lista = [(int)$r['servicio_id']];
            $r['servicios_ids'] = $lista;
        }
        unset($r);

        // Etiquetas por proyecto (many-to-many) — una sola query, sin N+1.
        $etqByProy = [];
        if (!empty($ids)) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $st = $db->prepare("SELECT pe.proyecto_id, e.id, e.nombre, e.color
                                FROM proyecto_etiquetas pe
                                JOIN etiquetas e ON e.id = pe.etiqueta_id
                                WHERE pe.proyecto_id IN ($ph)
                                ORDER BY e.orden ASC, e.id ASC");
            $st->execute($ids);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $etqByProy[(int)$row['proyecto_id']][] = [
                    'id' => (int)$row['id'], 'nombre' => $row['nombre'], 'color' => $row['color'],
                ];
            }
        }
        foreach ($data as &$r) {
            $r['etiquetas'] = $etqByProy[$r['id']] ?? [];
            $r['etiquetas_ids'] = array_map(fn($e) => $e['id'], $r['etiquetas']);
        }
        unset($r);

        // Miembros (trabajadores) por proyecto — una sola query.
        $memByProy = [];
        if (!empty($ids)) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $st = $db->prepare("SELECT pm.proyecto_id, u.id, u.nombre, u.rol
                                FROM proyecto_miembros pm
                                JOIN usuarios u ON u.id = pm.usuario_id
                                WHERE pm.proyecto_id IN ($ph)
                                ORDER BY u.nombre ASC");
            $st->execute($ids);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $memByProy[(int)$row['proyecto_id']][] = [
                    'id' => (int)$row['id'], 'nombre' => $row['nombre'], 'rol' => $row['rol'],
                ];
            }
        }
        foreach ($data as &$r) {
            $r['miembros'] = $memByProy[$r['id']] ?? [];
            $r['miembros_ids'] = array_map(fn($m) => $m['id'], $r['miembros']);
        }
        unset($r);

        // Resumen de dinero por proyecto, traído de Pagos (fuente única de la
        // plata). Dos consultas agrupadas por proyecto_id → sin N+1.
        //   cobrado    = suma de abonos de los pagos del proyecto
        //   por_cobrar = saldos pendientes (Pendiente + saldo de Parcial)
        // Lo usa el desplegable de Pagos para ocultar proyectos ya pagados.
        $cobradoByProy = [];
        $porCobrarByProy = [];
        if (!empty($ids)) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $stC = $db->prepare("
                SELECT pg.proyecto_id, COALESCE(SUM(pa.monto), 0) AS cobrado
                FROM pagos pg
                INNER JOIN pago_cuotas pc ON pc.pago_id = pg.id
                INNER JOIN pago_abonos pa ON pa.cuota_id = pc.id
                WHERE pg.deleted_at IS NULL AND pg.proyecto_id IN ($ph)
                GROUP BY pg.proyecto_id
            ");
            $stC->execute($ids);
            foreach ($stC->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $cobradoByProy[(int)$row['proyecto_id']] = (int)$row['cobrado'];
            }
            $stP = $db->prepare("
                SELECT pg.proyecto_id, COALESCE(SUM(
                    CASE WHEN pc.estado = 'Parcial'   THEN GREATEST(pc.boleta_monto - pc.pago_monto, 0)
                         WHEN pc.estado = 'Pendiente' THEN pc.boleta_monto
                         ELSE 0 END), 0) AS por_cobrar
                FROM pagos pg
                INNER JOIN pago_cuotas pc ON pc.pago_id = pg.id
                WHERE pg.deleted_at IS NULL AND pg.proyecto_id IN ($ph)
                GROUP BY pg.proyecto_id
            ");
            $stP->execute($ids);
            foreach ($stP->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $porCobrarByProy[(int)$row['proyecto_id']] = (int)$row['por_cobrar'];
            }
        }
        foreach ($data as &$r) {
            $r['cobrado']    = $cobradoByProy[$r['id']]   ?? 0;
            $r['por_cobrar'] = $porCobrarByProy[$r['id']] ?? 0;
        }
        unset($r);

        // Estado de briefing por proyecto: sin / pendiente / completo — batch.
        // 'completo' = todas las preguntas activas del tipo tienen respuesta.
        foreach ($data as &$r) { $r['briefing_estado'] = 'sin'; }
        unset($r);
        if (!empty($ids)) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $st = $db->prepare("
                SELECT p.id AS pid,
                  (SELECT COUNT(*) FROM briefing_preguntas bp WHERE bp.tipo_id = p.briefing_tipo_id AND bp.activa = 1) AS total,
                  (SELECT COUNT(*) FROM briefing_respuestas br
                     JOIN briefing_preguntas bp2 ON bp2.id = br.pregunta_id AND bp2.activa = 1
                     WHERE br.proyecto_id = p.id AND (COALESCE(br.valor,'') <> '' OR br.archivo_url IS NOT NULL)) AS resp
                FROM proyectos p
                WHERE p.id IN ($ph) AND p.briefing_tipo_id IS NOT NULL");
            $st->execute($ids);
            $brf = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $total = (int)$row['total']; $resp = (int)$row['resp'];
                if ($total === 0) continue; // tipo sin preguntas → 'sin'
                $brf[(int)$row['pid']] = ($resp >= $total) ? 'completo' : 'pendiente';
            }
            foreach ($data as &$r) { if (isset($brf[$r['id']])) $r['briefing_estado'] = $brf[$r['id']]; }
            unset($r);
        }

        // Estado de contrato por proyecto: sin / pendiente / firmado — batch.
        foreach ($data as &$r) { $r['contrato_estado'] = 'sin'; }
        unset($r);
        if (!empty($ids)) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $st = $db->prepare("SELECT proyecto_id, firmado_at, requiere_firma FROM proyecto_contratos WHERE proyecto_id IN ($ph)");
            $st->execute($ids);
            $ctr = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (!empty($row['firmado_at']))        $ctr[(int)$row['proyecto_id']] = 'firmado';
                elseif ((int)$row['requiere_firma'] === 1) $ctr[(int)$row['proyecto_id']] = 'pendiente';
            }
            foreach ($data as &$r) { if (isset($ctr[$r['id']])) $r['contrato_estado'] = $ctr[$r['id']]; }
            unset($r);
        }

        // Responder con o sin paginación
        if ($page && $per_page) {
            echo json_encode([
                'ok' => true, 
                'data' => $data,
                'pagination' => [
                    'page' => $page,
                    'per_page' => $per_page,
                    'total' => $total,
                    'total_pages' => (int)ceil($total / $per_page)
                ]
            ]);
        } else {
            echo json_encode(['ok' => true, 'data' => $data]);
        }
        exit;
    }

    // --- RESTAURAR PROYECTO ---
    // DEBE IR ANTES de POST/CREAR para que no se confunda
    if ($method === 'POST' && $esRestore && $id) {
        $stmt = $db->prepare("UPDATE proyectos SET deleted_at = NULL WHERE id = ? AND deleted_at IS NOT NULL");
        $stmt->execute([$id]);
        
        if ($stmt->rowCount() > 0) {
            audit($db, $user['id'], 'RESTORE', 'proyectos', "Restauró proyecto #{$id}", $id);
            echo json_encode(['ok' => true, 'message' => 'Proyecto restaurado']);
        } else {
            echo json_encode(['ok' => false, 'error' => 'Proyecto no encontrado o no estaba eliminado']);
        }
        exit;
    }

    // Helper: sincroniza la tabla pivote con la lista de servicios elegida.
    // Borra los existentes y vuelve a insertarlos en el orden recibido.
    // También actualiza proyectos.servicio_id legacy con el primero.
    $syncServicios = function(int $proyecto_id, $servicios_ids) use ($db) {
        $db->prepare("DELETE FROM proyecto_servicios WHERE proyecto_id = ?")->execute([$proyecto_id]);
        $ids = array_values(array_unique(array_filter(array_map('intval', (array)$servicios_ids), fn($x) => $x > 0)));
        if (!empty($ids)) {
            $ins = $db->prepare("INSERT INTO proyecto_servicios (proyecto_id, servicio_id, orden) VALUES (?, ?, ?)");
            foreach ($ids as $orden => $sid) $ins->execute([$proyecto_id, $sid, $orden]);
        }
        $primero = $ids[0] ?? null;
        $db->prepare("UPDATE proyectos SET servicio_id = ? WHERE id = ?")->execute([$primero, $proyecto_id]);
    };

    // Sincroniza las etiquetas (disciplinas) del proyecto: borra y reinserta.
    $syncEtiquetas = function(int $proyecto_id, $etiquetas_ids) use ($db) {
        $db->prepare("DELETE FROM proyecto_etiquetas WHERE proyecto_id = ?")->execute([$proyecto_id]);
        $ids = array_values(array_unique(array_filter(array_map('intval', (array)$etiquetas_ids), fn($x) => $x > 0)));
        if (!empty($ids)) {
            $ins = $db->prepare("INSERT INTO proyecto_etiquetas (proyecto_id, etiqueta_id) VALUES (?, ?)");
            foreach ($ids as $eid) $ins->execute([$proyecto_id, $eid]);
        }
    };

    // Sincroniza los miembros (trabajadores) del proyecto: borra y reinserta.
    $syncMiembros = function(int $proyecto_id, $miembros_ids) use ($db) {
        $db->prepare("DELETE FROM proyecto_miembros WHERE proyecto_id = ?")->execute([$proyecto_id]);
        $ids = array_values(array_unique(array_filter(array_map('intval', (array)$miembros_ids), fn($x) => $x > 0)));
        if (!empty($ids)) {
            $ins = $db->prepare("INSERT INTO proyecto_miembros (proyecto_id, usuario_id) VALUES (?, ?)");
            foreach ($ids as $uid) $ins->execute([$proyecto_id, $uid]);
        }
    };

    // --- PUT mover_etapa: SOLO cambia la etapa (usado por el Tablero) ---
    // Aislado del PUT general para que editar/cambiar estado NO toque la etapa.
    if ($method === 'PUT' && $id && isset($_GET['mover_etapa'])) {
        $b = body();
        $etapa = !empty($b['etapa_id']) ? (int)$b['etapa_id'] : null;
        $db->prepare("UPDATE proyectos SET etapa_id = ?, updated_at = NOW() WHERE id = ? AND deleted_at IS NULL")
           ->execute([$etapa, (int)$id]);
        audit($db, $user['id'], 'UPDATE', 'proyectos', "Movió proyecto #{$id} a etapa #{$etapa}", $id);
        echo json_encode(['ok' => true]);
        exit;
    }

    // --- PUT entregar: marca (o desmarca) la ENTREGA del proyecto ---
    // Guarda la fecha de entrega real para medir cumplimiento del plazo.
    // Al entregar, cierra el proyecto (estado 'Terminado'); al deshacer, vuelve
    // a 'En curso'. Acción de flujo permitida a trabajadores.
    if ($method === 'PUT' && $id && isset($_GET['entregar'])) {
        $b = body();
        $entregar = !array_key_exists('entregar', $b) || !empty($b['entregar']); // default: true
        if ($entregar) {
            // Fecha opcional (permite registrar una entrega pasada); default hoy.
            $fecha = !empty($b['fecha']) ? $b['fecha'] : date('Y-m-d');
            $db->prepare("UPDATE proyectos SET fecha_entrega_real = ?, estado = 'Terminado', updated_at = NOW()
                          WHERE id = ? AND deleted_at IS NULL")->execute([$fecha, (int)$id]);
            audit($db, $user['id'], 'UPDATE', 'proyectos', "Marcó entregado el proyecto #{$id} ({$fecha})", $id);
        } else {
            $db->prepare("UPDATE proyectos SET fecha_entrega_real = NULL, estado = 'En curso', updated_at = NOW()
                          WHERE id = ? AND deleted_at IS NULL")->execute([(int)$id]);
            audit($db, $user['id'], 'UPDATE', 'proyectos', "Deshizo la entrega del proyecto #{$id}", $id);
        }
        echo json_encode(['ok' => true]);
        exit;
    }

    // --- PUT generar/obtener token del portal del cliente (magic link) ---
    // Solo admin (el guard de arriba ya lo asegura). Genera el token si no existe.
    if ($method === 'PUT' && $id && isset($_GET['portal_token'])) {
        $stmt = $db->prepare("SELECT portal_token FROM proyectos WHERE id = ? AND deleted_at IS NULL");
        $stmt->execute([(int)$id]);
        $tok = $stmt->fetchColumn();
        // Vencimiento a 90 días. Se renueva cada vez que el admin genera/comparte
        // el link, así "volver a compartir" reactiva un enlace vencido.
        $exp = date('Y-m-d H:i:s', strtotime('+90 days'));
        if (!$tok) {
            $tok = bin2hex(random_bytes(24)); // 48 chars
            $db->prepare("UPDATE proyectos SET portal_token = ?, portal_token_exp = ? WHERE id = ?")->execute([$tok, $exp, (int)$id]);
            audit($db, $user['id'], 'UPDATE', 'proyectos', "Generó link de portal para proyecto #{$id}", $id);
        } else {
            $db->prepare("UPDATE proyectos SET portal_token_exp = ? WHERE id = ?")->execute([$exp, (int)$id]);
        }

        // Envío por email al cliente: evita el copiar/pegar manual del link,
        // que era el cuello de botella del flujo del portal.
        if (!empty($_GET['enviar'])) {
            require_once __DIR__ . '/../mailer.php';
            $b = body();
            $base = rtrim((string)($b['base_url'] ?? ''), '/');
            if ($base === '') err('Falta la URL base del portal', 422);

            $info = $db->prepare("SELECT p.nombre AS proyecto, c.nombre AS cliente, c.email
                                  FROM proyectos p LEFT JOIN clientes c ON c.id = p.cliente_id
                                  WHERE p.id = ?");
            $info->execute([(int)$id]);
            $row = $info->fetch(PDO::FETCH_ASSOC) ?: [];
            $destino = trim((string)($b['email'] ?? $row['email'] ?? ''));
            if (!filter_var($destino, FILTER_VALIDATE_EMAIL)) {
                err('El cliente no tiene un email válido. Agrégalo en su ficha.', 422);
            }

            $link = $base . '#/portal/' . $tok;
            $proyNombre = htmlspecialchars((string)($row['proyecto'] ?? 'tu proyecto'), ENT_QUOTES, 'UTF-8');
            $html = correoPlantilla(
                'Seguimiento de tu proyecto',
                '<p>Hola,</p><p>Puedes seguir el avance de <strong>' . $proyNombre . '</strong> desde este enlace privado. '
                . 'Ahí verás las etapas, podrás dejar comentarios, subir archivos y aprobar lo que corresponda.</p>',
                'Ver mi proyecto', $link
            );
            [$ok, $error] = enviarCorreo($db, $destino, 'Seguimiento de tu proyecto · ' . strip_tags($proyNombre), $html, $row['cliente'] ?? null);
            if (!$ok) {
                echo json_encode(['ok' => false, 'error' => 'No se pudo enviar: ' . $error], JSON_UNESCAPED_UNICODE);
                exit;
            }
            audit($db, $user['id'], 'CREATE', 'proyectos', "Envió el link del portal del proyecto #{$id} a {$destino}", (int)$id);
            echo json_encode(['ok' => true, 'token' => $tok, 'enviado_a' => $destino], JSON_UNESCAPED_UNICODE);
            exit;
        }

        echo json_encode(['ok' => true, 'token' => $tok]);
        exit;
    }

    // --- POST: CREAR ---
    if ($method === 'POST') {
        $b = body();
        // Si llega lista de servicios, el campo legacy queda con el primero.
        $serviciosIds = $b['servicios_ids'] ?? (!empty($b['servicio_id']) ? [(int)$b['servicio_id']] : []);
        $primero = !empty($serviciosIds) ? (int)$serviciosIds[0] : null;

        // Etapa: la indicada o, por defecto, la primera del tablero (menor orden)
        // para que el proyecto aparezca en el kanban desde su creación.
        $etapa_id = !empty($b['etapa_id'])
            ? (int)$b['etapa_id']
            : ($db->query("SELECT id FROM etapas ORDER BY orden ASC, id ASC LIMIT 1")->fetchColumn() ?: null);

        $sql = "INSERT INTO proyectos (cliente_id, sitio_id, servicio_id, nombre, descripcion, tipo_proyecto, estado, etapa_id, fecha_inicio, fecha_termino, presupuesto, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
        $db->prepare($sql)->execute([
            (int)$b['cliente_id'],
            !empty($b['sitio_id']) ? (int)$b['sitio_id'] : null,
            $primero,
            $clean($b['nombre']),
            $clean($b['descripcion']),
            $clean($b['tipo_proyecto']),
            $clean($b['estado'] ?? 'Cotizado'),
            $etapa_id ?: null,
            !empty($b['fecha_inicio']) ? $b['fecha_inicio'] : null,
            !empty($b['fecha_termino']) ? $b['fecha_termino'] : null,
            (int)($b['presupuesto'] ?? 0)
        ]);
        $proyecto_id = (int)$db->lastInsertId();

        $syncServicios($proyecto_id, $serviciosIds);
        $syncEtiquetas($proyecto_id, $b['etiquetas_ids'] ?? []);
        $syncMiembros($proyecto_id, $b['miembros_ids'] ?? []);

        // Cobro pendiente automático: si el proyecto tiene estimado y se pidió
        // generar el cobro, creamos un pago PENDIENTE vinculado (monto a cobrar
        // = estimado, 1 cuota, sin abonos). Así el "por cobrar" aparece solo,
        // sin tener que crear un pago vacío a mano; al cobrar se registra el
        // abono en ese pago. tipo por defecto 'boleta' (editable al cobrar).
        $estimado = (int)($b['presupuesto'] ?? 0);
        $generarCobro = !isset($b['generar_cobro']) || !empty($b['generar_cobro']);
        if ($generarCobro && $estimado > 0) {
            $tipoCobro = ($b['tipo_cobro'] ?? 'boleta') === 'directo' ? 'directo' : 'boleta';
            $db->prepare("INSERT INTO pagos (cliente_id, proyecto_id, descripcion, monto_total, tipo_pago)
                          VALUES (?, ?, ?, ?, ?)")
               ->execute([(int)$b['cliente_id'], $proyecto_id, $clean($b['nombre']), $estimado, $tipoCobro]);
            $pago_id = (int)$db->lastInsertId();
            $db->prepare("INSERT INTO pago_cuotas (pago_id, numero_cuota, boleta_monto, pago_monto, estado, tipo_pago)
                          VALUES (?, 1, ?, 0, 'Pendiente', ?)")
               ->execute([$pago_id, $estimado, $tipoCobro]);
        }

        audit($db, $user['id'], 'CREATE', 'proyectos', "Creó proyecto #{$proyecto_id}: {$b['nombre']}", $proyecto_id);

        echo json_encode(['ok' => true, 'id' => $proyecto_id]);
        exit;
    }

    // --- EDITAR PROYECTO (PUT) ---
        if ($method === 'PUT' && $id) {
            $b = body();
            $serviciosIds = $b['servicios_ids'] ?? (!empty($b['servicio_id']) ? [(int)$b['servicio_id']] : []);
            $primero = !empty($serviciosIds) ? (int)$serviciosIds[0] : null;

            $sql = "UPDATE proyectos SET
                    cliente_id = ?,
                    sitio_id = ?,
                    servicio_id = ?,
                    nombre = ?,
                    descripcion = ?,
                    tipo_proyecto = ?,
                    estado = ?,
                    fecha_inicio = ?,
                    fecha_termino = ?,
                    presupuesto = ?,
                    updated_at = NOW()
                    WHERE id = ? AND deleted_at IS NULL";

            $stmt = $db->prepare($sql);

            $stmt->execute([
                (int)$b['cliente_id'],
                !empty($b['sitio_id']) ? (int)$b['sitio_id'] : null,
                $primero,
                $clean($b['nombre']),
                $clean($b['descripcion']),
                $clean($b['tipo_proyecto']),
                $clean($b['estado']),
                !empty($b['fecha_inicio']) ? $b['fecha_inicio'] : null,
                !empty($b['fecha_termino']) ? $b['fecha_termino'] : null,
                (int)($b['presupuesto'] ?? 0),
                (int)$id // El ID del proyecto para el WHERE
            ]);

            $syncServicios((int)$id, $serviciosIds);
            if (array_key_exists('etiquetas_ids', $b)) $syncEtiquetas((int)$id, $b['etiquetas_ids']);
            if (array_key_exists('miembros_ids', $b)) $syncMiembros((int)$id, $b['miembros_ids']);
            if (array_key_exists('briefing_tipo_id', $b)) {
                $bt = !empty($b['briefing_tipo_id']) ? (int)$b['briefing_tipo_id'] : null;
                $db->prepare("UPDATE proyectos SET briefing_tipo_id = ? WHERE id = ?")->execute([$bt, (int)$id]);
            }

            audit($db, $user['id'], 'UPDATE', 'proyectos', "Editó proyecto #{$id}: {$b['nombre']}", $id);

            echo json_encode(['ok' => true]);
            exit;
        }

    // --- DELETE ---
    if ($method === 'DELETE' && $id) {
        $info = $db->prepare("SELECT nombre FROM proyectos WHERE id = ?");
        $info->execute([$id]);
        $proy = $info->fetch();
        
        if (!$proy) {
            echo json_encode(['ok' => false, 'error' => 'Proyecto no encontrado']);
            exit;
        }
        
        if ($esHard) {
            $db->prepare("DELETE FROM proyectos WHERE id = ?")->execute([$id]);
            audit($db, $user['id'], 'HARD_DELETE', 'proyectos', "Eliminó PERMANENTEMENTE proyecto #{$id}: " . $proy['nombre'], $id);
            echo json_encode(['ok' => true, 'message' => 'Proyecto eliminado permanentemente']);
        } else {
            $db->prepare("UPDATE proyectos SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL")->execute([$id]);
            audit($db, $user['id'], 'SOFT_DELETE', 'proyectos', "Envió a papelera proyecto #{$id}: " . $proy['nombre'], $id);
            echo json_encode(['ok' => true, 'message' => 'Proyecto enviado a papelera']);
        }
        exit;
    }

} catch (Throwable $e) {
    if (function_exists('logError')) {
        logError('Error en proyectos.php', ['message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
    }
    errSafe($e, 500);
}