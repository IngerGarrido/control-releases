-- Aprobación de fases por el cliente (portal).
--
-- Flujo: la agencia marca una fase como "En revisión" → el cliente la ve en el
-- portal con un botón "Aprobar" → al aprobar queda Completada con su fecha.
-- aprobada_at: cuándo el cliente aprobó (NULL = no aprobada).
-- Idempotente.

-- UTF-8 en la conexión para que el valor de enum con acento (En revisión) se
-- guarde bien sin importar cómo se importe el .sql.
SET NAMES utf8mb4;

ALTER TABLE `proyecto_fases`
  MODIFY COLUMN `estado` ENUM('Pendiente','En curso','En revisión','Completada') NOT NULL DEFAULT 'Pendiente';

SET @has_col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'proyecto_fases' AND COLUMN_NAME = 'aprobada_at');
SET @sql := IF(@has_col = 0,
  'ALTER TABLE `proyecto_fases` ADD COLUMN `aprobada_at` DATETIME NULL DEFAULT NULL AFTER `estado`',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
