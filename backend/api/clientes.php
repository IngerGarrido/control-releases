<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

try {
    require_once __DIR__ . '/../config.php';
    require_once __DIR__ . '/../lib/saldos.php';
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
        $per_page = isset($_GET['per_page']) ? max(1, min(1000, (int)$_GET['per_page'])) : null;

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
            // Por cobrar por cliente desde la única fuente (lib/saldos.php).
            $cobByCli = array_map(fn($r) => $r['por_cobrar'], saldoResumenPor(
                saldosCuotas($db, "COALESCE(pr.cliente_id, pg.cliente_id) IN ($ph)", $ids), 'cliente_id'));

            foreach ($data as &$d) {
                $p = $proyByCli[$d['id']] ?? ['activos' => 0, 'atrasados' => 0];
                $d['proyectos_activos']   = $p['activos'];
                $d['proyectos_atrasados'] = $p['atrasados'];
                // Cuánto debe el cliente es cobranza: solo admin.
                $d['por_cobrar']          = esAdmin($user) ? ($cobByCli[$d['id']] ?? 0) : null;
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

        // Restaura también lo que se envió a la papelera JUNTO con el cliente
        // (mismo deleted_at): sitios, proyectos, cotizaciones y pagos.
        $st = $db->prepare("SELECT deleted_at FROM clientes WHERE id = ? AND deleted_at IS NOT NULL");
        $st->execute([$id_target]);
        $borradoAt = $st->fetchColumn();
        $stmt = $db->prepare("UPDATE clientes SET deleted_at = NULL WHERE id = ? AND deleted_at IS NOT NULL");
        $stmt->execute([$id_target]);

        if ($stmt->rowCount() > 0) {
            $n = clienteCascadaPapelera($db, $id_target, $borradoAt, null);
            audit($db, $user['id'], 'RESTORE', 'clientes', "Restauró cliente #{$id_target}" . ($n ? " y {$n} registros asociados" : ''), $id_target);
            echo json_encode(['ok' => true, 'message' => 'Cliente restaurado' . ($n ? " junto con {$n} registros asociados" : '')]);
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
            // ELIMINACIÓN DEFINITIVA (hard delete). Proyectos, sitios y
            // cotizaciones caen por FK CASCADE; los pagos no tienen FK al
            // cliente y se borran a mano (sus cuotas y abonos caen por FK).
            // Los archivos subidos a sus proyectos se borran del disco.
            $adj = $db->prepare("SELECT a.url FROM proyecto_adjuntos a JOIN proyectos p ON p.id = a.proyecto_id
                                 WHERE p.cliente_id = ? AND a.tipo = 'archivo'");
            $adj->execute([$id_target]);
            $archivos = $adj->fetchAll(PDO::FETCH_COLUMN);
            $db->beginTransaction();
            $db->prepare("DELETE pg FROM pagos pg LEFT JOIN proyectos pr ON pr.id = pg.proyecto_id
                          WHERE pg.cliente_id = ? OR pr.cliente_id = ?")->execute([$id_target, $id_target]);
            $db->prepare("DELETE FROM clientes WHERE id = ?")->execute([$id_target]);
            $db->commit();
            foreach ($archivos as $url) {
                if (strpos((string)$url, '/uploads/proyectos/') !== 0) continue;
                $f = __DIR__ . '/../..' . $url;
                if (is_file($f)) @unlink($f);
            }
            audit($db, $user['id'], 'HARD_DELETE', 'clientes', "Eliminó PERMANENTEMENTE cliente #{$id_target}: " . $cli['nombre'], $id_target);
            echo json_encode(['ok' => true, 'message' => 'Cliente eliminado permanentemente']);
        } else {
            // SOFT DELETE: el cliente y lo suyo (sitios, proyectos, cotizaciones,
            // pagos) van a la papelera con la MISMA fecha, para restaurarlos juntos.
            // Antes quedaban activos y huérfanos. (Portado de sistema-local.)
            $ahora = date('Y-m-d H:i:s');
            $db->beginTransaction();
            $db->prepare("UPDATE clientes SET deleted_at = ? WHERE id = ? AND deleted_at IS NULL")->execute([$ahora, $id_target]);
            $n = clienteCascadaPapelera($db, $id_target, null, $ahora);
            $db->commit();
            audit($db, $user['id'], 'SOFT_DELETE', 'clientes', "Envió a papelera cliente #{$id_target}: " . $cli['nombre'] . ($n ? " (y {$n} registros asociados)" : ''), $id_target);
            echo json_encode(['ok' => true, 'message' => 'Cliente enviado a papelera' . ($n ? " junto con {$n} registros asociados" : '')]);
        }
        exit;
    }

} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    if (function_exists('logError')) {
        logError('Error en clientes.php', ['message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
    }
    errSafe($e, 500);
}

/**
 * Envía a la papelera (o restaura) lo asociado a un cliente junto con él.
 * - Borrar:    $desde = null,       $hasta = fecha  → marca lo activo con esa fecha.
 * - Restaurar: $desde = fecha usada, $hasta = null  → restaura solo lo que se
 *   borró en ese mismo momento (no lo que ya estaba en la papelera antes).
 * @return int cantidad de registros afectados
 */
function clienteCascadaPapelera(PDO $db, int $clienteId, ?string $desde, ?string $hasta): int {
    $cond = $desde === null ? 't.deleted_at IS NULL' : 't.deleted_at = ?';
    $extra = $desde === null ? [] : [$desde];
    $n = 0;
    // Pagos primero: se identifican por el cliente de su proyecto (o el propio).
    $st = $db->prepare("UPDATE pagos t LEFT JOIN proyectos pr ON pr.id = t.proyecto_id SET t.deleted_at = ?
                        WHERE COALESCE(pr.cliente_id, t.cliente_id) = ? AND $cond");
    $st->execute(array_merge([$hasta, $clienteId], $extra));
    $n += $st->rowCount();
    foreach (['cotizaciones', 'proyectos', 'sitios'] as $tabla) { // lista fija, no viene del cliente
        $st = $db->prepare("UPDATE {$tabla} t SET t.deleted_at = ? WHERE t.cliente_id = ? AND $cond");
        $st->execute(array_merge([$hasta, $clienteId], $extra));
        $n += $st->rowCount();
    }
    return $n;
}
