# Plan de implementación y cierre: cinco patrones GoF en Prometheus

> **Estado:** plan ejecutado y cerrado en octubre de 2026.
>
> Este documento partió del encargo de implementar **Bridge, Composite, Facade, Flyweight y Proxy**. Se conserva como registro de alcance, decisiones y verificación, pero ya no describe tareas pendientes: los cinco patrones están implementados y documentados. El detalle de roles, diagramas y valoración vive en `docs/Patrones estructurales/`.
>
> La entrega completa de la materia quedó en **doce patrones**: cinco creacionales y siete estructurales. Este plan cubre los cinco patrones estructurales que faltaban; **Decorator** se documentó en la Fase 6 y **Adapter** ya existía. El documento principal es `docs/DOCUMENTO-PRINCIPAL-PARCIAL-2.md`.

---

## 0. Resumen ejecutivo

| Feature | Patrón | Dónde se aplica | Estado final | Documento |
| --- | --- | --- | --- | --- |
| 1 | **Bridge** | Documentos y formatos texto/PDF/JSON | Implementado; `JsonDocumentFactory` y `JsonRenderer` completan la familia | `Bridge.md` |
| 2 | **Composite** | Árbol de espacios del inventario del acta | Implementado; `spaces.parent_id`, `SpaceComponent`, `SpaceLeaf`, `SpaceComposite`, `SpaceTreeBuilder` | `Composite.md` |
| 3 | **Facade** | Operaciones del alquiler | Implementado; `RentalFacade` como singleton y punto de entrada | `Facade.md` |
| 4 | **Flyweight** | Catálogo maestro de elementos de inventario | Implementado; `MasterInventoryItem`, `InventoryItemFactory` y pool scoped | `Flyweight.md` |
| 5 | **Proxy** | Festivos y documentos | Implementadas las rutas A (`DocumentProxy`) y C (`CachedHolidaySource`); B (auditoría en renderer) se descartó | `Proxy.md` |

### 0.1. Relación con la entrega de doce patrones

| Grupo | Patrones |
| --- | --- |
| Creacionales | Singleton, Prototype, Builder, Abstract Factory, Factory Method. |
| Estructurales | Adapter, Bridge, Composite, Decorator, Facade, Flyweight, Proxy. |

`Decorator` no forma parte de este plan de cinco features porque ya existía en `PactadoCapDecorator`; la Fase 6 solo agregó la prueba unitaria y el documento. `Adapter` también existía y se actualizó para separar la caché y la degradación del adaptador.

### 0.2. Decisiones cerradas

| Decisión | Valor final |
| --- | --- |
| TTL de la caché de festivos | **1 día**, sin cachear fallos. |
| Vínculo del inventario con el catálogo maestro | FK nullable `act_items.master_inventory_item_id`, con `nullOnDelete`. |
| Selección de espacios en el acta | **`space_id`**, con `act_items.space` como snapshot histórico. |
| Nombre del elemento en el acta | **Snapshot** en `act_items.name`; el flyweight conserva el nombre actual del catálogo. |
| Ruta del Proxy | **A + C**: `DocumentProxy` para auditoría y `CachedHolidaySource` para caché. B no se implementó. |
| Auditoría de documentos | Solo `content()`; la metadata no registra acceso. |
| Política de fallo de auditoría | **Fail-closed**: sin registro no se entrega contenido. |
| Alcance de la Facade | Orquesta; no contiene reglas de negocio, notificaciones ni transacciones. |
| Catálogo de espacios e inventario | Por arrendador; toda búsqueda y validación respeta `user_id`. |
| Unicidad de raíces de espacios | Se refuerza en la aplicación porque los `NULL` no colisionan en el índice único. |
| Decorator | Se documenta; no se reimplementa. |
| Alcance de pruebas | Pruebas dirigidas por fase; no se ejecutó la suite completa ni Pint global durante la documentación. |

---

## 1. Alcance y no alcance

### 1.1. Alcance ejecutado

- Jerarquía de espacios y árbol de inventario en el acta.
- Catálogo maestro de elementos de inventario por arrendador.
- Punto de entrada único para cobros, pagos, reajustes, fechas y documentos.
- Proxy de caché para festivos.
- Proxy de protección, construcción diferida y auditoría para documentos.
- Formato JSON de documentos y entrega unificada por `content()`.
- Pruebas dirigidas por patrón y documentos individuales.

