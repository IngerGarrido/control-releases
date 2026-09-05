-- Adjuntos de proyecto: enlaces y documentos (estilo Trello).
--
-- tipo='link'    → url externa (titulo + url).
-- tipo='archivo' → archivo subido (titulo + url interna /uploads/proyectos/...
--                  + archivo_nombre con el nombre original para mostrar).
-- La descripción general del proyecto ya vive en proyectos.descripcion.
-- Idempotente.

CREATE TABLE IF NOT EXISTS `proyecto_adjuntos` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `proyecto_id` INT UNSIGNED NOT NULL,
  `tipo` ENUM('link','archivo') NOT NULL DEFAULT 'link',
  `titulo` VARCHAR(200) NOT NULL,
  `url` VARCHAR(500) NOT NULL,
  `archivo_nombre` VARCHAR(255) NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_adj_proyecto` (`proyecto_id`),
  CONSTRAINT `fk_adj_proyecto` FOREIGN KEY (`proyecto_id`)
    REFERENCES `proyectos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
