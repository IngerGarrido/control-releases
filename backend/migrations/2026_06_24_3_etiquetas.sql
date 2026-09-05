-- Etiquetas / disciplinas configurables (con color).
--
-- Sirven para marcar fases por tipo de trabajo: "Diseño UI/UX", "Programación
-- Web", etc. Cada una tiene un color para identificarla de un vistazo.
-- Se gestionan desde Configuración.
--
-- Las etiquetas se asignan al PROYECTO (varias por proyecto, tabla pivote
-- proyecto_etiquetas), no por fase. Un proyecto web puede llevar "Diseño
-- UI/UX" y "Programación Web" a la vez.
-- Idempotente.

-- Forzar UTF-8 en la conexión para que el seed con acentos (Diseño,
-- Programación) se guarde correctamente sin importar cómo se importe el .sql.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `etiquetas` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nombre` VARCHAR(80) NOT NULL,
  `color` VARCHAR(20) NOT NULL DEFAULT '#7c3aed',
  `orden` INT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Seed inicial (solo si la tabla está vacía)
INSERT INTO `etiquetas` (`nombre`, `color`, `orden`)
SELECT * FROM (
  SELECT 'Diseño UI/UX' AS nombre, '#ec4899' AS color, 0 AS orden
  UNION ALL SELECT 'Programación Web', '#84cc16', 1
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM `etiquetas` LIMIT 1);

-- Pivote proyecto ↔ etiquetas (many-to-many)
CREATE TABLE IF NOT EXISTS `proyecto_etiquetas` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `proyecto_id` INT UNSIGNED NOT NULL,
  `etiqueta_id` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_proy_etq` (`proyecto_id`, `etiqueta_id`),
  KEY `idx_pe_proyecto` (`proyecto_id`),
  KEY `idx_pe_etiqueta` (`etiqueta_id`),
  CONSTRAINT `fk_pe_proyecto` FOREIGN KEY (`proyecto_id`) REFERENCES `proyectos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pe_etiqueta` FOREIGN KEY (`etiqueta_id`) REFERENCES `etiquetas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
