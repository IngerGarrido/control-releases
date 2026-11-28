<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Portal del cliente (PÚBLICO): acceso por magic link (token en la URL).
// NO usa requireAuth: el token del proyecto es la llave. Devuelve solo datos
// de avance, SIN información financiera (montos, presupuesto, etc.).
try {
    require_once __DIR__ . '/../config.php';
    require_once __DIR__ . '/../lib/entregas.php';
    cors();
    $db = getDB();
    $db->exec("SET NAMES utf8mb4");

    // Una pregunta de briefing es válida para el proyecto si pertenece a la
    // plantilla (tipo) asignada a ese proyecto. Evita responder preguntas ajenas.
    function preguntaPerteneceAlProyecto(PDO $db, int $preguntaId, int $proyectoId): bool {
        $q = $db->prepare("SELECT 1 FROM briefing_preguntas q
                           JOIN proyectos p ON p.briefing_tipo_id = q.tipo_id
                           WHERE q.id = ? AND p.id = ? AND q.activa = 1 LIMIT 1");
        $q->execute([$preguntaId, $proyectoId]);
        return (bool)$q->fetchColumn();
    }

    $token = isset($_GET['token']) ? trim((string)$_GET['token']) : '';
    // Token largo y bien formado; evita escaneo trivial.
    if ($token === '' || !preg_match('/^[a-f0-9]{32,64}$/', $token)) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Link inválido']);
        exit;
    }

    $stmt = $db->prepare("
        SELECT p.id, p.nombre, p.descripcion, p.estado, p.briefing_tipo_id, p.portal_token_exp,
               p.fecha_entrega_real, p.garantia_meses, p.entrega_confirmada_at, p.entrega_confirmada_nombre,
               p.entrega_por_plazo, p.entrega_observacion, p.entrega_observacion_at,
               e.nombre AS etapa_nombre, e.color AS etapa_color,
               c.nombre AS cliente_nombre
        FROM proyectos p
        LEFT JOIN etapas e   ON e.id = p.etapa_id
        LEFT JOIN clientes c ON c.id = p.cliente_id
        WHERE p.portal_token = ? AND p.deleted_at IS NULL
        LIMIT 1
    ");
    $stmt->execute([$token]);
    $proy = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$proy) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Proyecto no encontrado o link revocado']);
        exit;
    }
    // Magic link caducado: si tiene fecha de expiración y ya pasó, se rechaza.
    // (portal_token_exp NULL = link sin vencimiento, por compatibilidad.)
    if (!empty($proy['portal_token_exp']) && strtotime($proy['portal_token_exp']) < time()) {
        http_response_code(410);
        echo json_encode(['ok' => false, 'error' => 'Este enlace expiró. Pídele uno nuevo a la agencia.']);
        exit;
    }
    $pid = (int)$proy['id'];
    $proyNombre = (string)$proy['nombre'];

    // Aviso en la campana de la agencia (todos los usuarios: usuario_id NULL).
    // Sin esto la agencia no se enteraba de lo que hacía el cliente.
    $avisar = function (string $mensaje, string $tipo = 'info') use ($db, $pid) {
        try {
            $db->prepare("INSERT INTO notificaciones (usuario_id, tipo, mensaje, link) VALUES (NULL, ?, ?, ?)")
               ->execute([$tipo, mb_substr($mensaje, 0, 255), 'proyectos:' . $pid]);
        } catch (Throwable $e) { /* el aviso nunca debe romper la acción del cliente */ }
    };

    // --- POST: acciones del cliente (acotadas a ESTE proyecto por el token) ---
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Portal público: límite por IP y por link (evita spam de comentarios
        // y que se llene el disco con subidas).
        rateLimit('portal.post', 60, 3600);
        rateLimit('portal.' . substr($token, 0, 16), 120, 3600);

        // a) Subida de archivo (multipart): adjunto del cliente al proyecto.
        if (!empty($_FILES['archivo'])) {
            rateLimit('portal.upload', 20, 3600);
            $cnt = $db->prepare("SELECT COUNT(*) FROM proyecto_adjuntos WHERE proyecto_id = ? AND origen = 'cliente'");
            $cnt->execute([$pid]);
            if ((int)$cnt->fetchColumn() >= 100) { http_response_code(429); echo json_encode(['ok'=>false,'error'=>'Se alcanzó el máximo de archivos para este proyecto. Escríbele a la agencia.']); exit; }
            $file = $_FILES['archivo'];
            if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
                http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Upload inválido']); exit;
            }
            if ($file['size'] > 15 * 1024 * 1024) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Archivo muy grande (máx 15MB)']); exit; }
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $permitidas = ['pdf','doc','docx','xls','xlsx','ppt','pptx','txt','csv','zip','rar','jpg','jpeg','png','webp','gif'];
            $prohibidas = ['php','phtml','php3','php4','php5','phar','html','htm','svg','js','sh','exe','bat'];
            if (!in_array($ext, $permitidas, true) || in_array($ext, $prohibidas, true)) {
                http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Formato no permitido']); exit;
            }
            $dir = __DIR__ . '/../../uploads/proyectos/';
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $nombreInterno = bin2hex(random_bytes(16)) . '.' . $ext;
            if (!move_uploaded_file($file['tmp_name'], $dir . $nombreInterno)) { http_response_code(500); echo json_encode(['ok'=>false,'error'=>'No se pudo guardar']); exit; }
            $nombreOriginal = mb_substr(trim((string)$file['name']), 0, 255);
            $url = '/uploads/proyectos/' . $nombreInterno;

            // Respuesta de briefing tipo archivo: si viene pregunta_id válida
            // (pertenece al briefing de este proyecto), se guarda como respuesta.
            $preguntaId = isset($_POST['pregunta_id']) ? (int)$_POST['pregunta_id'] : 0;
            if ($preguntaId && preguntaPerteneceAlProyecto($db, $preguntaId, $pid)) {
                $db->prepare("INSERT INTO briefing_respuestas (proyecto_id, pregunta_id, archivo_url, archivo_nombre)
                              VALUES (?,?,?,?)
                              ON DUPLICATE KEY UPDATE archivo_url=VALUES(archivo_url), archivo_nombre=VALUES(archivo_nombre)")
                   ->execute([$pid, $preguntaId, $url, $nombreOriginal]);
                $avisar("El cliente subió un archivo al briefing de «{$proyNombre}»");
                echo json_encode(['ok' => true]);
                exit;
            }

            $db->prepare("INSERT INTO proyecto_adjuntos (proyecto_id, tipo, origen, titulo, url, archivo_nombre) VALUES (?, 'archivo', 'cliente', ?, ?, ?)")
               ->execute([$pid, $nombreOriginal, $url, $nombreOriginal]);
            $avisar("El cliente subió «{$nombreOriginal}» en «{$proyNombre}»");
            echo json_encode(['ok' => true]);
            exit;
        }

        $b = json_decode(file_get_contents('php://input'), true) ?: [];
        $accion = $b['accion'] ?? '';

        // b) Aprobar una fase en revisión → Completada + fecha.
        if ($accion === 'aprobar_fase') {
            $faseId = (int)($b['fase_id'] ?? 0);
            $upd = $db->prepare("UPDATE proyecto_fases SET estado='Completada', aprobada_at=NOW()
                                 WHERE id=? AND proyecto_id=? AND estado='En revisión'");
            $upd->execute([$faseId, $pid]);
            if ($upd->rowCount() === 0) { http_response_code(409); echo json_encode(['ok'=>false,'error'=>'La fase no está disponible para aprobar']); exit; }
            $fn = $db->prepare("SELECT nombre FROM proyecto_fases WHERE id = ?");
            $fn->execute([$faseId]);
            $avisar("El cliente aprobó la fase «" . ($fn->fetchColumn() ?: '') . "» de «{$proyNombre}»", 'success');
            echo json_encode(['ok' => true]);
            exit;
        }

        // c) Comentar o pedir cambios (con fase opcional). Registra en el hilo.
        if ($accion === 'comentar' || $accion === 'pedir_cambios') {
            $texto = trim((string)($b['texto'] ?? ''));
            if ($texto === '') { http_response_code(422); echo json_encode(['ok'=>false,'error'=>'Escribe un comentario']); exit; }
            if (mb_strlen($texto) > 5000) { http_response_code(422); echo json_encode(['ok'=>false,'error'=>'El comentario es muy largo (máx. 5.000 caracteres)']); exit; }
            $faseId = !empty($b['fase_id']) ? (int)$b['fase_id'] : null;
            $db->prepare("INSERT INTO proyecto_comentarios (proyecto_id, fase_id, autor, autor_nombre, texto) VALUES (?, ?, 'cliente', ?, ?)")
               ->execute([$pid, $faseId, $proy['cliente_nombre'] ?: 'Cliente', $texto]);
            // Pedir cambios sobre una fase en revisión → vuelve a "En curso".
            if ($accion === 'pedir_cambios' && $faseId) {
                $db->prepare("UPDATE proyecto_fases SET estado='En curso' WHERE id=? AND proyecto_id=? AND estado='En revisión'")
                   ->execute([$faseId, $pid]);
            }
            $avisar($accion === 'pedir_cambios'
                ? "El cliente pidió cambios en «{$proyNombre}»"
                : "Nuevo comentario del cliente en «{$proyNombre}»", $accion === 'pedir_cambios' ? 'warning' : 'info');
            echo json_encode(['ok' => true]);
            exit;
        }

        // e) Firmar el contrato (firma electrónica simple: nombre + aceptación).
        //    Guarda copia congelada del texto, nombre, IP y fecha como rastro.
        if ($accion === 'firmar_contrato') {
            $nombre = trim((string)($b['nombre'] ?? ''));
            $rut = trim((string)($b['rut'] ?? ''));
            $acepta = !empty($b['acepta']);
            if ($nombre === '' || !$acepta) {
                http_response_code(422); echo json_encode(['ok'=>false,'error'=>'Escribe tu nombre y acepta los términos']); exit;
            }
            $c = $db->prepare("SELECT id, contenido, requiere_firma, firmado_at FROM proyecto_contratos WHERE proyecto_id = ?");
            $c->execute([$pid]);
            $contrato = $c->fetch(PDO::FETCH_ASSOC);
            if (!$contrato || !$contrato['requiere_firma']) { http_response_code(409); echo json_encode(['ok'=>false,'error'=>'Este proyecto no tiene un contrato para firmar']); exit; }
            if ($contrato['firmado_at']) { http_response_code(409); echo json_encode(['ok'=>false,'error'=>'El contrato ya fue firmado']); exit; }
            $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
            $db->prepare("UPDATE proyecto_contratos
                          SET firmado_at=NOW(), firma_nombre=?, firma_rut=?, firma_ip=?, contenido_firmado=?
                          WHERE proyecto_id=? AND firmado_at IS NULL")
               ->execute([mb_substr($nombre,0,200), mb_substr($rut,0,20), $ip, $contrato['contenido'], $pid]);
            $avisar("{$nombre} firmó el contrato de «{$proyNombre}»", 'success');
            echo json_encode(['ok' => true]);
            exit;
        }

        // f) Acta de entrega: confirmar la recepción u observar.
        if ($accion === 'confirmar_entrega' || $accion === 'observar_entrega') {
            if (empty($proy['fecha_entrega_real'])) { http_response_code(409); echo json_encode(['ok'=>false,'error'=>'El proyecto aún no se entrega']); exit; }
            if (!empty($proy['entrega_confirmada_at'])) { http_response_code(409); echo json_encode(['ok'=>false,'error'=>'La entrega ya fue confirmada']); exit; }
            if ($accion === 'confirmar_entrega') {
                $nombre = mb_substr(trim((string)($b['nombre'] ?? '')), 0, 150);
                if ($nombre === '') { http_response_code(422); echo json_encode(['ok'=>false,'error'=>'Escribe tu nombre']); exit; }
                $db->prepare("UPDATE proyectos SET entrega_confirmada_at = NOW(), entrega_confirmada_nombre = ?, entrega_confirmada_ip = ?,
                                     entrega_observacion_at = NULL WHERE id = ? AND entrega_confirmada_at IS NULL")
                   ->execute([$nombre, $_SERVER['REMOTE_ADDR'] ?? null, $pid]);
                $avisar("{$nombre} confirmó la recepción de «{$proyNombre}»", 'success');
            } else {
                $texto = trim((string)($b['texto'] ?? ''));
                if ($texto === '') { http_response_code(422); echo json_encode(['ok'=>false,'error'=>'Cuéntanos qué falta o qué hay que corregir']); exit; }
                $texto = mb_substr($texto, 0, 5000);
                $db->prepare("UPDATE proyectos SET entrega_observacion = ?, entrega_observacion_at = NOW() WHERE id = ?")->execute([$texto, $pid]);
                // También queda en el hilo de comentarios del proyecto.
                $db->prepare("INSERT INTO proyecto_comentarios (proyecto_id, autor, autor_nombre, texto) VALUES (?, 'cliente', ?, ?)")
                   ->execute([$pid, $proy['cliente_nombre'] ?: 'Cliente', "Observaciones a la entrega: " . $texto]);
                $avisar("El cliente dejó observaciones a la entrega de «{$proyNombre}»", 'warning');
            }
            echo json_encode(['ok' => true]);
            exit;
        }

        // d) Responder una pregunta de briefing (texto / textarea). upsert.
        if ($accion === 'responder_briefing') {
            $preguntaId = (int)($b['pregunta_id'] ?? 0);
            $valor = mb_substr(trim((string)($b['valor'] ?? '')), 0, 10000);
            if (!$preguntaId || !preguntaPerteneceAlProyecto($db, $preguntaId, $pid)) {
                http_response_code(422); echo json_encode(['ok'=>false,'error'=>'Pregunta inválida']); exit;
            }
            $db->prepare("INSERT INTO briefing_respuestas (proyecto_id, pregunta_id, valor)
                          VALUES (?,?,?)
                          ON DUPLICATE KEY UPDATE valor=VALUES(valor)")
               ->execute([$pid, $preguntaId, $valor]);
            echo json_encode(['ok' => true]);
            exit;
        }

        http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Acción inválida']); exit;
    }

    // Fases del proyecto (avance macro)
    $fases = $db->prepare("SELECT id, nombre, estado, orden, aprobada_at FROM proyecto_fases WHERE proyecto_id = ? ORDER BY orden ASC, id ASC");
    $fases->execute([$pid]);
    $fasesRows = array_map(fn($f) => [
        'id' => (int)$f['id'], 'nombre' => $f['nombre'], 'estado' => $f['estado'],
        'orden' => (int)$f['orden'], 'aprobada_at' => $f['aprobada_at'],
    ], $fases->fetchAll(PDO::FETCH_ASSOC));

    // Tareas: el cliente NO ve la lista (son trabajo interno, con estados
    // como «QA interna»); solo cuántas van listas por fase.
    $tc = $db->prepare("SELECT fase_id, COUNT(*) AS total, SUM(estado = 'Aprobada') AS listas
                        FROM proyecto_tareas WHERE proyecto_id = ? AND fase_id IS NOT NULL GROUP BY fase_id");
    $tc->execute([$pid]);
    $avanceFase = [];
    foreach ($tc->fetchAll(PDO::FETCH_ASSOC) as $t) $avanceFase[(int)$t['fase_id']] = [(int)$t['total'], (int)$t['listas']];
    foreach ($fasesRows as &$fr) {
        $fr['tareas_total']  = $avanceFase[$fr['id']][0] ?? 0;
        $fr['tareas_listas'] = $avanceFase[$fr['id']][1] ?? 0;
    }
    unset($fr);

    // Hilo de comentarios (cliente ↔ agencia)
    $com = $db->prepare("SELECT id, autor, autor_nombre, texto, created_at FROM proyecto_comentarios WHERE proyecto_id = ? AND interno = 0 ORDER BY created_at ASC, id ASC");
    $com->execute([$pid]);
    $comentarios = array_map(fn($c) => [
        'id' => (int)$c['id'], 'autor' => $c['autor'], 'autor_nombre' => $c['autor_nombre'],
        'texto' => $c['texto'], 'created_at' => $c['created_at'],
    ], $com->fetchAll(PDO::FETCH_ASSOC));

    // Archivos y enlaces compartidos: los que subió el cliente + los que la
    // agencia marcó como visibles. Los internos de la agencia NO salen.
    $adj = $db->prepare("SELECT id, tipo, titulo, url, origen, archivo_nombre FROM proyecto_adjuntos
                         WHERE proyecto_id = ? AND (origen = 'cliente' OR visible_cliente = 1)
                         ORDER BY id DESC");
    $adj->execute([$pid]);
    $archivos = array_map(fn($a) => [
        'id' => (int)$a['id'], 'tipo' => $a['tipo'], 'titulo' => $a['titulo'], 'url' => $a['url'], 'origen' => $a['origen'],
    ], $adj->fetchAll(PDO::FETCH_ASSOC));

    // Briefing del proyecto (preguntas de la plantilla asignada + respuestas)
    $briefing = null;
    if (!empty($proy['briefing_tipo_id'])) {
        $tipoId = (int)$proy['briefing_tipo_id'];
        $tq = $db->prepare("SELECT nombre FROM briefing_tipos WHERE id = ?");
        $tq->execute([$tipoId]);
        $tipoNombre = $tq->fetchColumn();
        $pq = $db->prepare("SELECT id, pregunta, formato, orden FROM briefing_preguntas
                            WHERE tipo_id = ? AND activa = 1 ORDER BY orden ASC, id ASC");
        $pq->execute([$tipoId]);
        $preguntas = array_map(fn($q) => [
            'id' => (int)$q['id'], 'pregunta' => $q['pregunta'], 'formato' => $q['formato'],
        ], $pq->fetchAll(PDO::FETCH_ASSOC));
        $rq = $db->prepare("SELECT pregunta_id, valor, archivo_url, archivo_nombre FROM briefing_respuestas WHERE proyecto_id = ?");
        $rq->execute([$pid]);
        $respuestas = [];
        foreach ($rq->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $respuestas[(int)$r['pregunta_id']] = [
                'valor' => $r['valor'], 'archivo_url' => $r['archivo_url'], 'archivo_nombre' => $r['archivo_nombre'],
            ];
        }
        if ($preguntas) {
            $briefing = ['tipo_nombre' => $tipoNombre, 'preguntas' => $preguntas, 'respuestas' => $respuestas];
        }
    }

    // Contrato del proyecto (para firmar en el portal)
    $contrato = null;
    $cq = $db->prepare("SELECT titulo, contenido, requiere_firma, firmado_at, firma_nombre, contenido_firmado
                        FROM proyecto_contratos WHERE proyecto_id = ?");
    $cq->execute([$pid]);
    $cr = $cq->fetch(PDO::FETCH_ASSOC);
    if ($cr && ($cr['requiere_firma'] || $cr['firmado_at'])) {
        $firmado = !empty($cr['firmado_at']);
        $contrato = [
            'titulo' => $cr['titulo'],
            // Una vez firmado se muestra la copia congelada (lo que realmente firmó).
            'contenido' => $firmado ? ($cr['contenido_firmado'] ?: $cr['contenido']) : $cr['contenido'],
            'firmado' => $firmado,
            'firma_nombre' => $cr['firma_nombre'],
            'firmado_at' => $cr['firmado_at'],
        ];
    }

    // Marca de la agencia para la cabecera del portal (solo nombre y logo).
    $cfg = $db->query("SELECT clave, valor FROM configuracion WHERE clave IN ('nombre_empresa','logo_url','color_primario')")
              ->fetchAll(PDO::FETCH_KEY_PAIR);

    echo json_encode(['ok' => true, 'data' => [
        'agencia' => [
            'nombre' => $cfg['nombre_empresa'] ?? '',
            'logo_url' => $cfg['logo_url'] ?? '',
            'color' => preg_match('/^#[0-9a-fA-F]{6}$/', (string)($cfg['color_primario'] ?? '')) ? $cfg['color_primario'] : null,
        ],
        'proyecto' => [
            'nombre' => $proy['nombre'],
            'descripcion' => $proy['descripcion'],
            'estado' => $proy['estado'],
            'etapa_nombre' => $proy['etapa_nombre'],
            'etapa_color' => $proy['etapa_color'],
            'cliente_nombre' => $proy['cliente_nombre'],
        ],
        'fases' => $fasesRows,
        'comentarios' => $comentarios,
        'archivos' => $archivos,
        'briefing' => $briefing,
        'contrato' => $contrato,
        // Acta de entrega (null si aún no se entrega)
        'entrega' => entResumen($proy, entPlazoDias($db)),
    ]], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    if (function_exists('logError')) logError('Error en portal.php', ['message' => $e->getMessage(), 'line' => $e->getLine()]);
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error en el portal']);
}
