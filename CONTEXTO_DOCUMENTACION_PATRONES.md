# Contexto de la documentación de patrones de diseño

> Documento de trabajo. Registra el encargo, el alcance, los patrones verificados, las correcciones aplicadas y lo que falta antes de cerrar la documentación. No es la entrega final.

- **Proyecto:** Prometheus (Laravel 12 + Filament 3, PHP 8.2)
- **Fecha de este corte:** 2026-10-25
- **Estado:** los 12 patrones están implementados y documentados. La revisión de consistencia está en curso: ya se corrigieron Prototype, Builder, Abstract Factory, Factory Method, Proxy y Flyweight. No se ha modificado código ni se ha reejecutado la suite.

---

## 1. Encargo

Documentar los patrones de diseño implementados en Prometheus con base en el código real, actualizar los documentos individuales y mantener sincronizado el documento principal del parcial.

La entrega incluye:

1. Documentos individuales de los cinco patrones creacionales.
2. Documentos individuales de los siete patrones estructurales.
3. Actualización del documento principal del parcial.
4. Documentación de apoyo solo cuando aporte detalle y no duplique los documentos de patrón.

---

## 2. Reglas de trabajo

- **Solo documentación.** No se modifica código fuente, lógica, pruebas, configuración ni migraciones.
- **No se implementan patrones.** Solo se documentan los que ya existen.
- **Verificación obligatoria.** Cada clase, ruta, método, relación y flujo debe existir en el código actual antes de escribirse.
- **No inventar.** No se agregan clases, archivos, requerimientos ni relaciones no verificadas.
- **No confundir el nombre con el patrón.** Una clase llamada `Factory` no convierte automáticamente su uso en Factory Method; los roles se comprueban en el código.
- **Jerarquía de fuentes:** código actual > documentación existente > CodeGraph. Si hay conflicto, manda el código.
- **No hacer cambios masivos sin justificación.** Cada edición debe ser revisable.
- **Pruebas bajo demanda:** no ejecutar la suite completa ni repetir pruebas ya verificadas. Ejecutar solo el filtro mínimo indispensable cuando el cambio lo requiera.

### Criterios de documentación

1. **Núcleo común de cinco apartados:** 1) nombre y propósito, 2) problema que resuelve, 3) solución y roles, 4) diagrama de clases, 5) clases participantes y valoración.
2. **Apartados opcionales de evidencia:** los documentos estructurales más recientes agregan 6) flujo de una llamada, 7) pruebas y 8) decisión de alcance. No sustituyen el núcleo; amplían la sustentación cuando el patrón tiene varios casos o una decisión de diseño relevante.
3. **Concisión:** la información esencial se integra en los apartados; no se duplica el mismo contenido en varios documentos.
4. **Roles GoF en los diagramas:** cada clase muestra su rol junto al nombre real. Las clases que no cumplen un rol GoF se marcan `(no GoF)`.
5. **Verificación de roles:** los roles se asignan por responsabilidad real, no por semejanza de nombres.
6. **Compatibilidad:** los diagramas deben seguir siendo válidos y legibles en PlantUML y Draw.io.

---

## 3. Patrones implementados

Los 12 patrones fueron verificados en el código y tienen documento individual.

