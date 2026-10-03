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
     * @param  array{business_name: string, tax_id: string, address?: string|null, currency?: string|null, logo_path?: string|null, email?: string|null, phone?: string|null, invoice_due_days?: int|null, legal_footer?: string|null, space_catalog?: array<int, string>|null}  $data
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
                'space_catalog' => json_encode(
                    array_values(array_filter(array_map(
                        static fn (mixed $space): string => is_string($space) ? trim($space) : '',
                        $data['space_catalog'] ?? [],
                    ))),
                    JSON_UNESCAPED_UNICODE,
                ),
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
            'space_catalog' => $settings->spaceCatalog(),
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