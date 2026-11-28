<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Comentarios del proyecto (lado admin): ver el hilo y responder.
try {
    require_once __DIR__ . '/../config.php';
    cors();
    $db = getDB();
    $user = requireAuth();
    $db->exec("SET NAMES utf8mb4");
    $method = $_SERVER['REQUEST_METHOD'];
    if ($method === 'POST' && isset($_GET['_method'])) $method = strtoupper($_GET['_method']);

    $id = isset($_GET['id']) ? (int)$_GET['id'] : null;
    $proyecto_id = isset($_GET['proyecto_id']) ? (int)$_GET['proyecto_id'] : null;

    // Acceso por proyecto: se resuelve el proyecto del registro (PUT/DELETE
    // por id) o el indicado (GET/POST) y se valida antes de cualquier acción.
    $pidAcceso = $id ? proyectoDeRegistro($db, 'proyecto_comentarios', (int)$id)
               : ($proyecto_id ?: (int)($_POST['proyecto_id'] ?? (body()['proyecto_id'] ?? 0)));
    requireAccesoProyecto($db, $user, (int)$pidAcceso);

    // --- GET: listar comentarios de un proyecto ---
    if ($method === 'GET') {
        if (!$proyecto_id) err('proyecto_id requerido', 422);
        $stmt = $db->prepare("SELECT id, fase_id, autor, autor_nombre, texto, created_at, interno
                              FROM proyecto_comentarios WHERE proyecto_id = ? ORDER BY created_at ASC, id ASC");
        $stmt->execute([$proyecto_id]);
        $rows = array_map(fn($c) => [
            'id' => (int)$c['id'], 'fase_id' => $c['fase_id'] !== null ? (int)$c['fase_id'] : null,
            'autor' => $c['autor'], 'autor_nombre' => $c['autor_nombre'],
            'texto' => $c['texto'], 'created_at' => $c['created_at'], 'interno' => (int)$c['interno'] === 1,
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
        echo json_encode(['ok' => true, 'data' => $rows], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // --- POST: la agencia responde en el hilo ---
    if ($method === 'POST') {
        $b = body();
        $pid = (int)($b['proyecto_id'] ?? 0);
        $texto = trim((string)($b['texto'] ?? ''));
        if (!$pid) err('proyecto_id requerido', 422);
        if ($texto === '') err('Escribe un comentario', 422);
        // interno = nota del equipo: el cliente NO la ve en su portal.
        $interno = !empty($b['interno']) ? 1 : 0;
        $db->prepare("INSERT INTO proyecto_comentarios (proyecto_id, autor, autor_nombre, texto, interno) VALUES (?, 'admin', ?, ?, ?)")
           ->execute([$pid, $user['nombre'] ?? 'Agencia', $texto, $interno]);
        $cid = (int)$db->lastInsertId();
        audit($db, $user['id'], 'CREATE', 'proyecto_comentarios', "Respondió en proyecto #{$pid}", $cid);
        echo json_encode(['ok' => true, 'id' => $cid]);
        exit;
    }

    err('Método no permitido', 405);

} catch (Throwable $e) {
    if (function_exists('logError')) logError('Error en proyecto_comentarios.php', ['message' => $e->getMessage(), 'line' => $e->getLine()]);
    errSafe($e, 500);
}
