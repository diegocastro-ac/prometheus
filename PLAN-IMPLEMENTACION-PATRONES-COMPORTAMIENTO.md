# Plan de implementación — Patrones de comportamiento (GoF)

## Objetivo
Implementar/ajustar los **patrones de comportamiento GoF faltantes** en Prometheus, siguiendo **una única opción (la mejor)** por patrón. Se prioriza **reorganizar o adaptar código existente** antes que agregar funcionalidad nueva; toda funcionalidad nueva debe aportar **valor real**. Todo lo que funciona hoy debe **seguir funcionando** (sin regresiones).

## Estado base (ya documentados)
- **Comportamiento claros (hechos):** `Strategy`, `Observer`, `Template Method`, `Chain of Responsibility` — `docs/Patrones de comportamiento/Strategy.md`, `Observer.md`, `Template Method.md`, `Chain of Responsibility.md`; mapa actualizado en `docs/MAPA-PATRONES.md`.
- **Alcance de este plan (pendientes):** State, Memento, Command, Iterator, Mediator, Visitor, Interpreter.

## Forma de trabajo (acordada)
1. Trabajaremos **patrón por patrón**, en el orden del checklist.
2. Antes de implementar cada patrón, entregaré una **explicación sencilla** de: qué cambia, cómo quedará la estructura GoF y qué archivos toca.
3. **Tú das el visto bueno** de ese patrón.
4. Solo tras tu aprobación, **implemento** ese patrón (código + pruebas necesarias + documentación/mapa de ese patrón).
5. Verificamos (pruebas relacionadas/diff) y pasamos al siguiente.

> No se implementa nada sin tu aprobación explícita del patrón en turno.

## Criterios obligatorios
- **Parcialmente implementados:** ajustar para cumplir roles/estructura clásica GoF.
- **No implementados:** cambios/funcionalidad que permitan implementación GoF clásica **solo si aportan valor real**.
- **Sin regresiones:** conservar contratos públicos (Filament, `RentalFacade`, servicios), comportamiento observable y reglas de negocio; evitar romper tests existentes.
- **Mínima complejidad:** no agregar capas “por patrón” si no simplifican o protegen el dominio.
- **Evidencia antes/después (UML):** cuando el patrón se implementa ajustando código existente (no una funcionalidad completamente nueva), su documento individual debe incluir una sección **9. Evidencia de implementación: antes y después** con **dos diagramas UML** —el diseño previo y el actual— que expliquen por qué el anterior no bastaba y qué rol GoF introduce el cambio. El código es opcional y solo el mínimo imprescindible (la línea o método que evidencia el mecanismo); no pegar bloques largos. Si el patrón es nuevo de punta a punta, el UML muestra dónde se inserta en el flujo real. Evitar duplicar el diagrama completo de §4: en §9 basta el contraste estructural.

## Contexto técnico (evidencia base)
- Stack: Laravel 12 + Filament 3, PHP 8.2. Carpeta `vendor/` y `node_modules/` excluidas del análisis.
- Subsistemas clave: ajustes IPC/cadenas (`app/Services/Adjustment/*`, `app/Models/RentAdjustment.php`, `app/Enums/AdjustmentState.php`), planes de pago (`app/Services/PaymentPlans/*`), actas/espacios-inventario (`app/Services/Acts/*`, `app/Models/Space*`), documentos, fachadas/servicios.
- Docs patrón: creacionales/estructurales `§§1–5/1–8`; comportamiento nuevos siguen formato `§§1–9` (ver `Strategy.md`/`Observer.md` y la sección 9 de evidencia antes/después).

## Orden sugerido de implementación
1. Template Method (bajo riesgo, localizado en planes de pago)
2. Chain of Responsibility (validación de reajustes)
3. State (estado derivado de reajuste)
4. Memento (cadena/historial de reajustes)
5. Command (acciones de reajuste encapsuladas)
6. Iterator (recorrido de espacios/acta, apoya Composite)
7. Visitor (operaciones sobre espacios, tras Iterator)
8. Mediator (coordinación del subsistema de reajustes)
9. Interpreter (reglas/expresiones acotadas de elegibilidad; solo variante con valor real)

