#!/bin/sh
set -e

: "${DB_DATABASE:?DB_DATABASE requerido}"

DB_DIR="$(dirname "$DB_DATABASE")"
mkdir -p "$DB_DIR"

if [ ! -f "$DB_DATABASE" ]; then
    : > "$DB_DATABASE"
fi

php artisan migrate --force

COUNT="$(php artisan tinker --execute="echo \\DB::table('users')->count();" | tr -d '[:space:]')"
if [ "$COUNT" = "0" ]; then
    php artisan db:seed --force
fi

php artisan storage:link --force || true
chown -R www-data:www-data storage bootstrap/cache "$DB_DIR"

exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
