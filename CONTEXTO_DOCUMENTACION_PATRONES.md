# Contexto de la documentación de patrones de diseño

> Documento de trabajo. Registra el encargo, el alcance confirmado, lo que ya se verificó en el código y las discrepancias que hay que resolver antes de escribir la documentación definitiva. No es la entrega final.

- **Proyecto:** Prometheus (Laravel 12 + Filament 3, PHP 8.2)
- **Fecha de este corte:** 2026-10-25
- **Estado:** los ocho patrones del alcance documentados con estructura simplificada y roles GoF en los diagramas; documento principal actualizado en Markdown; pendiente la revisión final y el commit documental del Proxy.

---

## 1. Encargo

Documentar los patrones de diseño implementados en Prometheus, con base en el código real, y actualizar la documentación existente y el documento principal del parcial.

La entrega debe incluir:

1. Documentos individuales de los patrones creacionales confirmados.
2. Documentos de los patrones estructurales confirmados (Adapter, Bridge y Proxy).
3. Actualización del documento principal del parcial.
4. Documentación de apoyo (diagramas, guías o resúmenes), solo si aporta y no duplica.

---

## 2. Reglas de trabajo

- **Solo documentación.** No se modifica código fuente, lógica, pruebas ni configuración funcional.
- **No se implementan patrones.** Solo se documentan los que ya existen.
- **Avance incremental.** Primero el análisis y el diagnóstico; después un patrón a la vez, con aprobación antes de continuar.
- **Verificación obligatoria.** Cada clase, ruta, método, relación y flujo debe existir en el código actual antes de escribirse.
- **No inventar.** No se agregan clases, archivos, requerimientos ni relaciones que no estén verificados.
- **No confundir el nombre con el patrón.** Una clase llamada `Factory` no convierte automáticamente su uso en Factory Method; hay que comprobar los roles.
- **Jerarquía de fuentes:** código actual > documentación existente > CodeGraph. Si hay conflicto, manda el código.
- **Alcance estructural restringido:** de los patrones estructurales están confirmados **Adapter**, **Bridge** y **Proxy**. Los demás que aparecen en el código o en documentos de planificación (Facade, Composite, Flyweight, Decorator, por ejemplo) no se documentan como implementados hasta que se autoricen.
- **No hacer cambios masivos.** Cada edición debe ser revisable y justificada.
- **Pruebas bajo demanda:** no ejecutar la suite completa ni repetir pruebas ya verificadas. Ejecutar solo el filtro mínimo indispensable cuando el cambio lo requiera; correr toda la suite consume demasiado tiempo para el ritmo de trabajo.

### Criterios permanentes de documentación

1. **Estructura fija de cinco apartados:** 1) nombre y propósito, 2) problema que resuelve, 3) solución y roles, 4) diagrama de clases, 5) clases participantes y valoración (ventajas y desventajas). No se agregan apartados de funcionamiento paso a paso, evidencia, resúmenes, relaciones entre patrones ni contenido redundante.
2. **Concisión:** la información esencial se integra en los cinco apartados. No se elimina lo necesario para entender la implementación.
3. **Roles GoF en los diagramas:** cada clase debe mostrar su rol junto al nombre real, por ejemplo `AppSettings (Singleton)`, `DeliveryAct (Prototipo concreto)` o `DeliveryActDraft (Cliente)`. Las clases que no cumplen un rol GoF se marcan `(no GoF)`.
4. **Verificación de roles:** los roles se asignan por la responsabilidad real en el código, no por semejanza de nombres. Si la implementación difiere del modelo clásico, se representa la correspondencia real.
5. **Compatibilidad:** los diagramas deben seguir siendo válidos y legibles en PlantUML y Draw.io.

---

## 3. Patrones confirmados

Los cinco creacionales y los tres estructurales fueron verificados directamente en el código. Los ocho patrones y el documento principal ya están documentados; queda la revisión final y el commit documental del Proxy.

