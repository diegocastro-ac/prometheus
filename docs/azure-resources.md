# Cotización Azure — Fase 3

Fecha: 25 nov 2026. Región final: `westus2`. Suscripción final: **Azure for Students** (`f3c81d52-...`, crédito US$ 100).

## Por qué VM y no App Service

1. `eastus` quedó descartado al inicio: la política `sys.regionrestriction` de Azure for Students solo permite `westus`, `canadacentral`, `northcentralus`, `westus2` y `chilecentral`. Se eligió `westus2`.
2. Azure for Students tiene **cuota B1 = 0 en las cinco regiones permitidas**, sin botón self-service. La otra suscripción (`eec1fba8-...`) anunciaba B1 = 0/30 en la API, pero la creación real devolvió cupo 0 en `westus2` (409, sin capacidad) y en `eastus` (401, sin cuota), también con F1 = 0. La API de usages no refleja el límite real.
3. Azure for Students sí tiene **B-series 0/4 vCPU** en `westus2`. Se despliega una VM `Standard_B1ms` (1 vCPU, 2 GB RAM) con Terraform. Sigue siendo Azure-only + Terraform; no hay Kubernetes ni Container Apps.

Fuente: Azure Retail Prices API, consultada antes de crear los recursos.

```text
GET https://prices.azure.com/api/retail/prices
  ?$filter=armRegionName eq 'westus2' and serviceName eq 'Virtual Machines' and skuName eq 'B1ms'
```

Resultado relevante:

```json
{
  "retailPrice": 0.02,
  "unitOfMeasure": "1 Hour",
  "productName": "Virtual Machines BS Series",
  "skuName": "B1ms",
  "armRegionName": "westus2"
}
```

## Coste mensual estimado

| Recurso | Detalle | Coste estimado |
| --- | --- | --- |
| VM `Standard_B1ms` Linux | `0.02 USD/h × 730 h` | **US$ 14.60/mes** |
| Disco OS Standard HDD S4 LRS (32 GiB) | SQLite persistente en el disco | US$ 1.54/mes |
| IP pública Basic estática | Tarifa Basic: US$ 0.00/h | US$ 0.00 |
| VNet / NIC / NSG | Sin cargo | US$ 0.00 |
| GHCR público | Imagen Docker pública; sin credenciales de pull | US$ 0.00 |
| Storage Account del state (Standard LRS) | Estado de Terraform de unos KB | < US$ 0.01/mes |
| Observabilidad | Fase 5; no se crea ahora | US$ 0.00 ahora |

**Total Fase 3: ≈ US$ 16.14/mes** (~US$ 0.53/día). Se carga al crédito de Azure for Students.

## Notas

- `APP_KEY` se genera dentro de la VM en el primer arranque y se guarda solo en `/opt/prometheus/.env` (modo 600). No está en Git, en la imagen ni en el state de Terraform.
- La imagen se descarga de GHCR sin autenticación. La BD SQLite vive en `/opt/prometheus/database/database.sqlite`, dentro del disco persistente.
- No se crea PostgreSQL, Blob Storage de datos ni Application Insights en esta fase.
- Todos los recursos llevan prefijo `prometheus-diego` y sufijo aleatorio para no colisionar con el compañero.
- El coste real puede variar por impuestos o cambios de tarifa; revisar en Cost Management.
