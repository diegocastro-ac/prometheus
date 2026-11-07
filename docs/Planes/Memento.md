# Plan e implementación — Memento (cadena de reajustes)

> Documento de memoria específica del patrón. El resumen y la bitácora general viven en `PLAN-IMPLEMENTACION-PATRONES-COMPORTAMIENTO.md`.

## Estado

**Implementado** el 2026-11-07. Relacionadas y suite completa en verde.

## Objetivo

Hacer reversible el recálculo de la cadena de reajustes y convertir `--dry-run` en una simulación real, sin agregar tablas ni una pantalla de historial. Se conservan:

- los valores calculados y las transacciones actuales;
- los contratos de `RentAdjustmentService`, `RentalFacade` y Filament;
- el resultado de `recalculate()`, al que solo se le agrega la clave `memento`.

## Diagnóstico inicial

- `AdjustmentChainRecalculator` escribía `sequence`, `previous_rent`, `legal_cap_rent`, `excess_over_cap` y `monthly_amount` sin conservar el estado anterior.
- `--dry-run` imprimía la cadena **actual**, no la que resultaría del cálculo.
- `--dry-run` sin `--rental` ignoraba la bandera y reprocesaba de verdad.
- `AdjustmentChainCalculator` devolvía como `anchor_rent` el canon de entrada de cada ajuste (`$running`), aunque el campo significa el canon base del alquiler. El error estaba latente porque el recalculador no comparaba ese campo; al empezar a compararlo, dos pruebas de la cadena lo expusieron.
- No existía ninguna forma de deshacer un recálculo equivocado sin restaurar una copia de la base.

## Diseño aprobado

- `AdjustmentMemento` inmutable: `adjustmentId`, `sequence`, `anchorRent`, `previousRent`, `legalCapRent`, `excessOverCap`.
- `AdjustmentChainMemento` inmutable: `rentalId`, `monthlyAmount`, arreglo de mementos indexado por id.
- `RentAdjustment` es Originator: `createMemento()` y `restore()`.
- `Rental` es Originator agregado: `createAdjustmentChainMemento()` y `restoreAdjustmentChain()`.
- `AdjustmentChainRecalculator` es Caretaker: captura antes de escribir, devuelve `memento` y ofrece `restore()`.
- `preview()` y `previewAll()` calculan sin escribir y comparan el memento con el resultado.
- El comando usa `preview()`/`previewAll()` para `--dry-run` en modo por alquiler y global.
- Sin tablas, migraciones, columnas ni UI de deshacer.

## Archivos tocados

| Archivo | Cambio |
| --- | --- |
| `app/ValueObjects/AdjustmentMemento.php` | Nuevo memento por ajuste. |
| `app/ValueObjects/AdjustmentChainMemento.php` | Nuevo memento de la cadena. |
| `app/Models/RentAdjustment.php` | `createMemento()` y `restore()`. |
| `app/Models/Rental.php` | `createAdjustmentChainMemento()` y `restoreAdjustmentChain()`. |
| `app/Services/Adjustment/AdjustmentChainCalculator.php` | Calcula `excess_over_cap` y corrige `anchor_rent` al canon base del alquiler. |
| `app/Services/Adjustment/AdjustmentChainRecalculator.php` | Captura y devuelve el memento; `preview()`, `previewAll()` y `restore()`. |
| `app/Console/Commands/RecalculateAdjustmentChains.php` | `--dry-run` seguro en ambos modos. |
| `tests/Unit/AdjustmentMementoTest.php` | Memento unitario. |
| `tests/Feature/AdjustmentChainMementoTest.php` | Captura, restauración, simulación y memento ajeno. |
| `tests/Feature/RecalculateAdjustmentChainsCommandTest.php` | Dry-run global/por alquiler y recálculo normal. |
| `docs/Patrones de comportamiento/Memento.md` | Documento del patrón con §9 UML. |
| `docs/MAPA-PATRONES.md` | Fila nueva y total 17 → 18. |

## Decisiones a recordar

- **Evidencia antes/después (UML):** §9 contrasta la escritura directa sin fotografía con el trío Originator/Memento/Caretaker; solo se muestra la captura y la restauración.
- **El memento captura solo lo que el recálculo escribe.** `new_rent`, fechas, estado y relaciones quedan fuera: el patrón deshace el reproceso, no cualquier operación del reajuste.
- **`anchor_rent` es el canon base del alquiler.** El calculator debe devolver `$anchor`, no `$running`; `previous_rent` es el que cambia por tramo. Esto corrige una desviación latente y lo fija `AdjustmentChainTest`.
- **`excess_over_cap` se calcula en el calculator.** Antes lo calculaba el recalculador al escribir; ahora la simulación puede mostrar ese campo sin repetir la fórmula.
- **El memento vive en memoria.** No hay persistencia; si el proceso termina, se pierde. No sustituye una copia de seguridad.
- **Restauración transaccional.** Alquiler y ajustes se restauran juntos o no se restaura nada.
- **Filas borradas/creadas después del memento:** las borradas se omiten; las nuevas no se tocan. El memento restaura lo que existía.
- **Sin UI de deshacer.** El resultado del recálculo transporta el memento para que un llamador lo conserve; hoy lo consumen las pruebas y el `--dry-run`.
- **`--dry-run` ya no escribe.** En modo global resume cuántos alquileres y reajustes cambiarían; en modo por alquiler muestra el detalle `antes → después`.

## Pruebas

- Nuevas: `tests/Unit/AdjustmentMementoTest.php` (1) + `tests/Feature/AdjustmentChainMementoTest.php` (4) + `tests/Feature/RecalculateAdjustmentChainsCommandTest.php` (3) = **8 pruebas OK**.
- Relacionadas: `AdjustmentChainTest`, `RentAdjustmentServiceTest`, `ReajusteCalculationTest`, `ReajusteScreensRenderTest`, `RentAdjustmentFormTest`, `RentalFacadeTest` → **83 pruebas OK**.
- Total relacionado: **91 pruebas OK**.
- Suite completa: **389 pruebas, 1859 aserciones, OK**. Se corrió en dos procesos (`378 + 11`) por el `set_time_limit(300)` de `ListIpcSeries`; no es un fallo de código.

## Riesgos residuales

- Si se necesita “deshacer” más de una vez o entre usuarios, hace falta un caretaker con colección y persistencia; el diseño actual no lo cubre.
- `restore()` no revive ajustes borrados ni elimina los creados después del memento. Es una restauración del estado existente, no una reversión histórica.
- El memento es legible por el cuidador (PHP no tiene visibilidad de paquete). Se acepta porque el diff y la simulación necesitan leerlo; sigue siendo inmutable y sin setters.
- Cambiar el conjunto de campos que escribe el recálculo obliga a actualizar `AdjustmentMemento` y `restore()`. Es el precio de que el memento sea mínimo.

## Siguiente patrón

**#5 Command** (acciones de reajuste encapsuladas). Antes de implementar: explicación sencilla y aprobación explícita.