| Patrón | Implementación verificada | Clases y archivos clave | Estado de la doc. |
| --- | --- | --- | --- |
| **Singleton** | `AppSettings` y `CurrentUserContextInterface` registrados como singleton en el contenedor | `app/Settings/AppSettings.php`, `app/Infrastructure/AuthUserContext.php`, `app/Providers/AppServiceProvider.php` | Documentado; pendiente de revisión |
| **Builder** | `DeliveryActBuilder`, registrado como `scoped`, con secciones opcionales y `build()` | `app/Services/Acts/DeliveryActBuilder.php` | Documentado; pendiente de revisión |
| **Abstract Factory** | `DocumentFactory` con tres familias (`TextDocumentFactory`, `PdfDocumentFactory`, `JsonDocumentFactory`) y cuatro productos | `app/Documents/Factories/`, `app/Documents/DocumentFactoryLocator.php` | Documentado; pendiente de revisión |
| **Factory Method** | `PaymentPlanGenerator` y tres subclases por cadencia de cobro | `app/Services/PaymentPlans/`, `app/Services/PaymentPlanService.php` | Documentado; pendiente de revisión |
| **Prototype** | `Prototype`, `__clone()` en `DeliveryAct` y `ActItem`, cliente `DeliveryActDraft` | `app/Contracts/Prototype.php`, `app/Models/DeliveryAct.php`, `app/Models/ActItem.php`, `app/Services/Acts/DeliveryActDraft.php` | Documentado; pendiente de revisión |
| **Adapter** | `BusinessCalendar` + `NagerDateBusinessCalendar` + `NagerDateHolidaySource`; segunda aplicación para el IPC | `app/Contracts/BusinessCalendar.php`, `app/Contracts/HolidaySource.php`, `app/Infrastructure/Holidays/`, `app/Contracts/Ipc/IpcProvider.php`, `app/Infrastructure/Ipc/` | Documentado; actualizado por el Proxy |
| **Bridge** | `AbstractDocument` como abstracción; `DocumentRenderer` como implementador; `PlainTextRenderer`, `PdfRenderer` y `JsonRenderer` como implementadores concretos | `app/Documents/AbstractDocument.php`, `app/Documents/Rendering/`, `app/Documents/Factories/JsonDocumentFactory.php` | Documentado; pendiente de revisión |
| **Proxy** | `HolidaySource` como Subject; `CachedHolidaySource` como Proxy; `NagerDateHolidaySource` como RealSubject/Adapter; `NagerDateBusinessCalendar` como cliente | `app/Contracts/HolidaySource.php`, `app/Infrastructure/Holidays/CachedHolidaySource.php`, `app/Infrastructure/Holidays/NagerDateHolidaySource.php`, `app/Infrastructure/Holidays/NagerDateBusinessCalendar.php`, `app/Providers/AppServiceProvider.php` | Documentado; pendiente de revisión |

### 3.1. Singleton

- `AppSettings` es `final`, tiene constructor privado y se construye con `fromDatabase()` o `defaults()`.
- Se registra con `$this->app->singleton(AppSettings::class, fn () => AppSettings::fromDatabase())`.
- La instancia es inmutable (`readonly`) y se descarta al guardar ajustes para que la siguiente petición la vuelva a leer.
- `CurrentUserContextInterface` también se registra como singleton; es una segunda aplicación del patrón, no el caso principal.
- Campos actuales de `AppSettings`: `businessName`, `taxId`, `address`, `currency`, `logoPath`, `email`, `phone`, `invoiceDueDays`, `legalFooter`, `defaultCityCode`.

### 3.2. Builder

- `DeliveryActBuilder` no tiene dependencias inyectadas; el constructor vacío es intencional.
- Se registra como `scoped`, no como singleton, porque acumula secciones durante la construcción.
- Métodos verificados: `forRental()`, `ofType()`, `onDate()`, `withParties()`, `withMeterReadings()`, `withInventory()`, `withCommitments()`, `withSignatures()` y `build()`.
- `build()` valida los datos obligatorios y solo persiste las secciones solicitadas.
- El catálogo de espacios ya no vive en `AppSettings`: se consulta en la tabla `spaces`, por dueño (`user_id`).
- El Builder ya no crea espacios faltantes; falla con un mensaje claro.
- No hay interfaz `Builder` ni `Director` separados: `DeliveryActBuilder` es el builder concreto y `CreateDeliveryAct::assemble()` cumple el papel de director.
- `withInventory()` valida contra el catálogo y resuelve el dueño; el orden respecto a `forRental()` importa.
- `build()` persiste el acta y su inventario, por lo que no es un builder puramente en memoria ni idempotente.