> El orden puede ajustarse si lo pides, pero conviene mantener Iterator antes de Visitor y estabilizar validación/estado/cadena antes de Mediator/Command.

---

## Propuesta por patrón (opción única)

### 1) Template Method — *parcial* (ajuste GoF)
- **Qué se hará:** formalizar el esqueleto ya existente en `PaymentPlanGenerator::generate()` (método plantilla invariable, idealmente `final`) y explicitar *primitive operations/hooks* (p. ej. pasos para conteo/fechas/datos de cuota) implementados por `MonthlyPlanGenerator`, `QuincenalPlanGenerator`, `AdvancePlanGenerator`. Mantener `createInvoices()` como paso variable y **no cambiar** el contrato de salida/resolución.
- **Cómo funcionará (GoF):** `AbstractClass` = `PaymentPlanGenerator` (algoritmo fijo + hooks); `ConcreteClass` = generadores por cadencia. El cliente (`PaymentPlanService`/`RentalFacade`/resolver) sigue llamando igual.
- **Archivos/componentes:** `app/Services/PaymentPlans/PaymentPlanGenerator.php`, `MonthlyPlanGenerator.php`, `QuincenalPlanGenerator.php`, `AdvancePlanGenerator.php`, `PaymentPlanGeneratorResolver.php`, `app/Services/PaymentPlanService.php`, `app/Services/RentalFacade.php`.
- **Valor:** protege la secuencia común de facturación ante cambios; reduce duplicación/regresiones. Coexiste con la faceta *Factory Method* ya documentada (sin duplicar docs).
- **Pruebas:** `tests/Feature/PaymentPlanGeneratorTest.php` y relacionadas de plan/facturación.

### 2) Chain of Responsibility — *parcial* (ajuste GoF)
- **Qué se hará:** evolucionar la validación a cadena GoF real con `successor`/`setNext()` + `handle()` que pueda **cortar** en regla bloqueante, conservando `runAll()`/agregado de resultados para la UI actual.
- **Cómo funcionará (GoF):** `Handler` = `ReajusteRule` (base con enlace y delegación); `ConcreteHandler` = reglas (`HasIpcData`, `IsResidentialScope`, `WithinTwelveMonthsSamePrice`, `WithinCapOrAcknowledged`, etc.); `Client` = `ReajusteValidationChain`/`RentAdjustmentService`. La cadena se arma en contenedor/provider.
- **Archivos/componentes:** `app/Services/Adjustment/Validation/ReajusteValidationChain.php`, `ReajusteRule.php`, `RuleResult.php`, `AdjustmentContext.php`, reglas `app/Services/Adjustment/Validation/*`, `app/Providers/AppServiceProvider.php`, `app/Services/RentAdjustmentService.php`.
- **Valor:** detiene validaciones dependientes cuando falta un prerrequisito (p. ej., IPC), permite reordenar/extender reglas sin tocar el cliente y mantiene feedback completo donde ya se usa.
- **Pruebas:** features/unit de reajuste/validación (`tests/Feature/*Adjustment*`, `tests/Unit/*RentAdjustment*` según aplique).

### 3) State — *parcial* (ajuste GoF)
- **Qué se hará:** introducir comportamiento polimórfico por estado sin romper la derivación actual por fechas: `AdjustmentStateBehavior` + `ConcreteState` (`Registered/Notified/Applied/Closed` según dominio real) con `canNotify/canApply/canEdit/countsTowardsChain`; `AdjustmentState`/`RentAdjustment` delegan conservando métodos públicos existentes.
- **Cómo funcionará (GoF):** `Context` = `RentAdjustment`; `State` = interfaz de comportamiento; `ConcreteState` = clases por estado. El estado se **deriva** de datos persistidos (transición compatible con el modelo actual), no se inventa un workflow de DB nuevo.
- **Archivos/componentes:** `app/Enums/AdjustmentState.php`, `app/Models/RentAdjustment.php`, nuevo `app/States/Adjustment/*` (o namespace equivalente), `app/Services/RentAdjustmentService.php`, recursos/acciones Filament de reajustes.
- **Valor:** centraliza guardas por estado, facilita cambios normativos/UX y mejora testabilidad; evita condicionales duplicados.
- **Pruebas:** `tests/Unit/RentAdjustmentTest.php`, features de reajuste/cadena.

