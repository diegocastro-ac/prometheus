# Plan de implementación: patrones estructurales restantes

> Documento de trabajo para no perder contexto entre sesiones.
>
> Alcance: implementar los patrones estructurales que faltan, **excepto Adapter y Bridge**.
> Eso deja: **Composite, Decorator, Facade, Flyweight y Proxy**.
>
> La **Fase 7 (documentación e integración final)** la está ejecutando otro agente. Este plan no la duplica; solo deja los insumos que esa fase necesita.

## Estado de ejecución

- Fase 0: **completada** (2026-10-25).
- Fases 1 a 6: pendientes.
- Fase 7: delegada a otro agente.

---

## 0. Estado de partida

### Ya implementado

- **Bridge**: `JsonRenderer`, `JsonDocumentFactory`, `JsonDocumentBridgeTest`, entrega unificada por `content()` y documentación en `docs/Patrones estructurales/Bridge.md`.
- **Adapter**: `BusinessCalendar` / `NagerDateBusinessCalendar` e `IpcProvider` / adaptadores del DANE.
- **Decorator de código**: `app/Services/Adjustment/Decorators/PactadoCapDecorator.php`.
  - Documentado en `docs/PATRONES-IPC-REAJUSTES.md` (§7) y `docs/MODULO-IPC.md` (§13).
  - Falta su documento dentro de `docs/Patrones estructurales/`.

### Hallazgos que condicionan el plan

1. `docs/PROXY-FESTIVOS.md` afirma que el Proxy de caché está implementado, pero **no existen** `HolidaySource`, `NagerDateHolidaySource` ni `CachedHolidaySource`.
   - Hoy `NagerDateBusinessCalendar` cachea internamente y degrada por su cuenta.
   - Decisión: implementar la ruta documentada y dejar la documentación verdadera.
2. `DocumentService` devuelve tipos concretos, no `Document`.
3. `DocumentsPage` usa `$statement->invoices()`; un `DocumentProxy` no puede cumplir eso sin cambiar el contrato.
4. `Document` no declara `isEmpty()`.
5. El plan que llegó del pull cambió Decorator por Facade. Por eso el Decorator “no se ve” en ese plan, aunque sí existe en el código.
6. La unicidad de raíces de `spaces` no queda garantizada solo con un índice que incluya `parent_id NULL`; hay que reforzarla en aplicación.
7. `ActItem.name` se conserva como snapshot histórico aunque se implemente Flyweight.
8. La Facade no debe absorber reglas de negocio ni accesos auxiliares del formulario.

---

## 1. Fase 0 — Cierre del Bridge y contrato de documentos

### Objetivo

Preparar `Document` para que el Proxy pueda sustituir al documento real sin romper la pantalla.

### Cambios

- Agregar `isEmpty(): bool` a `Document`.
- Implementar `false` por defecto en `AbstractDocument`.
- Sobrescribir en `MonthlyStatement` con `$this->invoices === []`.
- Reemplazar en `DocumentsPage`:
  - `$statement->invoices() === []`
  - por `$statement->isEmpty()`.
- No agregar `body()` al contrato: el PDF sigue renderizándose desde el documento real.
- Ajustar pruebas solo si alguna depende de la API concreta para este caso.

### Archivos para commit

- `app/Documents/Contracts/Document.php`
- `app/Documents/AbstractDocument.php`
- `app/Documents/MonthlyStatement.php`
- `app/Filament/Pages/DocumentsPage.php`
- `tests/Feature/DocumentsPageTest.php` (si se agrega cobertura de `isEmpty()`)

### Commit sugerido

```text
refactor(documentos): exponer isEmpty en el contrato Document
```

---

## 2. Fase 1 — Proxy de festivos

### Objetivo

Separar traducción, caché y degradación. El Adapter solo traduce; el Proxy controla el acceso.

### Cambios

- Crear `app/Contracts/HolidaySource.php`.
- Crear `app/Infrastructure/Holidays/NagerDateHolidaySource.php`.
  - Solo HTTP + parseo.
  - Lanza excepción si la API falla.
- Crear `app/Infrastructure/Holidays/CachedHolidaySource.php`.
  - Cache por país y año.
  - TTL de 1 día.
  - Fallo de API: `Log::warning`, devuelve `[]`, no cachea el fallo.
