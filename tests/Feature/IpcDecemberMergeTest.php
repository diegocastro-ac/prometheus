<?php

namespace Tests\Feature;

use App\Dto\IpcObservation;
use App\Infrastructure\Ipc\InMemoryIpcProvider;
use App\Models\IpcSeries;
use App\Services\Ipc\IpcSeriesRepository;
use App\Services\Ipc\IpcSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * La fusión de diciembre con lo guardado al sincronizar.
 *
 * Un diciembre que el DANE trajo sin variación anual borraba esa
 * variación en la copia local: el año seguía existiendo en la tabla pero
 * desaparecía del selector de «Año del IPC» y de las pantallas que solo
 * ofrecen los años con el dato de diciembre.
 *
 * Estas pruebas fijan que la sincronización conserva el bloque de diciembre
 * guardado cuando el fetch llega sin él.
 */
class IpcDecemberMergeTest extends TestCase
{
    use RefreshDatabase;

    private static function december(string $code, int $year, ?float $variation): IpcObservation
    {
        return new IpcObservation(
            geographyCode: $code,
            geographyName: 'NACIONAL',
            year: $year,
            month: 12,
            index: 148.49,
            variationMonthly: 0.30,
            variationYoY: $variation,
            variationYear: $variation,
        );
    }

    private static function month(string $code, int $year, int $month): IpcObservation
    {
        return new IpcObservation(
            geographyCode: $code,
            geographyName: 'NACIONAL',
            year: $year,
            month: $month,
            index: 140.00 + $month,
            variationMonthly: 0.30,
            variationYoY: null,
            variationYear: null,
        );
    }

    /**
     * Serie de trece filas: doce meses corrientes mas el diciembre que se va
     * a traer sin variacion, como la trae un DANE que no cuadró las cifras.
     *
     * @return list<IpcObservation>
     */
    private static function seriesWithEmptyDecember(string $code, int $year): array
    {
        $rows = [];

        for ($month = 1; $month <= 12; $month++) {
            $rows[] = $month === 12
                ? new IpcObservation(
                    geographyCode: $code,
                    geographyName: 'NACIONAL',
                    year: $year,
                    month: 12,
                    index: 148.49,
                    variationMonthly: 0.30,
                    variationYoY: null,
                    variationYear: null,
                )
                : self::month($code, $year, $month);
        }

        $rows[] = self::month($code, $year - 1, 12);

        return $rows;
    }

    #[Test]
    public function el_diciembre_guardado_sobrevive_a_una_sync_que_lo_trae_vacio(): void
    {
        $code = (string) config('dane_ipc_sql.national_code', '50');

        IpcSeries::factory()->national()->decemberOf(2025, 5.10)->create();

        $provider = (new InMemoryIpcProvider)->withSeries(
            self::seriesWithEmptyDecember($code, 2025),
        );

        $result = (new IpcSynchronizer($provider))->syncGeography($code);

        $this->assertTrue($result['ok']);

        // El año sigue ofreciéndose en el selector con su cifra buena.
        $this->assertArrayHasKey(
            2025,
            app(IpcSeriesRepository::class)->yearOptions($code),
        );
    }

    #[Test]
    public function el_diciembre_nuevo_si_manda_cuando_trae_la_variacion(): void
    {
        $code = (string) config('dane_ipc_sql.national_code', '50');

        IpcSeries::factory()->national()->decemberOf(2025, 5.10)->create();

        $rows = [];

        for ($month = 1; $month <= 12; $month++) {
            $rows[] = $month === 12
                ? self::december($code, 2025, 5.20)
                : self::month($code, 2025, $month);
        }

        $rows[] = self::month($code, 2024, 12);

        $provider = (new InMemoryIpcProvider)->withSeries($rows);

        $result = (new IpcSynchronizer($provider))->syncGeography($code);

        $this->assertTrue($result['ok']);
        $this->assertSame(
            5.20,
            (float) IpcSeries::query()
                ->where('geography_code', $code)
                ->where('year', 2025)
                ->where('month', 12)
                ->value('variation_year'),
        );
    }
}
