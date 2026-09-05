<?php
require_once __DIR__ . '/../config.php';
cors();

try {
    $db = getDB();
    $user = requireAuth();
    $method = $_SERVER['REQUEST_METHOD'];
    $uid = (int)$user['id'];

    // GET: listar últimas 50 + contador no leídas
    if ($method === 'GET') {
        $stmt = $db->prepare("SELECT id, tipo, mensaje, link, leida, created_at
                              FROM notificaciones
                              WHERE usuario_id = ? OR usuario_id IS NULL
                              ORDER BY created_at DESC
                              LIMIT 50");
        $stmt->execute([$uid]);
        $lista = $stmt->fetchAll();

        $cnt = $db->prepare("SELECT COUNT(*) FROM notificaciones
                             WHERE leida = 0 AND (usuario_id = ? OR usuario_id IS NULL)");
        $cnt->execute([$uid]);
        $noLeidas = (int)$cnt->fetchColumn();

        ok(['items' => $lista, 'no_leidas' => $noLeidas]);
    }

    // POST: crear (uso interno — desde otros endpoints o admin)
    if ($method === 'POST') {
        $b = body();
        $tipo = preg_replace('/[^a-z_]/', '', strtolower($b['tipo'] ?? 'info'));
        $msg  = trim((string)($b['mensaje'] ?? ''));
        if ($msg === '') err('Mensaje requerido');
        $link = $b['link'] ?? null;
        $target = isset($b['usuario_id']) ? (int)$b['usuario_id'] : null;

        $db->prepare("INSERT INTO notificaciones (usuario_id, tipo, mensaje, link) VALUES (?, ?, ?, ?)")
           ->execute([$target, $tipo ?: 'info', $msg, $link]);
        ok(['id' => (int)$db->lastInsertId()]);
    }

    // PUT: marcar como leída (?id=N) o todas (?all=1)
    if ($method === 'PUT') {
        if (!empty($_GET['all'])) {
            $db->prepare("UPDATE notificaciones SET leida = 1
                          WHERE leida = 0 AND (usuario_id = ? OR usuario_id IS NULL)")
               ->execute([$uid]);
            ok(['updated' => true]);
        }
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) err('id requerido');
        $db->prepare("UPDATE notificaciones SET leida = 1
                      WHERE id = ? AND (usuario_id = ? OR usuario_id IS NULL)")
           ->execute([$id, $uid]);
        ok(['updated' => true]);
    }

    // DELETE
    if ($method === 'DELETE') {
        if (!empty($_GET['all'])) {
            $db->prepare("DELETE FROM notificaciones WHERE usuario_id = ? OR usuario_id IS NULL")
               ->execute([$uid]);
            ok(['deleted_all' => true]);
        }
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) err('id requerido');
        $db->prepare("DELETE FROM notificaciones
                      WHERE id = ? AND (usuario_id = ? OR usuario_id IS NULL)")
           ->execute([$id, $uid]);
        ok(['deleted' => true]);
    }

    err('Método no permitido', 405);

} catch (Throwable $e) {
    errSafe($e, 500, 'notificaciones.php');
}
