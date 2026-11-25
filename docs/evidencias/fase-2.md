# Evidencias — Fase 2: CI

Fecha: 25 nov 2026. Rama: `aisaac-ai`. Commit: `3d64252`.

## Archivo creado

- `.github/workflows/ci.yml`

## Disparadores

- `push` a `aisaac-ai`.
- `pull_request` con destino `aisaac-ai`.
- Concurrency por rama; se cancelan runs duplicados.

## Jobs

| Job | Acción | Bloqueante |
| --- | --- | --- |
| `validate` | `composer validate --strict --no-check-publish` | Sí |
| `assets` | `npm ci && npm run build` + verificación de `public/build/manifest.json` | Sí |
| `tests` | `composer install` + `php -d memory_limit=512M artisan test` | No — excepción temporal etiquetada |
| `docker` | `docker build -t prometheus:ci .` | Sí |

`continue-on-error: true` está **solo** en `tests`. El job escribe un resumen visible en `$GITHUB_STEP_SUMMARY` con el fallo conocido `tests/Feature/DeliveryActInventoryViewTest.php:170`.

No se usa secreto de APP_KEY: el job de tests genera una clave efímera con `openssl rand -base64 32` y la inyecta vía `$GITHUB_ENV` solo durante el run.

## Verificación local previa al push

```text
$ composer validate --strict --no-check-publish
./composer.json is valid

$ node -e "...yaml.parse('.github/workflows/ci.yml')..."
yaml OK [ 'validate', 'assets', 'tests', 'docker' ]

$ php -d memory_limit=512M artisan test --filter=DeliveryActInventoryViewTest
Tests: 1 failed, 5 passed (35 assertions)
FAILED  Tests\Feature\DeliveryActInventoryViewTest > la pagina carga las utilidades tailwind propias despues del css de filament
Failed asserting that 549 is greater than 3357.
at tests\Feature\DeliveryActInventoryViewTest.php:170
```

Los jobs `assets` y `docker` ya se verificaron en Fase 1: `npm run build` generó `public/build/manifest.json` y `docker build -t prometheus:local .` terminó OK.

## Run en GitHub

- Commit pushed: `336471e..3d64252  aisaac-ai -> aisaac-ai`.
- Run: https://github.com/diegocastro-ac/prometheus/actions/runs/45528056340

## Resultado del run

`status=completed conclusion=success` — 4 jobs ejecutados.

| Job | Conclusión | Duración |
| --- | --- | --- |
| Validate composer | success | 7s |
| Build assets | success | 12s |
| Tests (known failure - temporary exception) | failure — esperado, no bloqueante | 1m 6s |
| Build Docker image | success | 2m 34s |

Enlaces de jobs:

- Validate: https://github.com/diegocastro-ac/prometheus/actions/runs/45528056340/job/138916200944
- Assets: https://github.com/diegocastro-ac/prometheus/actions/runs/45528056340/job/138916201046
- Tests: https://github.com/diegocastro-ac/prometheus/actions/runs/45528056340/job/138916200695
- Docker: https://github.com/diegocastro-ac/prometheus/actions/runs/45528056340/job/138916201056

El job `tests` falló en `Run test suite` y el paso `Registrar excepción temporal` corrió con `if: always()` → success, dejando el aviso visible en el resumen. Los otros tres jobs pasaron. El run global quedó en `success` porque `continue-on-error` está limitado a `tests`.

Advertencias no bloqueantes del runner: `actions/checkout@v4` y `actions/setup-node@v4` avisan de Node.js 20 deprecado; GitHub los ejecuta forzados sobre Node.js 24. Se registra como deuda menor.

## Gate Fase 2

- [x] Workflow creado y pusheado.
- [x] Cuatro jobs ejecutados en GitHub Actions.
- [x] `validate`, `assets` y `docker` en success.
- [x] Fallo conocido de tests visible, etiquetado y no enmascarado en otros jobs.
- [x] Sin secretos en el workflow.

## Estado

**Cerrada.** Pendiente de commit de esta evidencia y confirmación para Fase 3.
