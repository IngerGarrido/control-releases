-- Facturas: la agencia emite facturas afectas (IVA 19%). Antes el sistema
-- (freelance) solo manejaba boletas de honorarios (retención 15,25%).
--
-- Cada cuota/documento pasa a poder ser: factura | boleta | directo.
--  - factura: neto + iva = boleta_monto (total que se cobra). Default.
--  - boleta:  boleta_monto = bruto; retención se calcula aparte. neto/iva NULL.
--  - directo: boleta_monto = monto; sin documento. neto/iva NULL.
-- boleta_monto se mantiene como el TOTAL del documento (lo que se cobra), para
-- no romper dashboard/reportes/cobranza que ya lo usan.
-- Idempotente.

-- Ampliar enum tipo_pago para incluir 'factura' (default), preservando datos.
ALTER TABLE `pago_cuotas`
  MODIFY COLUMN `tipo_pago` ENUM('factura','boleta','directo') NOT NULL DEFAULT 'factura';
ALTER TABLE `pagos`
  MODIFY COLUMN `tipo_pago` ENUM('factura','boleta','directo') NOT NULL DEFAULT 'factura';

-- Desglose de la factura (informativo): neto + iva = boleta_monto.
SET @has_neto := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pago_cuotas' AND COLUMN_NAME = 'neto');
SET @sql := IF(@has_neto = 0,
  'ALTER TABLE `pago_cuotas` ADD COLUMN `neto` INT NULL DEFAULT NULL AFTER `boleta_monto`, ADD COLUMN `iva` INT NULL DEFAULT NULL AFTER `neto`',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- IVA configurable (porcentaje). 19% en Chile.
INSERT INTO `configuracion` (`clave`, `valor`)
SELECT 'iva_pct', '19'
WHERE NOT EXISTS (SELECT 1 FROM `configuracion` WHERE `clave` = 'iva_pct');
