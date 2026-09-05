-- Briefing configurable POR TIPO: la agencia define plantillas de briefing
-- (ej. "Página web", "Diseño de logo"), cada una con sus preguntas. Cada
-- proyecto usa la plantilla que corresponda; el cliente la responde en el portal.
--
-- briefing_tipos      : plantilla de briefing (nombre).
-- briefing_preguntas  : preguntas de una plantilla (formato: texto/largo/archivo).
-- briefing_respuestas : una respuesta por (proyecto, pregunta). upsert.
-- proyectos.briefing_tipo_id: qué plantilla aplica al proyecto.
-- Idempotente. SET NAMES utf8mb4 por acentos del seed.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `briefing_tipos` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nombre` VARCHAR(150) NOT NULL,
  `orden` INT NOT NULL DEFAULT 0,
  `activa` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `briefing_preguntas` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tipo_id` INT UNSIGNED NOT NULL,
  `pregunta` VARCHAR(300) NOT NULL,
  `formato` ENUM('texto','textarea','archivo') NOT NULL DEFAULT 'texto',
  `orden` INT NOT NULL DEFAULT 0,
  `activa` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_preg_tipo` (`tipo_id`),
  CONSTRAINT `fk_preg_tipo` FOREIGN KEY (`tipo_id`) REFERENCES `briefing_tipos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `briefing_respuestas` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `proyecto_id` INT UNSIGNED NOT NULL,
  `pregunta_id` INT UNSIGNED NOT NULL,
  `valor` TEXT NULL,
  `archivo_url` VARCHAR(500) NULL,
  `archivo_nombre` VARCHAR(255) NULL,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_resp` (`proyecto_id`, `pregunta_id`),
  KEY `idx_resp_proyecto` (`proyecto_id`),
  CONSTRAINT `fk_resp_proyecto` FOREIGN KEY (`proyecto_id`) REFERENCES `proyectos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_resp_pregunta` FOREIGN KEY (`pregunta_id`) REFERENCES `briefing_preguntas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- proyectos.briefing_tipo_id (qué plantilla aplica). Idempotente.
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'proyectos' AND COLUMN_NAME = 'briefing_tipo_id');
SET @sql := IF(@col = 0,
  'ALTER TABLE `proyectos` ADD COLUMN `briefing_tipo_id` INT UNSIGNED NULL AFTER `id`,
   ADD CONSTRAINT `fk_proy_briefing_tipo` FOREIGN KEY (`briefing_tipo_id`) REFERENCES `briefing_tipos` (`id`) ON DELETE SET NULL',
  'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- Seed: plantillas de ejemplo con sus preguntas (solo si no hay tipos)
INSERT INTO `briefing_tipos` (`nombre`, `orden`)
SELECT * FROM (
  SELECT 'Página web' AS nombre, 0 AS orden
  UNION ALL SELECT 'Diseño de logo', 1
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM `briefing_tipos` LIMIT 1);

INSERT INTO `briefing_preguntas` (`tipo_id`, `pregunta`, `formato`, `orden`)
SELECT t.id, s.pregunta, s.formato, s.orden FROM (
  SELECT 'Página web' AS tnombre, '¿Cómo se llama tu negocio o marca?' AS pregunta, 'texto' AS formato, 0 AS orden
  UNION ALL SELECT 'Página web', '¿Cuál es el objetivo principal del sitio?', 'textarea', 1
  UNION ALL SELECT 'Página web', '¿Quién es tu público objetivo?', 'textarea', 2
  UNION ALL SELECT 'Página web', 'Sitios o referencias que te gustan (links)', 'textarea', 3
  UNION ALL SELECT 'Página web', 'Sube tu logo o manual de marca', 'archivo', 4
  UNION ALL SELECT 'Diseño de logo', '¿Cómo se llama tu marca?', 'texto', 0
  UNION ALL SELECT 'Diseño de logo', '¿Qué transmite tu marca? (valores, personalidad)', 'textarea', 1
  UNION ALL SELECT 'Diseño de logo', 'Colores o estilos que prefieres / evitas', 'textarea', 2
  UNION ALL SELECT 'Diseño de logo', 'Logos de referencia que te gustan', 'textarea', 3
) AS s
JOIN `briefing_tipos` t ON t.nombre = s.tnombre
WHERE NOT EXISTS (SELECT 1 FROM `briefing_preguntas` LIMIT 1);
