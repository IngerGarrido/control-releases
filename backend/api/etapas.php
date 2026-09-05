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

    $clean = fn($s) => trim((string)($s ?? ''));
    // Color válido: hex #rgb o #rrggbb; si no, cae al violeta por defecto.
    $normColor = function($v) {
        $c = trim((string)($v ?? ''));
        return preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $c) ? $c : '#7c3aed';
    };

    // --- GET: listar etapas ---
    if ($method === 'GET') {
        $rows = $db->query("SELECT * FROM etapas ORDER BY orden ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) { $r['id'] = (int)$r['id']; $r['orden'] = (int)$r['orden']; }
        unset($r);
        echo json_encode(['ok' => true, 'data' => $rows]);
        exit;
    }

    // Mutaciones: solo admin
    if ($user['rol'] !== 'admin') err('Acceso denegado', 403);

    // --- POST: crear ---
    if ($method === 'POST') {
        $b = body();
        $nombre = $clean($b['nombre'] ?? '');
        if ($nombre === '') err('El nombre es requerido', 422);
        $ord = (int)$db->query("SELECT COALESCE(MAX(orden),-1)+1 FROM etapas")->fetchColumn();
        $stmt = $db->prepare("INSERT INTO etapas (nombre, color, orden) VALUES (?,?,?)");
        $stmt->execute([$nombre, $normColor($b['color'] ?? ''), $ord]);
        $eid = (int)$db->lastInsertId();
        audit($db, $user['id'], 'CREATE', 'etapas', "Creó etapa #{$eid}: {$nombre}", $eid);
        echo json_encode(['ok' => true, 'id' => $eid]);
        exit;
    }

    // --- PUT: editar ---
    if ($method === 'PUT' && $id) {
        $b = body();
        $sets = []; $vals = [];
        if (array_key_exists('nombre', $b)) { $sets[] = 'nombre = ?'; $vals[] = $clean($b['nombre']); }
        if (array_key_exists('color', $b))  { $sets[] = 'color = ?';  $vals[] = $normColor($b['color']); }
        if (array_key_exists('orden', $b))  { $sets[] = 'orden = ?';  $vals[] = (int)$b['orden']; }
        if (empty($sets)) err('Nada para actualizar', 422);
        $vals[] = $id;
        $db->prepare("UPDATE etapas SET " . implode(', ', $sets) . " WHERE id = ?")->execute($vals);
        audit($db, $user['id'], 'UPDATE', 'etapas', "Editó etapa #{$id}", $id);
        echo json_encode(['ok' => true]);
        exit;
    }

    // --- DELETE: eliminar (fases que la usan quedan en NULL via FK) ---
    if ($method === 'DELETE' && $id) {
        $db->prepare("DELETE FROM etapas WHERE id = ?")->execute([$id]);
        audit($db, $user['id'], 'DELETE', 'etapas', "Eliminó etapa #{$id}", $id);
        echo json_encode(['ok' => true]);
        exit;
    }

    err('Método no permitido', 405);

} catch (Throwable $e) {
    if (function_exists('logError')) {
        logError('Error en etapas.php', ['message' => $e->getMessage(), 'line' => $e->getLine()]);
    }
    errSafe($e, 500);
}