### 1.2. Fuera de alcance

- Reimplementar Adapter o Decorator.
- Mover reglas de negocio a `RentalFacade`.
- Mover notificaciones de `PaymentPlanService` a la fachada.
- Absorber en la fachada los accesos auxiliares a `IpcSeriesRepository` y `AppSettings`.
- Agregar autorización dentro de `DocumentProxy` o `DocumentAccessLogger`.
- Reintentos o invalidación por tags en la caché de festivos.
- Auditoría de metadata, IP, user agent o identificador de entrega.
- Purga o retención automática de `document_accesses`.
- Implementar la ruta B (auditoría dentro del renderer).

---

## 2. Feature 1: Bridge — documentos y formatos

### 2.1. Resultado

La jerarquía de documentos ya estaba separada de la jerarquía de renderizadores. El trabajo pendiente era completar la familia JSON y unificar la entrega:

| Rol | Clases |
| --- | --- |
| **Abstraction** | `AbstractDocument` y sus refinamientos `PaymentReceipt`, `RentInvoice`, `AdjustmentLetter`, `MonthlyStatement`. |
| **Implementor** | `DocumentRenderer`. |
| **ConcreteImplementor** | `PlainTextRenderer`, `PdfRenderer`, `JsonRenderer`. |
| **Factory relacionada** | `TextDocumentFactory`, `PdfDocumentFactory`, `JsonDocumentFactory`. |

`JsonDocumentFactory` produce los cuatro documentos JSON y `JsonRenderer` serializa la representación estructurada. La pantalla y las acciones usan `Document::content()` e `isEmpty()`; ya no llaman a `body()` ni inspeccionan `invoices()` de un producto concreto.

### 2.2. Archivos principales

- `app/Documents/AbstractDocument.php`
- `app/Documents/Contracts/Document.php`
- `app/Documents/Rendering/JsonRenderer.php`
- `app/Documents/Factories/JsonDocumentFactory.php`
- `app/Documents/Factories/DocumentFactory.php`
- `app/Documents/DocumentFactoryLocator.php`
- `app/Filament/Pages/DocumentsPage.php`
- `app/Filament/Support/DocumentAction.php`

### 2.3. Pruebas

- `tests/Feature/JsonDocumentBridgeTest.php`: 6 pruebas.
- `tests/Feature/DocumentFactoryTest.php`: 14 pruebas.
- Acciones y páginas de documentos actualizadas para consumir `Document`.

### 2.4. Desviación final

`PdfRenderer::selectView()` todavía conoce las clases concretas para elegir la vista Blade. El Bridge separa formato y tipo de documento, pero la selección de plantilla PDF no es totalmente polimórfica. Agregar un documento nuevo exige actualizar las tres fábricas y la selección de vista.

---

## 3. Feature 2: Composite — jerarquía de espacios

### 3.1. Resultado

`spaces` dejó de ser un catálogo plano. La tabla guarda `parent_id` y la aplicación arma un árbol de Components para la vista y el selector.

| Rol | Clases |
| --- | --- |
| **Component** | `SpaceComponent`. |
| **Leaf** | `SpaceLeaf`. |
| **Composite** | `SpaceComposite`. |
| **Builder del árbol** | `SpaceTreeBuilder`. |
| **Client** | `DeliveryAct`, `inventory-tree.blade.php`, `SpaceCatalogField`. |
| **Persistencia** | `Space`, `ActItem`. |

`SpaceComponent` expone `children()`, `isComposite()`, `space()`, `name()`, `path()`, `items()`, `allItems()`, `totalItems()` y `flatten()`. `items()` devuelve solo los elementos directos; `allItems()` y `totalItems()` recorren el subárbol. `SpaceTreeBuilder` carga los espacios en una consulta, enlaza los padres en memoria y agrupa los elementos por `space_id`.

### 3.2. Persistencia

Migración `2026_10_25_100000_add_parent_id_to_spaces_table.php`:

- elimina el unique `(name, user_id)`;
- agrega `parent_id` nullable, FK a `spaces`, `nullOnDelete`;
- agrega unique `(user_id, parent_id, name)`.

La unicidad de raíces se valida en `Space::assertSiblingNameIsUnique()` porque `parent_id = NULL` no colisiona en el índice. `Space::path()` sube por los padres con guarda de ciclo; `moveTo()` y `canBeParentedBy()` rechazan mover un nodo bajo su descendiente.

