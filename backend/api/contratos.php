<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Contrato del proyecto (lado admin): ver y redactar el contrato, ver el
// estado de firma. La firma la hace el cliente desde portal.php.
//
//   GET  contratos.php?proyecto_id=N   -> contrato del proyecto (o null)
//   PUT  contratos.php?proyecto_id=N   -> upsert titulo/contenido/requiere_firma (admin)
//   PUT  contratos.php?proyecto_id=N&accion=reset -> reabrir firma (admin)
try {
    require_once __DIR__ . '/../config.php';
    cors();
    $db = getDB();
    $user = requireAuth();
    $db->exec("SET NAMES utf8mb4");
    $method = $_SERVER['REQUEST_METHOD'];
    if ($method === 'POST' && isset($_GET['_method'])) $method = strtoupper($_GET['_method']);
    $pid = isset($_GET['proyecto_id']) ? (int)$_GET['proyecto_id'] : 0;
    if (!$pid) err('proyecto_id requerido', 422);

    // --- GET: contrato del proyecto ---
    if ($method === 'GET') {
        $stmt = $db->prepare("SELECT id, titulo, contenido, requiere_firma, firmado_at,
                                     firma_nombre, firma_rut, firma_ip, contenido_firmado
                              FROM proyecto_contratos WHERE proyecto_id = ?");
        $stmt->execute([$pid]);
        $c = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($c) {
            $c['id'] = (int)$c['id'];
            $c['requiere_firma'] = (int)$c['requiere_firma'];
            $c['firmado'] = !empty($c['firmado_at']);
        }
        echo json_encode(['ok' => true, 'data' => $c ?: null], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Mutaciones: solo admin
    requireAdmin($user);

    // --- PUT reset: reabrir firma (borra los datos de firma) ---
    if ($method === 'PUT' && ($_GET['accion'] ?? '') === 'reset') {
        $db->prepare("UPDATE proyecto_contratos
                      SET firmado_at=NULL, firma_nombre=NULL, firma_rut=NULL, firma_ip=NULL,
                          firma_img=NULL, contenido_firmado=NULL
                      WHERE proyecto_id = ?")->execute([$pid]);
        audit($db, $user['id'], 'UPDATE', 'proyecto_contratos', "Reabrió firma de contrato del proyecto #{$pid}", $pid);
        echo json_encode(['ok' => true]);
        exit;
    }

    // --- PUT: upsert contenido del contrato ---
    if ($method === 'PUT') {
        $b = body();
        $titulo = trim((string)($b['titulo'] ?? 'Contrato de servicios'));
        if ($titulo === '') $titulo = 'Contrato de servicios';
        $contenido = sanitizeHtml((string)($b['contenido'] ?? ''));
        $requiere = array_key_exists('requiere_firma', $b) ? ($b['requiere_firma'] ? 1 : 0) : 1;

        // No permitir editar el contenido si ya fue firmado (protege la copia
        // congelada). El admin debe "reabrir firma" primero.
        $ya = $db->prepare("SELECT firmado_at FROM proyecto_contratos WHERE proyecto_id = ?");
        $ya->execute([$pid]);
        $firmadoAt = $ya->fetchColumn();
        if ($firmadoAt) err('El contrato ya fue firmado. Reabre la firma para editarlo.', 409);

        $db->prepare("INSERT INTO proyecto_contratos (proyecto_id, titulo, contenido, requiere_firma)
                      VALUES (?,?,?,?)
                      ON DUPLICATE KEY UPDATE titulo=VALUES(titulo), contenido=VALUES(contenido), requiere_firma=VALUES(requiere_firma)")
           ->execute([$pid, $titulo, $contenido, $requiere]);
        audit($db, $user['id'], 'UPDATE', 'proyecto_contratos', "Editó contrato del proyecto #{$pid}", $pid);
        echo json_encode(['ok' => true]);
        exit;
    }

    err('Método no permitido', 405);

} catch (Throwable $e) {
    if (function_exists('logError')) logError('Error en contratos.php', ['message' => $e->getMessage(), 'line' => $e->getLine()]);
    errSafe($e, 500);
}
