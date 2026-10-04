<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Rental;
use App\Models\User;
use App\Services\PaymentPlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * El periodo es el mes que se cobra, y no siempre coincide con el mes en que se
 * emite el documento. Estos tests fijan que ambos conceptos queden separados.
 */
class InvoicePeriodTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Rental $rental;

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

        $this->user = User::factory()->create();

        // El factory de alquileres tira la fecha de inicio hasta un ano atras,
        // asi que un alquiler suyo puede tener todo el contrato vencido. Aqui se
        // fija uno que empieza este mes para poder comprobar los dos lados.
        $this->rental = Rental::factory()->create([
            'user_id' => $this->user->id,
            'start_date' => today()->startOfMonth()->toDateString(),
            'total_months' => 6,
        ]);
    }

    #[Test]
    public function el_periodo_se_deriva_del_mes_que_se_pasa(): void
    {
        $this->assertSame('2026-03', Invoice::periodFor(new \DateTimeImmutable('2026-03-01')));
        $this->assertSame('2026-12', Invoice::periodFor(new \DateTimeImmutable('2026-12-31')));
        $this->assertSame('2027-01', Invoice::periodFor(new \DateTimeImmutable('2027-01-01')));
    }

    #[Test]
    public function el_plan_asigna_un_periodo_distinto_a_cada_factura(): void
    {
        $this->actingAs($this->user);

        app(PaymentPlanService::class)->generateFor($this->rental);

        $periods = Invoice::where('rental_id', $this->rental->id)
            ->orderBy('id')
            ->pluck('period')
            ->all();

        $this->assertCount($this->rental->total_months, $periods);

        // Ninguna repetida: dos facturas del mismo mes serian el mismo cobro.
        $this->assertSame($periods, array_values(array_unique($periods)));
    }

    #[Test]
    public function el_plan_calcula_el_vencimiento_desde_el_periodo_y_no_desde_hoy(): void
    {
        $this->actingAs($this->user);

        $dueDays = app(\App\Settings\AppSettings::class)->invoiceDueDays();

        app(PaymentPlanService::class)->generateFor($this->rental);

        Invoice::where('rental_id', $this->rental->id)
            ->get()
            ->each(function (Invoice $invoice) use ($dueDays): void {
                // El vencimiento sale de la fecha de emision mas los dias de
                // Ajustes. Si se calculara desde hoy, todas las facturas del
                // contrato tendrian la misma fecha de vencimiento.
                $this->assertSame(
                    $invoice->issued_at->copy()->addDays($dueDays)->toDateString(),
                    $invoice->due_at->toDateString(),
                );
            });
    }

    #[Test]
    public function los_periodos_pasados_del_contrato_quedan_vencidos(): void
    {
        $this->actingAs($this->user);

        app(PaymentPlanService::class)->generateFor($this->rental);

        $invoices = Invoice::where('rental_id', $this->rental->id)->get();

        // El plan recorre el contrato completo, asi que los meses ya
        // transcurridos nacen vencidos: esa mora es real, la arrendadora no ha
        // abonado nada. Las unicas que no deben estar vencidas son las del mes
        // en curso y las futuras.
        $future = $invoices->filter(fn (Invoice $invoice): bool => $invoice->due_at->gte(today()));

        $this->assertNotCount(0, $future);

        foreach ($future as $invoice) {
            $this->assertFalse($invoice->isOverdue());
        }

        $past = $invoices->filter(fn (Invoice $invoice): bool => $invoice->due_at->lt(today()));

        foreach ($past as $invoice) {
            $this->assertTrue($invoice->isOverdue());
            $this->assertSame(\App\Enums\InvoiceStatus::VENCIDA, $invoice->fresh()->status);
        }
    }

    #[Test]
    public function el_plan_registra_el_concepto_como_clave_y_no_como_texto(): void
    {
        $this->actingAs($this->user);

        app(PaymentPlanService::class)->generateFor($this->rental);

        // Guardar el texto traducido haria que un filtro por 'rent' no
        // encontrara nada, porque la columna valdria 'Canon de alquiler'.
        $this->assertSame(
            ['rent'],
            Invoice::where('rental_id', $this->rental->id)->distinct()->pluck('concept')->all(),
        );
    }

    #[Test]
    public function el_periodo_de_una_factura_no_cambia_al_cambiarla_de_estado(): void
    {
        $invoice = Invoice::factory()->create([
            'rental_id' => $this->rental->id,
            'user_id' => $this->user->id,
            'period' => '2026-01',
            'issued_at' => '2026-02-01',
            'amount' => 500_000,
        ]);

        app(\App\Services\PaymentRecordingService::class)->record($invoice, 500_000);

        $this->assertSame('2026-01', $invoice->fresh()->period);
    }

    #[Test]
    public function dos_facturas_del_mismo_mes_conviven_con_numeros_distintos(): void
    {
        $service = app(\App\Services\InvoiceNumberService::class);

        $numbers = [
            $service->next($this->user->id, 2026),
            $service->next($this->user->id, 2026),
        ];

        foreach ($numbers as $number) {
            Invoice::factory()->create([
                'rental_id' => $this->rental->id,
                'user_id' => $this->user->id,
                'number' => $number,
                'period' => '2026-03',
                'concept' => 'rent',
            ]);
        }

        $this->assertSame(2, Invoice::where('period', '2026-03')->count());
    }
}