### 3.3. Abstract Factory

- `DocumentFactory` declara cuatro productos: `paymentReceipt()`, `adjustmentLetter()`, `monthlyStatement()` y `rentInvoice()`.
- Familias: `TextDocumentFactory`, `PdfDocumentFactory` y `JsonDocumentFactory`.
- `DocumentFactoryLocator` traduce el formato elegido a la fábrica correspondiente; `for(null)` usa texto y un formato desconocido lanza `InvalidArgumentException`.
- El binding por defecto es `TextDocumentFactory`; PDF y JSON se piden por nombre desde el locator.
- Los productos heredan de `AbstractDocument` y comparten `DocumentBody`; el renderizador de la familia decide el formato.
- La familia JSON usa `JsonRenderer` y entrega `title`, `document_type`, `fields`, `table`, `warnings`, `notes` y `footer`; excluye `meta` para no incluir la imagen del comprobante.
- `Document` declara `isEmpty()`: `AbstractDocument` devuelve `false` y `MonthlyStatement` devuelve `true` cuando no tiene facturas. `DocumentsPage` usa el contrato y ya no llama a `invoices()`. Eso prepara una eventual sustitución por un Proxy de documentos, que no forma parte de esta entrega; el Proxy implementado es el de festivos.
- Los métodos de `DocumentFactory` devuelven tipos concretos (`PaymentReceipt`, `AdjustmentLetter`, etc.), no el contrato `Document`. Es una desviación menor del modelo clásico.
- El comentario de `AppServiceProvider` dice que las fábricas son scoped, pero el binding real es `bind(DocumentFactory::class, TextDocumentFactory::class)`. No se documentan como scoped.
- La entrega de PDF, texto y JSON se unificó en `DocumentAction::download()` y `DocumentsPage::deliver()`: ambos usan `content()`, `filename()` y `mimeType()` del documento.

### 3.4. Factory Method

- `PaymentPlanGenerator` es el creador abstracto; `createInvoices()` es el método de fábrica y `generate()` el método plantilla que lo invoca.
- Subclases: `MonthlyPlanGenerator`, `QuincenalPlanGenerator` y `AdvancePlanGenerator`.
- `PaymentPlanGeneratorResolver` es una fábrica simple/selector (no GoF) que decide la subclase según `BillingCadence` (`MENSUAL`, `QUINCENAL`, `ANTICIPO`).
- `PaymentPlanService` es el cliente usado por la pantalla de alquileres; elige la cadencia explícita, la del alquiler o `MENSUAL`.
- Las subclases reciben `Rental`, `InvoiceNumberService`, `AppSettings` y `BusinessCalendar`.
- El producto es `Invoice` en las tres cadencias; lo que varía es la política de creación (cantidad, fechas y montos). Por eso el patrón se acerca también a Template Method.
- `generate()` devuelve las facturas creadas o existentes; `PaymentPlanService` no usa ese retorno.
- El Factory Method real del sistema es este, no los métodos estáticos `forInvoice()` de los documentos.

### 3.5. Prototype

- `Prototype` declara únicamente `__clone(): void`.
- `DeliveryAct::__clone()` hace copia profunda del acta y de su inventario.
- `ActItem::__clone()` conserva el contenido del elemento, pero desvincula la copia del registro original.
- `DeliveryActDraft` es el cliente: clona el acta y traduce la copia al estado del formulario.
- La entrada visual es la acción `derive` del listado, que abre `CreateDeliveryAct` con `?from=<id>`.
- La copia no se guarda sola: el guardado sigue pasando por `DeliveryActBuilder`.

### 3.6. Adapter