### 4) Memento — *no implementado* (formalización con valor)
- **Qué se hará:** hacer explícito el snapshot de cadena ya implícito: `RentAdjustmentMemento` (inmutable) con estado relevante de encadenamiento; `RentAdjustment` como `Originator` (`createMemento()`/aplicación interna); `AdjustmentChainCalculator`/`AdjustmentChainRecalculator` como `Caretaker`. Sin UI de “deshacer” ni tablas nuevas.
- **Cómo funcionará (GoF):** `Originator` captura/restaura estado lógico; `Memento` encapsula; `Caretaker` gestiona la secuencia al recalcular. Se apoya en historial/filas existentes.
- **Archivos/componentes:** `app/Models/RentAdjustment.php`, `app/Services/Adjustment/AdjustmentChainCalculator.php`, `AdjustmentChainRecalculator.php`, `app/Services/RentAdjustmentService.php`.
- **Valor:** recalculo de cadenas más seguro/auditable (p. ej., correcciones DANE/resync), menor riesgo al modificar encadenamiento.
- **Pruebas:** `tests/Feature/AdjustmentChainTest.php` y casos de recálculo.

### 5) Command — *no implementado* (valor en acciones discretas)
- **Qué se hará:** encapsular operaciones de reajuste como comandos de aplicación con `execute()` (p. ej. `Record/Apply/NotifyRentAdjustment`, `RecalculateAdjustmentChain`), reutilizados por `RentalFacade`/Filament; **wrappers** sobre Artisan existente cuando aporte, sin reemplazar `SyncIpcDane` ni romper CLI.
- **Cómo funcionará (GoF):** `Command` = contrato `execute()`; `ConcreteCommand` = acciones; `Receiver` = servicios/modelos (`RentAdjustmentService`, recalculador, etc.); `Invoker` = fachada/handler de acción; `Client` = UI/CLI arma el comando.
- **Archivos/componentes:** nuevo `app/Commands/Adjustment/*` o `app/Actions/Adjustment/*`, `app/Services/RentAdjustmentService.php`, `app/Services/RentalFacade.php`, `app/Services/Adjustment/AdjustmentChainRecalculator.php`, recursos Filament de reajustes, `app/Console/Commands/*` relacionados.
- **Valor:** trazabilidad, punto único para colas/reintentos futuros y pruebas por acción; desacopla UI de secuencias largas.
- **Pruebas:** tests feature/unit de acciones de reajuste + smoke de pantallas/commands existentes.

### 6) Iterator — *no implementado* (valor sobre Composite existente)
- **Qué se hará:** agregar iterador de dominio sobre el árbol de espacios (`SpaceComponent`/`SpaceComposite`/`SpaceLeaf`) construido por `SpaceTreeBuilder` (p. ej. DFS, opcional hojas), para que `DeliveryActBuilder`/`DeliveryActDraft` recorran una abstracción uniforme.
- **Cómo funcionará (GoF):** `Aggregate` = `SpaceComponent`/raíz; `ConcreteAggregate` = `SpaceComposite`; `Iterator/ConcreteIterator` = recorrido; `Client` = builder/draft/acta. Compatible con `IteratorAggregate` de PHP.
- **Archivos/componentes:** `app/Models/SpaceComponent.php`, `SpaceLeaf.php`, `SpaceComposite.php`, `app/Services/Acts/SpaceTreeBuilder.php`, `DeliveryActBuilder.php`, `DeliveryActDraft.php`.
- **Valor:** recorrido único y testeable del inventario/espacios (complementa Composite), evita lógica de árbol duplicada.
- **Pruebas:** tests de actas/builder/espacios existentes; añadir caso de recorrido si falta.

