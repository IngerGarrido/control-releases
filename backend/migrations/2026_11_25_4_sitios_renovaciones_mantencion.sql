-- Sitios: renovaciones de dominio/hosting y mantención periódica como cobro
-- automático (portado de sistema-local, 2026-11-07 y 2026-11-20).
--  - *_vence: fecha de vencimiento; *_monto (neto, opcional): si tiene monto,
--    30 días antes se crea el proyecto «Renovación de … AAAA» con su cobro.
--    *_cobrado = vencimiento ya cobrado (evita duplicar); pasada la fecha, el
--    vencimiento avanza un año.
--  - mantencion_*: monto (neto) y frecuencia (1, 3, 6 o 12 meses); al llegar
--    mantencion_proxima se crea «Mantención <mes año>» con su cobro.
-- Los cobros se crean como factura (neto + IVA). Idempotente.

SET NAMES utf8mb4;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sitios' AND COLUMN_NAME = 'dominio_vence');
SET @sql := IF(@c = 0, 'ALTER TABLE `sitios` ADD COLUMN `dominio_vence` DATE NULL DEFAULT NULL AFTER `url_principal`', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sitios' AND COLUMN_NAME = 'hosting_vence');
SET @sql := IF(@c = 0, 'ALTER TABLE `sitios` ADD COLUMN `hosting_vence` DATE NULL DEFAULT NULL AFTER `dominio_vence`', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sitios' AND COLUMN_NAME = 'dominio_monto');
SET @sql := IF(@c = 0, 'ALTER TABLE `sitios` ADD COLUMN `dominio_monto` INT UNSIGNED NULL DEFAULT NULL AFTER `hosting_vence`', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sitios' AND COLUMN_NAME = 'hosting_monto');
SET @sql := IF(@c = 0, 'ALTER TABLE `sitios` ADD COLUMN `hosting_monto` INT UNSIGNED NULL DEFAULT NULL AFTER `dominio_monto`', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sitios' AND COLUMN_NAME = 'dominio_cobrado');
SET @sql := IF(@c = 0, 'ALTER TABLE `sitios` ADD COLUMN `dominio_cobrado` DATE NULL DEFAULT NULL AFTER `hosting_monto`', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sitios' AND COLUMN_NAME = 'hosting_cobrado');
SET @sql := IF(@c = 0, 'ALTER TABLE `sitios` ADD COLUMN `hosting_cobrado` DATE NULL DEFAULT NULL AFTER `dominio_cobrado`', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sitios' AND COLUMN_NAME = 'mantencion_monto');
SET @sql := IF(@c = 0, 'ALTER TABLE `sitios` ADD COLUMN `mantencion_monto` INT UNSIGNED NULL DEFAULT NULL AFTER `hosting_cobrado`', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sitios' AND COLUMN_NAME = 'mantencion_frecuencia');
SET @sql := IF(@c = 0, 'ALTER TABLE `sitios` ADD COLUMN `mantencion_frecuencia` TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER `mantencion_monto`', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sitios' AND COLUMN_NAME = 'mantencion_proxima');
SET @sql := IF(@c = 0, 'ALTER TABLE `sitios` ADD COLUMN `mantencion_proxima` DATE NULL DEFAULT NULL AFTER `mantencion_frecuencia`', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sitios' AND COLUMN_NAME = 'mantencion_dia');
SET @sql := IF(@c = 0, 'ALTER TABLE `sitios` ADD COLUMN `mantencion_dia` TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER `mantencion_proxima`', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sitios' AND COLUMN_NAME = 'mantencion_ultimo_periodo');
SET @sql := IF(@c = 0, 'ALTER TABLE `sitios` ADD COLUMN `mantencion_ultimo_periodo` CHAR(7) NULL DEFAULT NULL AFTER `mantencion_dia`', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
