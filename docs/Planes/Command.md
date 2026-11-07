# Plan e implementación — Command (acciones de reajuste)

> Documento de memoria específica del patrón. El resumen y la bitácora general viven en `PLAN-IMPLEMENTACION-PATRONES-COMPORTAMIENTO.md`.

## Estado

**Implementado** el 2026-11-07. Pruebas relacionadas y suite completa en verde.

## Objetivo

Convertir notificar, aplicar y recalcular un reajuste en comandos explícitos, sin cambiar el comportamiento observable ni las firmas públicas. Se conservan:

- los mensajes, validaciones, transacciones y valores calculados;
- los contratos de `RentAdjustmentService`, `RentalFacade` y Filament;
- el `--dry-run` seguro que dejó Memento.

## Diagnóstico inicial

- La lógica de `markNotified()` y `applyToRental()` vivía dentro de `RentAdjustmentService`, mezclada con el alta, la validación y la previsualización del IPC.
- `RentalFacade` delegaba en el servicio para notificar/aplicar, pero llamaba al recalculador directamente para recalcular.
- El comando Artisan también llamaba al recalculador por su cuenta.
- No existía un objeto que representara la operación completa con su receptor y parámetros.

## Diseño aprobado

- `App\Commands\Adjustment\AdjustmentCommand` con `execute(): mixed`.
- `NotifyRentAdjustment`: receptor `RentAdjustment`; exige el medio y guarda `notified_on`/`notification_method`.
- `ApplyRentAdjustment`: receptor `AdjustmentChainRecalculator`; comprueba `effective_from`, marca `applied_on`, recalcula y devuelve el alquiler.
- `RecalculateRentAdjustmentChain`: receptor `AdjustmentChainRecalculator`; devuelve el array actual con `memento`.
- `RentAdjustmentService` conserva `markNotified()`, `applyToRental()` y `recalculateChain()` como envoltorios que ejecutan comandos.
- `RentalFacade` conserva sus métodos y construye/ejecuta los comandos.
- `RecalculateAdjustmentChains` usa el comando en modo individual y global.
- **Sin cola, reintentos, historial ni UI nueva.**
- **El alta (`record`) queda fuera:** es un flujo de formulario con muchos parámetros y no una transición discreta.

## Archivos tocados

| Archivo | Cambio |
| --- | --- |
| `app/Commands/Adjustment/AdjustmentCommand.php` | Nuevo contrato `execute()`. |
| `app/Commands/Adjustment/NotifyRentAdjustment.php` | Nuevo comando de comunicación. |
| `app/Commands/Adjustment/ApplyRentAdjustment.php` | Nuevo comando de aplicación. |
| `app/Commands/Adjustment/RecalculateRentAdjustmentChain.php` | Nuevo comando de recálculo. |
| `app/Services/RentAdjustmentService.php` | Métodos de notificar/aplicar/recalcular delegan en comandos. |
| `app/Services/RentalFacade.php` | Construye y ejecuta los comandos; contrato intacto. |
| `app/Console/Commands/RecalculateAdjustmentChains.php` | Usa `RecalculateRentAdjustmentChain`. |
| `tests/Feature/AdjustmentCommandsTest.php` | Cobertura de los tres comandos y de la fachada. |
| `docs/Patrones de comportamiento/Command.md` | Documento del patrón con §9 UML. |
| `docs/MAPA-PATRONES.md` | Fila nueva y total 18 → 19. |

## Decisiones a recordar

- **Evidencia antes/después (UML):** §9 contrasta los métodos repartidos con el trío Command/Receiver/Invoker; solo se muestra la construcción y ejecución del comando.
- **La lógica sale del servicio, no se duplica.** `RentAdjustmentService` conserva sus métodos como envoltorios; las pruebas existentes siguen pasando.
- **La fachada es el invocador de la UI.** Filament no conoce los comandos; sigue llamando a `RentalFacade`.
- **El comando Artisan comparte el recálculo.** Ya no llama al recalculador por su cuenta.
- **El alta no se envuelve.** `record()` tiene 10 parámetros de formulario; un comando ahí sería un objeto grande sin reutilización.
- **No hay cola ni reintentos.** El contrato deja el punto de extensión, pero la ejecución sigue siendo síncrona.
- **No se agrega `undo()`.** Memento ya expone `restore()` y no hay UI de deshacer; un `undo()` en el comando obligaría a conservar la instancia y no aporta hoy.
- **Interpreter queda fuera del alcance** por decisión del usuario; no se implementará.

## Pruebas

- Nuevas: `tests/Feature/AdjustmentCommandsTest.php` → **6 pruebas OK**.
- Relacionadas: `RentAdjustmentServiceTest`, `AdjustmentChainTest`, `RentalFacadeTest`, `RecalculateAdjustmentChainsCommandTest`, `ReajusteScreensRenderTest`, `RentAdjustmentFormTest`, `AdjustmentStateTest`, `AdjustmentChainMementoTest`, `ReajusteCalculationTest`, `AdjustmentStateBehaviorTest`, `AdjustmentMementoTest` → **99 pruebas OK**.
- Total relacionado: **105 pruebas OK**.
- Suite completa: **395 pruebas, 1877 aserciones, OK**. Se corrió en dos procesos (`384 + 11`) por el `set_time_limit(300)` de `ListIpcSeries`; no es un fallo de código.

## Riesgos residuales

- La fachada y el servicio construyen comandos; si se agrega un cuarto invocador, conviene centralizar la construcción en un contenedor o fábrica.
- Los comandos no son `ShouldQueue`; ejecutarlos en una cola exigiría serializar modelos y decidir la transacción.
- `NotifyRentAdjustment` no comprueba `canNotify()`: conserva la laxitud actual del servicio y deja la visibilidad en Filament (State). Endurecerlo sería un cambio de comportamiento aparte.
- `ApplyRentAdjustment` no envuelve `applied_on` y recálculo en una transacción: mantiene el comportamiento actual. Mover esa frontera sería otra decisión.
- El alta sigue fuera del patrón; si se necesita, se documentará como comando de creación con su propio contexto.

## Siguiente patrón

**#6 Iterator** (recorrido de espacios/acta sobre Composite). Antes de implementar: explicación sencilla y aprobación explícita.
