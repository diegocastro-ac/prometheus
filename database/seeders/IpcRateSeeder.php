<?php

namespace Database\Seeders;

use App\Models\IpcRate;
use Illuminate\Database\Seeder;

/**
 * IPC de diciembre de cada ano, que es el que aplica el articulo 20 de la Ley
 * 820 de 2003 para reajustar el canon en el ano siguiente.
 *
 * Las cifras vienen del IPC anual publicado por el DANE. Se siembran como
 * referencia operante, no como dato scraping: cada fila guarda su url de
 * origen, asi que el valor puede verificarse y corregirse.
 *
 * Los valores anteriores a 2024 se omiten a proposito. Sembrar cifras que el
 * proyecto nunca uso solo agrega ruido; si mas adelante se necesita
 * historico, la fila se agrega con su fuente.
 */
class IpcRateSeeder extends Seeder
{
    /**
     * @var array<int, array{year: int, percentage: float, published: string}>
     */
    private const RATES = [
        ['year' => 2024, 'percentage' => 5.20, 'published' => '2025-01-05'],
        ['year' => 2025, 'percentage' => 9.28, 'published' => '2026-01-05'],
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
