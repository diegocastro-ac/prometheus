<?php

namespace App\Providers;

use App\Contracts\AgreementStorageInterface;
use App\Contracts\CurrentUserContextInterface;
use App\Infrastructure\AuthUserContext;
use App\Infrastructure\PublicDiskAgreementStorage;
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
        $this->app->bind(CurrentUserContextInterface::class, AuthUserContext::class);
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