- Refactorizar `NagerDateBusinessCalendar` para depender de `HolidaySource`; sacar `Cache`, `Http` y `Log`.
- Registrar la cadena en `AppServiceProvider`:
  - testing: `WeekendBusinessCalendar`.
  - producción: `CachedHolidaySource(NagerDateHolidaySource)`.

### Archivos para commit

- `app/Contracts/HolidaySource.php`
- `app/Infrastructure/Holidays/NagerDateHolidaySource.php`
- `app/Infrastructure/Holidays/CachedHolidaySource.php`
- `app/Infrastructure/Holidays/NagerDateBusinessCalendar.php`
- `app/Providers/AppServiceProvider.php`
- `tests/Feature/CachedHolidaySourceTest.php`
- `tests/Feature/BusinessCalendarTest.php`

### Commit sugerido

```text
feat(proxy): separar cache de festivos del adaptador
```

---

## 3. Fase 2 — Composite de espacios

### Objetivo

Convertir `spaces` en un árbol y tratar igual una hoja y un compuesto al mostrar el inventario.

### Cambios

- Migración `add_parent_id_to_spaces_table`:
  - `parent_id` nullable, FK a `spaces`, `nullOnDelete`.
  - Quitar unique `(name, user_id)`.
  - Agregar unique `(user_id, parent_id, name)`.
  - Reforzar unicidad de raíces en aplicación porque los `NULL` no colisionan.
- `Space`: `parent()`, `children()`, `items()`, `path()`, `asComponent()`, `descendants()`.
- Crear `SpaceComponent`, `SpaceLeaf`, `SpaceComposite`.
- Crear `SpaceTreeBuilder`.
- `DeliveryAct`: `inventoryTree()` e `itemsBySpacePath()`.
- `DeliveryActResource`: acción modal de árbol.
- Crear vista `resources/views/filament/acts/inventory-tree.blade.php`.
- `SpaceCatalogField`: opciones `id => path`; crear/editar con padre.
- Repeater y `CreateDeliveryAct`: pasar de `space` a `space_id`.
- `DeliveryActBuilder`: aceptar `space_id`, validar dueño y guardar `space` como snapshot; conservar ruta legacy por nombre.
- `ActItem::__clone()`: conservar `space_id` y `master_inventory_item_id`.
- Actualizar factories, seeders y pruebas.

### Archivos para commit

- `database/migrations/*_add_parent_id_to_spaces_table.php`
- `database/migrations/*_add_master_inventory_item_id_to_act_items_table.php` (si se adelanta la FK; si no, va en Fase 3)
- `app/Models/Space.php`
- `app/Models/SpaceComponent.php`
- `app/Models/SpaceLeaf.php`
- `app/Models/SpaceComposite.php`
- `app/Models/ActItem.php`
- `app/Models/DeliveryAct.php`
- `app/Services/Acts/SpaceTreeBuilder.php`
- `app/Services/Acts/DeliveryActBuilder.php`
- `app/Services/Acts/DeliveryActDraft.php`
- `app/Filament/Support/SpaceCatalogField.php`
- `app/Filament/Resources/DeliveryActResource.php`
- `app/Filament/Resources/DeliveryActResource/Pages/CreateDeliveryAct.php`
- `resources/views/filament/acts/inventory-tree.blade.php`
- `database/factories/SpaceFactory.php` (si no existe, crearlo)
- `database/seeders/SpaceSeeder.php`
- `tests/Feature/SpaceHierarchyCompositeTest.php`
- `tests/Feature/DeliveryActBuilderTest.php`
- `tests/Feature/CreateDeliveryActTest.php`
- `tests/Feature/SpaceCatalogFieldTest.php` (si se crea)
- `tests/Feature/SeedingTest.php` (si aplica)

### Commit sugerido

```text
feat(composite): jerarquia de espacios e inventario del acta
```

---

## 4. Fase 3 — Flyweight de inventario

### Objetivo

Compartir un catálogo maestro por arrendador y guardar en cada acta solo el estado extrínseco.

### Cambios

