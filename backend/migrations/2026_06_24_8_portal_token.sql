-- Portal del cliente: cada proyecto puede tener un token único (magic link)
-- para que el cliente vea el avance sin contraseña.
--
-- portal_token: NULL hasta que el admin genera el link. Único.
-- Idempotente.

SET @has_col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'proyectos' AND COLUMN_NAME = 'portal_token');
SET @sql := IF(@has_col = 0,
  'ALTER TABLE `proyectos` ADD COLUMN `portal_token` VARCHAR(64) NULL DEFAULT NULL AFTER `etapa_id`, ADD UNIQUE KEY `uq_portal_token` (`portal_token`)',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
