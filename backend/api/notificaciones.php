<?php
require_once __DIR__ . '/../config.php';
cors();

try {
    $db = getDB();
    $user = requireAuth();
    $method = $_SERVER['REQUEST_METHOD'];
    $uid = (int)$user['id'];
    // Los avisos globales (usuario_id NULL: pagos vencidos, respuestas de
    // clientes, cotizaciones) son de la administración. El trabajador solo ve
    // los dirigidos a él, y nadie borra los globales de los demás por error.
    // El comercial recibe los globales de su área (cotizaciones, proyectos,
    // clientes), no los de dinero ni los del sistema.
    $mios = esAdmin($user) ? '(usuario_id = ? OR usuario_id IS NULL)'
          : (esComercial($user) ? "(usuario_id = ? OR (usuario_id IS NULL AND link IN ('cotizaciones','proyectos','clientes','tablero')))"
                                : '(usuario_id = ?)');

    // GET: listar últimas 50 + contador no leídas
    if ($method === 'GET') {
        $stmt = $db->prepare("SELECT id, tipo, mensaje, link, leida, created_at
                              FROM notificaciones
                              WHERE $mios
                              ORDER BY created_at DESC
                              LIMIT 50");
        $stmt->execute([$uid]);
        $lista = $stmt->fetchAll();

        $cnt = $db->prepare("SELECT COUNT(*) FROM notificaciones
                             WHERE leida = 0 AND $mios");
        $cnt->execute([$uid]);
        $noLeidas = (int)$cnt->fetchColumn();

        ok(['items' => $lista, 'no_leidas' => $noLeidas]);
    }

    // POST: crear (uso interno — desde otros endpoints o admin)
    if ($method === 'POST') {
        requireAdmin($user);
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
                          WHERE leida = 0 AND $mios")
               ->execute([$uid]);
            ok(['updated' => true]);
        }
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) err('id requerido');
        $db->prepare("UPDATE notificaciones SET leida = 1
                      WHERE id = ? AND $mios")
           ->execute([$id, $uid]);
        ok(['updated' => true]);
    }

    // DELETE
    if ($method === 'DELETE') {
        if (!empty($_GET['all'])) {
            $db->prepare("DELETE FROM notificaciones WHERE $mios")
               ->execute([$uid]);
            ok(['deleted_all' => true]);
        }
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) err('id requerido');
        $db->prepare("DELETE FROM notificaciones
                      WHERE id = ? AND $mios")
           ->execute([$id, $uid]);
        ok(['deleted' => true]);
    }

    err('Método no permitido', 405);

} catch (Throwable $e) {
    errSafe($e, 500, 'notificaciones.php');
}