`act_item.space_id` ya existía con `nullOnDelete`; `act_items.space` sigue siendo el snapshot textual. `DeliveryActBuilder::withInventory()` resuelve el id contra el catálogo del dueño del alquiler y rechaza un espacio ajeno.

### 3.3. Archivos principales

- `app/Models/Space.php`
- `app/Models/SpaceComponent.php`
- `app/Models/SpaceLeaf.php`
- `app/Models/SpaceComposite.php`
- `app/Services/Acts/SpaceTreeBuilder.php`
- `app/Services/Acts/DeliveryActBuilder.php`
- `app/Services/Acts/DeliveryActDraft.php`
- `app/Models/DeliveryAct.php`
- `app/Models/ActItem.php`
- `app/Filament/Support/SpaceCatalogField.php`
- `resources/views/filament/acts/inventory-tree.blade.php`

### 3.4. Pruebas

`tests/Feature/SpaceHierarchyCompositeTest.php`: 7 pruebas.

| Prueba | Cobertura |
| --- | --- |
| `el_arbol_trata_igual_una_hoja_y_un_compuesto` | Leaf/Composite, `isComposite()` y `path()`. |
| `las_opciones_usan_el_id_y_muestran_la_ruta` | Selector `id => ruta`. |
| `una_raiz_no_puede_repetir_nombre_pero_una_rama_si` | Unicidad de raíces. |
| `dos_ramas_pueden_tener_el_mismo_nombre` | Nombres repetidos en ramas distintas. |
| `mover_un_espacio_bajo_su_descendiente_crea_un_ciclo_y_se_rechaza` | Prevención de ciclos. |
| `el_acta_guarda_la_referencia_y_el_snapshot_y_arma_el_arbol` | `space_id`, snapshot, agrupación por ruta, `totalItems()` y render. |
| `un_espacio_de_otro_arrendador_no_se_acepta_por_id` | Aislamiento por dueño. |

### 3.5. Desviación final

El Composite es **inmutable**: no tiene `add()`, `remove()` ni `getChild()`. El árbol se reconstruye desde `spaces`; el alta y el movimiento son operaciones de `Space`. `Space::asComponent()` existe como construcción recursiva alternativa, pero la aplicación usa `SpaceTreeBuilder` para evitar consultas por nivel.

---

## 4. Feature 3: Facade — RentalFacade

### 4.1. Resultado

`RentalFacade` es el punto de entrada de las operaciones del alquiler. Recibe siete dependencias y reenvía sin agregar reglas:

| Dependencia | Operaciones expuestas |
| --- | --- |
| `PaymentPlanService` | `generatePaymentPlan()`. |
| `PaymentRecordingService` | `recordPayment()`. |
| `RentAdjustmentService` | `recordAdjustment()`, `markAdjustmentNotified()`, `applyAdjustment()`, `previewAdjustmentIpc()`, `validateAdjustment()`. |
| `DocumentService` | `receiptFor()`, `invoiceFor()`, `adjustmentLetterFor()`, `statementFor()`, `documentFormats()`. |
| `AdjustmentChainCalculator` | `currentRentFor()`. |
| `AdjustmentChainRecalculator` | `recalculateAdjustmentChain()`. |
| `AdjustmentDateProposer` | `suggestedAdjustmentDate()`, `adjustmentDateExplanation()`. |

Se registra como singleton en `AppServiceProvider`. No guarda estado ni abre transacciones.

### 4.2. Clientes migrados

- `CreateRental`: inyecta la fachada y llama `generatePaymentPlan()`.
- `InvoiceResource`: registra pagos y entrega documentos.
- `RentAdjustmentResource`: consulta canon, previsualiza, valida, aplica, notifica, recalcula y entrega cartas.
- `CreateRentAdjustment`: registra el reajuste.
- `DocumentsPage`: consulta formatos y entrega documentos.

### 4.3. Límites conservados

- `RentAdjustmentResource` sigue usando `IpcSeriesRepository` y `AppSettings` para opciones del formulario.
- Las notificaciones siguen en `PaymentPlanService` y en las pantallas.
- Las reglas legales siguen en `RentAdjustmentService` y en `ReajusteValidationChain`.
- La autorización sigue en las consultas y relaciones de los modelos.
- La fachada no oculta el subsistema por completo: expone contratos de servicio y devuelve los mismos tipos.

