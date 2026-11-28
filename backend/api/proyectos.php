<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

try {
    require_once __DIR__ . '/../config.php';
    require_once __DIR__ . '/../finance.php';
    require_once __DIR__ . '/../lib/saldos.php';
    require_once __DIR__ . '/../lib/cobros.php';
    require_once __DIR__ . '/../lib/entregas.php';
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
    // La papelera y el borrado definitivo son del admin (el comercial envía
    // a la papelera y puede deshacer, nada más).
    if (($esPapelera || $esHard) && !esAdmin($user)) err('Acceso denegado', 403);

    // Permisos: el trabajador (no admin) solo puede LEER (GET), MOVER etapas
    // en el tablero y MARCAR ENTREGA (acciones de flujo del trabajo).
    // Crear/editar/borrar/restaurar proyectos es solo admin.
    $esMoverEtapa = ($method === 'PUT' && isset($_GET['mover_etapa']));
    $esEntregar   = ($method === 'PUT' && isset($_GET['entregar']));
    if ($method !== 'GET' && !$esMoverEtapa && !$esEntregar && !esGestor($user)) {
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

        // Conteo por pestaña (respeta la búsqueda, no el filtro de estado).
        $cntSt = $db->prepare("SELECT p.estado, COUNT(*) n FROM proyectos p LEFT JOIN clientes c ON p.cliente_id = c.id {$where} GROUP BY p.estado");
        $cntSt->execute($params);
        $conteo = ['Todos' => 0];
        foreach ($cntSt->fetchAll(PDO::FETCH_ASSOC) as $r) { $conteo[$r['estado']] = (int)$r['n']; $conteo['Todos'] += (int)$r['n']; }

        // Filtro por estado (server-side, para que aplique a todo el dataset)
        $estado = isset($_GET['estado']) ? trim((string)$_GET['estado']) : '';
        if ($estado !== '' && $estado !== 'Todos') {
            $where .= " AND p.estado = ?";
            $params[] = $estado;
        }

        if ($esPapelera && !esAdmin($user)) err('Acceso denegado', 403);
        // Seguridad/visibilidad: si NO es admin y la config "tablero_solo_mis"
        // está activa, solo ve proyectos donde es miembro asignado.
        if (!esGestor($user)) {
            $soloMis = $db->query("SELECT valor FROM configuracion WHERE clave='tablero_solo_mis'")->fetchColumn();
            if ($soloMis === '1') {
                $where .= " AND EXISTS (SELECT 1 FROM proyecto_miembros pm WHERE pm.proyecto_id = p.id AND pm.usuario_id = ?)";
                $params[] = (int)$user['id'];
            }
        }

        // Parámetros de paginación
        $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : null;
        $per_page = isset($_GET['per_page']) ? max(1, min(1000, (int)$_GET['per_page'])) : null;

        // Contar total (necesita JOIN si filtramos por cliente)
        $countSql = "SELECT COUNT(*) FROM proyectos p LEFT JOIN clientes c ON p.cliente_id = c.id {$where}";
        $countStmt = $db->prepare($countSql);
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $sql = "SELECT p.*, c.nombre as cliente_nombre_real, c.email AS cliente_email,
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
        $lineasByProy = [];
        $plazoActa = entPlazoDias($db);
        if (!empty($ids)) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            // Líneas: servicio del catálogo (con cantidad y precio propio
            // opcional) o línea libre (sin servicio_id, con nombre y precio).
            $st = $db->prepare("SELECT ps.proyecto_id, ps.servicio_id, ps.nombre, ps.cantidad, ps.precio_unitario,
                                       s.nombre AS servicio_nombre, s.precio AS precio_catalogo
                                FROM proyecto_servicios ps LEFT JOIN servicios s ON s.id = ps.servicio_id
                                WHERE ps.proyecto_id IN ($ph) ORDER BY ps.orden ASC, ps.id ASC");
            $st->execute($ids);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $pid = (int)$row['proyecto_id'];
                if ($row['servicio_id'] !== null) $svcByProy[$pid][] = (int)$row['servicio_id'];
                $lineasByProy[$pid][] = [
                    'servicio_id' => $row['servicio_id'] !== null ? (int)$row['servicio_id'] : null,
                    'nombre'      => $row['servicio_id'] !== null ? $row['servicio_nombre'] : $row['nombre'],
                    'cantidad'    => max(1, (int)$row['cantidad']),
                    // precio efectivo: el propio de la línea o, si no tiene, el del catálogo
                    'precio'      => (int)($row['precio_unitario'] ?? $row['precio_catalogo'] ?? 0),
                    'precio_propio' => $row['precio_unitario'] !== null,
                ];
            }
        }
        foreach ($data as &$r) {
            $lista = $svcByProy[$r['id']] ?? [];
            if (empty($lista) && !empty($r['servicio_id'])) $lista = [(int)$r['servicio_id']];
            $r['servicios_ids'] = $lista;
            $r['servicios'] = $lineasByProy[$r['id']] ?? [];   // [{servicio_id, nombre, cantidad, precio, precio_propio}]
            $r['acta'] = entResumen($r, $plazoActa);            // null si no está entregado
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

        // Avance por fases (tarjetas del Tablero y resumen de la ficha) — una sola query.
        $avance = [];
        if (!empty($ids)) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $st = $db->prepare("SELECT proyecto_id, COUNT(*) AS total, SUM(estado = 'Completada') AS listas,
                                       MAX(CASE WHEN estado = 'En revisión' THEN nombre END) AS en_revision,
                                       MIN(CASE WHEN estado = 'En revisión' THEN fecha_objetivo END) AS revision_fecha
                                FROM proyecto_fases WHERE proyecto_id IN ($ph) GROUP BY proyecto_id");
            $st->execute($ids);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) $avance[(int)$row['proyecto_id']] = $row;
        }
        foreach ($data as &$r) {
            $a = $avance[$r['id']] ?? null;
            $r['fases_total']   = (int)($a['total'] ?? 0);
            $r['fases_listas']  = (int)($a['listas'] ?? 0);
            $r['fase_en_revision'] = $a['en_revision'] ?? null;
            $r['fase_revision_fecha'] = $a['revision_fecha'] ?? null;
            $r['automatico'] = (int)($r['automatico'] ?? 0) === 1;
        }
        unset($r);

        // Resumen de dinero por proyecto desde la única fuente (lib/saldos.php).
        //   cobrado    = lo cobrado en los pagos del proyecto
        //   falta      = saldo total (emitido + por emitir); lo usa el
        //                desplegable de Pagos para ocultar proyectos ya pagados
        //   por_cobrar = solo lo emitido (lo que el cliente ya debe)
        // El trabajador no ve montos.
        $resProy = [];
        if (!empty($ids) && esAdmin($user)) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $resProy = saldoResumenPor(saldosCuotas($db, "pg.proyecto_id IN ($ph)", $ids), 'proyecto_id');
        }
        foreach ($data as &$r) {
            $rs = $resProy[$r['id']] ?? null;
            $r['cobrado']    = $rs['cobrado'] ?? 0;
            $r['por_cobrar'] = $rs['falta'] ?? 0;
            $r['cobro_emitido_pendiente'] = $rs['por_cobrar'] ?? 0;
            $r['cuotas_vencidas'] = $rs['vencidas'] ?? 0;
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
                // OJO: no llamar $total a esto: pisaba el total de la paginación
                // y la lista perdía sus páginas siguientes.
                $pregs = (int)$row['total']; $resp = (int)$row['resp'];
                if ($pregs === 0) continue; // tipo sin preguntas → 'sin'
                $brf[(int)$row['pid']] = ($resp >= $pregs) ? 'completo' : 'pendiente';
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

        // Lo cobrado y lo que falta es cobranza: solo admin (sin esto llegaría
        // en 0 y se leería como «sin saldo pendiente»).
        if (!esAdmin($user)) {
            foreach ($data as &$r) { unset($r['cobrado'], $r['por_cobrar'], $r['cobro_emitido_pendiente'], $r['cuotas_vencidas']); }
            unset($r);
        }

        // El trabajador no ve montos ni el link privado del cliente. (El
        // comercial sí: presupuesto y portal son parte de su trabajo; lo que
        // no recibe es el resumen de cobro de más arriba, solo del admin.)
        if (!esGestor($user)) {
            foreach ($data as &$r) {
                unset($r['presupuesto'], $r['portal_token'], $r['portal_token_exp'], $r['cliente_email'], $r['entrega_confirmada_ip']);
                // Descripciones antiguas generadas desde una cotización traen montos.
                if (isset($r['descripcion'])) $r['descripcion'] = preg_replace('/\s*[—-]\s*\$\s?[\d.]+/u', '', (string)$r['descripcion']);
                foreach ($r['servicios'] as &$ln) { unset($ln['precio'], $ln['precio_propio']); }
                unset($ln);
            }
            unset($r);
        }

        // Responder con o sin paginación
        if ($page && $per_page) {
            echo json_encode([
                'ok' => true, 
                'data' => $data,
                'conteo' => $conteo,
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

    // Sincroniza las líneas de servicio del proyecto. Acepta:
    //   - $lineas = [{servicio_id, nombre?, cantidad, precio?}, ...] (formulario nuevo)
    //   - o la lista vieja de ids [id, ...] (compatibilidad).
    //  Catálogo: servicio_id > 0; `precio` opcional sobrescribe el del catálogo
    //            (NULL = usar servicios.precio). Sin repetir servicio.
    //  Libre:    servicio_id vacío + nombre; su precio es obligatorio (0 si falta).
    // Actualiza proyectos.servicio_id (legacy) con el primero del catálogo.
    $syncServicios = function(int $proyecto_id, $lineas) use ($db, $clean) {
        $db->prepare("DELETE FROM proyecto_servicios WHERE proyecto_id = ?")->execute([$proyecto_id]);
        $rows = []; $vistos = [];
        foreach ((array)$lineas as $l) {
            if (is_array($l)) {
                $sid = (int)($l['servicio_id'] ?? 0);
                $cant = max(1, (int)($l['cantidad'] ?? 1));
                $tienePrecio = isset($l['precio']) && $l['precio'] !== '' && $l['precio'] !== null;
                $precio = $tienePrecio ? max(0, (int)$l['precio']) : null;
                $nombre = mb_substr($clean($l['nombre'] ?? ''), 0, 200);
            } else {
                $sid = (int)$l; $cant = 1; $precio = null; $nombre = '';
            }
            if ($sid > 0) {
                if (isset($vistos[$sid])) continue;
                $vistos[$sid] = true;
                $rows[] = [$sid, null, $cant, $precio];
            } elseif ($nombre !== '') {
                $rows[] = [null, $nombre, $cant, $precio ?? 0];
            }
        }
        if ($rows) {
            $ins = $db->prepare("INSERT INTO proyecto_servicios (proyecto_id, servicio_id, nombre, cantidad, precio_unitario, orden) VALUES (?, ?, ?, ?, ?, ?)");
            foreach ($rows as $orden => $r) $ins->execute([$proyecto_id, $r[0], $r[1], $r[2], $r[3], $orden]);
        }
        $primero = null;
        foreach ($rows as $r) { if ($r[0]) { $primero = $r[0]; break; } }
        $db->prepare("UPDATE proyectos SET servicio_id = ? WHERE id = ?")->execute([$primero, $proyecto_id]);
        return $primero;
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
        requireAccesoProyecto($db, $user, (int)$id);
        $b = body();
        $etapa = !empty($b['etapa_id']) ? (int)$b['etapa_id'] : null;
        if ($etapa !== null) {
            $ex = $db->prepare("SELECT 1 FROM etapas WHERE id = ?");
            $ex->execute([$etapa]);
            if (!$ex->fetchColumn()) err('La etapa no existe', 422);
        }
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
        requireAccesoProyecto($db, $user, (int)$id);
        $b = body();
        $entregar = !array_key_exists('entregar', $b) || !empty($b['entregar']); // default: true
        if ($entregar) {
            // Fecha opcional (permite registrar una entrega pasada); default hoy.
            $fecha = !empty($b['fecha']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$b['fecha']) ? $b['fecha'] : date('Y-m-d');
            // Garantía en meses (la indicada o la de Configuración). El acta
            // queda pendiente de respuesta del cliente en su portal.
            $meses = array_key_exists('garantia_meses', $b)
                ? max(0, min(60, (int)$b['garantia_meses']))
                : (int)($db->query("SELECT valor FROM configuracion WHERE clave='garantia_meses_default'")->fetchColumn() ?: 3);
            $db->prepare("UPDATE proyectos SET fecha_entrega_real = ?, garantia_meses = ?, estado = 'Terminado',
                                 entrega_confirmada_at = NULL, entrega_confirmada_nombre = NULL, entrega_confirmada_ip = NULL,
                                 entrega_por_plazo = 0, entrega_observacion = NULL, entrega_observacion_at = NULL,
                                 garantia_aviso_at = NULL, updated_at = NOW()
                          WHERE id = ? AND deleted_at IS NULL")->execute([$fecha, $meses ?: null, (int)$id]);
            audit($db, $user['id'], 'UPDATE', 'proyectos', "Marcó entregado el proyecto #{$id} ({$fecha})", $id);
        } else {
            $db->prepare("UPDATE proyectos SET fecha_entrega_real = NULL, estado = 'En curso',
                                 entrega_confirmada_at = NULL, entrega_confirmada_nombre = NULL, entrega_confirmada_ip = NULL,
                                 entrega_por_plazo = 0, entrega_observacion = NULL, entrega_observacion_at = NULL,
                                 garantia_aviso_at = NULL, updated_at = NOW()
                          WHERE id = ? AND deleted_at IS NULL")->execute([(int)$id]);
            audit($db, $user['id'], 'UPDATE', 'proyectos', "Deshizo la entrega del proyecto #{$id}", $id);
        }
        echo json_encode(['ok' => true]);
        exit;
    }

    // --- PUT generar/obtener token del portal del cliente (magic link) ---
    // Solo admin (el guard de arriba ya lo asegura). Genera el token si no existe.
    if ($method === 'PUT' && $id && isset($_GET['portal_token'])) {
        // Revocar: el link anterior deja de funcionar de inmediato. El próximo
        // «copiar» o «enviar» genera uno nuevo, distinto.
        if (!empty($_GET['revocar'])) {
            $up = $db->prepare("UPDATE proyectos SET portal_token = NULL, portal_token_exp = NULL
                                WHERE id = ? AND deleted_at IS NULL AND portal_token IS NOT NULL");
            $up->execute([(int)$id]);
            if ($up->rowCount() > 0) {
                audit($db, $user['id'], 'UPDATE', 'proyectos', "Revocó el link del portal del proyecto #{$id}", (int)$id);
            }
            ok(['revocado' => true]);
        }
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
            // base = origen + ruta del SPA tal como la manda el navegador (con su «/» final).
            $base = trim((string)($b['base_url'] ?? ''));
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
            if (!$ok) err('No se pudo enviar: ' . $error, 502);
            audit($db, $user['id'], 'CREATE', 'proyectos', "Envió el link del portal del proyecto #{$id} a {$destino}", (int)$id);
            ok(['token' => $tok, 'exp' => $exp, 'enviado_a' => $destino]);
        }

        // Dentro de `data`, como el resto de la API (antes iba suelto y el
        // frontend nunca encontraba el token: «No se pudo generar el link»).
        ok(['token' => $tok, 'exp' => $exp]);
    }

    // --- POST: CREAR ---
    if ($method === 'POST') {
        $b = body();
        // Si llega lista de servicios, el campo legacy queda con el primero.
        $lineas = $b['servicios'] ?? $b['servicios_ids'] ?? (!empty($b['servicio_id']) ? [(int)$b['servicio_id']] : []);
        $primero = null; // lo fija $syncServicios con el primer servicio del catálogo

        // Etapa: la indicada o, por defecto, la primera del tablero (menor orden)
        // para que el proyecto aparezca en el kanban desde su creación.
        $etapa_id = !empty($b['etapa_id'])
            ? (int)$b['etapa_id']
            : etapaInicial($db);

        if ($clean($b['nombre'] ?? '') === '') err('El proyecto necesita un nombre.', 422);
        $cx = $db->prepare("SELECT 1 FROM clientes WHERE id = ? AND deleted_at IS NULL");
        $cx->execute([(int)($b['cliente_id'] ?? 0)]);
        if (!$cx->fetchColumn()) err('Selecciona un cliente.', 422);
        $ESTADOS_PROY = ['Cotizado', 'En curso', 'Pausado', 'Terminado', 'Cancelado'];
        if (!in_array($b['estado'] ?? 'Cotizado', $ESTADOS_PROY, true)) $b['estado'] = 'Cotizado';
        $b['descripcion'] = $b['descripcion'] ?? '';
        $b['tipo_proyecto'] = $b['tipo_proyecto'] ?? '';
        $db->beginTransaction();

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

        $syncServicios($proyecto_id, $lineas);
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
            // Por defecto FACTURA (el estimado es el neto + IVA). lib/cobros.php
            crearCobroPendiente($db, (int)$b['cliente_id'], $proyecto_id, $clean($b['nombre']), $estimado, (string)($b['tipo_cobro'] ?? 'factura'));
        }

        $db->commit();
        audit($db, $user['id'], 'CREATE', 'proyectos', "Creó proyecto #{$proyecto_id}: {$b['nombre']}", $proyecto_id);

        echo json_encode(['ok' => true, 'id' => $proyecto_id]);
        exit;
    }

    // --- EDITAR PROYECTO (PUT) ---
        if ($method === 'PUT' && $id) {
            $b = body();
            // PUT parcial: lo que no viene conserva su valor (la insignia de
            // estado manda solo { estado }). Los servicios solo se re-sincronizan
            // si la petición los trae. (Portado de sistema-local cbc7559.)
            $cur = $db->prepare("SELECT * FROM proyectos WHERE id = ? AND deleted_at IS NULL");
            $cur->execute([(int)$id]);
            $actual = $cur->fetch(PDO::FETCH_ASSOC);
            if (!$actual) err('Proyecto no encontrado', 404);
            foreach (['cliente_id', 'sitio_id', 'nombre', 'descripcion', 'tipo_proyecto', 'estado',
                      'fecha_inicio', 'fecha_termino', 'presupuesto'] as $k) {
                if (!array_key_exists($k, $b)) $b[$k] = $actual[$k];
            }
            $serviciosVienen = array_key_exists('servicios', $b) || array_key_exists('servicios_ids', $b) || array_key_exists('servicio_id', $b);
            $lineas = $b['servicios'] ?? $b['servicios_ids'] ?? (!empty($b['servicio_id']) ? [(int)$b['servicio_id']] : []);
            $primero = $actual['servicio_id']; // si vienen servicios, $syncServicios lo actualiza

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

            // Si cambió el presupuesto y el proyecto tiene UN solo cobro intacto
            // (una cuota pendiente, sin documento ni abonos), se actualiza al
            // nuevo monto. Si ya se emitió o cobró algo, no se toca.
            $nuevoPres = (int)($b['presupuesto'] ?? 0);
            if ($nuevoPres > 0 && $nuevoPres !== (int)$actual['presupuesto']) {
                $pq = $db->prepare("SELECT pg.id, pc.id AS cuota_id, pc.tipo_pago FROM pagos pg JOIN pago_cuotas pc ON pc.pago_id = pg.id
                                    WHERE pg.proyecto_id = ? AND pg.deleted_at IS NULL");
                $pq->execute([(int)$id]);
                $cuotas = $pq->fetchAll(PDO::FETCH_ASSOC);
                if (count($cuotas) === 1) {
                    $ok1 = $db->prepare("SELECT 1 FROM pago_cuotas WHERE id = ? AND estado = 'Pendiente' AND COALESCE(pago_monto,0) = 0
                                         AND (boleta_num IS NULL OR boleta_num = '')");
                    $ok1->execute([$cuotas[0]['cuota_id']]);
                    if ($ok1->fetchColumn()) {
                        $doc = finance_calc_doc(['tipo_pago' => $cuotas[0]['tipo_pago'], 'neto' => $nuevoPres, 'boleta_monto' => $nuevoPres],
                                                (float)($db->query("SELECT valor FROM configuracion WHERE clave='iva_pct'")->fetchColumn() ?: 19));
                        $db->prepare("UPDATE pago_cuotas SET boleta_monto = ?, neto = ?, iva = ? WHERE id = ?")
                           ->execute([$doc['total'], $doc['neto'], $doc['iva'], $cuotas[0]['cuota_id']]);
                        $db->prepare("UPDATE pagos SET monto_total = ? WHERE id = ?")->execute([$doc['total'], $cuotas[0]['id']]);
                    }
                }
            }

            if ($serviciosVienen) $syncServicios((int)$id, $lineas);
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
    try { if (isset($db) && $db->inTransaction()) $db->rollBack(); } catch (Throwable $_) { /* nada */ }
    if (function_exists('logError')) {
        logError('Error en proyectos.php', ['message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
    }
    errSafe($e, 500);
}