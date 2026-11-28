-- Plazos por fase: cada fase puede tener su fecha objetivo (deadline interno),
-- para ver de un vistazo qué etapa está por vencer o atrasada dentro del
-- proyecto. Complementa la entrega final (a nivel de proyecto). Idempotente.

SET NAMES utf8mb4;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'proyecto_fases' AND COLUMN_NAME = 'fecha_objetivo');
SET @sql := IF(@col = 0,
  'ALTER TABLE `proyecto_fases` ADD COLUMN `fecha_objetivo` DATE NULL AFTER `estado`',
  'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
