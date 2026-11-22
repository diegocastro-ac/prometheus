<?php

namespace Tests\Feature;

use App\Contracts\HolidaySource;
use App\Infrastructure\Holidays\CachedHolidaySource;
use App\Infrastructure\Holidays\NagerDateBusinessCalendar;
use App\Infrastructure\Holidays\NagerDateHolidaySource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Proxy (GoF): cache de festivos delante de Nager.Date.
 */
class CachedHolidaySourceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    #[Test]
    public function el_proxy_cachea_los_festivos_y_no_vuelve_a_golpear_la_api(): void
    {
        Http::fake([
            '*/PublicHolidays/2026/CO' => Http::response([
                ['date' => '2026-01-12', 'localName' => 'Dia de los Reyes Magos'],
            ]),
        ]);

        $calendar = $this->calendar();

        // Dos consultas del mismo anio: la segunda sale de la cache del Proxy.
        $this->assertFalse($calendar->isBusinessDay(Carbon::parse('2026-01-12')));
        $this->assertFalse($calendar->isBusinessDay(Carbon::parse('2026-01-12')));

        Http::assertSentCount(1);
    }

    #[Test]
    public function el_proxy_degrada_a_lista_vacia_si_la_api_falla(): void
    {
        Http::fake([
            '*/PublicHolidays/*' => Http::response('', 500),
        ]);

        $this->assertSame([], $this->proxy()->holidaysFor(2026));
    }

    #[Test]
    public function el_proxy_no_cachea_el_fallo_y_reintenta_en_el_siguiente_intento(): void
    {
        Http::fake([
            '*/PublicHolidays/2026/CO' => Http::sequence()
                ->push('', 500)
                ->push([['date' => '2026-01-12']], 200),
        ]);

        $proxy = $this->proxy();

        // Primer intento: falla y devuelve lista vacia sin envenenar la cache.
        $this->assertSame([], $proxy->holidaysFor(2026));

        // Segundo intento: vuelve a consultar y ya recibe los festivos.
        $this->assertSame(['2026-01-12'], $proxy->holidaysFor(2026));

        Http::assertSentCount(2);
    }

    #[Test]
    public function el_proxy_y_el_real_son_intercambiables_para_el_cliente(): void
    {
        Http::fake([
            '*/PublicHolidays/2026/CO' => Http::response([
                ['date' => '2026-01-12'],
            ]),
        ]);

        $real = new NagerDateHolidaySource('https://date.nager.at/api/v3', 'CO');
        $proxy = new CachedHolidaySource(
            new NagerDateHolidaySource('https://date.nager.at/api/v3', 'CO'),
            'CO',
        );

        $this->assertInstanceOf(HolidaySource::class, $real);
        $this->assertInstanceOf(HolidaySource::class, $proxy);

        foreach ([$real, $proxy] as $source) {
            $calendar = new NagerDateBusinessCalendar($source);

            $this->assertFalse($calendar->isBusinessDay(Carbon::parse('2026-01-12')));
        }
    }

    private function calendar(string $country = 'CO'): NagerDateBusinessCalendar
    {
        return new NagerDateBusinessCalendar($this->proxy($country));
    }

    private function proxy(string $country = 'CO'): CachedHolidaySource
    {
        return new CachedHolidaySource(
            new NagerDateHolidaySource('https://date.nager.at/api/v3', $country),
            $country,
        );
    }
}
