# PLAN INTERNO — Prometheus → Azure + Terraform

Documento de trabajo interno. No es para el usuario. No subir secretos a este archivo ni a ningún archivo del repo.

## 0. Objetivo

Desplegar `C:\Users\diego-castro\Documents\04-Dev\fullstack\laravel-filament\prometheus` en **Azure únicamente**, con **Terraform** como IaC, sin AWS, sin multi-cloud, sin Kubernetes.

Proyecto: monolito Laravel 12 + Filament 3.3.26 + Livewire 3.6.3. Repo remoto: `https://github.com/diegocastro-ac/prometheus.git`. Rama de trabajo: `aisaac-ai`. PR final a `main`.

Fuente del plan original: `C:\Users\diego-castro\Downloads\PLAN-DEVOPS-CLOUD.md`. Ese plan no contemplaba Terraform; aquí se adapta.

## 1. Reglas duras

1. Azure-only. Sin AWS, sin AKS, sin Container Apps.
2. Terraform crea y gestiona la infraestructura. Actions hace build/push y llama a Terraform.
3. Una fase a la vez. No pasar sin verificación y confirmación del usuario.
4. Evidencia en `docs/evidencias/fase-N.md` antes de cerrar cada fase.
5. Sin secretos, `.env` real ni BD en Git ni en la imagen.
6. Cotizar antes de crear recursos. El crédito Azure for Students de $100 es crédito, no servicio gratis.
7. Sin migraciones automáticas sin aprobación. Excepción aprobada: BD SQLite nueva y desechable con seed controlado (ver §4).
8. No tocar archivos de usuario sin permiso.
9. La contraseña del service principal `keynography-terraform` quedó expuesta en el chat. Rotarla. No guardarla aquí.

## 2. Decisiones cerradas

| Tema | Decisión | Motivo |
| --- | --- | --- |
| IaC | Terraform `hashicorp/azurerm`, rama `infra/` en el repo | Usuario: "terraform sí o sí" |
| Rama base | `aisaac-ai`; PR final a `main` | Estado diagnosticado está ahí |
| BD | SQLite persistente en `/home` | Usuario: sin tiempo para PostgreSQL |
| Datos | BD nueva; migrate + seed con guard | Proyecto académico |
| Registro | GHCR público `ghcr.io/diegocastro-ac/prometheus-diego` | Coste $0, sin credenciales de pull; nombre propio para no chocar con el compañero |
| Región | `eastus` | Barata y disponible |
| SKU | App Service Plan B1 Linux | ~US$13.14/mes, 1.75 GB RAM, Always On |
| Dominio | `https://<app>.azurewebsites.net` | Lo más fácil |
| Storage | `/home` persistente, `WEBSITES_ENABLE_APP_SERVICE_STORAGE=true` | Sin Blob por ahora |
| Notificaciones | Correo, se configura en Fase 5 | No bloquea |
| Deuda técnica | Test fallido y Pint como excepción temporal etiquetada | "Solo si es estrictamente necesario" |

### Aislamiento frente al compañero

Un compañero está haciendo lo mismo con las mismas credenciales en la misma suscripción. Prefijo propio en **todos** los recursos: `prometheus-diego`.

- GHCR: `ghcr.io/diegocastro-ac/prometheus-diego`, no `prometheus`.
- Resource group: `rg-prometheus-diego-${random_id.suffix.hex}`.
- Plan: `plan-prometheus-diego-${random_id.suffix.hex}`.
- Web app: `prometheus-diego-${random_id.suffix.hex}`.
- Storage del state: `stpromdiegotf${random_id.suffix.hex}`, container `tfstate-prometheus-diego`, key `prometheus-diego.tfstate`.
- GitHub Environment: `diego`. Secrets y variables se guardan a nivel de environment, no de repo, para no mezclarse con los del compañero.
- Federated credential: nombre `gh-aisaac-ai-diego-20261124`, subject `repo:diegocastro-ac/prometheus:environment:diego`.
- El service principal es compartido y tiene `Owner` en la suscripción. No cambiar sus permisos sin hablar con el compañero. OIDC evita guardar su password.

### Correcciones aplicadas al plan original

- SQLite **no** va dentro de la capa de la imagen. Si va ahí, cada deploy borra datos. Va en `/home/site/wwwroot/database/database.sqlite` en Azure; en local, en un volumen gitignored.
- `db:seed` **no** se ejecuta ciegas en cada arranque. Se ejecuta solo si `users` está vacío.
- `migrate --force` se ejecuta en cada arranque; es idempotente. Usuario lo aprobó implícitamente al pedir seed en la imagen.
- Usuarios demo `admin@admin.com/admin` y `user@user.com/user` quedarán activos en la URL pública. Queda registrado como decisión académica, no como descuido.
- F1 descartado: 1 GB RAM, 60 CPU min/día, duerme, sin Always On. `/up` podría fallar tras inactividad.

