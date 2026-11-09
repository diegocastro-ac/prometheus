<?php

namespace Database\Seeders;

use App\Dto\Geography;
use App\Models\IpcRate;
use App\Models\IpcSeries;
use Illuminate\Database\Seeder;

/**
 * IPC de diciembre de cada ano, que es el que aplica el articulo 20 de la Ley
 * 820 de 2003 para reajustar el canon en el ano siguiente.
 *
 * LAS CIFRAS DE ESTE ARCHIVO SE VERIFICARON CONTRA EL DANE.
 *
 * La version anterior de este seeder decia 2024 => 5,20 % y 2025 => 9,28 %. La
 * segunda cifra es la de 2023, no la de 2025. El resultado era que un reajuste
 * que rigiera en 2026 calculaba el tope con el IPC de 2024 en vez de con el de
 * 2025, y las cartas de reajuste citaban una cifra que el DANE nunca publico
 * para ese ano.
 *
 * Las cifras correctas, que son las que devuelve el CDA y coinciden con las
 * publicadas por el DANE:
 *
 *   diciembre 2023 -> indice 137,72 ; variacion anual 9,28 %
 *   diciembre 2024 -> indice 144,88 ; variacion anual 5,20 %
 *   diciembre 2025 -> indice 152,27 ; variacion anual 5,10 %
 *
 * ipc_series es la fuente y ipc_rates es la tabla derivada. Sembrar solo la
 * segunda dejaba una instalacion nueva sin el dato mensual que el calculo del
 * reajuste consulta, y el seeder de reajustes fallaba con "December ... CPI is
 * not on record". Por eso se siembran las dos: primero el dato de diciembre del
 * total nacional y luego la tasa anual que se calcula a partir de el.
 *
 * Este seeder existe solo para que una instalacion nueva tenga algo antes de la
 * primera sincronizacion. En cuanto corra "php artisan ipc:sync", la serie se
 * reconstruye desde el origen real y estas filas quedan reemplazadas por el dato
 * que el DANE publica.
 */
class IpcRateSeeder extends Seeder
{
    /**
     * @var array<int, array{year: int, index: float, percentage: float, published: string}>
     */
    private const RATES = [
        ['year' => 2023, 'index' => 137.72, 'percentage' => 9.28, 'published' => '2024-01-05'],
        ['year' => 2024, 'index' => 144.88, 'percentage' => 5.20, 'published' => '2025-01-05'],
        ['year' => 2025, 'index' => 152.27, 'percentage' => 5.10, 'published' => '2026-01-08'],
    ];

    public const SOURCE_URL = 'https://www.dane.gov.co/index.php/estadisticas-por-tema/precios-y-costos/indice-de-precios-al-consumidor-ipc/ipc-historico';

    public function run(): void
    {
        foreach (self::RATES as $rate) {
            // Primero la fuente: el dato de diciembre del total nacional.
            // updateOrCreate para que corregir la referencia no dependa de
            // borrar filas que ya estan citadas por reajustes y cartas.
            IpcSeries::updateOrCreate(
                [
                    'geography_code' => Geography::nationalCode(),
                    'year' => $rate['year'],
                    'month' => 12,
                ],
                [
                    'geography_name' => (string) config('dane_ipc_sql.national_name', 'NACIONAL'),
                    'index_value' => $rate['index'],
                    // La variacion mensual no hace falta para el articulo 20 y
                    // no se inventa: si la fila ya existe, se conserva.
                    'variation_yoy' => $rate['percentage'],
                    'variation_year' => $rate['percentage'],
                    'source_name' => 'DANE',
                    'source_url' => self::SOURCE_URL,
                    'synced_at' => now(),
                ],
            );

            // Despues la tabla derivada que usan los documentos y los selectores.
            IpcRate::updateOrCreate(
                ['year' => $rate['year']],
                [
                    'percentage' => $rate['percentage'],
                    'source_name' => 'DANE',
                    'source_url' => self::SOURCE_URL,
                    'published_on' => $rate['published'],
                ],
            );
        }
    }
}
