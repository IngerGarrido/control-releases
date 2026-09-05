-- Cliente Empresa/Persona + contacto principal + datos de ubicación.
--
-- Contexto: `tipo` existía como enum('Agencia','Directo') pero quedó sin uso
-- ("el sistema ES la agencia"), arrastrando además `nombre_agencia` y una regla
-- de validación que hacía el nombre opcional para el tipo Agencia. En vez de
-- sumar OTRO discriminador, se reutiliza `tipo` con los valores que sí se usan
-- hoy: Empresa / Persona.
--
-- Decisiones:
--   · Un solo campo `nombre` (la etiqueta cambia en la UI según el tipo); no dos
--     columnas, porque "¿qué nombre muestro?" ya se resolvió mal una vez con
--     nombre_agencia y quedó regado en COALESCE por todo el backend.
--   · `razon_social` y `giro` solo aplican a Empresa. Van como texto libre: los
--     documentos tributarios NO se emiten desde la plataforma, estos datos son
--     para copiarlos al portal del SII.
--   · `comuna` además de `ciudad`: es el dato que piden los documentos en Chile.
--   · Contacto principal en campos planos, no tabla: hoy es 1 contacto por
--     cliente. Si algún día hay varios, migrar a `cliente_contactos`.
--
-- Idempotente.

SET NAMES utf8mb4;

-- ── 1. Campos nuevos ────────────────────────────────────────────────
-- razon_social / giro: solo se llenan cuando tipo = 'Empresa'.
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clientes' AND COLUMN_NAME = 'razon_social');
SET @sql := IF(@col = 0,
  'ALTER TABLE `clientes` ADD COLUMN `razon_social` varchar(200) DEFAULT NULL AFTER `nombre`',
  'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clientes' AND COLUMN_NAME = 'giro');
SET @sql := IF(@col = 0,
  'ALTER TABLE `clientes` ADD COLUMN `giro` varchar(200) DEFAULT NULL AFTER `rut`',
  'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clientes' AND COLUMN_NAME = 'comuna');
SET @sql := IF(@col = 0,
  'ALTER TABLE `clientes` ADD COLUMN `comuna` varchar(120) DEFAULT NULL AFTER `direccion`',
  'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clientes' AND COLUMN_NAME = 'ciudad');
SET @sql := IF(@col = 0,
  'ALTER TABLE `clientes` ADD COLUMN `ciudad` varchar(120) DEFAULT NULL AFTER `comuna`',
  'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- Contacto principal (1 por cliente).
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clientes' AND COLUMN_NAME = 'contacto_nombre');
SET @sql := IF(@col = 0,
  'ALTER TABLE `clientes` ADD COLUMN `contacto_nombre` varchar(200) DEFAULT NULL AFTER `ciudad`',
  'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clientes' AND COLUMN_NAME = 'contacto_cargo');
SET @sql := IF(@col = 0,
  'ALTER TABLE `clientes` ADD COLUMN `contacto_cargo` varchar(120) DEFAULT NULL AFTER `contacto_nombre`',
  'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clientes' AND COLUMN_NAME = 'contacto_email');
SET @sql := IF(@col = 0,
  'ALTER TABLE `clientes` ADD COLUMN `contacto_email` varchar(150) DEFAULT NULL AFTER `contacto_cargo`',
  'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clientes' AND COLUMN_NAME = 'contacto_telefono');
SET @sql := IF(@col = 0,
  'ALTER TABLE `clientes` ADD COLUMN `contacto_telefono` varchar(30) DEFAULT NULL AFTER `contacto_email`',
  'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- ── 2. Rescatar nombre_agencia antes de eliminarlo ──────────────────
-- Si algún cliente quedó con el nombre solo en nombre_agencia, se pasa a
-- `nombre` para no perder el dato. Se ejecuta solo si la columna aún existe.
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clientes' AND COLUMN_NAME = 'nombre_agencia');
SET @sql := IF(@col > 0,
  'UPDATE `clientes` SET `nombre` = `nombre_agencia`
     WHERE (`nombre` IS NULL OR TRIM(`nombre`) = "")
       AND `nombre_agencia` IS NOT NULL AND TRIM(`nombre_agencia`) <> ""',
  'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- Si el nombre comercial y el de agencia diferían, el de agencia era el legal:
-- lo conservamos como razón social en vez de tirarlo.
SET @sql := IF(@col > 0,
  'UPDATE `clientes` SET `razon_social` = `nombre_agencia`
     WHERE `nombre_agencia` IS NOT NULL AND TRIM(`nombre_agencia`) <> ""
       AND TRIM(`nombre_agencia`) <> TRIM(`nombre`)
       AND (`razon_social` IS NULL OR TRIM(`razon_social`) = "")',
  'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- ── 3. tipo: Agencia|Directo → Empresa|Persona ──────────────────────
-- Paso intermedio con los 4 valores para poder convertir los datos sin que
-- MySQL los trunque a ''. Todo lo existente pasa a Empresa (es el caso normal
-- de un cliente de agencia); Persona se elige a mano cuando corresponda.
SET @tipo_def := (SELECT COLUMN_TYPE FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clientes' AND COLUMN_NAME = 'tipo');
SET @sql := IF(@tipo_def LIKE '%Agencia%',
  "ALTER TABLE `clientes` MODIFY COLUMN `tipo` enum('Agencia','Directo','Empresa','Persona') NOT NULL DEFAULT 'Empresa'",
  'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @sql := IF(@tipo_def LIKE '%Agencia%',
  "UPDATE `clientes` SET `tipo` = 'Empresa' WHERE `tipo` IN ('Agencia','Directo')",
  'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @sql := IF(@tipo_def LIKE '%Agencia%',
  "ALTER TABLE `clientes` MODIFY COLUMN `tipo` enum('Empresa','Persona') NOT NULL DEFAULT 'Empresa'",
  'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- ── 4. Eliminar nombre_agencia ──────────────────────────────────────
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clientes' AND COLUMN_NAME = 'nombre_agencia');
SET @sql := IF(@col > 0,
  'ALTER TABLE `clientes` DROP COLUMN `nombre_agencia`',
  'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
