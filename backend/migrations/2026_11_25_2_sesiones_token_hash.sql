-- Sesiones: el token se guarda como SHA-256 (tokenHash() en config.php).
-- Las sesiones abiertas antes de esta migración tenían el token en claro:
-- se convierten a su hash para que nadie tenga que volver a iniciar sesión.
-- Corre una sola vez (el migrador la registra); no repetir a mano.
SET NAMES utf8mb4;
UPDATE `sesiones` SET `token` = SHA2(`token`, 256);
