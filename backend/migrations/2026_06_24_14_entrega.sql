-- Gestión de plazos: fecha de entrega REAL del proyecto (cuándo se entregó de
-- verdad), para compararla con fecha_termino (la comprometida) y saber si se
-- cumplió el plazo. El modelo de la agencia es por objetivos/fecha, no por horas.
-- Idempotente.

SET NAMES utf8mb4;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'proyectos' AND COLUMN_NAME = 'fecha_entrega_real');
SET @sql := IF(@col = 0,
  'ALTER TABLE `proyectos` ADD COLUMN `fecha_entrega_real` DATE NULL AFTER `fecha_termino`',
  'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
