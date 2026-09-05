-- Quita la duplicación de email/teléfono en la ficha de cliente.
--
-- La migración anterior agregó contacto_email/contacto_telefono junto a los
-- ya existentes email/telefono del cliente, y en el formulario quedaban dos
-- pares del mismo dato: en una agencia el correo del cliente ES el de su
-- contacto.
--
-- Se conservan `email` y `telefono` (no los del contacto) porque son los que
-- el sistema ya usa: destinatario del link del portal (proyectos.php) y del
-- PDF de cotización (exports.php), además de la búsqueda global y el detector
-- de duplicados. Pasan a mostrarse dentro de "Contacto principal", que es lo
-- que son en la práctica. De contacto_* solo sobreviven nombre y cargo.
--
-- Antes de borrar se rescata cualquier dato que se haya alcanzado a cargar en
-- las columnas nuevas, por si el cliente no tenía email/teléfono propios.
--
-- Idempotente.

SET NAMES utf8mb4;

-- ── 1. Rescatar datos antes de borrar ───────────────────────────────
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clientes' AND COLUMN_NAME = 'contacto_email');
SET @sql := IF(@col > 0,
  'UPDATE `clientes` SET `email` = `contacto_email`
     WHERE (`email` IS NULL OR TRIM(`email`) = "")
       AND `contacto_email` IS NOT NULL AND TRIM(`contacto_email`) <> ""',
  'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @sql := IF(@col > 0, 'ALTER TABLE `clientes` DROP COLUMN `contacto_email`', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clientes' AND COLUMN_NAME = 'contacto_telefono');
SET @sql := IF(@col > 0,
  'UPDATE `clientes` SET `telefono` = `contacto_telefono`
     WHERE (`telefono` IS NULL OR TRIM(`telefono`) = "")
       AND `contacto_telefono` IS NOT NULL AND TRIM(`contacto_telefono`) <> ""',
  'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @sql := IF(@col > 0, 'ALTER TABLE `clientes` DROP COLUMN `contacto_telefono`', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
