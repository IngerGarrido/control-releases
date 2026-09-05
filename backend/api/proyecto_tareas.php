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
    $ESTADOS = ['Pendiente', 'En curso', 'QA interna', 'Revisión cliente', 'Aprobada'];
    $normEstado = function($v) use ($ESTADOS) {
        $e = trim((string)($v ?? ''));
        return in_array($e, $ESTADOS, true) ? $e : 'Pendiente';
    };
    $faseOrNull = fn($v) => !empty($v) ? (int)$v : null;

    // --- GET: listar tareas de un proyecto ---
    if ($method === 'GET') {
        if (!$proyecto_id) err('proyecto_id requerido', 422);
        $stmt = $db->prepare("SELECT * FROM proyecto_tareas WHERE proyecto_id = ? ORDER BY orden ASC, id ASC");
        $stmt->execute([$proyecto_id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['id'] = (int)$r['id'];
            $r['proyecto_id'] = (int)$r['proyecto_id'];
            $r['fase_id'] = $r['fase_id'] !== null ? (int)$r['fase_id'] : null;
            $r['orden'] = (int)$r['orden'];
        }
        unset($r);
        echo json_encode(['ok' => true, 'data' => $rows]);
        exit;
    }

    // --- POST: crear tarea ---
    if ($method === 'POST') {
        $b = body();
        $pid = (int)($b['proyecto_id'] ?? 0);
        $titulo = $clean($b['titulo'] ?? '');
        if (!$pid) err('proyecto_id requerido', 422);
        if ($titulo === '') err('El título de la tarea es requerido', 422);

        $fase_id = $faseOrNull($b['fase_id'] ?? null);
        $estado = $normEstado($b['estado'] ?? 'Pendiente');
        // orden por defecto: al final de su columna (estado) dentro del proyecto
        $ord = isset($b['orden']) ? (int)$b['orden'] : 0;

        $stmt = $db->prepare("INSERT INTO proyecto_tareas (proyecto_id, fase_id, titulo, descripcion, estado, orden) VALUES (?,?,?,?,?,?)");
        $stmt->execute([$pid, $fase_id, $titulo, $clean($b['descripcion'] ?? ''), $estado, $ord]);
        $tid = (int)$db->lastInsertId();
        audit($db, $user['id'], 'CREATE', 'proyecto_tareas', "Creó tarea #{$tid}: {$titulo}", $tid);
        echo json_encode(['ok' => true, 'id' => $tid]);
        exit;
    }

    // --- PUT: editar tarea (título / descripción / estado / fase / orden) ---
    if ($method === 'PUT' && $id) {
        $b = body();
        $sets = [];
        $vals = [];
        if (array_key_exists('titulo', $b))      { $sets[] = 'titulo = ?';      $vals[] = $clean($b['titulo']); }
        if (array_key_exists('descripcion', $b)) { $sets[] = 'descripcion = ?'; $vals[] = $clean($b['descripcion']); }
        if (array_key_exists('estado', $b))      { $sets[] = 'estado = ?';      $vals[] = $normEstado($b['estado']); }
        if (array_key_exists('fase_id', $b))     { $sets[] = 'fase_id = ?';     $vals[] = $faseOrNull($b['fase_id']); }
        if (array_key_exists('orden', $b))       { $sets[] = 'orden = ?';       $vals[] = (int)$b['orden']; }
        if (empty($sets)) err('Nada para actualizar', 422);
        $vals[] = $id;
        $db->prepare("UPDATE proyecto_tareas SET " . implode(', ', $sets) . " WHERE id = ?")->execute($vals);
        audit($db, $user['id'], 'UPDATE', 'proyecto_tareas', "Editó tarea #{$id}", $id);
        echo json_encode(['ok' => true]);
        exit;
    }

    // --- DELETE: eliminar tarea ---
    if ($method === 'DELETE' && $id) {
        $db->prepare("DELETE FROM proyecto_tareas WHERE id = ?")->execute([$id]);
        audit($db, $user['id'], 'DELETE', 'proyecto_tareas', "Eliminó tarea #{$id}", $id);
        echo json_encode(['ok' => true]);
        exit;
    }

    err('Método no permitido', 405);

} catch (Throwable $e) {
    if (function_exists('logError')) {
        logError('Error en proyecto_tareas.php', ['message' => $e->getMessage(), 'line' => $e->getLine()]);
    }
    errSafe($e, 500);
}
