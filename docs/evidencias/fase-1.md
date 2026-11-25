# Evidencias — Fase 1: Contenedor Docker y entorno local

Fecha: 24-25 nov 2026. Rama: `aisaac-ai`. Prefijo de recursos: `prometheus-diego`.

## Archivos creados

- `Dockerfile`
- `.dockerignore`
- `docker-compose.yml`
- `docker/nginx.conf`
- `docker/supervisord.conf`
- `docker/entrypoint.sh`
- `docs/evidencias/fase-1.md`

## Cambios en archivos existentes

- `.env.example`: `GEMINI_TIMEOUT=10` → `240`. Sin secretos. Se conserva.
- `.gitignore`: se ignoran `.agents/`, `openspec/`, `skills-lock.json`, `.terraform/`, `*.tfstate*`, `/.docker-db`.

## Diseño de la imagen

- Base: `php:8.4-fpm-alpine`, multi-stage.
- Composer se copia desde `composer:2` para no arrastrar PHP 8.5 incompatible con `composer.lock`.
- Se instalan extensiones: `bcmath`, `curl`, `gd`, `intl`, `mbstring`, `opcache`, `pdo`, `pdo_sqlite`, `zip`.
- Assets: `node:22-bookworm-slim` + `npm ci` + `npm run build` (Vite y CSS de Filament).
- Runtime: nginx en `8080`, PHP-FPM en `127.0.0.1:9000`, supervisor y scheduler cada 60s.
- Se instalan dependencias `require-dev`: los seeders usan `fakerphp/faker`.
- `DB_DATABASE` es obligatorio. Arranque: crea BD, `migrate --force`, seed solo si `users` está vacío, `storage:link --force`.
- Sin `.env` real ni `database.sqlite` dentro de la imagen.

## Incidencias resueltas

1. `composer:2` trae PHP 8.5.13; `composer.lock` exige PHP ≤8.4. Corregido copiando `/usr/bin/composer` a la imagen `php:8.4-fpm-*`.
2. Disco C: llegó a 0 bytes; Docker Desktop respondió 500. Usuario liberó espacio.
3. Docker Desktop quedó con el engine caído. Reiniciado con autorización del usuario.
4. Imagen `php:8.4-fpm-bookworm` corrupta: `docker-php-source` y `php.tar.xz` a 0 bytes. Corregido con `php:8.4-fpm-alpine`.
5. `sqlite3` no compila en Alpine (`Cannot find config.m4`). Eliminado: Laravel usa `pdo_sqlite`.
6. `docker-php-ext-install -j` producía carrera en `.libs`. Eliminado el paralelismo.
7. `--no-dev` dejaba fuera `fakerphp/faker`, necesario para los seeders. Eliminado.
8. El volumen inicial montaba `./.docker-db` sobre `/var/www/html/database`, tapando `migrations/` y `seeders/`. Corregido: BD local en `/home/prometheus/database.sqlite`, volumen `./.docker-db:/home/prometheus`. La ruta de Azure sigue siendo `/home/site/wwwroot/database/database.sqlite`.

## Comandos y resultados

```text
$ docker build -t prometheus:local .
=> DONE 27.4s (rebuild final; capa PHP reutilizada de caché)

$ docker compose up -d
Container prometheus-app-1 Started

$ docker compose ps
NAME               IMAGE              STATUS                       PORTS
prometheus-app-1   prometheus:local   Up 33 seconds                0.0.0.0:8080->8080/tcp

$ docker compose logs --tail 250
INFO  Running migrations.   (38 migrations DONE)
INFO  Seeding database.

$ docker compose exec -T app php artisan --version
Laravel Framework 12.19.3

$ docker compose exec -T app php -m
bcmath
curl
gd
intl
mbstring
openssl
pdo_sqlite
zip

$ curl.exe -s -o NUL -w "HTTP %{http_code}\n" http://localhost:8080/up
HTTP 200

$ docker compose exec -T app test -f public/build/manifest.json
manifest.json OK

$ docker compose exec -T app php artisan tinker --execute="echo 'users=' . \DB::table('users')->count();"
users=2

$ docker compose restart
$ docker compose exec -T app php artisan tinker --execute="echo 'users=' . \DB::table('users')->count();"
users=2   # seed no se repite

$ docker run --rm --entrypoint sh prometheus:local -c '...'
sin .env
sin database.sqlite
manifest OK
```

## Gate Fase 1

- [x] `/up` → HTTP 200.
- [x] Laravel 12.19.3.
- [x] Extensiones PHP necesarias presentes.
- [x] `public/build/manifest.json` existe en el contenedor.
- [x] Migraciones y seed ejecutan desde cero.
- [x] Seed idempotente tras reinicio: `users=2`.
- [x] Sin `.env` real ni `database.sqlite` en la imagen.

## Estado

**Cerrada.** Pendiente solo commit en `aisaac-ai` y confirmación para Fase 2.