- **Target:** `BusinessCalendar` (`isBusinessDay()` y `nextBusinessDay()`).
- **Adapter de negocio:** `NagerDateBusinessCalendar`, que compone un `HolidaySource` y traduce festivos a días hábiles.
- **Adapter HTTP:** `NagerDateHolidaySource`, que implementa `HolidaySource` y traduce la API Nager.Date (`GET /PublicHolidays/{año}/{país}`).
- **Adaptee:** la API Nager.Date.
- **Respaldo:** `WeekendBusinessCalendar`, que solo evita fines de semana; es una implementación del Target, no un Adapter. Se usa en pruebas y como respaldo.
- **Clientes verificados:** `PaymentPlanGenerator` y sus subclases, `PaymentPlanGeneratorResolver`, `RentalPeriod`, `InvoiceResource` y `AdjustmentDateProposer`.
- El binding cambia por entorno: `WeekendBusinessCalendar` en `testing`; en producción, `NagerDateBusinessCalendar(CachedHolidaySource(NagerDateHolidaySource(...)))`.
- La caché y la degradación ya no están en el Adapter: viven en el Proxy `CachedHolidaySource`. Si Nager.Date falla, el Proxy devuelve `[]` y los festivos entre semana se tratan como hábiles; el respaldo efectivo es de fin de semana. `nextBusinessDay()` tiene tope de 366 días y un fallback explícito, pero en la práctica la degradación ocurre antes.
- **Segunda aplicación (IPC):** Target `IpcProvider`; adaptadores `DaneCdaIpcProvider` (CDA del DANE) y `LocalSeriesIpcProvider` (tabla `ipc_series`); doble de prueba `InMemoryIpcProvider` (no Adapter).
- El binding por defecto es `IpcProvider -> LocalSeriesIpcProvider`; los comandos `ipc:sync` y `ipc:sync:geographies` usan `DaneCdaIpcProvider` explícitamente y `IpcSynchronizer` lo recibe como `IpcProvider`.
- La lectura de IPC en pantallas y reajustes usa `IpcSeriesRepository` directamente en varios puntos; el Target no cubre todos los consumidores.
- `DaneCdaIpcProvider` normaliza coma decimal, meses abreviados, `PAI_ID` con ceros, columnas por nombre y nulos; reintenta con espera creciente y lanza `DaneIpcUnavailable`.

### 3.7. Bridge

- **Abstraction:** `AbstractDocument` contiene un `DocumentBody` y un `DocumentRenderer`; delega `content()`, nombre, MIME, etiqueta y modo de entrega.
- **RefinedAbstraction:** `PaymentReceipt`, `RentInvoice`, `AdjustmentLetter` y `MonthlyStatement`. Cada uno arma su cuerpo y se adjunta mediante `attachToBody()`.
- **Implementor:** `DocumentRenderer`; **ConcreteImplementor:** `PlainTextRenderer`, `PdfRenderer` y `JsonRenderer`.
- `JsonRenderer` es el tercer implementador concreto; `JsonDocumentFactory` es la familia JSON. `DocumentFactoryLocator` resuelve `text`, `pdf` y `json`.
- `PdfRenderer::selectView()` elige la plantilla comparando la clase del documento; es una desviación real porque el implementador conoce las abstracciones refinadas.
- El JSON usa valores de presentación y excluye `meta`; no es un feed contable crudo.
- Agregar un formato no obliga a tocar la lógica de los documentos; agregar un documento sí exige actualizar las tres fábricas y la selección de plantilla PDF.
- `Document::isEmpty()` forma parte de la abstracción: `AbstractDocument` responde `false` y `MonthlyStatement` lo sobrescribe. La pantalla ya no consulta `invoices()` del producto concreto.

### 3.8. Proxy