### 4.4. Archivos principales

- `app/Services/RentalFacade.php`
- `app/Providers/AppServiceProvider.php`
- `app/Filament/Resources/RentalResource/Pages/CreateRental.php`
- `app/Filament/Resources/InvoiceResource.php`
- `app/Filament/Resources/RentAdjustmentResource.php`
- `app/Filament/Resources/RentAdjustmentResource/Pages/CreateRentAdjustment.php`
- `app/Filament/Pages/DocumentsPage.php`

### 4.5. Pruebas

`tests/Feature/RentalFacadeTest.php`: 5 pruebas, 11 aserciones.

| Prueba | Cobertura |
| --- | --- |
| `la_fachada_es_un_singleton` | Misma instancia del contenedor. |
| `genera_el_plan_de_cobros` | Delegación a `PaymentPlanService`. |
| `registra_un_abono_con_las_mismas_reglas_del_servicio` | Pago y estado de factura. |
| `marca_un_reajuste_como_notificado` | Fecha y método de notificación. |
| `expone_la_cadena_y_los_documentos_sin_cambiar_sus_contratos` | Canon, formatos y comprobante. |

### 4.6. Desviación final

La fachada tiene 16 métodos y no tiene una interfaz propia. Varias pantallas la resuelven con `app(RentalFacade::class)` dentro de callbacks estáticos. Es una fachada de orquestación, no un Mediador ni una capa anticorrupción.

---

## 5. Feature 4: Flyweight — catálogo maestro de inventario

### 5.1. Resultado

El catálogo maestro se separó del inventario del acta:

| Estado | Ubicación |
| --- | --- |
| Intrínseco: identidad, nombre y dueño | `master_inventory_items` / `MasterInventoryItem`. |
| Extrínseco: espacio, estado, nota, foto y acta | `act_items` / `ActItem`. |
| Referencia compartida | `act_items.master_inventory_item_id`. |
| Snapshot histórico del nombre | `act_items.name`. |

`InventoryItemFlyweight` declara `id()`, `name()` y `ownerId()`. No tiene `code`, `description` ni `category`.

`InventoryItemFactory` mantiene un pool **scoped** por arrendador con `$catalogs` y `$byName`. Ofrece `all()`, `options()`, `find()`, `findByName()`, `resolve()`, `firstOrCreate()` y `updateName()`. Normaliza con `mb_strtolower(trim($name))`, consulta la base con `LOWER(name)` y recupera carreras de creación capturando `QueryException`.

`DeliveryActBuilder::withInventory()` resuelve el flyweight por id o por nombre legacy, valida el dueño, guarda la FK y el nombre como snapshot. `MasterInventoryItemCatalogField` usa el mismo pool para opciones, alta y renombrado.

### 5.2. Persistencia

Migraciones:

- `2026_10_25_110000_create_master_inventory_items_table.php`: `id`, `user_id` cascade, `name`, timestamps y unique `(user_id, name)`.
- `2026_10_25_110100_add_master_inventory_item_id_to_act_items_table.php`: FK nullable con `nullOnDelete` para compatibilidad.

### 5.3. Archivos principales

- `app/Contracts/InventoryItemFlyweight.php`
- `app/Models/MasterInventoryItem.php`
- `app/Services/InventoryItemFactory.php`
- `app/Filament/Support/MasterInventoryItemCatalogField.php`
- `app/Services/Acts/DeliveryActBuilder.php`
- `app/Services/Acts/DeliveryActDraft.php`
- `app/Models/ActItem.php`
- `app/Providers/AppServiceProvider.php`
- `database/factories/MasterInventoryItemFactory.php`
- `database/seeders/MasterInventoryItemSeeder.php`

### 5.4. Pruebas

`tests/Feature/InventoryItemFlyweightTest.php`: 5 pruebas.

| Prueba | Cobertura |
| --- | --- |
| `el_catalogo_y_las_opciones_son_por_arrendador` | Opciones por dueño e id como valor. |
| `la_fabrica_comparte_la_misma_instancia_para_el_mismo_elemento` | Resolución por id y por nombre normalizado. |
| `el_pool_es_scoped_y_no_sobrevive_a_la_peticion` | Descarte con `forgetScopedInstances()`. |
| `un_elemento_no_se_comparte_con_otro_arrendador` | Aislamiento por `user_id`. |
| `crear_y_renombrar_desde_el_selector_actualiza_el_pool` | Alta, reutilización por caso y renombrado. |

