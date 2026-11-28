-- Etapas del tablero (columnas del kanban de proyectos).
--
-- Son compartidas por todos los proyectos: el proyecto se "mueve" entre etapas
-- (Diseño → Maquetación → Desarrollo → Entrega…). Distintas de las FASES
-- internas de un proyecto (que tienen tareas). Se configuran en Configuración.
--
-- proyectos.etapa_id = en qué columna del tablero está el proyecto.
-- Idempotente. SET NAMES para acentos del seed.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `etapas` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nombre` VARCHAR(100) NOT NULL,
  `color` VARCHAR(20) NOT NULL DEFAULT '#7c3aed',
  `orden` INT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Seed inicial (solo si está vacía)
INSERT INTO `etapas` (`nombre`, `color`, `orden`)
SELECT * FROM (
  SELECT 'Diseño Inicial'   AS nombre, '#ec4899' AS color, 0 AS orden
  UNION ALL SELECT 'Revisión Cliente', '#f59e0b', 1
  UNION ALL SELECT 'Maquetación',      '#0ea5e9', 2
  UNION ALL SELECT 'Desarrollo',       '#84cc16', 3
  UNION ALL SELECT 'Entrega',          '#14b8a6', 4
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM `etapas` LIMIT 1);

-- Columna etapa_id en proyectos (idempotente)
SET @has_col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'proyectos' AND COLUMN_NAME = 'etapa_id');
SET @sql := IF(@has_col = 0,
  'ALTER TABLE `proyectos` ADD COLUMN `etapa_id` INT UNSIGNED NULL DEFAULT NULL AFTER `estado`,
   ADD KEY `idx_proy_etapa` (`etapa_id`),
   ADD CONSTRAINT `fk_proy_etapa` FOREIGN KEY (`etapa_id`) REFERENCES `etapas` (`id`) ON DELETE SET NULL',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