- Migración `create_master_inventory_items_table`.
- Migración `add_master_inventory_item_id_to_act_items_table`.
- Crear contrato `InventoryItemFlyweight`.
- Crear `MasterInventoryItem`.
- Crear `InventoryItemFactory` con pool por petición y binding `scoped`.
- `ActItem`: FK y relación `masterItem()`.
- `DeliveryActBuilder`: resolver flyweight, validar dueño y guardar FK + snapshot `name`.
- Formulario: selector de elementos maestros con creación/edición.
- `DeliveryActDraft`: conservar `master_inventory_item_id`.
- Factories/seeders por usuario.
- Mantener compatibilidad legacy por `name`.

### Archivos para commit

- `database/migrations/*_create_master_inventory_items_table.php`
- `database/migrations/*_add_master_inventory_item_id_to_act_items_table.php`
- `app/Contracts/InventoryItemFlyweight.php`
- `app/Models/MasterInventoryItem.php`
- `app/Models/ActItem.php`
- `app/Services/InventoryItemFactory.php`
- `app/Services/Acts/DeliveryActBuilder.php`
- `app/Services/Acts/DeliveryActDraft.php`
- `app/Filament/Resources/DeliveryActResource.php`
- `app/Filament/Resources/DeliveryActResource/Pages/CreateDeliveryAct.php`
- `app/Providers/AppServiceProvider.php`
- `database/factories/MasterInventoryItemFactory.php`
- `database/factories/ActItemFactory.php`
- `database/seeders/DeliveryActSeeder.php`
- `database/seeders/DatabaseSeeder.php` (si aplica)
- `tests/Feature/InventoryItemFlyweightTest.php`
- `tests/Feature/DeliveryActBuilderTest.php`
- `tests/Feature/CreateDeliveryActTest.php`

### Commit sugerido

```text
feat(flyweight): catalogo maestro de inventario por arrendador
```

---

## 5. Fase 4 — DocumentProxy

### Objetivo

Envolver los documentos de `DocumentService` con un Proxy que audita el acceso y difiere la construcción del documento real.

### Cambios

- Migración y modelo `DocumentAccess`.
- Crear `DocumentAccessLogger` y `DatabaseDocumentAccessLogger`.
- Crear `DocumentDescriptor`.
- Crear `DocumentProxy implements Document`.
- `DocumentService` devuelve `Document` y construye descriptor + closure.
- Mantener la validación de comprobante pagado como guard estático de `PaymentReceipt`, para que el error siga siendo inmediato.
- `DocumentsPage` usa `isEmpty()`.
- Metadata (`filename`, `mimeType`, `deliveryLabel`, `isDownload`, `preview`, `isEmpty`) no audita.
- `content()` audita antes de delegar y construye el real una sola vez.
- Una fila de auditoría por instancia de proxy en el primer `content()`.
- Política fail-closed si falla la auditoría.
- Ajustar pruebas que esperaban tipos concretos o usaban `body()`/`invoices()`.

### Archivos para commit

- `database/migrations/*_create_document_accesses_table.php`
- `app/Models/DocumentAccess.php`
- `app/Contracts/DocumentAccessLogger.php`
- `app/Documents/Audit/DatabaseDocumentAccessLogger.php`
- `app/Documents/DocumentDescriptor.php`
- `app/Documents/DocumentProxy.php`
- `app/Documents/DocumentService.php`
- `app/Documents/Contracts/Document.php`
- `app/Documents/AbstractDocument.php`
- `app/Documents/MonthlyStatement.php`
- `app/Documents/PaymentReceipt.php`
- `app/Filament/Pages/DocumentsPage.php`
- `app/Providers/AppServiceProvider.php`
- `tests/Feature/DocumentProxyTest.php`
- `tests/Feature/DocumentFactoryTest.php`
- `tests/Feature/DocumentsPageTest.php`
- `tests/Feature/InvoiceDocumentActionTest.php`
- `tests/Feature/InvoiceTableDocumentActionsTest.php`

### Commit sugerido

```text
feat(proxy): auditar documentos con carga diferida
```

---

## 6. Fase 5 — RentalFacade

### Objetivo

Dar un punto de entrada único a las operaciones del alquiler sin mover la lógica de negocio.

### Cambios

