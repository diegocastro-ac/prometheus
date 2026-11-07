# Plan e implementación — Chain of Responsibility (reajustes)

> Documento de memoria específica del patrón. El resumen y la bitácora general viven en `PLAN-IMPLEMENTACION-PATRONES-COMPORTAMIENTO.md`.

## Estado

**Implementado y verificado** el 2026-11-06 con la suite completa.

## Objetivo

Convertir la validación de reajustes en una **cadena GoF real** con sucesor (`next`/`setNext`), delegación y corte en reglas bloqueantes, conservando:

- el orden actual de las nueve reglas;
- los mismos mensajes, severidades y artículos;
- el detalle completo que consume el panel de comprobaciones del formulario;
- los contratos de `RentAdjustmentService` y `RentalFacade`.

## Diagnóstico inicial

- `ReajusteRule` era una **interfaz** con `check()`; no tenía sucesor ni delegación.
- `ReajusteValidationChain` guardaba un `array` de reglas y ejecutaba todas siempre en `runAll()`.
- El orden/composición estaba **duplicado**: array por defecto en la cadena y binding en `AppServiceProvider`.
- `HasIpcData` es la única regla que devuelve `blocking`; el resto devuelve avisos o información.
- Las reglas posteriores a un bloqueo se ejecutaban aunque no aportaran a la decisión.

## Diseño aprobado

`ReajusteRule` pasa de interfaz a **clase abstracta** con:

- `private ?ReajusteRule $next`;
- `setNext(ReajusteRule $next): ReajusteRule` (devuelve el siguiente para composición fluida);
- `next(): ?ReajusteRule`;
- `final handle(AdjustmentContext): array` → **modo decisión**: corta en el primer resultado `blocking`;
- `final runAll(AdjustmentContext): array` → **modo auditoría**: recorre toda la cadena;
- `check()`, `article()`, `name()` siguen siendo abstractos.

`ReajusteValidationChain` queda como fachada:

- constructor recibe la **cabeza** de la cadena;
- `check()` usa `handle()` y resume con `RuleResult::summary()`;
- `runAll()` y `messages()` usan el modo auditoría;
- `ruleNames()` y `rules()` recorren los sucesores.

`AppServiceProvider` arma la cadena con `setNext()` y es el único lugar donde vive el orden.

## Archivos tocados

| Archivo | Cambio |
| --- | --- |
| `app/Services/Adjustment/Validation/ReajusteRule.php` | Interfaz → clase abstracta con enlace y recorridos. |
| `app/Services/Adjustment/Validation/ReajusteValidationChain.php` | Cabeza + delegación a `handle()`/`runAll()`. |
| `app/Services/Adjustment/Validation/*.php` (9 reglas) | `implements` → `extends`. Lógica intacta. |
| `app/Providers/AppServiceProvider.php` | Composición con `setNext()`. |
| `tests/Unit/ReajusteValidationChainTest.php` | Nuevas pruebas del corte y de los dos modos. |
| `docs/Patrones de comportamiento/Chain of Responsibility.md` | Documento del patrón. |
| `docs/MAPA-PATRONES.md` | Fila nueva y total 15 → 16. |

## Decisiones a recordar

- **Evidencia antes/después (UML):** la doc individual incluye §9 con dos diagramas —el diseño previo (interfaz + arreglo de reglas, sin sucesor) y el actual (Handler con sucesor y composición en el provider)— y solo el código mínimo del corte. La instrucción general quedó en `PLAN-IMPLEMENTACION-PATRONES-COMPORTAMIENTO.md`.
- **Solo corta `blocking`, nunca `warning`.** Los avisos requieren confirmación del usuario y deben verse todos.
- **Dos modos explícitos:** decisión (`handle`) y auditoría (`runAll`). No mezclarlos: si una regla futura necesita efecto secundario, no debe vivir en la cadena.
- **El orden vive solo en el provider.** No volver a crear un array por defecto en la cadena.
- **`RuleResult::summary()` no cambia.** El resumen se calcula sobre los resultados recogidos hasta el corte; hoy solo `HasIpcData` bloquea, así que el comportamiento observable es idéntico.
- **Las reglas siguen siendo puras:** leen contexto/repositorio, no escriben ni lanzan excepciones. El servicio decide si un bloqueo impide guardar.

## Pruebas

- Nuevas unitarias: 5 pruebas (`handle` corta, `runAll` no corta, `messages` conserva avisos, aviso no corta, orden de `setNext`).
- Relacionadas ejecutadas: `AdjustmentChainTest`, `RentAdjustmentServiceTest`, `ReajusteCalculationTest`, `ReajusteScreensRenderTest`, `RentAdjustmentFormTest`, `RentalFacadeTest` → 83 pruebas + 5 nuevas = **88 OK**.
- Suite completa: **373 pruebas, 1758 aserciones, OK**.
  - Se ejecutó en dos procesos (`362 + 11`) porque `ListIpcSeries` llama a `set_time_limit(300)` dentro de su acción de sincronización; ese límite persiste en el proceso de PHPUnit y corta la corrida aunque se lance con `max_execution_time=0`. No es un fallo de código: los dos procesos pasan.

## Riesgos residuales

- Si se agrega una regla bloqueante nueva, la decisión se cortará allí. Es el comportamiento buscado.
- El modo auditoría ejecuta reglas posteriores a un bloqueo a propósito; no debe usarse para decidir si se guarda.
- Cualquier cambio de orden debe hacerse en `AppServiceProvider`, no en la cadena.

## Siguiente patrón

**#3 State** (estado derivado de `RentAdjustment`). Antes de implementar: explicación sencilla y aprobación explícita.
