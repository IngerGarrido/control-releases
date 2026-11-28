-- Estado de tareas «Revisión cliente»: en algunas BD quedó guardado con la
-- tilde dañada («RevisiÃ³n cliente», migración corrida sin utf8mb4), y el
-- backend nunca podía guardar ese estado. Se pasa por un enum con ambos
-- valores para no perder tareas, se corrigen y se deja el enum correcto.
-- Idempotente (sobre una BD sana no cambia nada).

SET NAMES utf8mb4;

ALTER TABLE `proyecto_tareas` MODIFY `estado` ENUM('Pendiente','En curso','QA interna','Revisión cliente','RevisiÃ³n cliente','Aprobada') NOT NULL DEFAULT 'Pendiente';

UPDATE `proyecto_tareas` SET `estado` = 'Revisión cliente' WHERE `estado` = 'RevisiÃ³n cliente';

ALTER TABLE `proyecto_tareas` MODIFY `estado` ENUM('Pendiente','En curso','QA interna','Revisión cliente','Aprobada') NOT NULL DEFAULT 'Pendiente';
