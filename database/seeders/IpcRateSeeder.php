<?php

namespace Database\Seeders;

use App\Models\IpcRate;
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
 *   diciembre 2023 -> 9,28 %
 *   diciembre 2024 -> 5,20 %
 *   diciembre 2025 -> 5,10 %
 *
 * Este seeder existe solo para que una instalacion nueva tenga algo antes de la
 * primera sincronizacion. En cuanto corra "php artisan ipc:sync", la tabla
 * ipc_rates se reconstruye desde la serie real y estas filas quedan reemplazadas
 * por el dato que el DANE publica.
 */
class IpcRateSeeder extends Seeder
{
    /**
     * @var array<int, array{year: int, percentage: float, published: string}>
     */
    private const RATES = [
        ['year' => 2023, 'percentage' => 9.28, 'published' => '2024-01-05'],
        ['year' => 2024, 'percentage' => 5.20, 'published' => '2025-01-05'],
        ['year' => 2025, 'percentage' => 5.10, 'published' => '2026-01-08'],
    ];

    public const SOURCE_URL = 'https://www.dane.gov.co/index.php/estadisticas-por-tema/precios-y-costos/indice-de-precios-al-consumidor-ipc/ipc-historico';

    public function run(): void
    {
        foreach (self::RATES as $rate) {
            // updateOrCreate en vez de firstOrCreate para que corregir el dato de
            // un año no requiera borrar la fila, que ya puede tener ajustes y
            // cartas de reajuste que la referencian.
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