### 7) Visitor — *no implementado* (valor tras Iterator/Composite)
- **Qué se hará:** doble despacho sobre espacios para operaciones separadas de la estructura: `SpaceVisitor` + visitantes concretos con valor real (p. ej. resumen/conteo de inventario, aplanado/líneas para render/acta). `accept()` en elementos.
- **Cómo funcionará (GoF):** `Visitor/ConcreteVisitor`; `Element` = `SpaceComponent`; `ConcreteElement` = `SpaceLeaf/SpaceComposite`; `ObjectStructure` = árbol.
- **Archivos/componentes:** modelos `Space*`, `SpaceTreeBuilder`, `DeliveryActBuilder`/`DeliveryActDraft`, y puntos de documentos/render de acta si consumen el resultado (`app/Documents/*` según corresponda).
- **Valor:** nuevas operaciones sobre actas sin engordar nodos ni condicionales por tipo.
- **Pruebas:** unit del visitante + integración en generación de acta/documento.

### 8) Mediator — *no implementado* (valor en subsistema sensible)
- **Qué se hará:** centralizar la coordinación del flujo de reajuste (validar → registrar/aplicar → recalcular cadena → efectos colaterales permitidos) en `AdjustmentWorkflowMediator` (nombre tentativo), reduciendo llamadas en cascada entre colegas. `RentalFacade` puede delegar operaciones de ajuste al mediador.
- **Cómo funcionará (GoF):** `Mediator/ConcreteMediator` orquesta; `Colleagues` = validación (`ReajusteValidationChain`), `RentAdjustmentService` (núcleo), `AdjustmentChainCalculator/Recalculator`, persistencia `Rental/RentAdjustment`.
- **Archivos/componentes:** `app/Services/RentAdjustmentService.php`, `app/Services/Adjustment/AdjustmentChainCalculator.php`, `AdjustmentChainRecalculator.php`, `app/Services/Adjustment/Validation/ReajusteValidationChain.php`, `app/Services/RentalFacade.php`, `app/Models/RentAdjustment.php`, `app/Models/Rental.php`.
- **Valor:** menor acoplamiento y riesgo de regresión en el flujo más sensible (canon/topes/cadena).
- **Pruebas:** `tests/Feature/AdjustmentChainTest.php`, `RentAdjustmentServiceTest.php`, `RentalFacadeTest.php` y flujos Filament relacionados.

### 9) Interpreter — *no implementado* (acotado, valor real)
- **Qué se hará:** mini-intérprete **acotado** para componer reglas de elegibilidad de reajuste sobre `AdjustmentContext`: `Expression::interpret()` con terminales (predicados ya existentes: IPC disponible, ámbito residencial, plazo/cap/geografía, etc.) y no-terminales `And/Or/Not`. **AST en código/config** (sin parser de texto libre) para seguridad/mantenibilidad/YAGNI, salvo que se justifique configuración textual.
- **Cómo funcionará (GoF):** `AbstractExpression` = `Expression`; `Terminal` = predicados; `Nonterminal` = combinadores; `Context` = `AdjustmentContext`; `Client` = validación/servicio.
- **Archivos/componentes:** nuevo `app/Services/Adjustment/Interpreter/*` (o `Rules/Expressions/*`), `app/Services/Adjustment/Validation/AdjustmentContext.php` y reglas `Validation/*`, `app/Services/RentAdjustmentService.php`, opcional `app/Settings/AppSettings.php`/config si se externaliza.
- **Valor:** componer políticas normativas sin ramificación creciente, reutilizando chequeos probados. Si no hay necesidad de políticas configurables, se limita a AST interno.
- **Pruebas:** unit de expresiones + integración con validación de reajustes.

---

## Checklist maestro

### Flujo por patrón (repetir en cada uno)
- [ ] Recibir explicación sencilla del patrón en turno.
- [ ] Aprobación explícita del usuario.
- [ ] Implementar (reorganización primero, sin romper contratos).
- [ ] Ejecutar pruebas relacionadas (y las afectadas).
- [ ] Crear/actualizar doc del patrón (`docs/Patrones de comportamiento/<Patron>.md`, §§1–9).
- [ ] Añadir la sección de evidencia antes/después en UML cuando el patrón ajuste código existente (ver criterios obligatorios).
- [ ] Actualizar `docs/MAPA-PATRONES.md` (fila/total/estado).
- [ ] Registrar notas de regresión/decisiones.

