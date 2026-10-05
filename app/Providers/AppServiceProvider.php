<?php

namespace App\Providers;

use App\Contracts\AgreementStorageInterface;
use App\Contracts\CurrentUserContextInterface;
use App\Documents\DocumentFactoryLocator;
use App\Documents\Factories\DocumentFactory;
use App\Documents\Factories\TextDocumentFactory;
use App\Infrastructure\AuthUserContext;
use App\Infrastructure\PublicDiskAgreementStorage;
use App\Services\Acts\DeliveryActBuilder;
use App\Settings\AppSettings;
use BezhanSalleh\FilamentLanguageSwitch\LanguageSwitch;
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
        $this->app->scoped(DeliveryActBuilder::class, fn ($app) => new DeliveryActBuilder(
            $app->make(AppSettings::class),
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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        LanguageSwitch::configureUsing(function (LanguageSwitch $switch) {
            $switch
                ->locales(['en', 'es']);
        });
    }
}
