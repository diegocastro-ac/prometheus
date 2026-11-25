# Cotización Azure — Fase 3

Fecha: 25 nov 2026. Región final: `eastus`. Suscripción final: `Suscripción de Azure 1` (`eec1fba8-...`).

Historial de decisiones:

1. `eastus` fue descartado al inicio por la política `sys.regionrestriction` de Azure for Students (`f3c81d52-...`), que solo permitía `westus`, `canadacentral`, `northcentralus`, `westus2` y `chilecentral`. Se eligió `westus2`.
2. Azure for Students resultó tener **cuota B1 = 0 en todas las regiones permitidas**, sin opción self-service. Se migró el backend y el state a `eec1fba8-...`, misma organización, sin política de región y con B1 = 0/30.
3. En `westus2` la API devolvió `409 No available instances to satisfy this request`: cuota había, pero no capacidad física. Se cambió la región del deploy a `eastus` (B1 = 0/30, mismo precio).

El storage del state sigue en `westus2`; eso no afecta al coste ni al deploy.

Fuente: Azure Retail Prices API, consultada antes de crear recursos.

```text
GET https://prices.azure.com/api/retail/prices
  ?$filter=serviceName eq 'Azure App Service'
   and armRegionName eq 'eastus'
   and skuName eq 'B1'
```

Resultado relevante:

```json
{
  "retailPrice": 0.017,
  "unitOfMeasure": "1 Hour",
  "productName": "Azure App Service Basic Plan - Linux",
  "skuName": "B1",
  "armRegionName": "eastus"
}
```

## Coste mensual estimado

| Recurso | Detalle | Coste estimado |
| --- | --- | --- |
| App Service Plan B1 Linux | `0.017 USD/h × 730 h` | **US$ 12.41/mes** |
| Web App | Incluida en el plan B1 | US$ 0.00 |
| GHCR público | Imagen Docker pública; sin credenciales de pull | US$ 0.00 |
| Storage Account del state (Standard LRS) | Estado de Terraform de unos KB | < US$ 0.01/mes |
| Base de datos SQLite | Archivo en `/home` del App Service | US$ 0.00 adicional |
| Observabilidad | Fase 5; no se crea ahora | US$ 0.00 ahora |

**Total Fase 3: ≈ US$ 12.42/mes** (~US$ 0.41/día).

El coste se carga a la suscripción `eec1fba8-...`. La estimación de autonomía con el crédito de Azure for Students ya no aplica; revisar el saldo de esa suscripción en Cost Management.

## Notas

- Se descartó F1: 1 GB RAM, freezer, sin Always On. B1 da 1.75 GB RAM y Always On.
- No se crea PostgreSQL, Blob Storage de datos ni Application Insights en esta fase.
- El nombre de todos los recursos lleva prefijo `prometheus-diego` y sufijo aleatorio para no colisionar con el compañero.
- El coste real puede variar por impuestos o cambios de tarifa; revisar en Cost Management.
