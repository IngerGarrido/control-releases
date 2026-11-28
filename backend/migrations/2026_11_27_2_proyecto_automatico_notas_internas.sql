-- Rediseño 2026-11-27. Idempotente.
--  - proyectos.automatico: proyectos creados solos por mantenciones y
--    renovaciones de sitios; no se muestran en el Tablero (no son trabajo de
--    diseño que avance por etapas).
--  - proyecto_comentarios.interno: nota del equipo que el cliente NO ve en su portal.

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'proyectos' AND COLUMN_NAME = 'automatico');
SET @sql := IF(@c = 0, 'ALTER TABLE `proyectos` ADD COLUMN `automatico` TINYINT(1) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

UPDATE `proyectos` SET `automatico` = 1 WHERE `automatico` = 0 AND `descripcion` LIKE 'Generado automáticamente:%';

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'proyecto_comentarios' AND COLUMN_NAME = 'interno');
SET @sql := IF(@c = 0, 'ALTER TABLE `proyecto_comentarios` ADD COLUMN `interno` TINYINT(1) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
