-- Cotizaciones afectas a IVA: una agencia cotiza con factura, así que el
-- cliente debe ver Neto + IVA + Total desde la cotización (antes el IVA
-- aparecía recién al convertirla en pago, y el total no cuadraba).
-- aplica_iva permite el caso excepcional sin IVA (boleta de honorarios).
-- Idempotente.

SET NAMES utf8mb4;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cotizaciones' AND COLUMN_NAME = 'aplica_iva');
SET @sql := IF(@col = 0,
  'ALTER TABLE `cotizaciones` ADD COLUMN `aplica_iva` TINYINT(1) NOT NULL DEFAULT 1 AFTER `descuento_global`',
  'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