| Patrón | Implementación verificada | Archivos clave | Documento |
| --- | --- | --- | --- |
| **Singleton** | `AppSettings` y `CurrentUserContextInterface` registrados como singleton | `app/Settings/AppSettings.php`, `app/Infrastructure/AuthUserContext.php`, `app/Providers/AppServiceProvider.php` | `docs/Patrones creacionales/Singleton.md` |
| **Prototype** | `Prototype`, `__clone()` en `DeliveryAct` y `ActItem`, cliente `DeliveryActDraft` | `app/Contracts/Prototype.php`, `app/Models/DeliveryAct.php`, `app/Models/ActItem.php`, `app/Services/Acts/DeliveryActDraft.php` | `docs/Patrones creacionales/Prototype.md` |
| **Builder** | `DeliveryActBuilder` scoped, secciones opcionales y `build()` persistente | `app/Services/Acts/DeliveryActBuilder.php` | `docs/Patrones creacionales/Builder.md` |
| **Abstract Factory** | `DocumentFactory` con tres familias y cuatro productos | `app/Documents/Factories/`, `app/Documents/DocumentFactoryLocator.php` | `docs/Patrones creacionales/Abstract Factory.md` |
| **Factory Method** | `PaymentPlanGenerator` y tres subclases por cadencia | `app/Services/PaymentPlans/`, `app/Services/PaymentPlanService.php` | `docs/Patrones creacionales/Factory Method.md` |
| **Adapter** | `BusinessCalendar` y `NagerDateBusinessCalendar`; segunda aplicación para IPC | `app/Contracts/BusinessCalendar.php`, `app/Contracts/HolidaySource.php`, `app/Infrastructure/Holidays/`, `app/Contracts/Ipc/IpcProvider.php`, `app/Infrastructure/Ipc/` | `docs/Patrones estructurales/Adapter.md` |
| **Bridge** | `AbstractDocument` como abstracción; `DocumentRenderer` como implementador | `app/Documents/AbstractDocument.php`, `app/Documents/Rendering/` | `docs/Patrones estructurales/Bridge.md` |
| **Composite** | `SpaceComponent`, `SpaceLeaf` y `SpaceComposite`; árbol construido por `SpaceTreeBuilder` | `app/Models/SpaceComponent.php`, `app/Models/SpaceLeaf.php`, `app/Models/SpaceComposite.php`, `app/Services/Acts/SpaceTreeBuilder.php` | `docs/Patrones estructurales/Composite.md` |
| **Decorator** | `PactadoCapDecorator` envuelve `IpcBasisStrategy` y limita el porcentaje pactado | `app/Services/Adjustment/Decorators/PactadoCapDecorator.php`, `app/Services/RentAdjustmentService.php` | `docs/Patrones estructurales/Decorator.md` |
| **Facade** | `RentalFacade` coordina cobros, pagos, reajustes, documentos y fechas | `app/Services/RentalFacade.php` | `docs/Patrones estructurales/Facade.md` |
| **Flyweight** | `InventoryItemFactory` comparte instancias de `MasterInventoryItem` por arrendador | `app/Services/InventoryItemFactory.php`, `app/Contracts/InventoryItemFlyweight.php`, `app/Models/MasterInventoryItem.php` | `docs/Patrones estructurales/Flyweight.md` |
| **Proxy** | `CachedHolidaySource` para festivos y `DocumentProxy` para documentos | `app/Infrastructure/Holidays/CachedHolidaySource.php`, `app/Documents/DocumentProxy.php`, `app/Documents/DocumentService.php` | `docs/Patrones estructurales/Proxy.md` |

---

## 4. Verificación por patrón

### 4.1. Singleton

- `AppSettings` es `final`, inmutable y se construye con `fromDatabase()` o `defaults()`.
- Se registra con `$this->app->singleton(AppSettings::class, fn () => AppSettings::fromDatabase())`.
- `CurrentUserContextInterface` también es singleton; es una segunda aplicación del patrón.
- Campos actuales: `businessName`, `taxId`, `address`, `currency`, `logoPath`, `email`, `phone`, `invoiceDueDays`, `legalFooter`, `defaultCityCode`.

### 4.2. Prototype

- `Prototype` declara únicamente `__clone(): void`.
- `DeliveryAct::__clone()` hace copia profunda del acta y de su inventario, limpia el evento anterior y redefine `occurred_at` a hoy.
- `ActItem::__clone()` limpia `id`, `exists` y `delivery_act_id`; conserva `space_id` y `master_inventory_item_id`.
- `DeliveryActDraft::formState()` traduce el clon al formulario. Solo resuelve un id por nombre cuando el acta antigua no tiene la referencia.
- La entrada visual es la acción `derive` del listado, que abre `CreateDeliveryAct` con `?from=<id>`.

### 4.3. Builder

