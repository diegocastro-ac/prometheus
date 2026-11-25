# Evidencias — Fase 3: Terraform + CD

Fecha: 25 nov 2026. Rama: `aisaac-ai`. Prefijo: `prometheus-diego`.

## Cotización previa

Documentada en `docs/azure-resources.md` antes de crear recursos.

- Fuente: Azure Retail Prices API.
- B1 Linux en `westus2`: `US$ 0.017/h × 730 h = US$ 12.41/mes`.
- GHCR público, SQLite y storage del state: coste despreciable.
- Total estimado: **≈ US$ 12.42/mes**.

## Cambio de región forzado

`eastus` fue rechazado por la política `sys.regionrestriction`:

```text
RequestDisallowedByAzure: This policy maintains a set of best available regions...
```

Regiones permitidas: `westus`, `canadacentral`, `northcentralus`, `westus2`, `chilecentral`. Se eligió `westus2`; mismo precio B1 Linux. El primer RG parcial en `eastus` fue destruido con `terraform destroy` antes de reaplicar.

## Archivos creados

```text
infra/bootstrap/versions.tf
infra/bootstrap/main.tf
infra/bootstrap/outputs.tf
infra/main/versions.tf
infra/main/variables.tf
infra/main/main.tf
infra/main/outputs.tf
.github/workflows/cd.yml
docs/azure-resources.md
docs/evidencias/fase-3.md
```

También se añadió `*.tfplan`, `**/.terraform/` y `crash.log` a `.gitignore`.

## Bootstrap

```text
$ terraform -chdir=infra/bootstrap init -input=false -backend=false
$ terraform -chdir=infra/bootstrap validate
Success! The configuration is valid.

$ terraform -chdir=infra/bootstrap apply -auto-approve -input=false
Apply complete! Resources: 4 added, 0 changed, 0 destroyed.
```

Outputs:

```text
container_name       = tfstate-prometheus-diego
resource_group_name  = rg-prometheus-diego-tfstate-50a468a8
state_key            = prometheus-diego.tfstate
storage_account_name = stpromdiegotf50a468a8
```

## OIDC y GitHub

- Service principal: `keynography-terraform`, `appId = a4b5f157-c76f-482a-84c5-b29cfca36c01`. No se usa password.
- Federated credential creada: `gh-aisaac-ai-diego-20261124`.
  - issuer: `https://token.actions.githubusercontent.com`
  - subject: `repo:diegocastro-ac/prometheus:environment:diego`
  - audience: `api://AzureADTokenExchange`
- Environment `diego` creado.
- Variables del environment:
  - `AZURE_CLIENT_ID`
  - `AZURE_TENANT_ID`
  - `AZURE_SUBSCRIPTION_ID`
  - `AZURE_TF_RESOURCE_GROUP`
  - `AZURE_TF_STORAGE_ACCOUNT`
-Secreto del environment: `APP_KEY`, generado una sola vez e inyectado directamente con GitHub CLI. No se muestra ni se guarda en el repo.

## Terraform main

```text
$ terraform -chdir=infra/main init -input=false -backend=false
$ terraform -chdir=infra/main validate
Success! The configuration is valid.

$ terraform fmt -check -recursive infra
OK
```

Recursos definidos:

- `random_id.suffix`
- `azurerm_resource_group` → `rg-prometheus-diego-<hex>`
- `azurerm_service_plan` → `plan-prometheus-diego-<hex>`, B1, Linux
- `azurerm_linux_web_app` → `prometheus-diego-<hex>`
- Imagen: `ghcr.io/diegocastro-ac/prometheus-diego:<SHA>`
- Health check: `/up`
- `WEBSITES_PORT=8080`, `WEBSITES_ENABLE_APP_SERVICE_STORAGE=true`
- `DB_DATABASE=/home/site/wwwroot/database/database.sqlite`
- `APP_KEY` desde variable sensible; nunca en Git.

## CD

Workflow `.github/workflows/cd.yml`:

1. Login GHCR con `GITHUB_TOKEN`.
2. Build y push por SHA.
3. Publicar paquete GHCR.
4. `terraform init` con backend remoto vía `-backend-config`.
5. `validate`, `plan`, `apply` en el mismo job.
6. Verificación `https://<hostname>/up` → 200 con reintentos.

`permissions`: `contents: read`, `id-token: write`, `packages: write`. Sin `ARM_CLIENT_SECRET`.

## Resultado del deploy

Bloqueado por cuota de Azure. Runs:

| Run | Commit | Resultado |
| --- | --- | --- |
| https://github.com/diegocastro-ac/prometheus/actions/runs/45530486694 | `69e8ed3` | Fallo: `gh api /user/packages/.../visibility` → 404 (`GITHUB_TOKEN` no es token de usuario) |
| https://github.com/diegocastro-ac/prometheus/actions/runs/45531197213 | `10b43c5` | Fallo: backend Terraform → `AuthorizationPermissionMismatch` (SP sin rol de datos de blobs) |
| Rerun del anterior | `10b43c5` | Fallo: `linux_fx_version` no configurable en azurerm 5.x |
| https://github.com/diegocastro-ac/prometheus/actions/runs/45532784501 | `c1a3083` | Fallo: `terraform apply` → cuota B1 = 0 |

Correcciones aplicadas:

1. Se eliminó el paso de visibilidad GHCR. El paquete ya es público: pull anónimo con token de registro anónimo devuelve HTTP 200 para el tag `c1a3083`.
2. Rol `Storage Blob Data Contributor` asignado al SP `keynography-terraform` sobre `stpromdiegotf50a468a8`. OIDC quedó funcional: `terraform init` y `plan` pasaron.
3. Imagen configurada con `site_config.application_stack`:

```hcl
application_stack {
  docker_image_name   = "diegocastro-ac/prometheus-diego:<SHA>"
  docker_registry_url = "https://ghcr.io"
}
```

### Bloqueo actual: cuota App Service

```text
Error: creating App Service Plan ... performing CreateOrUpdate: unexpected status 401
{"Code":"Unauthorized","Message":"Operation cannot be completed without additional quota.
Current Limit (B1 App Service instances): 0
Current Usage: 0
Amount required: 1"}
```

Consulta a `Microsoft.Web/locations/{región}/usages?api-version=2025-05-01`:

```text
westus        B1=0/0   F1=0/0   S1=0/0
canadacentral B1=0/0   F1=0/0   S1=0/0
northcentralus B1=0/0  F1=0/0   S1=0/0
westus2       B1=0/0   F1=0/0   S1=0/0
chilecentral  B1=0/0   F1=0/0   S1=0/0
```

Suscripción `Enabled`; política de región no es el problema esta vez. No existe SKU de App Service con cuota disponible, así que cambiar de SKU o de región no desbloquea. Hace falta solicitar aumento de cuota a Azure (portal Quotas o ticket de soporte).

Recursos creados hasta ahora (coste cero): `rg-prometheus-diego-tfstate-50a468a8` (state) y `rg-prometheus-diego-86f565ed` (vacío, creado antes del fallo del plan). La imagen `prometheus-diego:<SHA>` ya está en GHCR y es pública.

## Estado

**Bloqueada.** IaC, OIDC, GHCR y CD están verificados hasta `terraform plan`. Falta aprobación de cuota para ejecutar `apply` y verificar `/up`.
