-- Cotización en línea (portado y adaptado de sistema-local, 2026-11-20/21):
-- link privado /#/cotizacion/{token} para que el cliente la vea, la apruebe
-- (nombre + RUT + aceptación; queda un sello con fecha, IP y hash del
-- contenido), comente o diga que no seguirá. Nuevo estado 'Enviada' (link
-- enviado por correo, aún sin respuesta). Idempotente.

SET NAMES utf8mb4;

ALTER TABLE `cotizaciones`
  MODIFY COLUMN `estado` ENUM('Pendiente','Enviada','Aceptada','Rechazada','Expirada')
  COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'Pendiente';

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cotizaciones' AND COLUMN_NAME = 'token');
SET @sql := IF(@c = 0, 'ALTER TABLE `cotizaciones` ADD COLUMN `token` CHAR(43) NULL DEFAULT NULL', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cotizaciones' AND COLUMN_NAME = 'enviada_at');
SET @sql := IF(@c = 0, 'ALTER TABLE `cotizaciones` ADD COLUMN `enviada_at` DATETIME NULL DEFAULT NULL', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cotizaciones' AND COLUMN_NAME = 'vista_at');
SET @sql := IF(@c = 0, 'ALTER TABLE `cotizaciones` ADD COLUMN `vista_at` DATETIME NULL DEFAULT NULL', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cotizaciones' AND COLUMN_NAME = 'vistas');
SET @sql := IF(@c = 0, 'ALTER TABLE `cotizaciones` ADD COLUMN `vistas` INT UNSIGNED NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cotizaciones' AND COLUMN_NAME = 'aprobada_at');
SET @sql := IF(@c = 0, 'ALTER TABLE `cotizaciones` ADD COLUMN `aprobada_at` DATETIME NULL DEFAULT NULL', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cotizaciones' AND COLUMN_NAME = 'aprobada_nombre');
SET @sql := IF(@c = 0, 'ALTER TABLE `cotizaciones` ADD COLUMN `aprobada_nombre` VARCHAR(150) NULL DEFAULT NULL', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cotizaciones' AND COLUMN_NAME = 'aprobada_rut');
SET @sql := IF(@c = 0, 'ALTER TABLE `cotizaciones` ADD COLUMN `aprobada_rut` VARCHAR(12) NULL DEFAULT NULL', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cotizaciones' AND COLUMN_NAME = 'aprobada_ip');
SET @sql := IF(@c = 0, 'ALTER TABLE `cotizaciones` ADD COLUMN `aprobada_ip` VARCHAR(45) NULL DEFAULT NULL', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cotizaciones' AND COLUMN_NAME = 'aprobada_user_agent');
SET @sql := IF(@c = 0, 'ALTER TABLE `cotizaciones` ADD COLUMN `aprobada_user_agent` VARCHAR(255) NULL DEFAULT NULL', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cotizaciones' AND COLUMN_NAME = 'aprobada_hash');
SET @sql := IF(@c = 0, 'ALTER TABLE `cotizaciones` ADD COLUMN `aprobada_hash` CHAR(64) NULL DEFAULT NULL', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cotizaciones' AND COLUMN_NAME = 'respuesta_tipo');
SET @sql := IF(@c = 0, 'ALTER TABLE `cotizaciones` ADD COLUMN `respuesta_tipo` ENUM(''comentario'',''rechazo'') NULL DEFAULT NULL', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cotizaciones' AND COLUMN_NAME = 'respuesta_texto');
SET @sql := IF(@c = 0, 'ALTER TABLE `cotizaciones` ADD COLUMN `respuesta_texto` TEXT NULL', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cotizaciones' AND COLUMN_NAME = 'respuesta_at');
SET @sql := IF(@c = 0, 'ALTER TABLE `cotizaciones` ADD COLUMN `respuesta_at` DATETIME NULL DEFAULT NULL', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cotizaciones' AND INDEX_NAME = 'uq_cotizaciones_token');
SET @sql := IF(@c = 0, 'ALTER TABLE `cotizaciones` ADD UNIQUE KEY `uq_cotizaciones_token` (`token`)', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