- `DeliveryActBuilder` se registra como `scoped`; opcionalmente recibe `InventoryItemFactory` para pruebas.
- Métodos: `forRental()`, `ofType()`, `onDate()`, `withParties()`, `withMeterReadings()`, `withInventory()`, `withCommitments()`, `withSignatures()` y `build()`.
- `withInventory()` resuelve el espacio por `space_id` en el catálogo del arrendador y el elemento por `master_inventory_item_id` o nombre. Guarda nombres resueltos como snapshot.
- `build()` valida los obligatorios, persiste el acta y crea las filas ya resueltas. No vuelve a buscar espacios por nombre.
- No hay interfaz `Builder` ni `Director` separados: `DeliveryActBuilder` es el builder concreto y `CreateDeliveryAct::assemble()` cumple el papel de director.

### 4.4. Abstract Factory

- `DocumentFactory` declara cuatro productos: `paymentReceipt()`, `adjustmentLetter()`, `monthlyStatement()` y `rentInvoice()`.
- Familias: `TextDocumentFactory`, `PdfDocumentFactory` y `JsonDocumentFactory`.
- `DocumentFactoryLocator` traduce el formato; `for(null)` usa texto y un formato desconocido lanza `InvalidArgumentException`.
- Los métodos de la fábrica devuelven tipos concretos; `DocumentService` recupera el contrato `Document` al devolver un `DocumentProxy`.
- `DocumentService` elige la familia, crea el descriptor y difiere la construcción del producto hasta que se pide `content()`.
- El Proxy está fuera del patrón Abstract Factory: la fábrica sigue produciendo el producto concreto.

### 4.5. Factory Method

- `PaymentPlanGenerator` es el creador abstracto; `createInvoices()` es el método de fábrica y `generate()` el método plantilla.
- Subclases: `MonthlyPlanGenerator`, `QuincenalPlanGenerator` y `AdvancePlanGenerator`.
- `PaymentPlanGeneratorResolver` es una fábrica simple/selector (no GoF).
- `PaymentPlanService` es el cliente del creador; `RentalFacade::generatePaymentPlan()` delega en él.
- `CreateRental` llama a `RentalFacade`, no a `PaymentPlanService` directamente.
- El producto es `Invoice` en las tres cadencias; varía la política de creación, no el tipo de producto.

### 4.6. Adapter

- **Target:** `BusinessCalendar` (`isBusinessDay()` y `nextBusinessDay()`).
- **Adapter de negocio:** `NagerDateBusinessCalendar`, que compone un `HolidaySource`.
- **Adapter HTTP:** `NagerDateHolidaySource`, que adapta la API Nager.Date.
- **Adaptee:** la API Nager.Date.
- **Respaldo:** `WeekendBusinessCalendar` es otra implementación del Target, no un Adapter.
- **Segunda aplicación (IPC):** Target `IpcProvider`; adaptadores `DaneCdaIpcProvider` y `LocalSeriesIpcProvider`.
- La caché y la degradación de festivos viven en `CachedHolidaySource`, no en el Adapter.

### 4.7. Bridge

- **Abstraction:** `AbstractDocument` contiene `DocumentBody` y `DocumentRenderer`.
- **RefinedAbstraction:** `PaymentReceipt`, `RentInvoice`, `AdjustmentLetter` y `MonthlyStatement`.
- **Implementor:** `DocumentRenderer`; **ConcreteImplementor:** `PlainTextRenderer`, `PdfRenderer` y `JsonRenderer`.
- `PdfRenderer::selectView()` compara la clase del documento con las abstracciones refinadas; es una desviación real del modelo clásico.
- `Document::isEmpty()` forma parte de la abstracción y `MonthlyStatement` lo sobrescribe.

### 4.8. Composite

- `SpaceComponent` define la hoja y el compuesto; `SpaceLeaf` representa un espacio sin hijos y `SpaceComposite` agrupa hijos.
- El árbol se persiste con `Space.parent_id`; no se guarda una estructura serializada.
- `SpaceTreeBuilder::forUser()` carga el catálogo una vez y arma el árbol en memoria.
- Las operaciones de recorrido, búsqueda y nombres únicos se resuelven sobre el componente.
- El Composite no reemplaza el catálogo de espacios: lo organiza para la interfaz y para el inventario.

