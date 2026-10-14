<?php

namespace Tests\Feature;

use App\Contracts\BusinessCalendar;
use App\Enums\BillingCadence;
use App\Infrastructure\Holidays\NagerDateBusinessCalendar;
use App\Infrastructure\Holidays\WeekendBusinessCalendar;
use App\Models\Invoice;
use App\Models\Rental;
use App\Models\User;
use App\Services\PaymentPlanService;
use App\ValueObjects\RentalPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Adapter: el calendario de dias habiles.
 *
 * El dominio pregunta "es dia habil?" sin conocer de donde vienen los festivos.
 * El adaptador de Nager.Date traduce el servicio externo a esa interfaz y, si
 * no responde, degrada al calendario de fin de semana.
 */
class BusinessCalendarTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('app_settings')->insert([
            'id' => 1,
            'business_name' => 'Inmobiliaria Ejemplo S.A.S.',
            'tax_id' => '900123456-7',
            'currency' => 'COP',
            'invoice_due_days' => 5,
            'space_catalog' => json_encode(['Sala']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        User::factory()->create();
    }

    #[Test]
    public function en_las_pruebas_se_usa_el_calendario_de_fin_de_semana(): void
    {
        $this->assertInstanceOf(WeekendBusinessCalendar::class, app(BusinessCalendar::class));
    }

    #[Test]
    public function el_calendario_de_respaldo_salta_el_fin_de_semana(): void
    {
        $calendar = new WeekendBusinessCalendar();

        // 2026-01-10 es sabado, el lunes habil es el 12.
        $this->assertFalse($calendar->isBusinessDay(Carbon::parse('2026-01-10')));
        $this->assertTrue($calendar->isBusinessDay(Carbon::parse('2026-01-12')));
        $this->assertSame('2026-01-12', $calendar->nextBusinessDay(Carbon::parse('2026-01-10'))->toDateString());
    }

    #[Test]
    public function el_adaptador_salta_el_festivo_que_devuelve_la_api(): void
    {
        Http::fake([
            '*/PublicHolidays/2026/CO' => Http::response([
                ['date' => '2026-01-12', 'localName' => 'Dia de los Reyes Magos'],
            ]),
        ]);

        $calendar = new NagerDateBusinessCalendar('https://date.nager.at/api/v3', 'CO');

        // El lunes 2026-01-12 es festivo, el martes 13 es el siguiente habil.
        $this->assertFalse($calendar->isBusinessDay(Carbon::parse('2026-01-12')));
        $this->assertSame('2026-01-13', $calendar->nextBusinessDay(Carbon::parse('2026-01-12'))->toDateString());
    }

    #[Test]
    public function el_adaptador_degrada_a_fin_de_semana_si_la_api_falla(): void
    {
        Http::fake([
            '*/PublicHolidays/*' => Http::response('', 500),
        ]);

        $calendar = new NagerDateBusinessCalendar('https://date.nager.at/api/v3', 'CO');

        // Sabado 2026-01-10: aunque la API falle, salta al lunes.
        $this->assertSame('2026-01-12', $calendar->nextBusinessDay(Carbon::parse('2026-01-10'))->toDateString());
    }

    #[Test]
    public function el_vencimiento_de_una_factura_cae_en_dia_habil(): void
    {
        $rental = Rental::factory()->create([
            'start_date' => '2026-01-02',
            'end_date' => '2026-02-28',
            'total_months' => 1,
            'monthly_amount' => 1_000_000,
            'billing_cadence' => BillingCadence::MENSUAL,
        ]);

        app(PaymentPlanService::class)->generateFor($rental);

        $invoice = Invoice::where('rental_id', $rental->id)->firstOrFail();

        // El corte cae el 2026-02-02 (lunes) y su plazo vence el 2026-02-07
        // (sabado), por lo que corre al lunes 2026-02-09.
        $this->assertSame('2026-02-09', $invoice->due_at->toDateString());
    }

    #[Test]
    public function el_vencimiento_hereda_los_festivos_del_adaptador(): void
    {
        Http::fake([
            '*/PublicHolidays/2026/CO' => Http::response([
                ['date' => '2026-02-06', 'localName' => 'Festivo de prueba'],
            ]),
        ]);

        // Se usa el adaptador real: el vencimiento debe respetar su festivo.
        $this->app->bind(BusinessCalendar::class, fn (): NagerDateBusinessCalendar => new NagerDateBusinessCalendar(
            'https://date.nager.at/api/v3',
            'CO',
        ));

        $rental = Rental::factory()->create([
            'start_date' => '2026-01-01',
            'end_date' => '2026-02-28',
            'total_months' => 1,
            'monthly_amount' => 1_000_000,
            'billing_cadence' => BillingCadence::MENSUAL,
        ]);

        app(PaymentPlanService::class)->generateFor($rental);

        $invoice = Invoice::where('rental_id', $rental->id)->firstOrFail();

        // El corte cae el 2026-02-01 (domingo), su plazo el 2026-02-06 (viernes
        // festivo) y el siguiente habil es el lunes 2026-02-09.
        $this->assertSame('2026-02-09', $invoice->due_at->toDateString());
    }

    #[Test]
    public function el_fin_del_alquiler_incluye_el_plazo_y_el_dia_habil(): void
    {
        // Contrato de un mes desde el 2026-01-02: el ultimo corte cae el
        // 2026-02-02 (lunes), el plazo de 5 dias vence el 2026-02-07 (sabado)
        // y el fin de contrato corre al lunes 2026-02-09.
        $end = RentalPeriod::for(Carbon::parse('2026-01-02'), 1)->endDateFormatted();

        $this->assertSame('2026-02-09', $end);
    }
}