El trait `tests/SeedsMasterInventoryCatalog.php` centraliza la siembra.

### 5.5. Desviación final

- El pool es por petición, no global; la identidad persistente es la fila, no el objeto PHP.
- El catálogo se carga completo por dueño, no de forma puntual.
- El nombre se duplica en el acta como snapshot; renombrar el catálogo no reescribe actas.
- La unicidad `(user_id, name)` depende de la colación del motor; la fábrica normaliza la búsqueda, pero no hay índice funcional sobre `LOWER(name)`.

---

## 6. Feature 5: Proxy — festivos y documentos

### 6.1. Ruta C: Proxy de festivos

| Rol | Clase |
| --- | --- |
| **Subject** | `HolidaySource`. |
| **Proxy** | `CachedHolidaySource`. |
| **RealSubject / Adapter** | `NagerDateHolidaySource`. |
| **Client** | `NagerDateBusinessCalendar`. |

`CachedHolidaySource` usa la clave `holidays:{country}:{year}`, responde desde caché, delega en el real y guarda un día. Si el real lanza, registra `Log::warning` y devuelve `[]` **sin** cachear el fallo. `NagerDateBusinessCalendar` mantiene el respaldo de fin de semana y el tope de 366 días.

### 6.2. Ruta A: Proxy de documentos

| Rol | Clase |
| --- | --- |
| **Subject** | `Document`. |
| **Proxy** | `DocumentProxy`. |
| **RealSubject** | Documento producido por las fábricas de documentos. |
| **Descriptor** | `DocumentDescriptor`. |
| **Logger** | `DocumentAccessLogger` / `DatabaseDocumentAccessLogger`. |
| **Cliente creador** | `DocumentService`. |
| **Clientes** | `DocumentsPage`, `InvoiceResource`, `RentAdjustmentResource`. |

`DocumentService` valida el comprobante pagado antes de crear el Proxy, arma un `DocumentDescriptor` y un `Closure(): Document`, y devuelve `DocumentProxy`. El descriptor contiene `type`, `format`, `userId`, `reference` y `context`.

`DocumentProxy`:

- implementa `Document`;
- guarda `$document` y `$audited`;
- `content()` audita antes de construir y delegar;
- la metadata (`filename()`, `mimeType()`, `deliveryLabel()`, `isDownload()`, `isEmpty()`, `preview()`) no audita;
- el primer método delegado —incluida metadata— construye y memoriza el documento real;
- cada proxy registra como máximo un acceso;
- si la auditoría falla, `$audited` no se marca, el builder no se ejecuta y el contenido queda bloqueado;
- dos proxies distintos registran dos accesos, aunque representen el mismo documento lógico.

La auditoría se persiste en `document_accesses` con `user_id` nullable (`nullOnDelete`), `document_type`, `reference`, `format`, `context` JSON y `accessed_at`. La migración crea índices por `(user_id, accessed_at)` y `(document_type, accessed_at)`.

### 6.3. Ruta B: descartada

La auditoría dentro del renderer se analizó y no se implementó. La ruta A cubre el requisito con un contrato más claro y sin mezclar la auditoría con la selección de formato. El documento `Proxy.md` explica ambas rutas implementadas y el límite de la ruta B.

### 6.4. Archivos principales

- `app/Contracts/DocumentAccessLogger.php`
- `app/Documents/Audit/DatabaseDocumentAccessLogger.php`
- `app/Documents/DocumentDescriptor.php`
- `app/Documents/DocumentProxy.php`
- `app/Documents/DocumentService.php`
- `app/Documents/Contracts/Document.php`
- `app/Models/DocumentAccess.php`
- `app/Providers/AppServiceProvider.php`

### 6.5. Pruebas

- `tests/Feature/CachedHolidaySourceTest.php`: 4 pruebas, 11 aserciones.
- `tests/Feature/DocumentProxyTest.php`: 6 pruebas.

