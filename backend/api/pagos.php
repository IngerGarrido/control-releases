<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

try {
    require_once __DIR__ . '/../config.php';
    cors();
    $db = getDB();
    $user = requireAuth();
    $method = $_SERVER['REQUEST_METHOD'];
    // Soporte para method override via query string (POST con _method=PUT)
    if ($method === 'POST' && isset($_GET['_method'])) {
        $method = strtoupper($_GET['_method']);
    }

    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
    $db->exec("SET NAMES utf8mb4");

    if ($user['rol'] !== 'admin') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Acceso denegado']);
        exit;
    }

    $id_url = $_GET['id'] ?? null;
    if (!$id_url) {
        $path = explode('/', parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
        $last = end($path);
        $id_url = is_numeric($last) ? (int)$last : null;
    }

    // Detectar acción especial
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    $esRestore = strpos($uri, 'restore') !== false || isset($_GET['restore']);
    $esHard = isset($_GET['hard']) && $_GET['hard'] == '1';
    $esPapelera = isset($_GET['papelera']) && $_GET['papelera'] == '1';

    $limpiar = function($str) { return trim(str_replace("\0", "", (string)($str ?? ''))); };
    
    $cleanInt = function($valor) {
        if (is_null($valor) || $valor === '') return 0;
        $v = str_replace(['.', ',', '$', ' '], '', (string)$valor);
        return (int)round((float)$v);
    };

    // % de IVA configurable (default 19%).
    $ivaPct = (float)($db->query("SELECT valor FROM configuracion WHERE clave='iva_pct'")->fetchColumn() ?: 19);

    // Cálculos financieros puros (neto/IVA/total y abonos) viven en finance.php
    // y están cubiertos por tests. Acá solo los envolvemos con el % de IVA.
    require_once __DIR__ . '/../finance.php';
    require_once __DIR__ . '/../lib/saldos.php';
    $calcDoc = fn($cuota) => finance_calc_doc((array)$cuota, $ivaPct);
    $procesarAbonos = fn($cuota) => finance_procesar_abonos((array)$cuota);

    // Cliente del pago = cliente de su proyecto (si tiene). Antes se guardaba
    // aparte y se desincronizaba: el pago aparecía en la ficha de otro cliente.
    $clienteDelPago = function (array $b) use ($db): int {
        if (!empty($b['proyecto_id'])) {
            $st = $db->prepare("SELECT cliente_id FROM proyectos WHERE id = ?");
            $st->execute([(int)$b['proyecto_id']]);
            $cid = (int)$st->fetchColumn();
            if ($cid > 0) return $cid;
        }
        return (int)($b['cliente_id'] ?? 0);
    };
    // Total del pago = suma de sus cuotas (lib/saldos.php: cuotaMonto). Antes
    // se pedía un "Total acordado" aparte y unas pantallas usaban uno y otras
    // el otro. Se recalcula al guardar.
    $recalcularTotal = function (int $pagoId) use ($db): void {
        $db->prepare("UPDATE pagos SET monto_total = (
                          SELECT COALESCE(SUM(GREATEST(COALESCE(boleta_monto,0), COALESCE(pago_monto,0))), 0)
                          FROM pago_cuotas WHERE pago_id = ?)
                      WHERE id = ?")->execute([$pagoId, $pagoId]);
    };

    // --- LISTAR (GET) ---
    // Soporta paginación: ?page=X&per_page=Y
    if ($method === 'GET') {
        $where = $esPapelera ? "WHERE p.deleted_at IS NOT NULL" : "WHERE p.deleted_at IS NULL";

        // Búsqueda server-side
        $q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
        $params = [];
        if ($q !== '') {
            $where .= " AND (p.descripcion LIKE ? OR c.nombre LIKE ? OR pr.nombre LIKE ?)";
            $like = '%' . escapeLike($q) . '%';
            $params[] = $like; $params[] = $like; $params[] = $like;
        }

        // Filtro por estado de cobro (server-side). "estado" derivado:
        // comparamos monto_total contra lo efectivamente pagado en cuotas.
        $estado = isset($_GET['estado']) ? trim((string)$_GET['estado']) : '';
        // Cobrado real = suma de pago_monto en cuotas Pagado o Parcial (lo recibido)
        $cobradoExpr = "COALESCE((SELECT SUM(pc.pago_monto) FROM pago_cuotas pc WHERE pc.pago_id = p.id AND pc.estado IN ('Pagado','Parcial')), 0)";
        // Hay al menos una cuota Parcial pendiente de cobrar
        $hayParcialExpr = "EXISTS(SELECT 1 FROM pago_cuotas pc WHERE pc.pago_id = p.id AND pc.estado = 'Parcial')";
        if ($estado === 'Pagado') {
            $where .= " AND p.monto_total <= {$cobradoExpr}";
        } elseif ($estado === 'Pendiente') {
            // Pendiente "puro": falta cobrar y no hay nada parcial
            $where .= " AND p.monto_total > {$cobradoExpr} AND NOT {$hayParcialExpr}";
        } elseif ($estado === 'Parcial') {
            // Parcial: tiene al menos una cuota Parcial y aún falta cobrar
            $where .= " AND p.monto_total > {$cobradoExpr} AND {$hayParcialExpr}";
        }

        // Situación de cobro de cada pago desde la única fuente (lib/saldos.php):
        // vencido > por cobrar (emitido) > por emitir > pagado. La usan las
        // pestañas de Pagos y el «siguiente paso» de cada fila.
        $sit = [];
        $todasCuotas = saldosCuotas($db);
        foreach ($todasCuotas as $cq) {
            $k = $cq['pago_id'];
            $sit[$k] ??= ['vencidas' => 0, 'por_cobrar' => 0, 'por_emitir' => 0];
            if ($cq['saldo'] <= 0) continue;
            if ($cq['emitida']) { $sit[$k]['por_cobrar'] += $cq['saldo']; if ($cq['vencida']) $sit[$k]['vencidas']++; }
            else $sit[$k]['por_emitir'] += $cq['saldo'];
        }
        $situacionDe = function (array $s): string {
            if ($s['vencidas'] > 0) return 'vencido';
            if ($s['por_cobrar'] > 0) return 'por_cobrar';
            if ($s['por_emitir'] > 0) return 'por_emitir';
            return 'pagado';
        };
        $conteoSit = ['vencido' => 0, 'por_cobrar' => 0, 'por_emitir' => 0, 'pagado' => 0];
        $idsPorSit = ['vencido' => [], 'por_cobrar' => [], 'por_emitir' => [], 'pagado' => []];
        foreach ($sit as $pagoIdSit => $s) { $x = $situacionDe($s); $conteoSit[$x]++; $idsPorSit[$x][] = (int)$pagoIdSit; }
        $mapaFiltro = ['Vencidos' => 'vencido', 'Por cobrar' => 'por_cobrar', 'Por emitir' => 'por_emitir', 'Pagados' => 'pagado'];
        if (isset($mapaFiltro[$estado])) {
            $idsF = $idsPorSit[$mapaFiltro[$estado]];
            $where .= $idsF ? " AND p.id IN (" . implode(',', $idsF) . ")" : " AND 0";
        }
        $resumenSaldos = saldoResumen($todasCuotas);

        // Pantalla de Pagos: lo urgente primero (vencido → por cobrar → por
        // emitir → pagado) y, en «Todos», los pagados fuera salvo que se pidan
        // (son historial). Se ordena ANTES de paginar. Los ids vienen de
        // saldosCuotas(): son enteros, se pueden incrustar.
        $porUrgencia    = ($_GET['orden'] ?? '') === 'urgencia';
        $ocultarPagados = !empty($_GET['ocultar_pagados']) && !isset($mapaFiltro[$estado]);
        $inIds = fn(array $ids) => $ids ? implode(',', $ids) : '0';
        if ($ocultarPagados) $where .= " AND p.id NOT IN (" . $inIds($idsPorSit['pagado']) . ")";
        $orderBy = $porUrgencia
            ? "CASE WHEN p.id IN (" . $inIds($idsPorSit['vencido']) . ") THEN 0
                    WHEN p.id IN (" . $inIds($idsPorSit['por_cobrar']) . ") THEN 1
                    WHEN p.id IN (" . $inIds($idsPorSit['pagado']) . ") THEN 3
                    ELSE 2 END, p.id DESC"
            : "p.id DESC";

        // Cuota vencida más antigua de cada pago y cuándo se recordó: para el
        // botón «Recordar cobro» de la fila.
        $vencidaDe = [];
        foreach ($todasCuotas as $cq) {
            if ($cq['vencida'] && !isset($vencidaDe[$cq['pago_id']])) $vencidaDe[$cq['pago_id']] = (int)$cq['cuota_id'];
        }

        // Parámetros de paginación (con cap 100)
        $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : null;
        $per_page = isset($_GET['per_page']) ? max(1, min(1000, (int)$_GET['per_page'])) : null;

        // Contar total (con mismos JOINs por filtro server-side)
        $countSql = "SELECT COUNT(*) FROM pagos p INNER JOIN clientes c ON p.cliente_id = c.id LEFT JOIN proyectos pr ON p.proyecto_id = pr.id {$where}";
        $countStmt = $db->prepare($countSql);
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $sql = "SELECT p.*,
                       c.nombre as cliente_nombre,
                       pr.nombre as proyecto_nombre,
                       s.nombre as sitio_nombre
                FROM pagos p
                INNER JOIN clientes c ON p.cliente_id = c.id
                LEFT JOIN proyectos pr ON p.proyecto_id = pr.id
                LEFT JOIN sitios s ON pr.sitio_id = s.id
                {$where}
                ORDER BY {$orderBy}";

        // Aplicar paginación
        if ($page && $per_page) {
            $offset = ($page - 1) * $per_page;
            $sql .= " LIMIT {$per_page} OFFSET {$offset}";
        }

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $pagos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // OPTIMIZACIÓN N+1: cargar TODAS las cuotas en una sola query
        $cuotasPorPago = [];
        $cuotaIds = [];
        if (count($pagos) > 0) {
            $pagoIds = array_map(fn($p) => (int)$p['id'], $pagos);
            $placeholders = implode(',', array_fill(0, count($pagoIds), '?'));
            $sqlC = "SELECT * FROM pago_cuotas WHERE pago_id IN ($placeholders) ORDER BY pago_id ASC, numero_cuota ASC";
            $stmtC = $db->prepare($sqlC);
            $stmtC->execute($pagoIds);
            while ($c = $stmtC->fetch(PDO::FETCH_ASSOC)) {
                $cuotasPorPago[(int)$c['pago_id']][] = $c;
                $cuotaIds[] = (int)$c['id'];
            }
        }

        // Cargar TODOS los abonos de esas cuotas en una sola query (N+1 evitado)
        $abonosPorCuota = [];
        if (count($cuotaIds) > 0) {
            $ph = implode(',', array_fill(0, count($cuotaIds), '?'));
            $sqlA = "SELECT id, cuota_id, monto, fecha FROM pago_abonos WHERE cuota_id IN ($ph) ORDER BY fecha ASC, id ASC";
            $stmtAb = $db->prepare($sqlA);
            $stmtAb->execute($cuotaIds);
            while ($a = $stmtAb->fetch(PDO::FETCH_ASSOC)) {
                $abonosPorCuota[(int)$a['cuota_id']][] = [
                    'id'    => (int)$a['id'],
                    'monto' => (int)$a['monto'],
                    'fecha' => $a['fecha'],
                ];
            }
        }

        $res = [];
        foreach ($pagos as $p) {
            $cuotas = $cuotasPorPago[(int)$p['id']] ?? [];
            foreach ($cuotas as &$cRef) {
                $cRef['abonos'] = $abonosPorCuota[(int)$cRef['id']] ?? [];
            }
            unset($cRef);
            $suma_pagada = 0;
            foreach ($cuotas as $c) {
                // Suma incluye Pagado y Parcial (en Parcial cobramos menos del total)
                if ($c['estado'] === 'Pagado' || $c['estado'] === 'Parcial') {
                    $suma_pagada += (int)($c['pago_monto'] ?? 0);
                }
            }
            $p['total_pagado'] = $suma_pagada;
            $p['cuotas'] = $cuotas;
            // Situación de cobro (misma regla que las pestañas). Un pago sin
            // cuotas vivas no está en $sit: si tiene monto, falta emitirlo.
            $p['situacion'] = isset($sit[(int)$p['id']]) ? $situacionDe($sit[(int)$p['id']])
                            : ((int)$p['monto_total'] > 0 ? 'por_emitir' : 'pagado');
            $p['cuota_vencida_id'] = $vencidaDe[(int)$p['id']] ?? null;
            $p['recordado_at'] = null;
            foreach ($cuotas as $c) {
                if ((int)$c['id'] === $p['cuota_vencida_id']) $p['recordado_at'] = $c['recordado_at'] ?? null;
            }
            $res[] = $p;
        }
        
        // Responder con o sin paginación
        if ($page && $per_page) {
            echo json_encode([
                'ok' => true, 
                'data' => $res,
                'situaciones' => array_map(fn($s) => $s + ['situacion' => $situacionDe($s)], $sit),
                'conteo' => ['Vencidos' => $conteoSit['vencido'], 'Por cobrar' => $conteoSit['por_cobrar'], 'Por emitir' => $conteoSit['por_emitir'], 'Pagados' => $conteoSit['pagado']],
                'resumen' => ['por_cobrar' => $resumenSaldos['por_cobrar'], 'por_emitir' => $resumenSaldos['por_emitir'],
                              'vencido' => $resumenSaldos['vencido'], 'vencidas' => $resumenSaldos['vencidas'], 'cobrado' => $resumenSaldos['cobrado'],
                              // «Por cobrar al día» = emitido y dentro de plazo (sin lo vencido).
                              'al_dia' => max(0, $resumenSaldos['por_cobrar'] - $resumenSaldos['vencido']),
                              'plazo_dias' => cobroPlazoDias($db)],
                'pagination' => [
                    'page' => $page,
                    'per_page' => $per_page,
                    'total' => $total,
                    'total_pages' => (int)ceil($total / $per_page)
                ]
            ]);
        } else {
            echo json_encode(['ok' => true, 'data' => $res]);
        }
        exit;
    }

    // --- RESTAURAR PAGO ---
    // DEBE IR ANTES de POST/CREAR para que no se confunda
    if ($method === 'POST' && $esRestore && $id_url) {
        $id = (int)$id_url;
        $stmt = $db->prepare("UPDATE pagos SET deleted_at = NULL WHERE id = ? AND deleted_at IS NOT NULL");
        $stmt->execute([$id]);
        
        if ($stmt->rowCount() > 0) {
            audit($db, $user['id'], 'RESTORE', 'pagos', "Restauró pago #{$id}", $id);
            echo json_encode(['ok' => true, 'message' => 'Pago restaurado']);
        } else {
            echo json_encode(['ok' => false, 'error' => 'Pago no encontrado o no estaba eliminado']);
        }
        exit;
    }

    // --- CREAR (POST) ---
    if ($method === 'POST') {
        $b = json_decode(file_get_contents("php://input"), true);
        if (!$b) throw new Exception("No se recibieron datos");

        // Validación mínima: cliente_id requerido y numérico
        if (empty($b['cliente_id']) || !is_numeric($b['cliente_id'])) {
            err('El campo cliente_id es requerido y debe ser numérico.');
        }

        $b['cliente_id'] = $clienteDelPago($b);
        $db->beginTransaction();

        // 1. Insertar en tabla maestra 'pagos'
        $sql = "INSERT INTO pagos (cliente_id, proyecto_id, descripcion, monto_total, tipo_pago) VALUES (?, ?, ?, ?, ?)";
        $stmt = $db->prepare($sql);
        $stmt->execute([
            (int)$b['cliente_id'],
            $b['proyecto_id'] ?: null,
            $limpiar($b['descripcion'] ?? ''),
            $cleanInt($b['monto_total'] ?? 0),
            $b['tipo_pago'] ?? 'boleta'
        ]);
        $pago_id = $db->lastInsertId();

        // 2. Insertar cuotas en 'pago_cuotas' + sus abonos
        if (isset($b['cuotas']) && is_array($b['cuotas'])) {
            $sqlCuota = "INSERT INTO pago_cuotas (
                            pago_id, numero_cuota, boleta_num, boleta_monto, neto, iva,
                            boleta_fecha, pago_monto, pago_fecha, estado, tipo_pago
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmtC = $db->prepare($sqlCuota);
            $stmtA = $db->prepare("INSERT INTO pago_abonos (cuota_id, monto, fecha) VALUES (?, ?, ?)");
            foreach ($b['cuotas'] as $index => $cuota) {
                $r = $procesarAbonos($cuota); // deriva suma/estado/fecha desde abonos
                $doc = $calcDoc($cuota);      // tipo + neto/iva + total del documento
                $stmtC->execute([
                    $pago_id,
                    $index + 1,
                    $limpiar($cuota['boleta_num'] ?? ''),
                    $doc['total'],
                    $doc['neto'],
                    $doc['iva'],
                    !empty($cuota['boleta_fecha']) ? $cuota['boleta_fecha'] : null,
                    $r['suma'],
                    $r['maxFecha'],
                    $r['estado'],
                    $doc['tipo']
                ]);
                $cuotaId = (int)$db->lastInsertId();
                foreach ($r['abonos'] as $a) {
                    $stmtA->execute([$cuotaId, $a['monto'], $a['fecha']]);
                }
            }
        }
        $recalcularTotal((int)$pago_id);
        $db->commit();
        
        // Auditoría
        $montoFmt = number_format($cleanInt($b['monto_total'] ?? 0), 0, ',', '.');
        audit($db, $user['id'], 'CREATE', 'pagos', "Creó pago #{$pago_id} - Cliente: {$b['cliente_id']} - Total: $ {$montoFmt}", $pago_id);
        
        echo json_encode(['ok' => true, 'id' => $pago_id]);
        exit;
    }

    // --- EDITAR (PUT) ---
    if ($method === 'PUT') {
        $b = json_decode(file_get_contents("php://input"), true);
        $target_id = $id_url ?? $b['id'] ?? null;

        if (!$target_id) throw new Exception("ID no proporcionado");
        if (empty($b['cliente_id']) || !is_numeric($b['cliente_id'])) {
            err('El campo cliente_id es requerido y debe ser numérico.');
        }

        $b['cliente_id'] = $clienteDelPago($b);
        $db->beginTransaction();

        // 1. Actualizar Pago Maestro
        $sql = "UPDATE pagos SET cliente_id = ?, proyecto_id = ?, descripcion = ?, monto_total = ?, tipo_pago = ? WHERE id = ? AND deleted_at IS NULL";
        $stmt = $db->prepare($sql);
        $stmt->execute([
            (int)$b['cliente_id'],
            $b['proyecto_id'] ?: null,
            $limpiar($b['descripcion'] ?? ''),
            $cleanInt($b['monto_total'] ?? 0),
            $b['tipo_pago'] ?? 'boleta',
            (int)$target_id
        ]);

        // 2. Manejar Cuotas (+ abonos). Estrategia para abonos: por cada cuota
        // se borran y reinsertan sus abonos desde el payload (siempre llegan
        // completos), evitando rastrear ids de abono individuales.
        if (isset($b['cuotas']) && is_array($b['cuotas'])) {
            $ids_enviados = [];
            $stmtA = $db->prepare("INSERT INTO pago_abonos (cuota_id, monto, fecha) VALUES (?, ?, ?)");
            $stmtDelA = $db->prepare("DELETE FROM pago_abonos WHERE cuota_id = ?");

            foreach ($b['cuotas'] as $index => $c) {
                $r = $procesarAbonos($c); // deriva suma/estado/fecha desde abonos
                $doc = $calcDoc($c);      // tipo + neto/iva + total del documento
                $boletaFecha = !empty($c['boleta_fecha']) ? $c['boleta_fecha'] : null;
                if (isset($c['id']) && is_numeric($c['id'])) {
                    // ACTUALIZAR EXISTENTE
                    $cuotaId = (int)$c['id'];
                    $ids_enviados[] = $cuotaId;
                    $sqlU = "UPDATE pago_cuotas SET
                                boleta_num = ?, boleta_fecha = ?, boleta_monto = ?, neto = ?, iva = ?,
                                pago_fecha = ?, pago_monto = ?, estado = ?, numero_cuota = ?, tipo_pago = ?
                             WHERE id = ?";
                    $stmtU = $db->prepare($sqlU);
                    $stmtU->execute([
                        $limpiar($c['boleta_num'] ?? ''),
                        $boletaFecha,
                        $doc['total'],
                        $doc['neto'],
                        $doc['iva'],
                        $r['maxFecha'],
                        $r['suma'],
                        $r['estado'],
                        $index + 1,
                        $doc['tipo'],
                        $cuotaId
                    ]);
                } else {
                    // INSERTAR NUEVA
                    $sqlI = "INSERT INTO pago_cuotas (
                                pago_id, numero_cuota, boleta_num, boleta_monto, neto, iva,
                                boleta_fecha, pago_fecha, pago_monto, estado, tipo_pago
                             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                    $stmtI = $db->prepare($sqlI);
                    $stmtI->execute([
                        (int)$target_id,
                        $index + 1,
                        $limpiar($c['boleta_num'] ?? ''),
                        $doc['total'],
                        $doc['neto'],
                        $doc['iva'],
                        $boletaFecha,
                        $r['maxFecha'],
                        $r['suma'],
                        $r['estado'],
                        $doc['tipo']
                    ]);
                    $cuotaId = (int)$db->lastInsertId();
                    $ids_enviados[] = $cuotaId;
                }
                // Reescribir abonos de esta cuota
                $stmtDelA->execute([$cuotaId]);
                foreach ($r['abonos'] as $a) {
                    $stmtA->execute([$cuotaId, $a['monto'], $a['fecha']]);
                }
            }

            // 3. ELIMINAR LAS CUOTAS QUE NO VINIERON
            if (count($ids_enviados) > 0) {
                $placeholders = implode(',', array_fill(0, count($ids_enviados), '?'));
                $sqlD = "DELETE FROM pago_cuotas WHERE pago_id = ? AND id NOT IN ($placeholders)";
                $paramsD = array_merge([(int)$target_id], $ids_enviados);
                $db->prepare($sqlD)->execute($paramsD);
            } else {
                $db->prepare("DELETE FROM pago_cuotas WHERE pago_id = ?")->execute([(int)$target_id]);
            }
        }
        // Solo si vinieron cuotas: un PUT sin cuotas no debe dejar el total en 0.
        if (isset($b['cuotas']) && is_array($b['cuotas'])) $recalcularTotal((int)$target_id);

        $db->commit();
        
        // Auditoría
        audit($db, $user['id'], 'UPDATE', 'pagos', "Editó pago #{$target_id}", $target_id);
        
        echo json_encode(['ok' => true]);
        exit;
    }

// --- ELIMINAR (DELETE) ---
    if ($method === 'DELETE') {
        try {
            $target_id = $id_url;
            if (!$target_id && isset($_SERVER['PATH_INFO'])) {
                $target_id = trim($_SERVER['PATH_INFO'], '/');
            }
            if (!$target_id) {
                throw new Exception("ID de pago no proporcionado");
            }

            $id = (int)$target_id;
            
            // Verificar que existe
            $info = $db->prepare("SELECT descripcion FROM pagos WHERE id = ?");
            $info->execute([$id]);
            $pago = $info->fetch();
            
            if (!$pago) {
                throw new Exception("Pago no encontrado");
            }
            
            if ($esHard) {
                // HARD DELETE
                $db->beginTransaction();
                $db->prepare("DELETE FROM pago_cuotas WHERE pago_id = ?")->execute([$id]);
                $db->prepare("DELETE FROM pagos WHERE id = ?")->execute([$id]);
                $db->commit();
                
                audit($db, $user['id'], 'HARD_DELETE', 'pagos', "Eliminó PERMANENTEMENTE pago #{$id}", $id);
                echo json_encode(['ok' => true, 'message' => 'Pago eliminado permanentemente']);
            } else {
                // SOFT DELETE
                $db->prepare("UPDATE pagos SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL")->execute([$id]);
                
                audit($db, $user['id'], 'SOFT_DELETE', 'pagos', "Envió a papelera pago #{$id}: " . ($pago['descripcion'] ?? ''), $id);
                echo json_encode(['ok' => true, 'message' => 'Pago enviado a papelera']);
            }
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            errSafe($e, 500);
        }
        exit;
    }

} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    if (function_exists('logError')) {
        logError('Error en pagos.php', ['message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
    }
    errSafe($e, 500);
}