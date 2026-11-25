# Cotización Azure — Fase 3

Fecha: 25 nov 2026. Región: `westus2`. Suscripción: Azure for Students (`f3c81d52-...`).

`eastus` quedó descartado: la política `sys.regionrestriction` de la suscripción solo permite `westus`, `canadacentral`, `northcentralus`, `westus2` y `chilecentral`. Se eligió `westus2` por disponibilidad y mismo precio B1 Linux.

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

Crédito Azure for Students: US$ 100. Autonomía estimada: ~8 meses si no se crean otros recursos.

## Notas

- Se descartó F1: 1 GB RAM, freezer, sin Always On. B1 da 1.75 GB RAM y Always On.
- No se crea PostgreSQL, Blob Storage de datos ni Application Insights en esta fase.
- El nombre de todos los recursos lleva prefijo `prometheus-diego` y sufijo aleatorio para no colisionar con el compañero.
- El coste real puede variar por impuestos o cambios de tarifa; revisar en Cost Management.
