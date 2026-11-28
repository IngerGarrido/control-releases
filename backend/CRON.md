# Cron jobs recomendados

Asumiendo `/var/www/control/backend` como ruta. Ajustar a la real.

## Procesos diarios (una vez al día, 08:00) — el más importante

Cotizaciones vencidas → Expirada, avisos de cuotas vencidas, de respaldo y de
links del portal vencidos, y papelera de más de 30 días (`lib/procesos.php`).
Si el hosting no tiene cron, igual corre una vez al día al abrir el Panel.

```
0 8 * * * /usr/bin/php /var/www/control/backend/procesos_diarios.php >> /var/www/control/backend/logs/procesos.log 2>&1
```

> La primera vez que corre, la papelera solo **avisa** (en la campana) cuántos
> elementos se borrarán; desde el día siguiente se vacía sola.

## Email worker (cada minuto)

Procesa la cola `email_queue` en batches.

```
* * * * * /usr/bin/php /var/www/control/backend/email_worker.php >> /var/www/control/backend/logs/email_worker.log 2>&1
```

## Cleanup datos viejos (semanal, domingo 03:00)

Limpia `email_queue` enviados >30d, fallidos >90d, `login_attempts` >30d y sesiones expiradas.

```
0 3 * * 0 /usr/bin/php /var/www/control/backend/cleanup_old_data.php >> /var/www/control/backend/logs/cleanup.log 2>&1
```

## Backup DB (diario 02:00)

Respaldo automático (`scripts/auto_backup.php`, respeta la configuración de Configuración → Sistema):

```
0 2 * * * /usr/bin/php /var/www/control/backend/scripts/auto_backup.php >> /var/www/control/backend/logs/backup.log 2>&1
```

## Healthcheck (monitoring externo)

Endpoint público: `GET /backend/api/health.php` — devuelve 200 cuando todo OK, 503 si DB caída o disco <10%. Conectar UptimeRobot / similar.

## Notas

- En MAMP/macOS dev no es necesario configurar cron; se puede invocar manualmente.
- Asegurar que el usuario que corre el cron tiene permisos de escritura sobre `backend/logs/` y `backend/cache/`.