| Prueba | Cobertura |
| --- | --- |
| `la_metadata_no_registra_un_acceso` | Metadata sin filas de auditoría. |
| `el_contenido_registra_un_unico_acceso_aunque_se_pida_varias_veces` | Una fila por proxy y datos completos. |
| `cada_proxy_registra_su_propio_acceso` | Texto y JSON generan dos filas. |
| `el_documento_real_se_construye_una_sola_vez` | Builder memorizado. |
| `una_auditoria_fallida_bloquea_el_contenido` | Fail-closed y builder no ejecutado. |
| `el_comprobante_sin_pagar_falla_antes_de_crear_el_proxy` | Guard inmediato con `DomainException`. |

### 6.6. Desviación final

- La metadata no se audita, pero sí puede construir el documento real. Lo diferido es la auditoría y el render de contenido, no toda la construcción.
- No hay autorización dentro del Proxy; el alcance se aplica antes, en las consultas de `DocumentService`.
- No se registran IP, user agent ni identificador de entrega.
- No hay retención ni consulta de auditoría en la interfaz.
- La auditoría es fail-closed: una base no disponible bloquea la entrega.

---

## 7. Plan de ejecución por fases

| Fase | Alcance | Estado |
| --- | --- | --- |
| 0 | Línea base: corregir entrega por `content()`, cuerpo de documentos y API de `Document`. | Completada. |
| 1 | Bridge JSON: `JsonRenderer`, `JsonDocumentFactory`, registro y pruebas. | Completada. |
| 2 | Proxy de festivos: separar caché y degradación del adaptador. | Completada. |
| 3 | Composite: `parent_id`, árbol, selector y vista del acta. | Completada. |
| 4 | Flyweight: catálogo maestro, pool, FK y snapshots. | Completada. |
| 5 | DocumentProxy: auditoría, descriptor, migración y fail-closed. | Completada. |
| 6 | RentalFacade: API, singleton, clientes y pruebas. | Completada. |
| 7 | Decorator: prueba unitaria y documento. | Completada. |
| 8 | Documentación e integración final: documentos individuales, este plan y documento principal. | Completada. |

El orden real priorizó primero los prerrequisitos de documentos (Fase 0 y Bridge), después el Proxy de festivos que ya existía y luego los patrones que tocaban inventario y pantallas. La Facade se cerró al final para integrar las operaciones ya estabilizadas.

### 7.1. Verificación

- No se ejecutó la suite completa ni Pint global.
- Cada fase se verificó con la prueba dirigida:

```bash
php artisan test --filter=SpaceHierarchyCompositeTest
php artisan test --filter=InventoryItemFlyweightTest
php artisan test --filter=RentalFacadeTest
php artisan test --filter=DocumentProxyTest
php artisan test --filter=CachedHolidaySourceTest
php artisan test --filter=PactadoCapDecoratorTest
```

- Las fases que solo cambiaron documentación no ejecutaron pruebas.
- Los conteos de pruebas de este documento corresponden a los archivos presentes; la actualización documental final no reejecutó la suite.

---

## 8. Resumen de migraciones

| Migración | Tabla | Cambio | Patrón |
| --- | --- | --- | --- |
| `2026_10_25_100000_add_parent_id_to_spaces_table` | `spaces` | Agrega `parent_id` self-FK `nullOnDelete`; quita unique `(name, user_id)`; agrega unique `(user_id, parent_id, name)`. | Composite |
| `2026_10_25_110000_create_master_inventory_items_table` | `master_inventory_items` | Crea `id`, `user_id` cascade, `name`, timestamps y unique `(user_id, name)`. | Flyweight |
| `2026_10_25_110100_add_master_inventory_item_id_to_act_items_table` | `act_items` | Agrega FK nullable `master_inventory_item_id` con `nullOnDelete`. | Flyweight |
| `2026_10_25_120000_create_document_accesses_table` | `document_accesses` | Crea la auditoría con usuario nullable, tipo, referencia, formato, contexto, fecha e índices. | Proxy A |
| `2026_10_05_022900_add_space_id_to_act_items_table` | `act_items` | Ya existía: `space_id` nullable con `nullOnDelete`. | Composite |

No se agregaron columnas `code`, `description` ni `category` al catálogo maestro; el flyweight quedó con identidad, nombre y dueño.

---

## 9. Matriz de pruebas

