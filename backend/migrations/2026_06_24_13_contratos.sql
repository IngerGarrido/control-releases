-- Firma digital de contrato (bloque cliente). Un contrato por proyecto (1:1).
-- La agencia redacta el contenido; el cliente firma en el portal con firma
-- electrónica simple (nombre + aceptación), válida en Chile (Ley 19.799).
-- Se guarda una copia CONGELADA del texto al firmar (contenido_firmado) +
-- nombre, IP y fecha como rastro de auditoría. firma_img queda para una
-- futura firma "dibujada" (canvas) sin rehacer el modelo.
-- Idempotente.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `proyecto_contratos` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `proyecto_id` INT UNSIGNED NOT NULL,
  `titulo` VARCHAR(200) NOT NULL DEFAULT 'Contrato de servicios',
  `contenido` MEDIUMTEXT NULL,
  `requiere_firma` TINYINT(1) NOT NULL DEFAULT 1,
  `firmado_at` DATETIME NULL,
  `firma_nombre` VARCHAR(200) NULL,
  `firma_rut` VARCHAR(20) NULL,
  `firma_ip` VARCHAR(45) NULL,
  `firma_img` MEDIUMTEXT NULL,
  `contenido_firmado` MEDIUMTEXT NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_contrato_proyecto` (`proyecto_id`),
  CONSTRAINT `fk_contrato_proyecto` FOREIGN KEY (`proyecto_id`) REFERENCES `proyectos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