### Patrones
- [x] 1. Template Method — ajuste + doc + mapa
- [x] 2. Chain of Responsibility — ajuste + doc + mapa
- [ ] 3. State — ajuste + doc + mapa
- [ ] 4. Memento — implementación + doc + mapa
- [ ] 5. Command — implementación + doc + mapa
- [ ] 6. Iterator — implementación + doc + mapa
- [ ] 7. Visitor — implementación + doc + mapa
- [ ] 8. Mediator — implementación + doc + mapa
- [ ] 9. Interpreter — implementación + doc + mapa

### Cierre
- [ ] Verificación final de enlaces/consistencia en `docs/MAPA-PATRONES.md`.
- [ ] Resumen de cambios, pruebas ejecutadas y riesgos residuales.

## Notas de regresión (a mantener)
- No cambiar el significado legal/contable de cálculos IPC, topes, pactos ni facturación.
- No romper acciones/pantallas Filament ni métodos usados por `RentalFacade`.
- Preferir adaptadores/wrappers y métodos compatibles; deprecar internamente antes de eliminar.
- Cualquier desviación del GoF “puro” debe justificarse por compatibilidad con Laravel/Eloquent/Filament y documentarse.

---

## Bitácora de implementación (memoria de contexto)

> Esta sección se actualiza después de cada patrón para no depender del historial de la conversación.

### 2026-11-06 — #1 Template Method (HECHO)

**Estado:** implementado, probado y documentado.

**Cambios de código**
- `app/Services/PaymentPlans/PaymentPlanGenerator.php`
  - constructor único con `Rental`, `InvoiceNumberService`, `AppSettings`, `BusinessCalendar`;
  - `generate()` ahora es `final`: `createInvoices()` → `refreshStatus()` por factura → devuelve;
  - `createInvoices()` sigue siendo la operación primitiva abstracta;
  - nuevos pasos comunes: `ensureInvoice()`, `invoiceAttributes()`, `findInvoice()` (hook) y `dueDateFrom()` (se conserva).
- `app/Services/PaymentPlans/MonthlyPlanGenerator.php`
  - eliminado su constructor; usa `ensureInvoice()`;
  - sobrescribe `findInvoice()` para deduplicar solo por `rental + user + period` (comportamiento previo).
- `app/Services/PaymentPlans/QuincenalPlanGenerator.php`
  - eliminado su constructor y `createOrEnsure()`; usa `ensureInvoice()` con el hook por defecto (`period + issued_at`).
- `app/Services/PaymentPlans/AdvancePlanGenerator.php`
  - eliminado su constructor; usa `ensureInvoice()`.
- **No se tocaron** `PaymentPlanService`, `PaymentPlanGeneratorResolver` ni `RentalFacade` (el resolver sigue llamando con los mismos 4 argumentos).

**Decisiones a recordar**
- La deduplicación **mensual** es por periodo; quincenal/anticipo por periodo + fecha de emisión. No homogenizar: es una diferencia real del dominio.
- El refresco de estado se movió de “por factura dentro del bucle” a “después de crear todos los cortes”. El estado final es idéntico porque cada factura calcula su estado con sus propios pagos. Tenerlo en cuenta al depurar.
- No se añadieron hooks vacíos (`beforeGenerate`/`afterGenerate`); el esqueleto solo tiene pasos con efecto.
- `generate()` es `final` a propósito: una necesidad futura de otro orden debe resolverse con composición u otro template, no sobrescribiéndolo.

**Docs/mapa**
- Nuevo `docs/Patrones de comportamiento/Template Method.md` (§§1–9; §9 con evidencia antes/después).
- `docs/MAPA-PATRONES.md`: nueva fila y total 14 → 15.
- `docs/Patrones creacionales/Factory Method.md`: enlace cruzado a la lectura de Template Method.

