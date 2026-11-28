-- Adjuntos del proyecto: qué ve el cliente en el portal.
--
-- Antes el portal mostraba TODOS los archivos del proyecto, incluidos los
-- documentos internos de la agencia. Ahora cada adjunto de la agencia tiene
-- `visible_cliente` (por defecto 0 = privado); lo que sube el cliente siempre
-- lo ve él mismo.
--
-- Los archivos que ya existían quedan visibles (1) para no quitarle al cliente
-- de un día para otro lo que ya veía; los enlaces quedan privados (el portal
-- no los mostraba). Desde la ficha del proyecto se cambia uno por uno.
-- Idempotente.

SET NAMES utf8mb4;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'proyecto_adjuntos' AND COLUMN_NAME = 'visible_cliente');
SET @sql := IF(@col = 0,
  'ALTER TABLE `proyecto_adjuntos` ADD COLUMN `visible_cliente` TINYINT(1) NOT NULL DEFAULT 0 AFTER `origen`',
  'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- Solo la primera vez (si la columna recién se creó).
SET @sql := IF(@col = 0,
  'UPDATE `proyecto_adjuntos` SET `visible_cliente` = 1 WHERE `tipo` = ''archivo''',
  'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
