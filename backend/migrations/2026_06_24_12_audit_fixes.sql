-- Correcciones de auditoría (multiusuario / agencia):
--  1. usuarios.rol: default seguro 'usuario' (antes 'admin' — cualquier INSERT
--     que omitiera rol creaba un admin). Se mantiene VARCHAR para poder sumar
--     roles de equipo (PM, finanzas…) sin ALTER de ENUM.
--  2. proyectos.portal_token_exp: expiración del magic link del portal. Antes
--     el link vivía para siempre; ahora puede caducar y revocarse por tiempo.
-- Idempotente.

SET NAMES utf8mb4;

-- 1) Default seguro para rol
ALTER TABLE `usuarios` MODIFY COLUMN `rol` VARCHAR(20) NOT NULL DEFAULT 'usuario';

-- 2) Expiración del magic link (idempotente)
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'proyectos' AND COLUMN_NAME = 'portal_token_exp');
SET @sql := IF(@col = 0,
  'ALTER TABLE `proyectos` ADD COLUMN `portal_token_exp` DATETIME NULL AFTER `portal_token`',
  'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
