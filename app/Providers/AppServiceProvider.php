<?php

namespace App\Providers;

use App\Contracts\AgreementStorageInterface;
use App\Contracts\CurrentUserContextInterface;
use App\Infrastructure\AuthUserContext;
use App\Infrastructure\PublicDiskAgreementStorage;
use App\Services\Acts\DeliveryActBuilder;
use App\Settings\AppSettings;
use Illuminate\Support\ServiceProvider;
use BezhanSalleh\FilamentLanguageSwitch\LanguageSwitch;

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
