#!/usr/bin/env php
<?php
/**
 * Procesos automáticos diarios (lib/procesos.php → procesosDiarios):
 * mantenciones y renovaciones de sitios con cobro, cotizaciones vencidas → Expirada, avisos de cuotas vencidas, de respaldo y de
 * links del portal vencidos, y papelera de más de 30 días.
 *
 * Uso:
 *   php procesos_diarios.php                    # con la fecha de hoy
 *   php procesos_diarios.php --hoy=2026-12-01   # forzar la fecha (SOLO con datos de prueba)
 *
 * Cron (una vez al día, 08:00):
 *   0 8 * * * /usr/bin/php /ruta/backend/procesos_diarios.php >> /ruta/backend/logs/procesos.log 2>&1
 *
 * Si el hosting no tiene cron, igual corre una vez al día al abrir el Panel.
 * Es idempotente.
 */
require_once __DIR__ . '/lib/procesos.php';

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Solo CLI'); }

$hoy = null;
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--hoy=(\d{4}-\d{2}-\d{2})$/', $a, $m)) $hoy = $m[1];
}
$r = procesosDiarios(getDB(), $hoy);
$t = '[' . date('Y-m-d H:i:s') . ']' . ($hoy ? " (hoy forzado: $hoy)" : '');
echo "$t Mantenciones generadas: {$r['mantenciones']} · Renovaciones cobradas: {$r['renovaciones']}\n";
echo "$t Actas recibidas por plazo: " . ($r['entregas_por_plazo'] ? implode(', ', $r['entregas_por_plazo']) : 'ninguna') . " · Garantías por terminar: " . ($r['garantias'] ? implode(', ', $r['garantias']) : 'ninguna') . "\n";
echo "$t Cotizaciones expiradas: " . ($r['cotizaciones_expiradas'] ? implode(', ', $r['cotizaciones_expiradas']) : 'ninguna') . "\n";
echo "$t Papelera: {$r['papelera_vaciada']} borrados para siempre\n";
foreach ($r['errores'] as $e) echo "$t ERROR $e\n";