- **Subject:** `HolidaySource`, con `holidaysFor(int $year): array`.
- **Proxy:** `CachedHolidaySource`; recibe un `HolidaySource` real y el país.
- **RealSubject:** `NagerDateHolidaySource`; a la vez es Adapter de Nager.Date. Lanza `RuntimeException` si la API falla o responde con un JSON inválido.
- **Client:** `NagerDateBusinessCalendar`; ya no conoce `Http`, `Cache` ni `Log`.
- **Composición:** `NagerDateBusinessCalendar(CachedHolidaySource(NagerDateHolidaySource))` en producción; `WeekendBusinessCalendar` en `testing`.
- **Clave de caché:** `holidays:{country}:{year}`; TTL de un día (`now()->addDay()`).
- **Fallback:** tras un `Throwable` del real, `Log::warning` y `[]`; el fallo no se cachea.
- **Cálculo de días hábiles:** `isBusinessDay()` salta fines de semana y festivos; `nextBusinessDay()` tiene tope de 366 días y luego usa `WeekendBusinessCalendar`.
- **Pruebas:** `CachedHolidaySourceTest` contiene 4 pruebas y 11 aserciones por inspección estática; `BusinessCalendarTest` fue actualizado para construir la cadena completa. No se reejecutó la suite tras el commit `a4307bc`.
- **Documentos:** `docs/Patrones estructurales/Proxy.md` es el documento canónico; `docs/PROXY-FESTIVOS.md` conserva el detalle y la comparación con Adapter.

---

## 4. Adapter: dos aplicaciones verificadas

El patrón Adapter no se limita a los días hábiles. Hay una segunda aplicación para el IPC del DANE.

| Rol | Días hábiles | IPC |
| --- | --- | --- |
| Target | `BusinessCalendar` | `IpcProvider` |
| Adapter | `NagerDateHolidaySource` (Nager.Date → `HolidaySource`) y `NagerDateBusinessCalendar` (`HolidaySource` → `BusinessCalendar`) | `DaneCdaIpcProvider` y `LocalSeriesIpcProvider` |
| Adaptee | API Nager.Date | Servicio CDA del DANE / copia local en `ipc_series` |
| Cliente | Generadores de cobro, `RentalPeriod`, facturas y reajustes | `IpcSynchronizer`, comandos de sincronización |
| Proxy (no Adapter) | `CachedHolidaySource` | No aplica |

Decisión tomada: las dos aplicaciones se documentan en un solo archivo `docs/Patrones estructurales/Adapter.md`, con secciones y diagramas separados para días hábiles e IPC. La caché y la degradación de festivos se explican en `docs/Patrones estructurales/Proxy.md`, no como parte del Adapter.

---

## 5. Documento principal del parcial

- PDF original: `Parcial 2- Implementación de Patrones de Diseño Estructurales.pdf` (9 páginas).
- **Versión Markdown creada:** `docs/DOCUMENTO-PRINCIPAL-PARCIAL-2.md`.
- Contenido del PDF original: portada, índice, introducción con marcadores `XXXXX`, descripción de PROMETHEUS y requerimientos `RF-01` a `RF-11`.
- Contenido de la versión Markdown: portada, índice, introducción, requerimientos `RF-01` a `RF-18`, casos de uso, diseño conceptual, C4, UML, tablas de control de cambios, frontend, backend, base de datos y mapa de patrones.
- Las secciones de patrones (`2.1.x`, `2.2.x`) del PDF estaban en gran parte como plantilla o vacías; la versión Markdown ya las desarrolla.
- Problemas detectados:
  - `RF-06` (alquileres) no menciona la cadencia de facturación.
  - `RF-07` (pagos) sigue describiendo el modelo antiguo de booleanos y estados, reemplazado por facturas y pagos parciales.
  - Faltan módulos y funciones actuales: ajustes de empresa, espacios, actas de entrega, facturas, planes de cobro, documentos PDF/texto/JSON, reajustes con IPC, series IPC, multiempresa, cambio de idioma y perfil.
  - No había diagrama de casos de uso ni versión Markdown editable.
- Decisión tomada: crear una versión Markdown desde el PDF, actualizarla contra el código y conservar el PDF como referencia histórica.

---

## 6. Inventario documental y estado