### 4.9. Decorator

- `IpcBasisStrategy` es el componente; `PactadoCapDecorator` es el decorador concreto.
- El decorador envuelve una estrategia y aplica `min(pactado, legal)`, por lo que solo puede bajar el porcentaje.
- `legalSnapshot()` conserva el IPC legal sin aplicar el pacto; `resolve()` entrega el porcentaje aplicado.
- `RentAdjustmentService` construye la cadena y conserva separadas las cifras legal y aplicada.

### 4.10. Facade

- `RentalFacade` concentra las operaciones de alquileres, pagos, reajustes y documentos.
- Depende de `PaymentPlanService`, `PaymentRecordingService`, `RentAdjustmentService`, `DocumentService`, `AdjustmentChainCalculator`, `AdjustmentChainRecalculator` y `AdjustmentDateProposer`.
- No agrega reglas de negocio: delega en los servicios y conserva el punto de entrada único.
- No absorbe accesos auxiliares del formulario ni notificaciones.

### 4.11. Flyweight

- `InventoryItemFlyweight` expone `id()`, `name()` y `ownerId()`; `MasterInventoryItem` es la implementación compartida.
- `InventoryItemFactory` se registra como `scoped` y mantiene un pool por arrendador.
- `resolve()` busca en el pool, consulta la base y crea el elemento si no existe; normaliza el nombre para evitar duplicados por mayúsculas.
- `ActItem` conserva `master_inventory_item_id` como referencia extrínseca y `name` como snapshot.
- `DeliveryActDraft` recupera la referencia al editar actas existentes.

### 4.12. Proxy

Hay dos aplicaciones verificadas del mismo patrón:

- **Festivos:** Subject `HolidaySource`, Proxy `CachedHolidaySource`, RealSubject `NagerDateHolidaySource`, Client `NagerDateBusinessCalendar`. La política es caché, degradación a lista vacía y reintento tras fallo.
- **Documentos:** Subject `Document`, Proxy `DocumentProxy`, RealSubject el documento concreto de la familia, Client `DocumentService` y las pantallas. La política es auditoría, construcción diferida y bloqueo del contenido si falla el registro.
- `DocumentProxy` audita solo `content()`, no la metadata; memoriza el documento real y no registra dos veces el mismo contenido.
- `DocumentService` valida el comprobante antes de crear el Proxy, recibe modelos ya acotados por las relaciones y toma `auth()->id()` para el descriptor. La autorización no vive en el Proxy.

---

## 5. Documento principal del parcial

- PDF original: `Parcial 2- Implementación de Patrones de Diseño Estructurales.pdf` (9 páginas).
- **Versión Markdown:** `docs/DOCUMENTO-PRINCIPAL-PARCIAL-2.md`.
- El documento principal está en versión 1.4 y enumera los 12 patrones.
- Incluye portada, índice, introducción, requerimientos `RF-01` a `RF-18`, casos de uso, diseño conceptual, C4, UML, control de cambios, frontend, backend, base de datos, mapa de patrones y anexos.
- Las secciones de patrones del PDF original estaban en gran parte como plantilla; la versión Markdown ya las desarrolla.
- El PDF queda como referencia histórica y no debe incluirse en el commit documental.

---

## 6. Inventario documental