## 3. Estado verificado

Fecha: 24 Nov 2026.

```text
Repo:     C:\Users\diego-castro\Documents\04-Dev\fullstack\laravel-filament\prometheus
Remoto:   https://github.com/diegocastro-ac/prometheus.git
Rama:     aisaac-ai...origin/aisaac-ai (sin adelanto/atraso vs remote)
Subscription ID: f3c81d52-3310-4c22-98a1-a5f1495fca03
Terraform: v1.17.2
Azure CLI: instalado y con sesión activa (`az account show` funciona)
PHP local: 8.4.24
Composer: 2.10.2
```

### `git status --short --branch --untracked-files=all` actual

```text
## aisaac-ai...origin/aisaac-ai
 M .env.example
?? .agents/skills/...
?? openspec/...
?? skills-lock.json
```

- `resources/views/livewire/assistant-chat.blade.php` y `scripts/` ya **no** aparecen. Diagnóstico anterior quedó obsoleto. No se tocan.
- `.agents/`, `openspec/`, `skills-lock.json` son herramientas locales del agente. No se commitean. En Fase 1 se añaden a `.gitignore` y `.dockerignore`.
- `.env.example`: único cambio `GEMINI_TIMEOUT=10` → `240`. Sin secreto. Conservar y commitear con Fase 1.
- `.gitignore` actual ya ignora `.env`, `.env.backup`, `.env.production`, `/node_modules`, `/vendor`, `/public/build`, `/public/storage`, `.codegraph/`. Falta ignorar `.terraform/`, `*.tfstate*`, `.agents/`, `openspec/`, `skills-lock.json`, volumen local de BD.

### Datos del proyecto

- `composer.lock`: Laravel `v12.19.3`, Filament `v3.3.26`, Livewire `3.6.3`, PHPUnit `11.5.23`, Pint `v1.22.1`, Sail `v1.43.1`.
- `bootstrap/app.php` expone endpoint `/up`.
- `routes/console.php` scheduler real: `ipc:sync`, `ipc:sync:geographies`, `rent-adjustments:recalculate`. Cron interno en el contenedor. Una sola réplica.
- `config/database.php` tiene conexión `pgsql`, pero no se usa.
- `DB_CONNECTION=sqlite`; sesión, caché y cola usan `database`. No hay jobs `ShouldQueue`.
- `database/database.sqlite` local ~3 MB. No subir al repo.
- `phpunit.xml` usa SQLite `:memory:`. CI no necesita servicio de BD.
- Integraciones: DANE CDA vía `ipc:sync`, Nager.Date con degradación, Gemini opcional sin `GEMINI_API_KEY`.
- Disk `public` → `storage/app/public`. `public/storage` existe como junction local; en contenedor se crea con `php artisan storage:link`.
- `public/build/manifest.json` no existe localmente. El build debe ejecutar `npm ci && npm run build`.
- `tailwind.filament.config.js` genera `public/css/filament/app-utilities.css`; copiar al runtime.

### Extensiones PHP requeridas en la imagen

`ctype`, `dom`, `fileinfo`, `filter`, `gd`, `hash`, `iconv`, `intl`, `json`, `libxml`, `mbstring`, `openssl`, `pcre`, `phar`, `session`, `tokenizer`, `xml`, `xmlreader`, `xmlwriter`, `zip`, `zlib`.

Verificar dentro del contenedor con `php -m`.

### Línea base roja conocida

- `php artisan test` agota memoria: `Allowed memory size of 134217728 bytes exhausted`.
- Con `php -d memory_limit=512M artisan test --filter=DeliveryActInventoryViewTest`: `Tests: 1 failed, 5 passed`.
- Fallo: `Failed asserting that 549 is greater than 3357.` en `tests/Feature/DeliveryActInventoryViewTest.php:170`.
- Pint: 52 issues en 395 archivos. No ejecutar Pint como gate estricto ahora.

## 4. Diseño Docker (Fase 1)

Archivos nuevos previstos:

```text
Dockerfile
.dockerignore
docker-compose.yml
docker/nginx.conf
docker/supervisord.conf
docker/entrypoint.sh
docs/evidencias/fase-1.md
```

### Dockerfile — estructura multi-stage