| Archivo | Tipo | Estado |
| --- | --- | --- |
| `docs/DOCUMENTO-PRINCIPAL-PARCIAL-2.md` | Documento principal | Actualizado; versión Markdown editable del PDF, con RF-01 a RF-18 y las ocho secciones de patrones |
| `docs/Patrones creacionales/*.md` | Patrones creacionales | Documentados: Singleton, Prototype, Builder, Abstract Factory y Factory Method |
| `docs/Patrones estructurales/Adapter.md` | Patrón estructural | Documentado con las aplicaciones de días hábiles e IPC; actualizado a la cadena Client → Proxy → Adapter |
| `docs/Patrones estructurales/Bridge.md` | Patrón estructural | Documentado en cinco apartados; cubre documentos, formatos y la familia JSON |
| `docs/Patrones estructurales/Proxy.md` | Patrón estructural | Documentado en cinco apartados; cubre Subject, Proxy, RealSubject y Client |
| `docs/PROXY-FESTIVOS.md` | Documento de apoyo | Detallado y vigente; fuente secundaria del Proxy de festivos |
| `docs/DOCUMENTACION-PATRONES.md` | Documento integrador | Desactualizado; cubre cinco patrones sin Prototype y con Factory Method mal enfocado |
| `docs/ADAPTER-DIAS-HABILES.md` | Adapter | Útil y mayormente vigente; fragmentos y alcance desactualizados |
| `docs/DOCUMENTACION-FLUJOS.md` | Flujos | Desactualizado en catálogo, firmas y campos de `AppSettings` |
| `docs/GUIA-SUSTENTACION-BUILDER-SINGLETON.md` | Guía oral | Desactualizado; afirma cosas que el código ya corrigió |
| `docs/PLAN-PATRONES.md` | Planificación | Histórico; no describe el estado final |
| `docs/PLAN-UML-PATRONES.md` | Planificación UML | Desactualizado en `AppSettings` y Builder |
| `docs/UML-PLANTUML-PATRONES.md` | Diagramas | Desactualizado en campos, dependencias y constructores |
| `docs/PATRONES-IPC-REAJUSTES.md` | Patrones del IPC | Extenso; fuera del alcance actual salvo la parte de Adapter |
| `docs/MODULO-IPC.md` | Módulo IPC | Extenso; fuente secundaria para la segunda aplicación del Adapter |
| `docs/AUDITORIA_SOLID.md` | Auditoría | No es documentación de patrones; puede servir como contexto |
| `docs/PLAN-MEJORAS.md` | Planificación | No es entrega de patrones |
| `docs/guia-api-ipc-dane-laravel.md` | Guía técnica | Contexto del Adapter del IPC |
| `PROTOTYPE-PLANTUML.md` | Prototype | Útil; revisar cardinalidad y nombres |
| `PLAN-UML-PROTOTYPE.md` | Prototype | Planificación; revisar antes de reutilizar |

---

## 7. Discrepancias detectadas

1. **`DOCUMENTACION-PATRONES.md`**
   - Dice que documenta los cinco patrones principales, pero deja fuera Prototype.
   - El apartado de Factory Method describe los métodos estáticos `forInvoice()` de los documentos, no el Factory Method GoF de los planes de cobro.
   - El Builder aparece usando el catálogo de `AppSettings`, que ya no existe.
   - El Adapter se limita a días hábiles y no menciona la aplicación del IPC.

2. **`PLAN-PATRONES.md`**
   - Es un plan histórico de cuatro patrones; no refleja Prototype ni la implementación final.
   - Habla de "más de quince secciones" y del catálogo en `AppSettings`.

3. **`GUIA-SUSTENTACION-BUILDER-SINGLETON.md`**
   - Usa referencias de línea antiguas.
   - Dice que el Builder crea espacios y que cuatro pruebas fallan por `user_id`; el código actual ya no crea espacios y resuelve el dueño del catálogo.
   - Sigue ubicando el catálogo en `AppSettings`.
   - Nombra métodos y dependencias que cambiaron.

4. **`PLAN-UML-PATRONES.md` y `UML-PLANTUML-PATRONES.md`**
   - `AppSettings` conserva campos eliminados: `spaceCatalog`, `primaryColor`, `fontFamily`.
   - El Builder sigue dependiendo de `AppSettings`.
   - Los constructores de los generadores no incluyen el `BusinessCalendar`.
   - Hay diagramas de estados/planificación que no corresponden al código final.

