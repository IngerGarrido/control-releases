-- Trabajadores (usuarios) asignados a un proyecto.
--
-- Many-to-many: un proyecto puede tener varios trabajadores (diseñador,
-- desarrollador, etc.) y un usuario puede estar en varios proyectos.
-- Se asignan al PROYECTO completo (no por fase).
-- Idempotente.

CREATE TABLE IF NOT EXISTS `proyecto_miembros` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `proyecto_id` INT UNSIGNED NOT NULL,
  `usuario_id` INT NOT NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_proy_miembro` (`proyecto_id`, `usuario_id`),
  KEY `idx_pm_proyecto` (`proyecto_id`),
  KEY `idx_pm_usuario` (`usuario_id`),
  CONSTRAINT `fk_pm_proyecto` FOREIGN KEY (`proyecto_id`) REFERENCES `proyectos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pm_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
