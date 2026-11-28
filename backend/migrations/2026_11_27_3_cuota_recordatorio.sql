-- Recordatorio de cobro (2026-11-27). Idempotente.
-- pago_cuotas.recordado_at / recordatorios: cuándo se le recordó al cliente un
-- documento vencido y cuántas veces. Pagos muestra «recordado el …» para no
-- escribirle dos veces. Solo agrega columnas: no toca datos existentes.

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pago_cuotas' AND COLUMN_NAME = 'recordado_at');
SET @sql := IF(@c = 0, 'ALTER TABLE `pago_cuotas` ADD COLUMN `recordado_at` DATETIME DEFAULT NULL', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pago_cuotas' AND COLUMN_NAME = 'recordatorios');
SET @sql := IF(@c = 0, 'ALTER TABLE `pago_cuotas` ADD COLUMN `recordatorios` INT NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