1. `composer:8.4-cli` → `composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader`.
2. `node:22-bookworm-slim` → `npm ci && npm run build`. Copiar `public/build` y `public/css/filament/app-utilities.css`.
3. Runtime `php:8.4-fpm-bookworm` + `nginx`. Instalar extensiones listadas en §3. Copiar código, vendor y assets. Crear `/var/www/html/database`, `storage`, `bootstrap/cache`. Permisos `www-data`.
4. `EXPOSE 8080`. nginx escucha 8080 y hace `fastcgi_pass 127.0.0.1:9000`. `root /var/www/html/public`.
5. `CMD` o `ENTRYPOINT` → `docker/entrypoint.sh`, que termina con `exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf`.

App Service solo permite un puerto HTTP. `WEBSITES_PORT=8080`.

### `.dockerignore` — excluir

```text
.git
.env
.env.*
!.env.example
.agents
openspec
skills-lock.json
.codegraph
node_modules
vendor
public/build
public/css/filament/app-utilities.css
public/storage
database/*.sqlite
docs/evidencias
infra/.terraform
infra/*.tfstate
*.tfstate*
```

### `docker/entrypoint.sh` — lógica exacta

```sh
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
```

Rutas por entorno:
- Local: `DB_DATABASE=/var/www/html/database/database.sqlite` con volumen gitignored `./.docker-db`.
- Azure: `DB_DATABASE=/home/site/wwwroot/database/database.sqlite`.

### `docker-compose.yml` — local

- Servicio `app`, build `prometheus:local`, puerto `8080:8080`.
- `env_file: .env` local (gitignored) con `APP_KEY` generada, `APP_ENV=local`, `DB_CONNECTION=sqlite`, `DB_DATABASE=/var/www/html/database/database.sqlite`.
- Volumen `./.docker-db:/var/www/html/database`.
- Añadir `/.docker-db` a `.gitignore`.

### Gate Fase 1

```bash
docker build -t prometheus:local .
docker compose up -d
docker compose ps
docker compose exec app php artisan --version
docker compose exec app php -m
curl -i http://localhost:8080/up
docker compose exec app test -f public/build/manifest.json
```

Criterios: HTTP 200 en `/up`, Laravel 12, extensiones presentes, manifest existe, sin `.env` real ni `database.sqlite` en la imagen.

## 5. Diseño Terraform (Fase 3)

### Estructura

```text
infra/bootstrap/     # una sola vez: RG + Storage Account + container para el state
  main.tf
  variables.tf
  outputs.tf
infra/main/          # infra de la app
  versions.tf
  providers.tf
  variables.tf
  main.tf
  outputs.tf
```

State remoto: Azure Storage, container `tfstate-prometheus-diego`, key `prometheus-diego.tfstate`. No guardar `.tfstate` en Git. Cuenta de storage `stpromdiegotf${random_id.suffix.hex}`.

### Bootstrap

Se ejecuta una sola vez desde la máquina local con la sesión activa de `az login` (ya verificada). No meter credenciales en archivos.

```bash
cd infra/bootstrap
terraform init
terraform apply -auto-approve
```

Recursos: `azurerm_resource_group` `rg-prometheus-diego-tfstate-${random_id}`, `azurerm_storage_account` `stpromdiegotf${random_id}` (minúsculas, sin guiones, sufijo aleatorio), `azurerm_storage_container` `tfstate-prometheus-diego`. Habilitar `use_azuread_auth` en el backend de la main.

### Provider y backend

```hcl
terraform {
  required_version = ">= 1.5.0"

  required_providers {
    azurerm = {
      source  = "hashicorp/azurerm"
      version = "~> 5.0"
    }
    random = {
      source  = "hashicorp/random"
      version = "~> 3.6"
    }
  }

  backend "azurerm" {
    resource_group_name  = "<del bootstrap>"
    storage_account_name = "<del bootstrap>"
    container_name       = "tfstate-prometheus-diego"
    key                  = "prometheus-diego.tfstate"
    use_oidc             = true
    use_azuread_auth     = true
  }
}

provider "azurerm" {
  features {}
}
```

Verificar versión real de azurerm con `terraform init`; ajustar si `~> 5.0` no resuelve.

### Recursos de la app

Usar `local.app_name` y `local.hostname` para evitar autoreferencia circular en `app_settings`.

