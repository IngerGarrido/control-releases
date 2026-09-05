-- Fases y tareas por proyecto (estructura operativa del trabajo).
--
-- Un proyecto se divide en FASES (Brief → Diseño → Maquetación → QA → Entrega),
-- cada una con su orden y estado. Dentro de cada fase hay TAREAS con su propio
-- estado (flujo kanban: Pendiente → En curso → QA interna → Revisión cliente →
-- Aprobada).
--
-- ON DELETE CASCADE: al eliminar definitivamente un proyecto se limpian sus
-- fases y tareas; al eliminar una fase se limpian sus tareas.
-- Idempotente: CREATE TABLE IF NOT EXISTS.

CREATE TABLE IF NOT EXISTS `proyecto_fases` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `proyecto_id` INT UNSIGNED NOT NULL,
  `nombre` VARCHAR(150) NOT NULL,
  `orden` INT NOT NULL DEFAULT 0,
  `estado` ENUM('Pendiente','En curso','Completada') NOT NULL DEFAULT 'Pendiente',
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_fase_proyecto` (`proyecto_id`),
  CONSTRAINT `fk_fase_proyecto` FOREIGN KEY (`proyecto_id`)
    REFERENCES `proyectos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `proyecto_tareas` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `proyecto_id` INT UNSIGNED NOT NULL,
  `fase_id` INT UNSIGNED NULL,
  `titulo` VARCHAR(200) NOT NULL,
  `descripcion` TEXT NULL,
  `estado` ENUM('Pendiente','En curso','QA interna','Revisión cliente','Aprobada') NOT NULL DEFAULT 'Pendiente',
  `orden` INT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_tarea_proyecto` (`proyecto_id`),
  KEY `idx_tarea_fase` (`fase_id`),
  CONSTRAINT `fk_tarea_proyecto` FOREIGN KEY (`proyecto_id`)
    REFERENCES `proyectos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tarea_fase` FOREIGN KEY (`fase_id`)
    REFERENCES `proyecto_fases` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