- Crear `app/Services/RentalFacade.php`.
- Dependencias:
  - `PaymentPlanService`
  - `PaymentRecordingService`
  - `RentAdjustmentService`
  - `DocumentService`
  - `AdjustmentChainCalculator`
  - `AdjustmentChainRecalculator`
  - `AdjustmentDateProposer`
- Registrar como singleton.
- Migrar:
  - `CreateRental`
  - `InvoiceResource`
  - `RentAdjustmentResource`
  - `DocumentsPage`
- Mantener en `RentAdjustmentResource` los accesos directos a `IpcSeriesRepository` y `AppSettings`.
- Métodos de documentos devuelven `Document`.
- No mover notificaciones ni reglas nuevas a la Facade.

### Archivos para commit

- `app/Services/RentalFacade.php`
- `app/Providers/AppServiceProvider.php`
- `app/Filament/Resources/RentalResource/Pages/CreateRental.php`
- `app/Filament/Resources/InvoiceResource.php`
- `app/Filament/Resources/RentAdjustmentResource.php`
- `app/Filament/Pages/DocumentsPage.php`
- `tests/Feature/RentalFacadeTest.php`
- `tests/Feature/InvoiceResourceTest.php` (si requiere ajuste)
- `tests/Feature/ReajusteScreensRenderTest.php` (si existe y requiere ajuste)

### Commit sugerido

```text
feat(facade): centralizar operaciones de alquiler
```

---

## 7. Fase 6 — Decorator

### Objetivo

Documentar el Decorator que ya existe y dejar claro por qué no se reimplementa.

### Cambios

- Crear `docs/Patrones estructurales/Decorator.md`.
- Roles:
  - Component: `IpcBasisStrategy`
  - ConcreteComponent: `AnnualVariationStrategy`, `IndexRatioStrategy`
  - Decorator: `PactadoCapDecorator`
  - Client: `RentAdjustmentService`
- Explicar `legalSnapshot()` vs `resolve()`.
- Verificar cobertura del tope pactado; si no existe prueba explícita, agregar `PactadoCapDecoratorTest`.
- No modificar el código de producción salvo que la verificación descubra un bug.

### Archivos para commit

- `docs/Patrones estructurales/Decorator.md`
- `tests/Feature/PactadoCapDecoratorTest.php` (solo si falta cobertura)

### Commit sugerido

```text
docs(decorator): documentar tope pactado del IPC
```

---

## 8. Fase 7 — Documentación e integración final

**Fuera de este plan: la está ejecutando otro agente.**

Insumos que debe recibir:

- Documentos nuevos de Fase 6.
- Estado real de Composite, Facade, Flyweight, Proxy de festivos y DocumentProxy.
- Nombres de pruebas por patrón.
- Decisión de que `PROXY-FESTIVOS.md` ya es implementación real.
- Actualización de `docs/PLAN-IMPLEMENTACION-5-PATRONES-GOF.md` y `docs/DOCUMENTO-PRINCIPAL-PARCIAL-2.md`.

Documentos que debe crear o actualizar:

- `docs/Patrones estructurales/Composite.md`
- `docs/Patrones estructurales/Facade.md`
- `docs/Patrones estructurales/Flyweight.md`
- `docs/Patrones estructurales/Proxy.md`
- `docs/Patrones estructurales/Decorator.md`
- `docs/PLAN-IMPLEMENTACION-5-PATRONES-GOF.md`
- `docs/DOCUMENTO-PRINCIPAL-PARCIAL-2.md`

---

## 9. Verificación

Por fase:

```bash
php artisan test --filter=<TestName>
```

Al cerrar cada fase:

```bash
vendor/bin/pint --test
```

Al cerrar todas las fases:

```bash
php artisan test
vendor/bin/pint --test
```

---

## 10. Decisiones cerradas

- Snapshot `ActItem.name` se conserva aunque exista Flyweight.
- `space_id` es la referencia; `space` sigue como snapshot histórico.
- El Proxy de documentos audita `content()`, no metadata.
- El fallo de la API de festivos no se cachea.
- La Facade orquesta; no contiene reglas de negocio.
- Decorator se documenta como patrón existente, no se reescribe.
- El catálogo de inventario y los espacios son por arrendador.
- Unicidad de raíces de espacios se refuerza en aplicación.
