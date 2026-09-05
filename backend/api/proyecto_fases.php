<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

try {
    require_once __DIR__ . '/../config.php';
    cors();
    $db = getDB();
    $user = requireAuth();
    $method = $_SERVER['REQUEST_METHOD'];
    if ($method === 'POST' && isset($_GET['_method'])) {
        $method = strtoupper($_GET['_method']);
    }

    $id = isset($_GET['id']) ? (int)$_GET['id'] : null;
    $proyecto_id = isset($_GET['proyecto_id']) ? (int)$_GET['proyecto_id'] : null;

    $clean = fn($s) => trim((string)($s ?? ''));
    $ESTADOS = ['Pendiente', 'En curso', 'En revisión', 'Completada'];
    $normEstado = function($v) use ($ESTADOS) {
        $e = trim((string)($v ?? ''));
        return in_array($e, $ESTADOS, true) ? $e : 'Pendiente';
    };

    // --- GET: listar fases de un proyecto ---
    if ($method === 'GET') {
        if (!$proyecto_id) err('proyecto_id requerido', 422);
        $stmt = $db->prepare("SELECT * FROM proyecto_fases WHERE proyecto_id = ? ORDER BY orden ASC, id ASC");
        $stmt->execute([$proyecto_id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['id'] = (int)$r['id'];
            $r['proyecto_id'] = (int)$r['proyecto_id'];
            $r['orden'] = (int)$r['orden'];
            // aprobada_at viene de SELECT *; se deja tal cual (null o fecha).
        }
        unset($r);
        echo json_encode(['ok' => true, 'data' => $rows]);
        exit;
    }

    // --- POST: crear fase ---
    if ($method === 'POST') {
        $b = body();
        $pid = (int)($b['proyecto_id'] ?? 0);
        $nombre = $clean($b['nombre'] ?? '');
        if (!$pid) err('proyecto_id requerido', 422);
        if ($nombre === '') err('El nombre de la fase es requerido', 422);

        // orden por defecto: al final
        $ord = isset($b['orden']) ? (int)$b['orden']
             : (int)$db->query("SELECT COALESCE(MAX(orden),-1)+1 FROM proyecto_fases WHERE proyecto_id = " . $pid)->fetchColumn();

        $stmt = $db->prepare("INSERT INTO proyecto_fases (proyecto_id, nombre, orden, estado) VALUES (?,?,?,?)");
        $stmt->execute([$pid, $nombre, $ord, $normEstado($b['estado'] ?? 'Pendiente')]);
        $fid = (int)$db->lastInsertId();
        audit($db, $user['id'], 'CREATE', 'proyecto_fases', "Creó fase #{$fid}: {$nombre}", $fid);
        echo json_encode(['ok' => true, 'id' => $fid]);
        exit;
    }

    // --- PUT: editar fase (nombre / estado / orden) ---
    if ($method === 'PUT' && $id) {
        $b = body();
        $sets = [];
        $vals = [];
        if (array_key_exists('nombre', $b))  { $sets[] = 'nombre = ?'; $vals[] = $clean($b['nombre']); }
        if (array_key_exists('estado', $b))  { $sets[] = 'estado = ?'; $vals[] = $normEstado($b['estado']); }
        if (array_key_exists('orden', $b))   { $sets[] = 'orden = ?';  $vals[] = (int)$b['orden']; }
        if (array_key_exists('fecha_objetivo', $b)) { $sets[] = 'fecha_objetivo = ?'; $vals[] = !empty($b['fecha_objetivo']) ? $b['fecha_objetivo'] : null; }
        if (empty($sets)) err('Nada para actualizar', 422);
        $vals[] = $id;
        $db->prepare("UPDATE proyecto_fases SET " . implode(', ', $sets) . " WHERE id = ?")->execute($vals);
        audit($db, $user['id'], 'UPDATE', 'proyecto_fases', "Editó fase #{$id}", $id);
        echo json_encode(['ok' => true]);
        exit;
    }

    // --- DELETE: eliminar fase (y sus tareas via CASCADE) ---
    if ($method === 'DELETE' && $id) {
        $db->prepare("DELETE FROM proyecto_fases WHERE id = ?")->execute([$id]);
        audit($db, $user['id'], 'DELETE', 'proyecto_fases', "Eliminó fase #{$id}", $id);
        echo json_encode(['ok' => true]);
        exit;
    }

    err('Método no permitido', 405);

} catch (Throwable $e) {
    if (function_exists('logError')) {
        logError('Error en proyecto_fases.php', ['message' => $e->getMessage(), 'line' => $e->getLine()]);
    }
    errSafe($e, 500);
}