| Archivo | Tipo | Estado |
| --- | --- | --- |
| `docs/DOCUMENTO-PRINCIPAL-PARCIAL-2.md` | Documento principal | Versión 1.4, 12 patrones; revisar enlaces y conteos |
| `docs/Patrones creacionales/Singleton.md` | Patrón | Documentado; revisión final pendiente |
| `docs/Patrones creacionales/Prototype.md` | Patrón | Corregido: IDs conservados y resolución legacy por nombre |
| `docs/Patrones creacionales/Builder.md` | Patrón | Corregido: resolución por ID y snapshot en `withInventory()` |
| `docs/Patrones creacionales/Abstract Factory.md` | Patrón | Corregido: `DocumentService` devuelve `DocumentProxy` |
| `docs/Patrones creacionales/Factory Method.md` | Patrón | Corregido: entrada vía `RentalFacade` |
| `docs/Patrones estructurales/Adapter.md` | Patrón | Documentado; revisión final pendiente |
| `docs/Patrones estructurales/Bridge.md` | Patrón | Documentado; revisión final pendiente |
| `docs/Patrones estructurales/Composite.md` | Patrón | Documentado; revisión final pendiente |
| `docs/Patrones estructurales/Decorator.md` | Patrón | Documentado; revisión final pendiente |
| `docs/Patrones estructurales/Facade.md` | Patrón | Documentado; revisión final pendiente |
| `docs/Patrones estructurales/Flyweight.md` | Patrón | Corregido: `DeliveryActDraft::formState()` |
| `docs/Patrones estructurales/Proxy.md` | Patrón | Corregido: prueba de metadata sin `deliveryLabel()` |
| `docs/PROXY-FESTIVOS.md` | Apoyo | Detalle del Proxy de festivos; fuente secundaria |
| `docs/ADAPTER-DIAS-HABILES.md` | Apoyo | Útil; fragmentos y alcance desactualizados |
| `docs/DOCUMENTACION-PATRONES.md` | Integrador | Desactualizado; no cubre Prototype ni los patrones nuevos |
| `docs/DOCUMENTACION-FLUJOS.md` | Flujos | Desactualizado en catálogo, firmas y `AppSettings` |
| `docs/GUIA-SUSTENTACION-BUILDER-SINGLETON.md` | Guía oral | Desactualizada; afirma cosas que el código ya corrigió |
| `docs/PLAN-PATRONES.md`, `docs/PLAN-UML-PATRONES.md`, `docs/UML-PLANTUML-PATRONES.md` | Planificación | Históricos; no describen el estado final |
| `docs/PATRONES-IPC-REAJUSTES.md`, `docs/MODULO-IPC.md` | Apoyo IPC | Fuente secundaria para Adapter y Decorator |
| `docs/AUDITORIA_SOLID.md`, `docs/PLAN-MEJORAS.md` | Contexto | No son entrega de patrones |

---

## 7. Correcciones aplicadas y pendientes

### 7.1. Correcciones aplicadas

1. **Prototype:** la copia conserva `space_id` y `master_inventory_item_id`; ya no dice que el Builder resuelve siempre el espacio por nombre.
2. **Builder:** `withInventory()` resuelve espacio y elemento contra el catálogo; `build()` solo persiste las filas ya resueltas.
3. **Abstract Factory:** `DocumentService` devuelve `Document` mediante `DocumentProxy`; se eliminó la etiqueta “fachada”.
4. **Factory Method:** `CreateRental` llama `RentalFacade::generatePaymentPlan()`, que delega en `PaymentPlanService`.
5. **Proxy:** la prueba de metadata ya no atribuye `deliveryLabel()` al test.
6. **Flyweight:** el diagrama usa `DeliveryActDraft::formState()` y no el inexistente `fromAct()`.

### 7.2. Pendientes de revisión

- Verificar de nuevo Singleton, Adapter, Bridge, Composite, Decorator y Facade contra el código y el documento principal.
- Revisar que las rutas, métodos y conteos de pruebas citados en los 12 documentos sigan vigentes.
- Decidir si los documentos de apoyo desactualizados se corrigen, se reemplazan o se dejan como históricos.

### 7.3. Discrepancias históricas que siguen en documentos de apoyo

- `DOCUMENTACION-PATRONES.md` deja fuera Prototype y describe mal el Factory Method.
- `GUIA-SUSTENTACION-BUILDER-SINGLETON.md` usa referencias antiguas y afirma que el Builder crea espacios.
- `PLAN-UML-PATRONES.md` y `UML-PLANTUML-PATRONES.md` conservan campos eliminados de `AppSettings` y dependencias antiguas.
- `DOCUMENTACION-FLUJOS.md` repite el catálogo en `AppSettings` y usa firmas anteriores.