| Feature | Prueba principal | Pruebas de integración relacionadas |
| --- | --- | --- |
| Bridge | `JsonDocumentBridgeTest`, `DocumentFactoryTest` | `DocumentsPageTest`, acciones de documentos de factura y reajuste. |
| Composite | `SpaceHierarchyCompositeTest` | `DeliveryActBuilderTest`, `CreateDeliveryActTest`, `CreateDeliveryActFromTest`, `DeliveryActPrototypeTest`. |
| Facade | `RentalFacadeTest` | `PaymentPlanGeneratorTest`, pruebas de pagos, reajustes y pantallas. |
| Flyweight | `InventoryItemFlyweightTest` | `DeliveryActBuilderTest`, `CreateDeliveryActTest`, factories y seeders. |
| Proxy C | `CachedHolidaySourceTest` | `BusinessCalendarTest`. |
| Proxy A | `DocumentProxyTest` | `DocumentFactoryTest`, `DocumentsPageTest`, acciones de documentos. |
| Decorator | `PactadoCapDecoratorTest` | `AdjustmentChainTest`, `ReajusteCalculationTest`, `RentAdjustmentServiceTest`. |

---

## 10. Riesgos y resultado

| Riesgo | Estado final | Mitigación aplicada |
| --- | --- | --- |
| El Proxy de documentos rompe la API de `DocumentService`. | Resuelto. | `DocumentService` devuelve `Document`; las pruebas y clientes se ajustaron a `content()` e `isEmpty()`. |
| La entrega PDF evita la auditoría. | Resuelto. | La entrega se unificó por `content()` antes del Proxy. |
| El pool de flyweights filtra datos entre usuarios. | Resuelto. | Pool por `ownerId`, resolución por `user_id`, binding `scoped` y pruebas de aislamiento. |
| La Facade se convierte en un god object. | Controlado. | Solo reenvía; no absorbe reglas, notificaciones ni accesos auxiliares. |
| El árbol de espacios permite ciclos. | Resuelto. | `canBeParentedBy()` y `moveTo()` rechazan descendientes y auto-padres. |
| Dos raíces repiten nombre. | Resuelto en aplicación. | Validación en `saving`; el unique de base no cubre `NULL`. |
| La auditoría se duplica por evaluación del modal. | Resuelto. | `$audited` por instancia; metadata no audita. |
| Una auditoría fallida entrega contenido sin registro. | Resuelto. | `content()` audita antes del builder; fallo bloquea la entrega. |
| El fallo de festivos envenena la caché. | Resuelto. | El fallo se registra y se devuelve `[]` sin `Cache::put()`. |
| El catálogo maestro se renombra y cambia actas firmadas. | Controlado. | `act_items.name` es snapshot; renombrar no reescribe actas. |

### 10.1. Riesgos residuales

- El catálogo de inventario se carga completo por dueño; con volúmenes altos puede ser costoso.
- La metadata de documentos puede construir el documento real antes de auditar.
- La auditoría no registra IP, user agent ni identificador de entrega.
- `document_accesses` no tiene política de retención.
- La entrega PDF sigue eligiendo la vista por clase concreta.
- El árbol no impone un límite de profundidad.
- `itemsBySpacePath()` puede generar consultas adicionales al calcular la ruta de cada elemento si la cadena de padres no está precargada.

---

## 11. Criterios de aceptación globales

- [x] Los cinco patrones del encargo tienen una estructura identificable y un rol GoF documentado.
- [x] Bridge cubre texto, PDF y JSON sin ramas por formato en la pantalla.
- [x] Composite trata igual hojas y compuestos y conserva rutas y snapshots.
- [x] Facade da un punto de entrada único sin contener reglas de negocio.
- [x] Flyweight comparte el catálogo por arrendador y separa el estado extrínseco.
- [x] Proxy cubre caché de festivos y auditoría de documentos.
- [x] La ruta del Proxy está decidida: A + C implementadas; B descartada.
- [x] El documento principal quedó en doce patrones y versión 1.4.
- [x] Cada patrón tiene documento individual en `docs/Patrones estructurales/`.
- [x] Cada patrón tiene al menos una prueba dirigida o una prueba de integración asociada.
- [x] No se ejecutó la suite completa ni Pint global durante el cierre documental.

---

## 12. Apéndice: inventario de archivos

### 12.1. Nuevos