```hcl
resource "random_id" "suffix" { byte_length = 4 }

locals {
  app_name = "prometheus-diego-${random_id.suffix.hex}"
  hostname = "${local.app_name}.azurewebsites.net"
  image    = "ghcr.io/diegocastro-ac/prometheus-diego:${var.image_tag}"
}

resource "azurerm_resource_group" "main" {
  name     = "rg-prometheus-diego-${random_id.suffix.hex}"
  location = var.location
}

resource "azurerm_service_plan" "main" {
  name                = "plan-prometheus-diego-${random_id.suffix.hex}"
  resource_group_name = azurerm_resource_group.main.name
  location            = azurerm_resource_group.main.location
  os_type             = "Linux"
  sku_name            = "B1"
}

resource "azurerm_linux_web_app" "main" {
  name                = local.app_name
  resource_group_name = azurerm_resource_group.main.name
  location            = azurerm_resource_group.main.location
  service_plan_id     = azurerm_service_plan.main.id

  site_config {
    linux_fx_version  = "DOCKER|${local.image}"
    health_check_path = "/up"
    always_on         = true
    ftps_state        = "Disabled"
  }

  app_settings = {
    WEBSITES_PORT                       = "8080"
    WEBSITES_ENABLE_APP_SERVICE_STORAGE = "true"
    APP_ENV                             = "production"
    APP_DEBUG                           = "false"
    APP_URL                             = "https://${local.hostname}"
    APP_KEY                             = var.app_key
    DB_CONNECTION                       = "sqlite"
    DB_DATABASE                         = "/home/site/wwwroot/database/database.sqlite"
    SESSION_DRIVER                      = "database"
    CACHE_STORE                         = "database"
    QUEUE_CONNECTION                    = "database"
  }

  identity {
    type = "SystemAssigned"
  }
}
```

Variables: `location` (default `eastus`), `image_tag` (SHA), `app_key` (`sensitive = true`). Output: `hostname`, `app_name`.

`APP_KEY` se genera una vez con `php artisan key:generate --show` y se guarda como secreto de GitHub `APP_KEY`. Nunca en Git ni en este archivo. Si cambia, se invalidan sesiones.

### OIDC

- Service principal existente `keynography-terraform`. Su password no se usa en CI; rotarla.
- Federated credential: nombre `gh-aisaac-ai-diego-20261124`, issuer `https://token.actions.githubusercontent.com`, audience `api://AzureADTokenExchange`, subject `repo:diegocastro-ac/prometheus:environment:diego`.
- GitHub Environment `diego`: Variables (no secretos) `AZURE_CLIENT_ID`, `AZURE_TENANT_ID`, `AZURE_SUBSCRIPTION_ID`. Secreto: `APP_KEY`. Los workflows usan `environment: diego`.
- Workflow:

```yaml
permissions:
  contents: read
  id-token: write
  packages: write

env:
  ARM_USE_OIDC: "true"
  ARM_CLIENT_ID: ${{ vars.AZURE_CLIENT_ID }}
  ARM_TENANT_ID: ${{ vars.AZURE_TENANT_ID }}
  ARM_SUBSCRIPTION_ID: ${{ vars.AZURE_SUBSCRIPTION_ID }}
```

El provider lee `ACTIONS_ID_TOKEN_REQUEST_URL` y `ACTIONS_ID_TOKEN_REQUEST_TOKEN` automáticamente. No hace falta `azure/login` ni `ARM_CLIENT_SECRET`.

## 6. CI/CD (Fases 2 y 3)

### `ci.yml` — disparadores: PR y push a `aisaac-ai`

Jobs:

1. `validate`: `composer validate --strict --no-check-publish`.
2. `assets`: `npm ci && npm run build`; verificar `public/build/manifest.json`.
3. `tests (known failure - temporary exception)`: `composer install --prefer-dist --no-interaction --no-progress`, `php -d memory_limit=512M artisan test`. `continue-on-error: true` **solo en este job**, con comentario visible en el resumen. No maquillar otros jobs.
4. `docker`: `docker build -t prometheus:ci .` usando el mismo `Dockerfile`.

Evidencia: URL del run, logs de los cuatro jobs, registro del fallo conocido.

### `cd.yml` — push a `aisaac-ai` tras CI

1. `docker login ghcr.io` con `${{ secrets.GITHUB_TOKEN }}`.
2. `docker build -t ghcr.io/diegocastro-ac/prometheus-diego:${{ github.sha }} .`
3. `docker push ghcr.io/diegocastro-ac/prometheus-diego:${{ github.sha }}`.
4. Hacer el paquete GHCR público (repo → Packages → visibilidad pública) para que App Service pueda pull sin credenciales.
5. `hashicorp/setup-terraform@v3` con `terraform_version: 1.17.2`.
6. `terraform -chdir=infra/main init -input=false`, `validate`, `plan -input=false -out=tfplan -var="image_tag=${{ github.sha }}" -var="app_key=${{ secrets.APP_KEY }}"` y `apply -auto-approve -input=false tfplan` en el mismo job. No subir `tfplan` como artifact: contiene `APP_KEY`.
7. Post-deploy: `curl -i https://${hostname}/up` → 200.

