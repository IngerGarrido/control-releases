-- Acta de entrega con garantía (adaptado de sistema-local «Entregas», 2026-11-20):
-- al marcar la entrega final, el cliente la confirma (o deja observaciones)
-- desde su portal. Sin respuesta en N días hábiles se da por recibida
-- (entrega_por_plazo). La garantía corre desde fecha_entrega_real por
-- garantia_meses, con aviso 15 días antes de terminar (garantia_aviso_at).
-- Idempotente.

SET NAMES utf8mb4;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'proyectos' AND COLUMN_NAME = 'garantia_meses');
SET @sql := IF(@c = 0, 'ALTER TABLE `proyectos` ADD COLUMN `garantia_meses` TINYINT UNSIGNED NULL DEFAULT NULL AFTER `fecha_entrega_real`', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'proyectos' AND COLUMN_NAME = 'entrega_confirmada_at');
SET @sql := IF(@c = 0, 'ALTER TABLE `proyectos` ADD COLUMN `entrega_confirmada_at` DATETIME NULL DEFAULT NULL AFTER `garantia_meses`', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'proyectos' AND COLUMN_NAME = 'entrega_confirmada_nombre');
SET @sql := IF(@c = 0, 'ALTER TABLE `proyectos` ADD COLUMN `entrega_confirmada_nombre` VARCHAR(150) NULL DEFAULT NULL AFTER `entrega_confirmada_at`', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'proyectos' AND COLUMN_NAME = 'entrega_confirmada_ip');
SET @sql := IF(@c = 0, 'ALTER TABLE `proyectos` ADD COLUMN `entrega_confirmada_ip` VARCHAR(45) NULL DEFAULT NULL AFTER `entrega_confirmada_nombre`', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'proyectos' AND COLUMN_NAME = 'entrega_por_plazo');
SET @sql := IF(@c = 0, 'ALTER TABLE `proyectos` ADD COLUMN `entrega_por_plazo` TINYINT(1) NOT NULL DEFAULT 0 AFTER `entrega_confirmada_ip`', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'proyectos' AND COLUMN_NAME = 'entrega_observacion');
SET @sql := IF(@c = 0, 'ALTER TABLE `proyectos` ADD COLUMN `entrega_observacion` TEXT NULL AFTER `entrega_por_plazo`', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'proyectos' AND COLUMN_NAME = 'entrega_observacion_at');
SET @sql := IF(@c = 0, 'ALTER TABLE `proyectos` ADD COLUMN `entrega_observacion_at` DATETIME NULL DEFAULT NULL AFTER `entrega_observacion`', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'proyectos' AND COLUMN_NAME = 'garantia_aviso_at');
SET @sql := IF(@c = 0, 'ALTER TABLE `proyectos` ADD COLUMN `garantia_aviso_at` DATE NULL DEFAULT NULL AFTER `entrega_observacion_at`', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

INSERT IGNORE INTO `configuracion` (`clave`, `valor`) VALUES ('garantia_meses_default', '3'), ('entrega_plazo_dias', '5');
