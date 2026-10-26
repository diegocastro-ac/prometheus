<?php

namespace App\Providers;

use App\Contracts\AgreementStorageInterface;
use App\Contracts\BusinessCalendar;
use App\Contracts\CurrentUserContextInterface;
use App\Contracts\DocumentAccessLogger;
use App\Contracts\Ipc\IpcProvider;
use App\Documents\Audit\DatabaseDocumentAccessLogger;
use App\Documents\DocumentFactoryLocator;
use App\Documents\Factories\DocumentFactory;
use App\Documents\Factories\TextDocumentFactory;
use App\Events\IpcSeriesSynchronized;
use App\Infrastructure\AuthUserContext;
use App\Infrastructure\Holidays\CachedHolidaySource;
use App\Infrastructure\Holidays\NagerDateBusinessCalendar;
use App\Infrastructure\Holidays\NagerDateHolidaySource;
use App\Infrastructure\Holidays\WeekendBusinessCalendar;
use App\Infrastructure\Ipc\DaneCdaIpcProvider;
use App\Infrastructure\Ipc\LocalSeriesIpcProvider;
use App\Infrastructure\PublicDiskAgreementStorage;
use App\Listeners\RebuildAdjustmentChains;
use App\Listeners\RebuildAnnualRates;
use App\Services\Acts\DeliveryActBuilder;
use App\Services\Adjustment\AdjustmentChainCalculator;
use App\Services\Adjustment\AdjustmentChainRecalculator;
use App\Services\Adjustment\Strategies\IpcBasisStrategyResolver;
use App\Services\Adjustment\Validation\CityIpcRequiresAcknowledgement;
use App\Services\Adjustment\Validation\CommercialValueWithinCatastralCeiling;
use App\Services\Adjustment\Validation\HasIpcData;
use App\Services\Adjustment\Validation\IsResidentialScope;
use App\Services\Adjustment\Validation\NotificationHasValidMeans;
use App\Services\Adjustment\Validation\ReajusteValidationChain;
use App\Services\Adjustment\Validation\StatutoryCalendarYear;
use App\Services\Adjustment\Validation\WithinCapOrAcknowledged;
use App\Services\Adjustment\Validation\WithinOnePercentOfCommercialValue;
use App\Services\Adjustment\Validation\WithinTwelveMonthsSamePrice;
use App\Services\InventoryItemFactory;
use App\Services\Ipc\CdaQueryFactory;
use App\Services\Ipc\IpcSeriesRepository;
use App\Services\RentalFacade;
use App\Settings\AppSettings;
use BezhanSalleh\FilamentLanguageSwitch\LanguageSwitch;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(AgreementStorageInterface::class, PublicDiskAgreementStorage::class);

        // Singleton: el contexto del usuario se resuelve una sola vez y todas
        // las pantallas reciben la misma instancia. AuthUserContext pregunta el
        // id a Auth en cada llamada, asi que la instancia unica no guarda
        // datos del usuario de una peticion a otra.
        $this->app->singleton(CurrentUserContextInterface::class, AuthUserContext::class);

        // Singleton: la configuracion se lee una vez por peticion. El acta de
        // entrega y los documentos la reciben en lugar de duplicar los datos.
        $this->app->singleton(AppSettings::class, fn () => AppSettings::fromDatabase());

        // Scoped, no singleton: el Builder acumula secciones mientras arma el
        // acta, asi que una instancia unica para toda la aplicacion tendria
        // que reiniciarse a mano entre actas. Con scoped hay una instancia por
        // peticion, que es justo lo que hace falta sin arrastrar estado.
        //
        // El pool de flyweights tambien es scoped: comparte el catalogo maestro
        // durante la peticion y lo descarta al terminar, sin reutilizar objetos
        // de otro usuario.
        $this->app->scoped(InventoryItemFactory::class, fn (): InventoryItemFactory => new InventoryItemFactory);

        $this->app->scoped(DeliveryActBuilder::class, fn ($app): DeliveryActBuilder => new DeliveryActBuilder(
            $app->make(InventoryItemFactory::class),
        ));

        // Abstract Factory: la familia de documentos por defecto es la de texto
        // plano, porque no depende de dompdf y por eso los tests y la vista
        // previa funcionan siempre. La de PDF se pide por nombre con el locator
        // cuando la pantalla elige formato.
        //
        // El locator se registra como singleton porque no tiene estado: solo
        // mapea formato a implementacion. Las fabricas, en cambio, son scoped:
        // cada documento memoriza su contenido renderizado y no conviene
        // compartir un producto entre peticiones.
        $this->app->singleton(DocumentFactoryLocator::class);
        $this->app->bind(DocumentFactory::class, TextDocumentFactory::class);

        // Proxy de documentos: el contrato permite cambiar el destino de la
        // auditoria sin que el servicio ni las pantallas lo sepan.
        $this->app->bind(DocumentAccessLogger::class, DatabaseDocumentAccessLogger::class);

        // Facade: un solo punto de entrada para las operaciones del alquiler.
        // Singleton porque no tiene estado propio; las dependencias conservan
        // sus ciclos de vida y la logica de negocio sigue en ellas.
        $this->app->singleton(RentalFacade::class);

        // Adapter + Proxy: el dominio pregunta "es dia habil?" sin conocer la
        // fuente de festivos. En produccion el calendario consulta un Proxy que
        // cachea por anio y degrada si Nager.Date falla; en las pruebas se usa
        // el calendario de fin de semana para no depender de la red.
        if ($this->app->environment('testing')) {
            $this->app->bind(BusinessCalendar::class, WeekendBusinessCalendar::class);
        } else {
            $this->app->bind(BusinessCalendar::class, function (): NagerDateBusinessCalendar {
                $baseUrl = (string) config('services.holidays.base_url');
                $country = (string) config('services.holidays.country');

                return new NagerDateBusinessCalendar(
                    new CachedHolidaySource(
                        new NagerDateHolidaySource($baseUrl, $country),
                        $country,
                    ),
                );
            });
        }

        /*
        |----------------------------------------------------------------------
        | Adapter del IPC (segunda aplicacion del patron)
        |----------------------------------------------------------------------
        |
        | Igual que BusinessCalendar, el dominio no sabe de donde sale el dato.
        | Pero aqui la razon de que apunte a la copia local y no al DANE es de
        | disponibilidad: el IPC llega por un servicio CDA de Pentaho sin
        | documentacion, que devuelve 502 de forma intermitente.
        |
        | Si la aplicacion calculara contra el origen en tiempo real, una carta de
        | reajuste emitida por correo fallaria porque el DANE este caido, y una
        | correccion del DANE podria cambiar el texto de una carta ya emitida.
        | El flujo queda de una sola via: el comando ipc:sync pide los datos al
        | adaptador del DANE y los guarda en ipc_series; todo lo demas lee de ahi.
        |
        */

        $this->app->singleton(IpcProvider::class, LocalSeriesIpcProvider::class);

        $this->app->singleton(DaneCdaIpcProvider::class, fn ($app) => new DaneCdaIpcProvider(
            $app->make(CdaQueryFactory::class),
        ));

        $this->app->singleton(LocalSeriesIpcProvider::class);

        /*
        |----------------------------------------------------------------------
        | Cadena de reglas del articulo 18 y del articulo 20
        |----------------------------------------------------------------------
        |
        | Singleton porque no tiene estado: son nueve objetos inmutables que
        | devuelven lo mismo siempre. Compartirla evita que cada pantalla que
        | valida un reajuste arme su propia copia de las reglas, que es
        | exactamente como dos pantallas empiezan a discrepar sobre lo que dice
        | la ley.
        |
        */

        $this->app->singleton(ReajusteValidationChain::class, fn ($app) => new ReajusteValidationChain([
            new HasIpcData($app->make(IpcSeriesRepository::class)),
            new IsResidentialScope,
            new WithinTwelveMonthsSamePrice,
            new StatutoryCalendarYear,
            new CityIpcRequiresAcknowledgement,
            new CommercialValueWithinCatastralCeiling,
            new WithinOnePercentOfCommercialValue,
            new WithinCapOrAcknowledged,
            new NotificationHasValidMeans,
        ]));

        $this->app->singleton(IpcBasisStrategyResolver::class);
        $this->app->singleton(AdjustmentChainCalculator::class);
        $this->app->singleton(AdjustmentChainRecalculator::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Observer: sincronizar el IPC dispara la reconstruccion de las tasas
        // anuales y el recalculo de las cadenas de reajuste. El comando de
        // sincronizacion no necesita saber que estos dos existen.
        Event::listen(IpcSeriesSynchronized::class, RebuildAnnualRates::class);
        Event::listen(IpcSeriesSynchronized::class, RebuildAdjustmentChains::class);

        LanguageSwitch::configureUsing(function (LanguageSwitch $switch) {
            $switch
                ->locales(['en', 'es']);
        });

        /*
        |----------------------------------------------------------------------
        | CSS propio del panel de Filament
        |----------------------------------------------------------------------
        |
        | Filament v3 usa Tailwind v3, pero su hoja compilada por defecto solo
        | trae las clases de SUS componentes. Las utilidades Tailwind escritas
        | en nuestras vistas (grid, xl:grid-cols-3, space-y-6, etc.) no existen
        | en esa hoja. Por eso se compila una hoja adicional con Tailwind v3 y
        | se carga DESPUES del CSS de Filament.
        |
        | El hook STYLES_AFTER es importante: si se inyecta en HEAD_START, el
        | CSS de Filament se carga despues y puede tapar nuestras reglas.
        | resources/css/filament.css conserva los estilos .ajuste-* y tambien
        | debe ir despues del CSS base.
        */
        FilamentView::registerRenderHook(
            PanelsRenderHook::STYLES_AFTER,
            fn (): string => '<link rel="stylesheet" href="'.e(asset('css/filament/app-utilities.css')).'" data-navigate-track />',
        );

        FilamentView::registerRenderHook(
            PanelsRenderHook::STYLES_AFTER,
            fn (): string => Blade::render('@vite([\'resources/css/filament.css\'])'),
        );
    }
}
