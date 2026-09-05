-- Categoría y tier (nivel A/B/C) por servicio.
--
-- Cada servicio tiene: nombre + categoría (Branding, Web, etc.) + tier (A/B/C)
-- + precio. Un mismo concepto ("Spot radial") se carga como varias filas, una
-- por nivel: (A) 200.000, (B) 100.000, (C) 65.000.
--
-- tier es NULLABLE: hay servicios sin nivel (ej. "Soporte Web Mensual" → Web).
-- categoria es NULLABLE por compatibilidad con servicios ya cargados.
--
-- Idempotente: solo agrega columnas/índices que falten.

SET @has_cat := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'servicios' AND COLUMN_NAME = 'categoria');
SET @sql := IF(@has_cat = 0,
  'ALTER TABLE `servicios` ADD COLUMN `categoria` VARCHAR(100) NULL DEFAULT NULL AFTER `nombre`',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_tier := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'servicios' AND COLUMN_NAME = 'tier');
SET @sql := IF(@has_tier = 0,
  'ALTER TABLE `servicios` ADD COLUMN `tier` ENUM(''A'',''B'',''C'') NULL DEFAULT NULL AFTER `categoria`, ADD KEY `idx_svc_tier` (`tier`)',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
