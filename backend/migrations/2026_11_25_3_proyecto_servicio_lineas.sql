-- Líneas de servicio del proyecto (portado de sistema-local, 2026-10-16):
--  - cantidad: cuántas veces se cobra el servicio (ej. 3 páginas).
--  - precio_unitario: precio de ESTE proyecto; NULL = el del catálogo.
--    Para trabajos cuyo precio cambia por encargo.
--  - nombre + servicio_id NULL: "línea libre", trabajo que no está en el
--    catálogo (ej. "Migración de correo"), con su propio precio.
-- Idempotente.

SET NAMES utf8mb4;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'proyecto_servicios' AND COLUMN_NAME = 'cantidad');
SET @sql := IF(@c = 0, 'ALTER TABLE `proyecto_servicios` ADD COLUMN `cantidad` INT NOT NULL DEFAULT 1 AFTER `servicio_id`', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'proyecto_servicios' AND COLUMN_NAME = 'precio_unitario');
SET @sql := IF(@c = 0, 'ALTER TABLE `proyecto_servicios` ADD COLUMN `precio_unitario` INT NULL DEFAULT NULL AFTER `cantidad`', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'proyecto_servicios' AND COLUMN_NAME = 'nombre');
SET @sql := IF(@c = 0, 'ALTER TABLE `proyecto_servicios` ADD COLUMN `nombre` VARCHAR(200) NULL DEFAULT NULL AFTER `servicio_id`', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

ALTER TABLE `proyecto_servicios` MODIFY COLUMN `servicio_id` INT UNSIGNED NULL;
