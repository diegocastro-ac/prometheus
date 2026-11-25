#!/bin/sh
set -e
cd /var/www/html

# Si llega el .env completo como secret (Azure Container Apps), decodificarlo.
# Las variables de entorno reales del contenedor tienen prioridad sobre el
# archivo: Dotenv no sobreescribe variables ya definidas.
if [ -n "${APP_ENV_FILE_B64:-}" ]; then
  printf '%s' "$APP_ENV_FILE_B64" | base64 -d > .env
fi

# Generar .env si no existe
if [ ! -f .env ]; then
cat > .env <<'EOF'
APP_NAME=Prometheus
APP_ENV=production
APP_DEBUG=false
APP_URL=http://localhost
APP_KEY=

APP_LOCALE=en
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=en_US

APP_MAINTENANCE_DRIVER=file

LOG_CHANNEL=stack
LOG_LEVEL=warning

DB_CONNECTION=sqlite

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=false
SESSION_PATH=/
SESSION_DOMAIN=null

BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local
QUEUE_CONNECTION=database
CACHE_STORE=database

MAIL_MAILER=log
EOF
fi

# Generar APP_KEY solo si no viene ni por entorno ni en el .env
if [ -z "${APP_KEY:-}" ] && ! grep -q '^APP_KEY=base64:' .env; then
  php artisan key:generate --force
fi

# composer install corre con --no-scripts en el build: el discovery va aquí
php artisan package:discover --ansi
php artisan storage:link --force 2>/dev/null || true

# SQLite NO puede vivir en Azure Files (SMB): los locks de byte-range fallan
# y cualquier escritura devuelve "database is locked". La BD vive en el disco
# local del contenedor y se persiste al share con VACUUM INTO desde cron
# (ver mas abajo). Al arrancar se restaura el ultimo backup si existe.
BACKUP_PATH="/mnt/appdata/database-backup.sqlite"
if [ -f "$BACKUP_PATH" ]; then
  cp "$BACKUP_PATH" /var/www/html/database/database.sqlite 2>/dev/null || true
  chown application:application /var/www/html/database/database.sqlite 2>/dev/null || true
fi

# migrate escribe journal/WAL en la BD: debe correr como el usuario de php-fpm
# para que los archivos del share queden con el owner correcto
su -s /bin/sh application -c 'cd /var/www/html && php artisan migrate --force'

# Scheduler de Laravel: routes/console.php define comandos programados.
# busybox crond (supervisord) lee /etc/crontabs/<usuario>
cat > /etc/crontabs/application <<'EOF'
* * * * * cd /var/www/html && /usr/local/bin/php artisan schedule:run >> /var/www/html/storage/logs/schedule.log 2>&1
*/5 * * * * cd /var/www/html && /usr/local/bin/php -r '$db=new PDO("sqlite:/var/www/html/database/database.sqlite"); $db->exec("VACUUM INTO \"/tmp/db-backup.sqlite\"");' && cp /tmp/db-backup.sqlite /mnt/appdata/database-backup.sqlite >> /var/www/html/storage/logs/db-backup.log 2>&1
EOF
chmod 600 /etc/crontabs/application
