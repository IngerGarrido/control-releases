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

    if ($user['rol'] !== 'admin') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Acceso denegado']);
        exit;
    }

    $id = isset($_GET['id']) ? (int)$_GET['id'] : null;
    
    if (!$id) {
        $path = explode('/', parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
        $last = end($path);
        $id = is_numeric($last) ? (int)$last : null;
    }

    $uri = $_SERVER['REQUEST_URI'] ?? '';
    $esRestore = strpos($uri, 'restore') !== false || isset($_GET['restore']);
    $esHard = isset($_GET['hard']) && $_GET['hard'] == '1';
    $esPapelera = isset($_GET['papelera']) && $_GET['papelera'] == '1';

    $limpiar = function($str) {
        return trim(str_replace("\0", "", (string)($str ?? '')));
    };

    // Tipo de cliente. Antes era Agencia|Directo (quedó sin uso: el sistema ES
    // la agencia) y ahora distingue persona jurídica de natural, que es lo que
    // cambia los datos que se piden. Cualquier valor viejo cae en 'Empresa'.
    if (!function_exists('tipoCliente')) {
        function tipoCliente($v): string {
            $v = ucfirst(strtolower(trim((string)$v)));
            return $v === 'Persona' ? 'Persona' : 'Empresa';
        }
    }

    // --- LISTAR CLIENTES (GET) ---
    // Si ?papelera=1, muestra solo eliminados
    // Por defecto, excluye eliminados (deleted_at IS NULL)
    // Soporta paginación: ?page=X&per_page=Y
    if ($method === 'GET') {
        $where = $esPapelera ? "WHERE deleted_at IS NOT NULL" : "WHERE deleted_at IS NULL";

        // Búsqueda server-side (mismo patrón que servicios.php)
        $q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
        $params = [];
        if ($q !== '') {
            // Se busca también por razón social y por el contacto: al llamar
            // por teléfono uno se acuerda del nombre de la persona, no del de
            // la empresa.
            $where .= " AND (nombre LIKE ? OR razon_social LIKE ? OR rut LIKE ? OR email LIKE ? OR contacto_nombre LIKE ?)";
            $like = '%' . escapeLike($q) . '%';
            $params = array_merge($params, array_fill(0, 5, $like));
        }

        $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : null;
        $per_page = isset($_GET['per_page']) ? max(1, min(100, (int)$_GET['per_page'])) : null;

        $countSql = "SELECT COUNT(*) FROM clientes {$where}";
        $countStmt = $db->prepare($countSql);
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();
        
        // Simplificamos el SQL quitando CAST innecesarios que pueden truncar o alterar el texto
        $sql = "SELECT
            id,
            tipo,
            nombre,
            razon_social,
            url,
            rut,
            giro,
            email,
            telefono,
            direccion,
            comuna,
            ciudad,
            contacto_nombre,
            contacto_cargo,
            notas,
            created_at,
            updated_at,
            deleted_at
            FROM clientes
            {$where}
            ORDER BY id DESC"; // Cambiado a ID normal para mejor rendimiento
        
        if ($page && $per_page) {
            $offset = ($page - 1) * $per_page;
            $sql .= " LIMIT {$per_page} OFFSET {$offset}";
        }

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $raw_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $data = [];
        foreach ($raw_rows as $row) {
            // El nombre nunca debe ser null: la lista mostraría el ID pelado.
            $data[] = [
                'id'                => (int)$row['id'],
                'tipo'              => tipoCliente($row['tipo'] ?? ''),
                'nombre'            => $limpiar($row['nombre']),
                'razon_social'      => $limpiar($row['razon_social']),
                'url'               => $limpiar($row['url']),
                'rut'               => $limpiar($row['rut']),
                'giro'              => $limpiar($row['giro']),
                'email'             => $limpiar($row['email']),
                'telefono'          => $limpiar($row['telefono']),
                'direccion'         => $limpiar($row['direccion']),
                'comuna'            => $limpiar($row['comuna']),
                'ciudad'            => $limpiar($row['ciudad']),
                'contacto_nombre'   => $limpiar($row['contacto_nombre']),
                'contacto_cargo'    => $limpiar($row['contacto_cargo']),
                'notas'             => $limpiar($row['notas']),
                'activo'            => 1,
                'deleted_at'        => $row['deleted_at']
            ];
        }

        // ── Actividad de la cuenta (agencia): proyectos activos/atrasados y
        // saldo por cobrar. Dos queries batch para evitar N+1. Convierte la
        // lista de contactos en un panorama de negocio.
        $ids = array_map(fn($r) => $r['id'], $data);
        if (!empty($ids)) {
            $ph = implode(',', array_fill(0, count($ids), '?'));

            $stProy = $db->prepare("
                SELECT cliente_id,
                  SUM(CASE WHEN estado = 'En curso' THEN 1 ELSE 0 END) AS activos,
                  SUM(CASE WHEN fecha_entrega_real IS NULL AND estado <> 'Terminado'
                            AND fecha_termino IS NOT NULL AND fecha_termino < CURDATE()
                           THEN 1 ELSE 0 END) AS atrasados
                FROM proyectos
                WHERE deleted_at IS NULL AND cliente_id IN ($ph)
                GROUP BY cliente_id");
            $stProy->execute($ids);
            $proyByCli = [];
            foreach ($stProy->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $proyByCli[(int)$r['cliente_id']] = ['activos' => (int)$r['activos'], 'atrasados' => (int)$r['atrasados']];
            }

            // Saldo pendiente: cuota completa si está Pendiente; saldo si es Parcial.
            $stCob = $db->prepare("
                SELECT pg.cliente_id,
                  COALESCE(SUM(CASE WHEN pc.estado = 'Parcial'
                                    THEN GREATEST(pc.boleta_monto - pc.pago_monto, 0)
                                    ELSE pc.boleta_monto END), 0) AS por_cobrar
                FROM pago_cuotas pc
                INNER JOIN pagos pg ON pg.id = pc.pago_id
                WHERE pc.estado IN ('Pendiente','Parcial') AND pg.deleted_at IS NULL
                  AND pg.cliente_id IN ($ph)
                GROUP BY pg.cliente_id");
            $stCob->execute($ids);
            $cobByCli = [];
            foreach ($stCob->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $cobByCli[(int)$r['cliente_id']] = (int)$r['por_cobrar'];
            }

            foreach ($data as &$d) {
                $p = $proyByCli[$d['id']] ?? ['activos' => 0, 'atrasados' => 0];
                $d['proyectos_activos']   = $p['activos'];
                $d['proyectos_atrasados'] = $p['atrasados'];
                $d['por_cobrar']          = $cobByCli[$d['id']] ?? 0;
            }
            unset($d);
        }

        // Responder con o sin paginación según corresponda
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

    // --- RESTAURAR CLIENTE (POST a clientes.php/restore/ID) ---
    // DEBE IR PRIMERO, antes que CREAR, para que no se confunda con crear cliente
    if ($method === 'POST' && $esRestore && $id) {
        $id_target = (int)preg_replace('/[^0-9]/', '', (string)$id);
        
        $stmt = $db->prepare("UPDATE clientes SET deleted_at = NULL WHERE id = ? AND deleted_at IS NOT NULL");
        $stmt->execute([$id_target]);
        
        if ($stmt->rowCount() > 0) {
            audit($db, $user['id'], 'RESTORE', 'clientes', "Restauró cliente #{$id_target}", $id_target);
            echo json_encode(['ok' => true, 'message' => 'Cliente restaurado']);
        } else {
            echo json_encode(['ok' => false, 'error' => 'Cliente no encontrado o no estaba eliminado']);
        }
        exit;
    }

    // --- CREAR CLIENTE (POST) ---
    if ($method === 'POST') {
        $b = body();
        // Validación server-side (el frontend valida con Zod, pero esto evita
        // que un cliente directo del API envíe datos fuera de rango).
        $tipo = tipoCliente($b['tipo'] ?? '');
        $email = $limpiar($b['email'] ?? '');
        if ($email !== '' && !validateEmail($email)) err('Email inválido', 422);
        // El RUT es opcional a propósito: cuando entra un cliente nuevo casi
        // nunca se tiene a mano, y un campo obligatorio termina llenándose con
        // basura. Si viene, sí se valida.
        $rut = $limpiar($b['rut'] ?? '');
        if ($rut !== '' && !validateRut($rut)) err('RUT inválido', 422);
        $nombre = $limpiar($b['nombre'] ?? '');
        if ($nombre === '') err('El nombre es requerido', 422);
        if (mb_strlen($nombre) > 200) err('Nombre muy largo (máx 200 chars)', 422);
        // Razón social, giro y los datos del contacto son de persona jurídica:
        // si es Persona no se guardan, para que lo almacenado sea lo mismo que
        // muestra el formulario (una Persona ES su propio contacto).
        $esEmpresa = $tipo === 'Empresa';

        $sql = "INSERT INTO clientes
                (tipo, nombre, razon_social, url, rut, giro, email, telefono,
                 direccion, comuna, ciudad,
                 contacto_nombre, contacto_cargo, notas)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $db->prepare($sql);
        $stmt->execute([
            $tipo,
            $nombre,
            $esEmpresa ? $limpiar($b['razon_social'] ?? '') : '',
            $limpiar($b['url'] ?? ''),
            $rut,
            $esEmpresa ? $limpiar($b['giro'] ?? '') : '',
            $email,
            $limpiar($b['telefono'] ?? ''),
            $limpiar($b['direccion'] ?? ''),
            $limpiar($b['comuna'] ?? ''),
            $limpiar($b['ciudad'] ?? ''),
            $esEmpresa ? $limpiar($b['contacto_nombre'] ?? '') : '',
            $esEmpresa ? $limpiar($b['contacto_cargo'] ?? '') : '',
            $limpiar($b['notas'] ?? '')
        ]);
        $cliente_id = $db->lastInsertId();
        
        audit($db, $user['id'], 'CREATE', 'clientes', "Creó cliente #{$cliente_id}: {$b['nombre']}", $cliente_id);
        
        echo json_encode(['ok' => true, 'id' => $cliente_id]);
        exit;
    }

    // --- EDITAR CLIENTE (PUT) ---
    if ($method === 'PUT' && $id) {
        $b = body();
        $id_target = (int)preg_replace('/[^0-9]/', '', (string)$id);

        if ($id_target > 0) {
            // USAMOS UPDATE: Esto modifica los datos SIN borrar el registro,
            // por lo tanto, los proyectos asociados NO se eliminan.
            $tipo = tipoCliente($b['tipo'] ?? '');
            $esEmpresa = $tipo === 'Empresa';
            $email = $limpiar($b['email'] ?? '');
            if ($email !== '' && !validateEmail($email)) err('Email inválido', 422);
            $rut = $limpiar($b['rut'] ?? '');
            if ($rut !== '' && !validateRut($rut)) err('RUT inválido', 422);
            $nombre = $limpiar($b['nombre'] ?? '');
            if ($nombre === '') err('El nombre es requerido', 422);
            if (mb_strlen($nombre) > 200) err('Nombre muy largo (máx 200 chars)', 422);

            $sql = "UPDATE clientes SET
                    tipo = ?,
                    nombre = ?,
                    razon_social = ?,
                    url = ?,
                    rut = ?,
                    giro = ?,
                    email = ?,
                    telefono = ?,
                    direccion = ?,
                    comuna = ?,
                    ciudad = ?,
                    contacto_nombre = ?,
                    contacto_cargo = ?,
                    notas = ?
                    WHERE id = ? AND deleted_at IS NULL";

            $stmt = $db->prepare($sql);
            $stmt->execute([
                $tipo,
                $nombre,
                $esEmpresa ? $limpiar($b['razon_social'] ?? '') : '',
                $limpiar($b['url'] ?? ''),
                $rut,
                $esEmpresa ? $limpiar($b['giro'] ?? '') : '',
                $email,
                $limpiar($b['telefono'] ?? ''),
                $limpiar($b['direccion'] ?? ''),
                $limpiar($b['comuna'] ?? ''),
                $limpiar($b['ciudad'] ?? ''),
                $esEmpresa ? $limpiar($b['contacto_nombre'] ?? '') : '',
                $esEmpresa ? $limpiar($b['contacto_cargo'] ?? '') : '',
                $limpiar($b['notas'] ?? ''),
                $id_target
            ]);
            
            audit($db, $user['id'], 'UPDATE', 'clientes', "Editó cliente #{$id_target}: {$b['nombre']}", $id_target);
            
            echo json_encode(['ok' => true]);
            exit;
        }
    }

    // --- ELIMINAR CLIENTE (DELETE) ---
    // Por defecto: SOFT DELETE (marcar deleted_at)
    // Con ?hard=1: ELIMINACIÓN DEFINITIVA
    if ($method === 'DELETE' && $id) {
        $id_target = (int)preg_replace('/[^0-9]/', '', (string)$id);
        
        // Obtener info antes de borrar
        $info = $db->prepare("SELECT nombre FROM clientes WHERE id = ?");
        $info->execute([$id_target]);
        $cli = $info->fetch();
        
        if (!$cli) {
            echo json_encode(['ok' => false, 'error' => 'Cliente no encontrado']);
            exit;
        }
        
        if ($esHard) {
            // ELIMINACIÓN DEFINITIVA (hard delete)
            $db->prepare("DELETE FROM clientes WHERE id = ?")->execute([$id_target]);
            audit($db, $user['id'], 'HARD_DELETE', 'clientes', "Eliminó PERMANENTEMENTE cliente #{$id_target}: " . $cli['nombre'], $id_target);
            echo json_encode(['ok' => true, 'message' => 'Cliente eliminado permanentemente']);
        } else {
            // SOFT DELETE (marcar como eliminado)
            $db->prepare("UPDATE clientes SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL")->execute([$id_target]);
            audit($db, $user['id'], 'SOFT_DELETE', 'clientes', "Envió a papelera cliente #{$id_target}: " . $cli['nombre'], $id_target);
            echo json_encode(['ok' => true, 'message' => 'Cliente enviado a papelera']);
        }
        exit;
    }

} catch (Throwable $e) {
    if (function_exists('logError')) {
        logError('Error en clientes.php', ['message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
    }
    errSafe($e, 500);
}