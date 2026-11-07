# Mapa de Patrones de Diseño

Los 15 patrones documentados fueron verificados en el código y tienen documento individual.

## Creacionales

| Patrón | Implementación verificada | Archivos clave | Documento |
| --- | --- | --- | --- |
| **Singleton** | `AppSettings` y `CurrentUserContextInterface` registrados como singleton | `app/Settings/AppSettings.php`, `app/Infrastructure/AuthUserContext.php`, `app/Providers/AppServiceProvider.php` | `docs/Patrones creacionales/Singleton.md` |
| **Prototype** | `Prototype`, `__clone()` en `DeliveryAct` y `ActItem`, cliente `DeliveryActDraft` | `app/Contracts/Prototype.php`, `app/Models/DeliveryAct.php`, `app/Models/ActItem.php`, `app/Services/Acts/DeliveryActDraft.php` | `docs/Patrones creacionales/Prototype.md` |
| **Builder** | `DeliveryActBuilder` scoped, secciones opcionales y `build()` persistente | `app/Services/Acts/DeliveryActBuilder.php` | `docs/Patrones creacionales/Builder.md` |
| **Abstract Factory** | `DocumentFactory` con tres familias y cuatro productos | `app/Documents/Factories/`, `app/Documents/DocumentFactoryLocator.php` | `docs/Patrones creacionales/Abstract Factory.md` |
| **Factory Method** | `PaymentPlanGenerator` y tres subclases por cadencia | `app/Services/PaymentPlans/`, `app/Services/PaymentPlanService.php` | `docs/Patrones creacionales/Factory Method.md` |

## Estructurales

| Patrón | Implementación verificada | Archivos clave | Documento |
| --- | --- | --- | --- |
| **Adapter** | `BusinessCalendar` y `NagerDateBusinessCalendar`; segunda aplicación para IPC | `app/Contracts/BusinessCalendar.php`, `app/Contracts/HolidaySource.php`, `app/Infrastructure/Holidays/`, `app/Contracts/Ipc/IpcProvider.php`, `app/Infrastructure/Ipc/` | `docs/Patrones estructurales/Adapter.md` |
| **Bridge** | `AbstractDocument` como abstracción; `DocumentRenderer` como implementador | `app/Documents/AbstractDocument.php`, `app/Documents/Rendering/` | `docs/Patrones estructurales/Bridge.md` |
| **Composite** | `SpaceComponent`, `SpaceLeaf` y `SpaceComposite`; árbol construido por `SpaceTreeBuilder` | `app/Models/SpaceComponent.php`, `app/Models/SpaceLeaf.php`, `app/Models/SpaceComposite.php`, `app/Services/Acts/SpaceTreeBuilder.php` | `docs/Patrones estructurales/Composite.md` |
| **Decorator** | `PactadoCapDecorator` envuelve `IpcBasisStrategy` y limita el porcentaje pactado | `app/Services/Adjustment/Decorators/PactadoCapDecorator.php`, `app/Services/RentAdjustmentService.php` | `docs/Patrones estructurales/Decorator.md` |
| **Facade** | `RentalFacade` coordina cobros, pagos, reajustes, documentos y fechas | `app/Services/RentalFacade.php` | `docs/Patrones estructurales/Facade.md` |
| **Flyweight** | `InventoryItemFactory` comparte instancias de `MasterInventoryItem` por arrendador | `app/Services/InventoryItemFactory.php`, `app/Contracts/InventoryItemFlyweight.php`, `app/Models/MasterInventoryItem.php` | `docs/Patrones estructurales/Flyweight.md` |
| **Proxy** | `CachedHolidaySource` para festivos y `DocumentProxy` para documentos | `app/Infrastructure/Holidays/CachedHolidaySource.php`, `app/Documents/DocumentProxy.php`, `app/Documents/DocumentService.php` | `docs/Patrones estructurales/Proxy.md` |

## Comportamiento

| Patrón | Implementación verificada | Archivos clave | Documento |
| --- | --- | --- | --- |
| **Strategy** | `IpcBasisStrategy` con estrategias concretas (`AnnualVariationStrategy`, `IndexRatioStrategy`, `FrozenSnapshotStrategy`), selección por `IpcBasisStrategyResolver` y contexto `RentAdjustmentService` (compuesto con `PactadoCapDecorator`) | `app/Services/Adjustment/Strategies/`, `app/Services/RentAdjustmentService.php`, `app/Services/Adjustment/Decorators/PactadoCapDecorator.php` | `docs/Patrones de comportamiento/Strategy.md` |
| **Observer** | Evento `IpcSeriesSynchronized` suscrito a `RebuildAnnualRates` y `RebuildAdjustmentChains` en `AppServiceProvider`; publicado por `SyncIpcDane` y acción de sync en `ListIpcSeries` | `app/Events/IpcSeriesSynchronized.php`, `app/Listeners/RebuildAnnualRates.php`, `app/Listeners/RebuildAdjustmentChains.php`, `app/Providers/AppServiceProvider.php`, `app/Console/Commands/SyncIpcDane.php`, `app/Filament/Resources/IpcSeriesResource/Pages/ListIpcSeries.php` | `docs/Patrones de comportamiento/Observer.md` |
| **Template Method** | `PaymentPlanGenerator::generate()` final fija crear → refrescar; `createInvoices()` es la primitiva y `findInvoice()` el hook de idempotencia | `app/Services/PaymentPlans/PaymentPlanGenerator.php`, `MonthlyPlanGenerator.php`, `QuincenalPlanGenerator.php`, `AdvancePlanGenerator.php` | `docs/Patrones de comportamiento/Template Method.md` |