**Verificación**
- `php artisan test tests/Feature/PaymentPlanGeneratorTest.php tests/Feature/InvoicePeriodTest.php tests/Feature/BusinessCalendarTest.php tests/Feature/SeedingTest.php` → 30 pruebas OK.
- `php artisan test` → **368 pruebas, 1764 aserciones**, todas OK.
- `vendor/bin/pint app/Services/PaymentPlans` → OK.

**Siguiente:** #2 Chain of Responsibility (validación de reajustes). Antes de implementar, entregar explicación sencilla y esperar aprobación explícita.

### 2026-11-06 — #2 Chain of Responsibility (HECHO)

**Estado:** implementado, probado y documentado.

**Cambios de código**
- `app/Services/Adjustment/Validation/ReajusteRule.php`
  - pasó de **interfaz** a **clase abstracta** (Handler);
  - `private ?ReajusteRule $next`, `setNext()` (devuelve el siguiente para composición fluida) y `next()`;
  - `final handle()` = modo **decisión**: corta en el primer `blocking`;
  - `final runAll()` = modo **auditoría**: recorre toda la cadena;
  - `check()`, `article()` y `name()` siguen siendo el contrato abstracto.
- `app/Services/Adjustment/Validation/ReajusteValidationChain.php`
  - recibe la **cabeza** (`ReajusteRule $head`), no un array;
  - `check()` usa `handle()`; `runAll()` y `messages()` usan el modo auditoría;
  - `ruleNames()`/`rules()` recorren los sucesores con `next()`, sin array paralelo.
- `app/Services/Adjustment/Validation/*.php` (9 reglas): `implements ReajusteRule` → `extends ReajusteRule`; la lógica interna no cambió.
- `app/Providers/AppServiceProvider.php`: la cadena se arma con `setNext()`; se eliminó el array por defecto duplicado, así que el orden vive en **un solo lugar**.
- **No se tocaron** `RentAdjustmentService`, `RentalFacade` ni pantallas Filament: los contratos y los mensajes son los mismos.

**Decisiones a recordar**
- **Solo `blocking` corta.** Los `warning` nunca cortan: exigen confirmación del usuario y deben verse todos.
- **Dos modos explícitos:** `handle()` para decidir si se guarda; `runAll()`/`messages()` para el panel de comprobaciones. No usar `runAll()` como sustituto de la decisión.
- **La composición vive solo en `AppServiceProvider`.** Si se agrega una regla, se encadena ahí; no volver a crear un array por defecto en la cadena.
- Las reglas siguen siendo **puras** (no escriben ni lanzan); el servicio decide si un bloqueo impide guardar.
- Hoy la única regla bloqueante es `HasIpcData`, por lo que el comportamiento observable de `check()` no cambia.

**Docs/mapa**
- Nuevo `docs/Patrones de comportamiento/Chain of Responsibility.md` (§§1–9; §9 con evidencia antes/después).
- Nuevo `docs/Planes/Chain of Responsibility.md` (plan/memoria específica del patrón).
- `docs/MAPA-PATRONES.md`: nueva fila y total 15 → 16.

**Verificación**
- Nuevas unitarias: `tests/Unit/ReajusteValidationChainTest.php` → 5 pruebas OK (corte en bloqueo, auditoría completa, mensajes posteriores al bloqueo, aviso no corta, orden de `setNext()`).
- Relacionadas: `AdjustmentChainTest`, `RentAdjustmentServiceTest`, `ReajusteCalculationTest`, `ReajusteScreensRenderTest`, `RentAdjustmentFormTest`, `RentalFacadeTest` → 83 + 5 = **88 pruebas OK**.
- `vendor/bin/pint` sobre los archivos tocados → OK.
- Suite completa: **373 pruebas, 1758 aserciones, OK**. Se corrió en dos procesos (`362 + 11`) porque `ListIpcSeries` ejecuta `set_time_limit(300)`, que persiste en el proceso de PHPUnit; no es un fallo de código.

**Siguiente:** #3 State (estado derivado de `RentAdjustment`). Antes de implementar, entregar explicación sencilla y esperar aprobación explícita.