```text
app/Contracts/DocumentAccessLogger.php
app/Contracts/InventoryItemFlyweight.php
app/Documents/Audit/DatabaseDocumentAccessLogger.php
app/Documents/DocumentDescriptor.php
app/Documents/DocumentProxy.php
app/Models/DocumentAccess.php
app/Models/MasterInventoryItem.php
app/Models/SpaceComponent.php
app/Models/SpaceComposite.php
app/Models/SpaceLeaf.php
app/Services/Acts/SpaceTreeBuilder.php
app/Services/InventoryItemFactory.php
app/Services/RentalFacade.php
app/Filament/Support/MasterInventoryItemCatalogField.php
database/factories/MasterInventoryItemFactory.php
database/migrations/2026_10_25_100000_add_parent_id_to_spaces_table.php
database/migrations/2026_10_25_110000_create_master_inventory_items_table.php
database/migrations/2026_10_25_110100_add_master_inventory_item_id_to_act_items_table.php
database/migrations/2026_10_25_120000_create_document_accesses_table.php
database/seeders/MasterInventoryItemSeeder.php
tests/Feature/DocumentProxyTest.php
tests/Feature/InventoryItemFlyweightTest.php
tests/Feature/RentalFacadeTest.php
tests/Feature/SpaceHierarchyCompositeTest.php
tests/SeedsMasterInventoryCatalog.php
tests/Unit/PactadoCapDecoratorTest.php
docs/Patrones estructurales/Composite.md
docs/Patrones estructurales/Decorator.md
docs/Patrones estructurales/Facade.md
docs/Patrones estructurales/Flyweight.md
```

### 12.2. Modificados

```text
app/Models/Space.php
app/Models/ActItem.php
app/Models/DeliveryAct.php
app/Services/Acts/DeliveryActBuilder.php
app/Services/Acts/DeliveryActDraft.php
app/Filament/Support/SpaceCatalogField.php
app/Filament/Resources/DeliveryActResource.php
app/Filament/Resources/DeliveryActResource/Pages/CreateDeliveryAct.php
app/Filament/Resources/RentalResource/Pages/CreateRental.php
app/Filament/Resources/InvoiceResource.php
app/Filament/Resources/RentAdjustmentResource.php
app/Filament/Resources/RentAdjustmentResource/Pages/CreateRentAdjustment.php
app/Filament/Pages/DocumentsPage.php
app/Documents/DocumentService.php
app/Providers/AppServiceProvider.php
resources/views/filament/acts/inventory-tree.blade.php
database/factories/ActItemFactory.php
database/factories/SpaceFactory.php
database/seeders/DeliveryActSeeder.php
database/seeders/SpaceSeeder.php
lang/en/act.php
lang/es/act.php
```

### 12.3. Documentos

```text
docs/DOCUMENTO-PRINCIPAL-PARCIAL-2.md
docs/PLAN-IMPLEMENTACION-5-PATRONES-GOF.md
docs/PLAN-PATRONES-ESTRUCTURALES-RESTANTES.md
docs/Patrones estructurales/Proxy.md
docs/Patrones estructurales/Decorator.md
docs/Patrones estructurales/Composite.md
docs/Patrones estructurales/Facade.md
docs/Patrones estructurales/Flyweight.md
```

---

## 13. Referencias

- `docs/DOCUMENTO-PRINCIPAL-PARCIAL-2.md`: documento principal de la entrega, mapa de doce patrones y control de versiones.
- `docs/PLAN-PATRONES-ESTRUCTURALES-RESTANTES.md`: plan operativo de las fases 0 a 7.
- `docs/Patrones estructurales/Bridge.md`: detalle del Bridge de documentos.
- `docs/Patrones estructurales/Composite.md`: detalle del árbol de espacios.
- `docs/Patrones estructurales/Decorator.md`: detalle del tope pactado del IPC.
- `docs/Patrones estructurales/Facade.md`: detalle de `RentalFacade`.
- `docs/Patrones estructurales/Flyweight.md`: detalle del catálogo maestro.
- `docs/Patrones estructurales/Proxy.md`: detalle de los proxies de festivos y documentos.
- `app/Services/`: servicios que la Facade orquesta.
- `app/Models/Space.php`, `app/Models/ActItem.php`, `app/Models/DeliveryAct.php`: base del Composite y el Flyweight.
- `app/Documents/`: Subject, Proxy, descriptor, servicio y fábricas de documentos.
