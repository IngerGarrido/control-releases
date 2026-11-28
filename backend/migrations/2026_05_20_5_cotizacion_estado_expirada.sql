-- El formulario de cotizaciones ofrece 4 estados (Pendiente, Aceptada,
-- Rechazada, Expirada) pero la BD sólo permitía 3. Si el usuario elegía
-- "Expirada" el INSERT/UPDATE fallaba. Agregamos 'Expirada' al enum.
-- (2026-11-25) Incluye 'Enviada': si esta migración corría DESPUÉS de
-- 2026_11_25_5_cotizacion_en_linea borraba ese estado (le pasó a sistema-local).
ALTER TABLE `cotizaciones`
  MODIFY COLUMN `estado` ENUM('Pendiente','Enviada','Aceptada','Rechazada','Expirada')
  COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'Pendiente';
