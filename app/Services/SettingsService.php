<?php

namespace App\Services;

use App\Settings\AppSettings;
use Illuminate\Support\Facades\DB;

/**
 * Escribe la fila unica que respalda al singleton AppSettings.
 *
 * Al guardar se descarta la instancia del contenedor. Si no, el resto de la
 * peticion seguiria viendo los valores viejos y un documento impreso en el
 * mismo minuto mostraria el NIT anterior.
 */
class SettingsService
{
    /**
     * @param  array{business_name: string, tax_id: string, address?: string|null, currency?: string|null, logo_path?: string|null, email?: string|null, phone?: string|null, invoice_due_days?: int|null, legal_footer?: string|null, default_city_code?: string|null}  $data
     */
    public function save(array $data): void
    {
        DB::table('app_settings')->updateOrInsert(
            ['id' => 1],
            [
                'business_name' => $data['business_name'],
                'tax_id' => $data['tax_id'],
                'address' => $data['address'] ?? '',
                'currency' => $data['currency'] ?? 'COP',
                'logo_path' => $data['logo_path'] ?? null,
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'invoice_due_days' => $data['invoice_due_days'] ?? 5,
                'legal_footer' => $data['legal_footer'] ?? null,
                // La ciudad por defecto se deja en null cuando el arrendador no
                // elige ninguna, y esa ausencia es significativa: el formulario
                // de propiedades pide la ciudad en vez de rellenarla.
                'default_city_code' => $data['default_city_code'] ?? null,
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );

        $this->forgetCachedInstance();
    }

    /**
     * Los valores actuales, en el formato que espera el formulario.
     *
     * @return array<string, mixed>
     */
    public function current(): array
    {
        $settings = app(AppSettings::class);

        return [
            'business_name' => $settings->businessName(),
            'tax_id' => $settings->taxId(),
            'address' => $settings->address(),
            'currency' => $settings->currency(),
            'logo_path' => $settings->logoPath(),
            'email' => $settings->email(),
            'phone' => $settings->phone(),
            'invoice_due_days' => $settings->invoiceDueDays(),
            'legal_footer' => $settings->legalFooter(),
            'default_city_code' => $settings->defaultCityCode(),
        ];
    }

    /**
     * Descarta la instancia unica para que se vuelva a leer desde la base de datos.
     */
    public function forgetCachedInstance(): void
    {
        app()->forgetInstance(AppSettings::class);
    }
}