5. **`DOCUMENTACION-FLUJOS.md`**
   - Repite el catálogo en `AppSettings`.
   - Los flujos de Factory Method y Abstract Factory usan firmas y pasos anteriores.
   - La descripción de facturas y pagos no coincide con el modelo actual.

6. **`ADAPTER-DIAS-HABILES.md`**
   - El fragmento de `nextBusinessDay()` no coincide con la implementación actual (el código usa un `for` con tope y respaldo).
   - No incluye el Adapter del IPC.
   - El alcance enumerado omite `AdjustmentDateProposer`, que también consume el Target.
   - Las referencias de línea pueden haber cambiado.

7. **`PROTOTYPE-PLANTUML.md`**
   - La cardinalidad del inventario debe revisarse: los elementos son opcionales, no obligatorios.
   - Conviene alinear nombres y relaciones con `DeliveryActDraft` y el flujo `?from=`.

8. **Documento principal (PDF)**
   - Requerimientos desactualizados y módulos nuevos sin incluir.
   - Se corrigió con la versión Markdown `docs/DOCUMENTO-PRINCIPAL-PARCIAL-2.md`; el PDF queda como referencia histórica.

---

## 8. Fuentes de verdad

- Código de `app/` para clases, métodos, dependencias y flujos.
- `tests/Feature/` para confirmar comportamientos: `AppSettingsSingletonTest`, `DeliveryActBuilderTest`, `DeliveryActPrototypeTest`, `DocumentFactoryTest`, `JsonDocumentBridgeTest`, `PaymentPlanGeneratorTest`, `BusinessCalendarTest`, `CachedHolidaySourceTest`.
- `app/Providers/AppServiceProvider.php` para bindings y alcance de las instancias.
- CodeGraph solo como apoyo de navegación; no reemplaza la lectura del código.
- La documentación existente se usa como referencia, no como verdad.

Nota de entorno: el comando `codegraph` directo está bloqueado por la política de ejecución de PowerShell. La variante que funciona es:

```powershell
& "C:\nvm4w\nodejs\codegraph.cmd" explore "..."
```

---

## 9. Entregables y estructura prevista

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
    Proxy.md
