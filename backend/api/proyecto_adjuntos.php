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
    $esArchivo = isset($_GET['archivo']) && $_GET['archivo'] == '1';

    $clean = fn($s) => trim((string)($s ?? ''));

    // --- GET: listar adjuntos de un proyecto ---
    if ($method === 'GET') {
        if (!$proyecto_id) err('proyecto_id requerido', 422);
        $stmt = $db->prepare("SELECT * FROM proyecto_adjuntos WHERE proyecto_id = ? ORDER BY id DESC");
        $stmt->execute([$proyecto_id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) { $r['id'] = (int)$r['id']; $r['proyecto_id'] = (int)$r['proyecto_id']; }
        unset($r);
        echo json_encode(['ok' => true, 'data' => $rows], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    // --- POST archivo: subir documento (multipart) ---
    if ($method === 'POST' && $esArchivo) {
        $pid = isset($_POST['proyecto_id']) ? (int)$_POST['proyecto_id'] : 0;
        if (!$pid) err('proyecto_id requerido', 422);
        if (empty($_FILES['archivo'])) err('No se recibió archivo.');
        $file = $_FILES['archivo'];
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) err('Upload falló.');
        if (!is_uploaded_file($file['tmp_name'])) err('Upload inválido.');
        if ($file['size'] > 15 * 1024 * 1024) err('Archivo demasiado grande (máx 15MB).');

        // Extensiones de documento/imagen permitidas. Se rechaza ejecutable/HTML/SVG
        // para evitar XSS al servirse desde /uploads/.
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $permitidas = ['pdf','doc','docx','xls','xlsx','ppt','pptx','txt','csv','zip','rar',
                       'jpg','jpeg','png','webp','gif'];
        if (!in_array($ext, $permitidas, true)) {
            err('Formato no permitido: .' . $ext);
        }
        $prohibidas = ['php','phtml','php3','php4','php5','phar','html','htm','svg','js','sh','exe','bat'];
        if (in_array($ext, $prohibidas, true)) err('Formato no permitido por seguridad.');

        $dir = __DIR__ . '/../../uploads/proyectos/';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $nombreInterno = bin2hex(random_bytes(16)) . '.' . $ext;
        $dest = $dir . $nombreInterno;
        if (!move_uploaded_file($file['tmp_name'], $dest)) err('Error al guardar el archivo.');

        $url = '/uploads/proyectos/' . $nombreInterno;
        $nombreOriginal = mb_substr($clean($file['name']), 0, 255);
        $titulo = $clean($_POST['titulo'] ?? '') ?: $nombreOriginal;

        $stmt = $db->prepare("INSERT INTO proyecto_adjuntos (proyecto_id, tipo, titulo, url, archivo_nombre) VALUES (?, 'archivo', ?, ?, ?)");
        $stmt->execute([$pid, $titulo, $url, $nombreOriginal]);
        $aid = (int)$db->lastInsertId();
        audit($db, $user['id'], 'CREATE', 'proyecto_adjuntos', "Subió archivo a proyecto #{$pid}: {$nombreOriginal}", $aid);
        echo json_encode(['ok' => true, 'id' => $aid, 'url' => $url]);
        exit;
    }

    // --- POST link: agregar enlace ---
    if ($method === 'POST') {
        $b = body();
        $pid = (int)($b['proyecto_id'] ?? 0);
        $url = $clean($b['url'] ?? '');
        $titulo = $clean($b['titulo'] ?? '') ?: $url;
        if (!$pid) err('proyecto_id requerido', 422);
        if ($url === '') err('La URL es requerida', 422);
        // Validación básica de URL http/https
        if (!preg_match('#^https?://#i', $url)) $url = 'https://' . $url;
        if (!filter_var($url, FILTER_VALIDATE_URL)) err('URL inválida', 422);

        $stmt = $db->prepare("INSERT INTO proyecto_adjuntos (proyecto_id, tipo, titulo, url) VALUES (?, 'link', ?, ?)");
        $stmt->execute([$pid, $titulo, $url]);
        $aid = (int)$db->lastInsertId();
        audit($db, $user['id'], 'CREATE', 'proyecto_adjuntos', "Agregó enlace a proyecto #{$pid}", $aid);
        echo json_encode(['ok' => true, 'id' => $aid]);
        exit;
    }

    // --- DELETE: eliminar adjunto (borra el archivo físico si aplica) ---
    if ($method === 'DELETE' && $id) {
        $stmt = $db->prepare("SELECT tipo, url FROM proyecto_adjuntos WHERE id = ?");
        $stmt->execute([$id]);
        $adj = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$adj) err('Adjunto no encontrado', 404);
        if ($adj['tipo'] === 'archivo' && strpos($adj['url'], '/uploads/proyectos/') === 0) {
            $fpath = __DIR__ . '/../..' . $adj['url'];
            if (is_file($fpath)) @unlink($fpath);
        }
        $db->prepare("DELETE FROM proyecto_adjuntos WHERE id = ?")->execute([$id]);
        audit($db, $user['id'], 'DELETE', 'proyecto_adjuntos', "Eliminó adjunto #{$id}", $id);
        echo json_encode(['ok' => true]);
        exit;
    }

    err('Método no permitido', 405);

} catch (Throwable $e) {
    if (function_exists('logError')) {
        logError('Error en proyecto_adjuntos.php', ['message' => $e->getMessage(), 'line' => $e->getLine()]);
    }
    errSafe($e, 500);
}
