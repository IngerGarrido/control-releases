<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Briefing (lado admin): CRUD de plantillas (tipos) y sus preguntas, y lectura
// de las respuestas que el cliente envió en un proyecto.
//
//   GET  briefing.php                  -> tipos con sus preguntas
//   GET  briefing.php?proyecto_id=N    -> tipo + preguntas + respuestas del proyecto
//   POST/PUT/DELETE ?recurso=tipo|pregunta
try {
    require_once __DIR__ . '/../config.php';
    cors();
    $db = getDB();
    $user = requireAuth();
    $db->exec("SET NAMES utf8mb4");
    $method = $_SERVER['REQUEST_METHOD'];
    if ($method === 'POST' && isset($_GET['_method'])) $method = strtoupper($_GET['_method']);
    $id = isset($_GET['id']) ? (int)$_GET['id'] : null;
    $recurso = $_GET['recurso'] ?? '';
    $clean = fn($s) => trim((string)($s ?? ''));

    // --- GET ---
    if ($method === 'GET') {
        // Respuestas de un proyecto (para que la agencia las revise)
        if (isset($_GET['proyecto_id'])) {
            $pid = (int)$_GET['proyecto_id'];
            $tq = $db->prepare("SELECT briefing_tipo_id FROM proyectos WHERE id = ?");
            $tq->execute([$pid]);
            $tipoId = (int)($tq->fetchColumn() ?: 0);
            $tipo = null; $preguntas = [];
            if ($tipoId) {
                $t = $db->prepare("SELECT id, nombre FROM briefing_tipos WHERE id = ?");
                $t->execute([$tipoId]);
                $tipo = $t->fetch(PDO::FETCH_ASSOC) ?: null;
                if ($tipo) $tipo['id'] = (int)$tipo['id'];
                $pq = $db->prepare("SELECT id, pregunta, formato, orden FROM briefing_preguntas
                                    WHERE tipo_id = ? AND activa = 1 ORDER BY orden ASC, id ASC");
                $pq->execute([$tipoId]);
                $preguntas = array_map(fn($p) => [
                    'id' => (int)$p['id'], 'pregunta' => $p['pregunta'],
                    'formato' => $p['formato'], 'orden' => (int)$p['orden'],
                ], $pq->fetchAll(PDO::FETCH_ASSOC));
            }
            $rq = $db->prepare("SELECT pregunta_id, valor, archivo_url, archivo_nombre, updated_at
                                FROM briefing_respuestas WHERE proyecto_id = ?");
            $rq->execute([$pid]);
            $respuestas = [];
            foreach ($rq->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $respuestas[(int)$r['pregunta_id']] = [
                    'valor' => $r['valor'], 'archivo_url' => $r['archivo_url'],
                    'archivo_nombre' => $r['archivo_nombre'], 'updated_at' => $r['updated_at'],
                ];
            }
            echo json_encode(['ok' => true, 'data' => [
                'tipo' => $tipo, 'preguntas' => $preguntas, 'respuestas' => $respuestas,
            ]], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Catálogo: tipos con sus preguntas
        $tipos = $db->query("SELECT id, nombre, orden, activa FROM briefing_tipos ORDER BY orden ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
        $pregs = $db->query("SELECT id, tipo_id, pregunta, formato, orden, activa FROM briefing_preguntas ORDER BY orden ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
        $byTipo = [];
        foreach ($pregs as $p) {
            $byTipo[(int)$p['tipo_id']][] = [
                'id' => (int)$p['id'], 'tipo_id' => (int)$p['tipo_id'], 'pregunta' => $p['pregunta'],
                'formato' => $p['formato'], 'orden' => (int)$p['orden'], 'activa' => (int)$p['activa'],
            ];
        }
        $data = array_map(fn($t) => [
            'id' => (int)$t['id'], 'nombre' => $t['nombre'], 'orden' => (int)$t['orden'],
            'activa' => (int)$t['activa'], 'preguntas' => $byTipo[(int)$t['id']] ?? [],
        ], $tipos);
        echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Mutaciones: solo admin
    if ($user['rol'] !== 'admin') err('Acceso denegado', 403);
    $b = body();

    // ===== TIPOS (plantillas) =====
    if ($recurso === 'tipo') {
        if ($method === 'POST') {
            $nombre = $clean($b['nombre'] ?? '');
            if ($nombre === '') err('El nombre es requerido', 422);
            $ord = (int)$db->query("SELECT COALESCE(MAX(orden),-1)+1 FROM briefing_tipos")->fetchColumn();
            $db->prepare("INSERT INTO briefing_tipos (nombre, orden) VALUES (?,?)")->execute([$nombre, $ord]);
            $tid = (int)$db->lastInsertId();
            audit($db, $user['id'], 'CREATE', 'briefing_tipos', "Creó tipo de briefing #{$tid}: {$nombre}", $tid);
            echo json_encode(['ok' => true, 'id' => $tid]); exit;
        }
        if ($method === 'PUT' && $id) {
            $sets = []; $vals = [];
            if (array_key_exists('nombre', $b)) { $sets[] = 'nombre = ?'; $vals[] = $clean($b['nombre']); }
            if (array_key_exists('orden', $b))  { $sets[] = 'orden = ?';  $vals[] = (int)$b['orden']; }
            if (array_key_exists('activa', $b)) { $sets[] = 'activa = ?'; $vals[] = $b['activa'] ? 1 : 0; }
            if (empty($sets)) err('Nada para actualizar', 422);
            $vals[] = $id;
            $db->prepare("UPDATE briefing_tipos SET " . implode(', ', $sets) . " WHERE id = ?")->execute($vals);
            audit($db, $user['id'], 'UPDATE', 'briefing_tipos', "Editó tipo de briefing #{$id}", $id);
            echo json_encode(['ok' => true]); exit;
        }
        if ($method === 'DELETE' && $id) {
            $db->prepare("DELETE FROM briefing_tipos WHERE id = ?")->execute([$id]);
            audit($db, $user['id'], 'DELETE', 'briefing_tipos', "Eliminó tipo de briefing #{$id}", $id);
            echo json_encode(['ok' => true]); exit;
        }
    }

    // ===== PREGUNTAS =====
    if ($recurso === 'pregunta') {
        if ($method === 'POST') {
            $tipo_id = (int)($b['tipo_id'] ?? 0);
            $pregunta = $clean($b['pregunta'] ?? '');
            $formato = in_array($b['formato'] ?? '', ['texto','textarea','archivo'], true) ? $b['formato'] : 'texto';
            if (!$tipo_id) err('tipo_id requerido', 422);
            if ($pregunta === '') err('La pregunta es requerida', 422);
            $oq = $db->prepare("SELECT COALESCE(MAX(orden),-1)+1 FROM briefing_preguntas WHERE tipo_id = ?");
            $oq->execute([$tipo_id]);
            $ord = (int)$oq->fetchColumn();
            $db->prepare("INSERT INTO briefing_preguntas (tipo_id, pregunta, formato, orden) VALUES (?,?,?,?)")
               ->execute([$tipo_id, $pregunta, $formato, $ord]);
            $qid = (int)$db->lastInsertId();
            audit($db, $user['id'], 'CREATE', 'briefing_preguntas', "Creó pregunta de briefing #{$qid}", $qid);
            echo json_encode(['ok' => true, 'id' => $qid]); exit;
        }
        if ($method === 'PUT' && $id) {
            $sets = []; $vals = [];
            if (array_key_exists('pregunta', $b)) { $sets[] = 'pregunta = ?'; $vals[] = $clean($b['pregunta']); }
            if (array_key_exists('formato', $b) && in_array($b['formato'], ['texto','textarea','archivo'], true)) { $sets[] = 'formato = ?'; $vals[] = $b['formato']; }
            if (array_key_exists('orden', $b))  { $sets[] = 'orden = ?';  $vals[] = (int)$b['orden']; }
            if (array_key_exists('activa', $b)) { $sets[] = 'activa = ?'; $vals[] = $b['activa'] ? 1 : 0; }
            if (empty($sets)) err('Nada para actualizar', 422);
            $vals[] = $id;
            $db->prepare("UPDATE briefing_preguntas SET " . implode(', ', $sets) . " WHERE id = ?")->execute($vals);
            audit($db, $user['id'], 'UPDATE', 'briefing_preguntas', "Editó pregunta de briefing #{$id}", $id);
            echo json_encode(['ok' => true]); exit;
        }
        if ($method === 'DELETE' && $id) {
            $db->prepare("DELETE FROM briefing_preguntas WHERE id = ?")->execute([$id]);
            audit($db, $user['id'], 'DELETE', 'briefing_preguntas', "Eliminó pregunta de briefing #{$id}", $id);
            echo json_encode(['ok' => true]); exit;
        }
    }

    err('Método no permitido', 405);

} catch (Throwable $e) {
    if (function_exists('logError')) logError('Error en briefing.php', ['message' => $e->getMessage(), 'line' => $e->getLine()]);
    errSafe($e, 500);
}