CONTEXTO_DOCUMENTACION_PATRONES.md
```

Contenido de cada documento de patrón:

1. Nombre y propósito.
2. Problema que resuelve en Prometheus.
3. Solución y roles del patrón.
4. Diagrama de clases en PlantUML compatible con Draw.io, con el rol GoF junto al nombre real de cada clase.
5. Clases participantes y valoración: tabla con rutas reales, ventajas y desventajas.

---

## 10. Convenciones de diagramas

- PlantUML compatible con Draw.io.
- Cada clase muestra su rol GoF junto al nombre real, por ejemplo `AppSettings (Singleton)`.
- Las clases que no cumplen un rol GoF se marcan `(no GoF)`.
- Los roles se asignan por responsabilidad verificada, no por semejanza de nombres.
- Nombres reales de clases conservados en el diagrama mediante alias o etiquetas.
- Rutas completas en la tabla de clases, no en el diagrama.
- Sin clases hipotéticas ni relaciones no verificadas.
- Cardinalidades solo cuando estén comprobadas en modelos o migraciones.
- La estructura prioriza el diagrama de clases; no se agregan diagramas de secuencia salvo que sean imprescindibles.

---

## 11. Flujo de trabajo acordado

1. Análisis y diagnóstico inicial. **Completado.**
2. Aprobación del orden y del primer patrón.
3. Documentar un patrón:
   - releer el código implicado;
   - verificar roles y flujos;
   - redactar el documento;
   - revisar contra el código;
   - actualizar este contexto.
4. Revisión y aprobación antes de pasar al siguiente.
5. Al terminar los ocho patrones, actualizar el documento principal y consolidar enlaces.

Orden recomendado, de menor a mayor complejidad:

1. Singleton.
2. Prototype.
3. Builder.
4. Abstract Factory.
5. Factory Method.
6. Adapter.
7. Bridge.
8. Proxy.

El orden puede cambiar si se prefiere empezar por el patrón más reciente o por el que se va a sustentar primero.

---

## 12. Estado actual

- Análisis de código: completo para los ocho patrones del alcance.
- Verificación de pruebas: completa antes del Proxy; el commit `a4307bc` no se reejecutó por la política de pruebas bajo demanda.
- Inventario documental: completo.
- Discrepancias: identificadas.
- Documentos de patrón creados: `docs/Patrones creacionales/Singleton.md`, `docs/Patrones creacionales/Prototype.md`, `docs/Patrones creacionales/Builder.md`, `docs/Patrones creacionales/Abstract Factory.md`, `docs/Patrones creacionales/Factory Method.md`, `docs/Patrones estructurales/Adapter.md`, `docs/Patrones estructurales/Bridge.md` y `docs/Patrones estructurales/Proxy.md`.
- Bridge quedó en `docs/Patrones estructurales/Bridge.md`, junto a Adapter y Proxy; ya no existe la variante con minúscula.
- Contrato de documentos: `Document::isEmpty()` está implementado, `MonthlyStatement` lo sobrescribe y `DocumentsPage` dejó de depender de `invoices()`. Es preparación para un eventual Proxy de documentos, no implementado; el Proxy de esta entrega es el de festivos.
- Proxy de festivos: implementado en `a4307bc`; `HolidaySource` es el Subject, `CachedHolidaySource` el Proxy, `NagerDateHolidaySource` el RealSubject/Adapter y `NagerDateBusinessCalendar` el Client. Adapter fue actualizado para no atribuirle caché ni degradación.
- Estructura aplicada: cinco apartados, sin contenido redundante.
- Diagramas actualizados: roles GoF junto al nombre real de cada clase; clases ajenas al patrón marcadas `(no GoF)`.
- Documento principal actualizado: sí, en `docs/DOCUMENTO-PRINCIPAL-PARCIAL-2.md`; incluye RF-01 a RF-18, casos de uso, C4, UML, control de cambios, frontend, backend, base de datos, mapa de ocho patrones y Anexo A con los ocho documentos.
- Este archivo de contexto: creado y actualizado.
- Orden elegido: Singleton, Prototype, Builder, Abstract Factory, Factory Method, Adapter, Bridge y Proxy documentados; documento principal actualizado; pendiente revisión final y commit documental del Proxy.
- Pruebas ejecutadas antes del Proxy: `DeliveryActPrototypeTest` (5 pruebas, 66 aserciones), `CreateDeliveryActFromTest` (8 pruebas, 50 aserciones), `DeliveryActBuilderTest` y `CreateDeliveryActTest` (19 pruebas, 82 aserciones), `DocumentFactoryTest` (14 pruebas, 96 aserciones), `DocumentContentTest` (14 pruebas, 48 aserciones), `JsonDocumentBridgeTest` (6 pruebas, 68 aserciones), `PaymentPlanGeneratorTest` (7 pruebas, 18 aserciones), `BusinessCalendarTest` (versión anterior) y `DaneCdaIpcProviderTest` (25 pruebas, 47 aserciones), todas en verde. Suite completa: 325 pruebas, 1.594 aserciones.
- Pruebas del Proxy: `CachedHolidaySourceTest` tiene 4 pruebas y 11 aserciones por inspección estática; `BusinessCalendarTest` fue actualizado para construir Proxy → RealSubject. No se ejecutaron tras el commit `a4307bc`.

---

## 13. Decisiones pendientes

1. **Resuelta:** se creó la versión Markdown del documento principal en `docs/DOCUMENTO-PRINCIPAL-PARCIAL-2.md`.
2. Decidir si los documentos antiguos se corrigen, se reemplazan o se dejan como histórico.
3. **Resuelta:** el documento principal ya incluye enlaces a los ocho documentos de patrón.
4. **Resuelta:** el Proxy se implementó con la Ruta C (festivos), no con los proxies de documentos A/B. La caché y la degradación salieron del Adapter hacia `CachedHolidaySource`.
5. **Pendiente:** autorizar y crear el commit documental del Proxy; no incluir el PDF, los planes ajenos ni cambios de código.
