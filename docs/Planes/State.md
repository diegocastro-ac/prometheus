# Plan e implementación — State (ciclo de vida del reajuste)

> Documento de memoria específica del patrón. El resumen y la bitácora general viven en `PLAN-IMPLEMENTACION-PATRONES-COMPORTAMIENTO.md`.

## Estado

**Implementado y verificado** el 2026-11-06 con la suite completa.

## Objetivo

Convertir el comportamiento por estado de `RentAdjustment` en un **State GoF real**: cada estado decide sus capacidades y el enum queda como identificador estable, conservando:

- la derivación del estado desde `applied_on` y `notified_on`;
- la matriz actual de capacidades (`canNotify`, `canApply`, `canEdit`, `countsTowardsChain`);
- los contratos de `RentAdjustment`, `RentAdjustmentService` y `RentalFacade`;
- las acciones visibles de Filament y el filtrado de la cadena.

## Diagnóstico inicial

- `AdjustmentState` era un enum que mezclaba identidad y comportamiento: cada capacidad comparaba `$this === self::...`.
- Agregar un estado obligaba a editar todos los métodos del enum.
- `RentAdjustmentResource` consultaba `$record->state()->canApply()`/`canNotify()`: la UI conocía el enum y su matriz.
- `RentAdjustment` ya delegaba `countsTowardsChain()` en el enum, pero no exponía las demás capacidades.
- No existía una columna de estado: las fechas son la fuente de verdad.

## Diseño aprobado

- Nuevo contrato `App\States\Adjustment\AdjustmentStateBehavior` con las cuatro capacidades.
- Tres estados concretos: `RecordedState`, `NotifiedState`, `AppliedState`.
- `AdjustmentState::behavior()` devuelve el estado concreto; los métodos del enum delegan.
- `RentAdjustment` expone `canNotify()`, `canApply()` y `canEdit()`; `countsTowardsChain()` se mantiene.
- `RentAdjustmentResource` usa `$record->canApply()` y `$record->canNotify()`.
- El enum conserva `label()`, `options()`, `colors()` y `color()`: son identidad y presentación, no reglas de estado.

## Matriz de capacidades

| Estado | canNotify | canApply | canEdit | countsTowardsChain |
| --- | --- | --- | --- | --- |
| Recorded | sí | no | sí | no |
| Notified | no | sí | sí | no |
| Applied | no | no | no | sí |

## Archivos tocados

| Archivo | Cambio |
| --- | --- |
| `app/States/Adjustment/AdjustmentStateBehavior.php` | Nuevo contrato State. |
| `app/States/Adjustment/RecordedState.php` | Nuevo estado inicial. |
| `app/States/Adjustment/NotifiedState.php` | Nuevo estado comunicado. |
| `app/States/Adjustment/AppliedState.php` | Nuevo estado final. |
| `app/Enums/AdjustmentState.php` | Token + `behavior()`; las capacidades delegan. |
| `app/Models/RentAdjustment.php` | `canNotify()`, `canApply()`, `canEdit()` delegan en el estado. |
| `app/Filament/Resources/RentAdjustmentResource.php` | Acciones visibles según el modelo, no el enum. |
| `tests/Unit/AdjustmentStateBehaviorTest.php` | Matriz y despacho del enum. |
| `tests/Feature/AdjustmentStateTest.php` | Delegación observable desde el modelo. |
| `docs/Patrones de comportamiento/State.md` | Documento del patrón con §9 UML. |
| `docs/MAPA-PATRONES.md` | Fila nueva y total 16 → 17. |

## Decisiones a recordar

- **Evidencia antes/después (UML):** §9 contrasta el enum que decidía todo con el enum que despacha a tres estados; solo se muestra el `match` de `behavior()`.
- **El estado sigue derivándose de fechas.** No hay columna ni workflow nuevo; `applied_on` tiene prioridad sobre `notified_on`.
- **No se agregaron guardas nuevas** a `markNotified()` ni `applyToRental()`. Los tests existentes aplican directamente desde `Recorded` y deben seguir pasando.
- **El enum conserva `label()` y `color()`.** Son presentación del token; moverlos a cada estado agregaría ruido sin valor de dominio.
- **`Notified` no cuenta para la cadena.** Solo `Applied` cambió el canon del alquiler.
- **`Applied` no se edita.** Corregirlo exige el recálculo explícito de la cadena.
- **Objetos de estado sin datos:** se crean por consulta; no se cachean para no introducir estado global.

## Pruebas

- Nuevas: `tests/Unit/AdjustmentStateBehaviorTest.php` (5) + `tests/Feature/AdjustmentStateTest.php` (3) = **8 pruebas**.
- Relacionadas: `AdjustmentChainTest`, `RentAdjustmentServiceTest`, `ReajusteCalculationTest`, `ReajusteScreensRenderTest`, `RentAdjustmentFormTest`, `RentalFacadeTest` → **83 pruebas OK**.
- Total relacionado: **91 pruebas OK**.
- Suite completa: **381 pruebas, 1837 aserciones, OK**.
  - Se ejecutó en dos procesos (`370 + 11`) por el `set_time_limit(300)` de `ListIpcSeries`; no es un fallo de código.

## Riesgos residuales

- Si se agrega un estado, hay que actualizar `RentAdjustment::state()` (derivación por fechas) además de la clase y el enum. El estado no controla sus transiciones a propósito.
- La UI ya no conoce el enum; cualquier acción nueva debe expresarse como capacidad del estado, no como comparación de tokens.
- Si en el futuro se necesita editar un reajuste aplicado, habrá que decidir si se permite y cómo se recalcula la cadena; hoy `AppliedState` lo prohíbe.

## Siguiente patrón

**#4 Memento** (cadena/historial de reajustes). Antes de implementar: explicación sencilla y aprobación explícita.