---

## 8. Fuentes de verdad

- Código de `app/` para clases, métodos, dependencias y flujos.
- `app/Providers/AppServiceProvider.php` para bindings y alcance de las instancias.
- `tests/Feature/` y `tests/Unit/` para comportamientos verificables.
- Pruebas relevantes: `AppSettingsSingletonTest`, `DeliveryActBuilderTest`, `DeliveryActPrototypeTest`, `DocumentFactoryTest`, `JsonDocumentBridgeTest`, `PaymentPlanGeneratorTest`, `BusinessCalendarTest`, `CachedHolidaySourceTest`, `DocumentProxyTest`, `SpaceHierarchyCompositeTest`, `InventoryItemFlyweightTest`, `RentalFacadeTest` y `PactadoCapDecoratorTest`.
- CodeGraph solo como apoyo de navegación; no reemplaza la lectura del código.
- La documentación existente se usa como referencia, no como verdad.

Nota de entorno: el comando `codegraph` directo está bloqueado por la política de ejecución de PowerShell. La variante que funciona es:

```powershell
& "C:\nvm4w\nodejs\codegraph.cmd" explore "..."
```

Las búsquedas deben hacerse con `findstr` o `Get-ChildItem`; `rg` no está disponible y las herramientas `glob`/`grep` fallan en este entorno.

---

## 9. Entregables

```text
docs/
  DOCUMENTO-PRINCIPAL-PARCIAL-2.md
  Patrones creacionales/
    Singleton.md
    Prototype.md
    Builder.md
    Abstract Factory.md
    Factory Method.md
  Patrones estructurales/
    Adapter.md
    Bridge.md
    Composite.md
    Decorator.md
    Facade.md
    Flyweight.md
    Proxy.md
CONTEXTO_DOCUMENTACION_PATRONES.md
```

Cada documento de patrón contiene los cinco apartados comunes. Los documentos de Composite, Decorator, Facade, Flyweight y Proxy agregan flujo, pruebas y decisión de alcance cuando aportan evidencia específica.

---

## 10. Convenciones de diagramas

- PlantUML compatible con Draw.io.
- Cada clase muestra su rol GoF junto al nombre real.
- Las clases que no cumplen un rol GoF se marcan `(no GoF)`.
- Los roles se asignan por responsabilidad verificada, no por semejanza de nombres.
- Nombres reales de clases conservados mediante alias o etiquetas.
- Rutas completas en la tabla de clases, no en el diagrama.
- Sin clases hipotéticas ni relaciones no verificadas.
- Cardinalidades solo cuando estén comprobadas en modelos o migraciones.
- Sin diagramas de secuencia salvo que sean imprescindibles.

---

## 11. Estado actual

- **Análisis de código:** completo para los 12 patrones.
- **Documentos de patrón:** 12 creados y enlazados desde el documento principal.
- **Correcciones de esta revisión:** Prototype, Builder, Abstract Factory, Factory Method, Flyweight y Proxy.
- **Documento principal:** versión 1.4, con 12 patrones; revisión final de enlaces y conteos pendiente.
- **Commits relevantes:** `fdce28e` incorporó Composite, Flyweight, Facade y el Proxy de documentos; `01f9d43` documentó el Proxy de festivos.
- **Cambios actuales:** solo documentación; sin commit mientras no se solicite.
- **Pruebas:** no se reejecutó la suite. La verificación de esta revisión es estática contra código y pruebas.
- **PDF:** `Parcial 2- Implementación de Patrones de Diseño Estructurales.pdf` está sin seguimiento y se excluye del commit.

---

## 12. Decisiones pendientes

1. Terminar la revisión de Singleton, Adapter, Bridge, Composite, Decorator y Facade.
2. Confirmar enlaces, conteos de patrones y referencias del documento principal.
3. Decidir el destino de los documentos de apoyo desactualizados.
4. Autorizar un commit documental solo después de la revisión final; no incluir el PDF.
