<?php
/**
 * Datos de ejemplo (Configuración → Sistema). Solo admin.
 * GET    → { hay: n }       cuántos clientes de ejemplo hay
 * POST   → carga los 5 clientes de ejemplo (lib/ejemplos.php)
 * DELETE → los borra para siempre con todo lo suyo
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);

try {
    require_once __DIR__ . '/../../config.php';
    require_once __DIR__ . '/../../lib/ejemplos.php';
    cors();
    $db = getDB();
    $user = requireAuth();
    if ($user['rol'] !== 'admin') err('Solo administradores', 403);

    switch ($_SERVER['REQUEST_METHOD']) {
        case 'GET':
            ok(['hay' => ejemplosHay($db)]);
            break;
        case 'POST':
            if (ejemplosHay($db) > 0) err('Ya hay datos de ejemplo. Bórralos antes de volver a cargarlos.', 409);
            $r = ejemplosCargar($db, (int)$user['id']);
            // Las mantenciones/renovaciones de ejemplo también son «automáticas» (fuera del Tablero).
            $db->exec("UPDATE proyectos SET automatico = 1 WHERE automatico = 0 AND descripcion LIKE 'Generado automáticamente:%'");
            ok($r + ['mensaje' => 'Se cargaron 5 clientes de ejemplo con sus cotizaciones, proyectos, pagos y sitios.']);
            break;
        case 'DELETE':
            $n = ejemplosBorrar($db);
            audit($db, (int)$user['id'], 'DELETE', 'clientes', "Borró los datos de ejemplo ($n clientes)");
            ok(['borrados' => $n, 'mensaje' => $n ? "Se borraron $n clientes de ejemplo con todo lo suyo." : 'No había datos de ejemplo.']);
            break;
        default:
            err('Método no permitido', 405);
    }
} catch (Throwable $e) {
    errSafe($e);
}
