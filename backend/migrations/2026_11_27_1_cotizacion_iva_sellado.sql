-- IVA sellado al aprobar: una cotización aprobada por el cliente conserva el %
-- de IVA con que se aprobó aunque después cambie Configuración → Finanzas.
-- Idempotente.

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cotizaciones' AND COLUMN_NAME = 'aprobada_iva_pct');
SET @sql := IF(@c = 0, 'ALTER TABLE `cotizaciones` ADD COLUMN `aprobada_iva_pct` DECIMAL(5,2) NULL DEFAULT NULL', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