El job lleva `environment: diego`. App Service hace pull en cada reinicio. El tag por SHA hace el artifact trazable e inmutable.

## 7. Ejecución por fases

### Fase 1 — Docker local

Archivos: §4. Evidencia: `docs/evidencias/fase-1.md` con salida de `docker build`, `docker compose ps`, `curl -i .../up`, `php -m`, `php artisan --version`, commit.

Gate: `/up` 200, sin secretos en capas, manifest presente, permisos de escritura OK.

### Fase 2 — CI

Archivo: `.github/workflows/ci.yml`. Evidencia: `docs/evidencias/fase-2.md` con enlace al run y logs. Gate: cuatro jobs ejecutados; fallo conocido visible y etiquetado.

### Fase 3 — Terraform + CD

Archivos: `infra/bootstrap/`, `infra/main/`, `.github/workflows/cd.yml`, `docs/azure-resources.md`, `docs/evidencias/fase-3.md`.

Antes de `apply`: cotizar B1 + GHCR + monitoring en Azure Pricing Calculator y documentar en `docs/azure-resources.md`.

Gate: `curl -i https://<app>.azurewebsites.net/up` → 200; `terraform show` con la imagen esperada; logs de contenedor sin errores de arranque; coste documentado.

### Fase 4 — BD y secretos

Sin PostgreSQL. Verificar:
- `DB_DATABASE` apunta a `/home/site/wwwroot/database/database.sqlite`.
- El archivo sobrevive a un reinicio del contenedor.
- `users` no se duplica trasreinicios: seed solo si conteo `0`.
- Login demo y pantallas clave de Filament.
- `ipc:sync` en entorno controlado.
- `git status` sin `.env`, `.sqlite`, `.tfstate` ni secretos.

Evidencia: `docs/evidencias/fase-4.md` con decisiones, conteos, settings protegidos y `git status`.

### Fase 5 — Observabilidad

- Application Insights vinculado a App Service.
- Log Analytics con retención corta; vigilar primer 5 GB gratis.
- Ping test de Azure Monitor sobre `/up`.
- Alert rules: health down, 5xx, excepciones, fallo de scheduler.
- Action group con correo. preguntar destinatario en su momento.
- Evidencia: `docs/evidencias/fase-5.md` y `docs/monitoreo.md`.

### Fase 6 — Cierre

- `docs/RUNBOOK.md`, `docs/CHECKLIST-PRODUCCION.md`, `docs/decisiones.md`, `docs/evidencias/fase-6.md`.
- Registrar deuda: 52 issues de Pint, fallo de `DeliveryActInventoryViewTest.php:170`, excepción temporal de CI.
- Ensayar rollback: cambiar `image_tag` a SHA anterior y `terraform apply`.
- Verificación final completa: `composer validate`, assets, tests, docker build, deploy, `/up`.
- PR de `aisaac-ai` a `main`. No mergear sin aprobación.

## 8. Riesgos y mitigaciones

| Riesgo | Mitigación |
| --- | --- |
| SQLite en App Service | `/home` persistente; una sola réplica; BD desechable; documentado como decisión académica |
| Seed duplicado | Guard por conteo de `users` |
| Pérdida de datos por deploy | BD fuera de la imagen, en `/home` |
| `APP_KEY` cambiante | Generar una vez; guardar en `secrets.APP_KEY`; no regenerar en arranque |
| Coste inesperado | B1, sin PostgreSQL, GHCR gratis, calculator antes de apply, alerta de gasto, limpieza de pruebas |
| Secretos expuestos | OIDC sin client secret en CI; `.gitignore` y `.dockerignore`; rotar password del SP |
| Fallo de CI enmascarado | `continue-on-error` solo en job `tests`, con nombre y comentario explícitos |
| Scheduler duplicado | Una sola instancia; sin scale-out |
| Nombre global ocupado | `random_id` en todos los nombres |
| Estado de Terraform local | Backend remoto en Storage desde el inicio; bootstrap una sola vez |

## 9. Pendientes menores (no bloquean Fase 1)

- Destinatario de correo para alertas (Fase 5).
- Confirmar visibilidad pública del paquete GHCR (Fase 3).
- Rotar password del service principal fuera del repo.
- Añadir entradas a `.gitignore` en Fase 1: `.terraform/`, `*.tfstate*`, `.agents/`, `openspec/`, `skills-lock.json`, `/.docker-db`.
- Commitear el cambio de `.env.example` (`GEMINI_TIMEOUT=240`) con Fase 1.
