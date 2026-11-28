-- Comentarios del proyecto (hilo cliente ↔ agencia) + origen de adjuntos.
--
-- proyecto_comentarios: hilo de texto. autor = 'cliente' (desde el portal) o
-- 'admin' (respuesta de la agencia). fase_id opcional (cuando viene de
-- "Pedir cambios" sobre una fase).
-- proyecto_adjuntos.origen: distingue archivos subidos por el admin vs el
-- cliente (desde el portal).
-- Idempotente. SET NAMES utf8mb4 por el enum/textos con acentos.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `proyecto_comentarios` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `proyecto_id` INT UNSIGNED NOT NULL,
  `fase_id` INT UNSIGNED NULL,
  `autor` ENUM('cliente','admin') NOT NULL DEFAULT 'cliente',
  `autor_nombre` VARCHAR(150) NULL,
  `texto` TEXT NOT NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_com_proyecto` (`proyecto_id`),
  CONSTRAINT `fk_com_proyecto` FOREIGN KEY (`proyecto_id`) REFERENCES `proyectos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_com_fase` FOREIGN KEY (`fase_id`) REFERENCES `proyecto_fases` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

SET @has_col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'proyecto_adjuntos' AND COLUMN_NAME = 'origen');
SET @sql := IF(@has_col = 0,
  'ALTER TABLE `proyecto_adjuntos` ADD COLUMN `origen` ENUM(''admin'',''cliente'') NOT NULL DEFAULT ''admin'' AFTER `tipo`',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
