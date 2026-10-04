<?php

namespace Tests\Feature;

use App\Enums\BillingCadence;
use App\Models\Invoice;
use App\Models\Rental;
use App\Models\User;
use App\Services\PaymentPlans\AdvancePlanGenerator;
use App\Services\PaymentPlans\MonthlyPlanGenerator;
use App\Services\PaymentPlans\PaymentPlanGeneratorResolver;
use App\Services\PaymentPlans\QuincenalPlanGenerator;
use App\Services\PaymentPlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Factory Method: las tres cadencias de cobro.
 *
 * Cada generador decide cuantos cortes produce y cuando vence cada uno. El
 * servicio no contiene ningun calculo de fecha propio.
 */
class PaymentPlanGeneratorTest extends TestCase
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

    private function rental(array $attributes = []): Rental
    {
        return Rental::factory()->create($attributes);
    }

    #[Test]
    public function el_resolutor_devuelve_el_generador_de_cada_cadencia(): void
    {
        $rental = $this->rental();
        $resolver = app(PaymentPlanGeneratorResolver::class);

        $this->assertInstanceOf(MonthlyPlanGenerator::class, $resolver->resolve($rental, BillingCadence::MENSUAL));
        $this->assertInstanceOf(QuincenalPlanGenerator::class, $resolver->resolve($rental, BillingCadence::QUINCENAL));
        $this->assertInstanceOf(AdvancePlanGenerator::class, $resolver->resolve($rental, BillingCadence::ANTICIPO));
    }

    #[Test]
    public function la_cadencia_mensual_crea_una_factura_por_mes(): void
    {
        $rental = $this->rental([
            'start_date' => '2026-01-01',
            'end_date' => '2026-04-30',
            'total_months' => 4,
            'monthly_amount' => 1_000_000,
            'billing_cadence' => BillingCadence::MENSUAL,
        ]);

        app(PaymentPlanService::class)->generateFor($rental);

        $invoices = Invoice::where('rental_id', $rental->id)->orderBy('period')->get();

        $this->assertCount(4, $invoices);
        $this->assertSame(['2026-02', '2026-03', '2026-04', '2026-05'], $invoices->pluck('period')->all());
        $this->assertSame([1_000_000.0, 1_000_000.0, 1_000_000.0, 1_000_000.0], $invoices->pluck('amount')->all());
    }

    #[Test]
    public function la_cadencia_quincenal_crea_dos_facturas_por_mes(): void
    {
        $rental = $this->rental([
            'start_date' => '2026-01-01',
            'end_date' => '2026-03-31',
            'total_months' => 2,
            'monthly_amount' => 1_000_000,
            'billing_cadence' => BillingCadence::QUINCENAL,
        ]);

        app(PaymentPlanService::class)->generateFor($rental);

        $invoices = Invoice::where('rental_id', $rental->id)->orderBy('issued_at')->get();

        $this->assertCount(4, $invoices);
        $this->assertSame([500_000.0, 500_000.0, 500_000.0, 500_000.0], $invoices->pluck('amount')->all());

        // Dos cortes por mes: dia 1 y dia 15.
        $this->assertSame(
            ['2026-02-01', '2026-02-15', '2026-03-01', '2026-03-15'],
            $invoices->map(fn (Invoice $invoice): string => $invoice->issued_at->toDateString())->all(),
        );
    }

    #[Test]
    public function la_cadencia_de_anticipo_crea_una_sola_factura_por_todo_el_contrato(): void
    {
        $rental = $this->rental([
            'start_date' => '2026-01-01',
            'end_date' => '2026-06-30',
            'total_months' => 6,
            'monthly_amount' => 1_000_000,
            'billing_cadence' => BillingCadence::ANTICIPO,
        ]);

        app(PaymentPlanService::class)->generateFor($rental);

        $invoices = Invoice::where('rental_id', $rental->id)->get();

        $this->assertCount(1, $invoices);
        $this->assertSame(6_000_000.0, (float) $invoices->first()->amount);
        $this->assertTrue($invoices->first()->issued_at->isSameDay('2026-01-01'));
    }

    #[Test]
    public function la_cadencia_mensual_maneja_meses_de_distinta_duracion(): void
    {
        // Febrero de 2026 es corto y marzo es largo. La fecha del corte no debe
        // desplazarse por eso: el 31 de marzo sigue siendo un dia valido.
        $rental = $this->rental([
            'start_date' => '2026-01-31',
            'end_date' => '2026-04-30',
            'total_months' => 3,
            'monthly_amount' => 800_000,
            'billing_cadence' => BillingCadence::MENSUAL,
        ]);

        app(PaymentPlanService::class)->generateFor($rental);

        $periods = Invoice::where('rental_id', $rental->id)
            ->orderBy('id')
            ->pluck('period')
            ->all();

        $this->assertSame(['2026-02', '2026-03', '2026-04'], $periods);
    }

    #[Test]
    public function el_plan_respeta_la_cadencia_guardada_en_el_alquiler(): void
    {
        $rental = $this->rental([
            'start_date' => '2026-01-01',
            'end_date' => '2026-02-28',
            'total_months' => 1,
            'monthly_amount' => 500_000,
            'billing_cadence' => BillingCadence::QUINCENAL,
        ]);

        // Sin pasar cadencia explicita, el servicio usa la del alquiler.
        app(PaymentPlanService::class)->generateFor($rental);

        $this->assertSame(2, Invoice::where('rental_id', $rental->id)->count());
    }

    #[Test]
    public function las_facturas_del_plan_nacen_sin_pagos(): void
    {
        $rental = $this->rental([
            'start_date' => '2026-01-01',
            'end_date' => '2026-02-28',
            'total_months' => 1,
            'monthly_amount' => 500_000,
            'billing_cadence' => BillingCadence::ANTICIPO,
        ]);

        app(PaymentPlanService::class)->generateFor($rental);

        $invoice = Invoice::where('rental_id', $rental->id)->firstOrFail();

        // El plan emite deuda, nunca abonos: la factura nace sin pagos y con
        // saldo. Que este emitida o vencida depende de la fecha de emision, no
        // de la cadencia.
        $this->assertFalse($invoice->isPaid());
        $this->assertNull($invoice->paid_at);
        $this->assertGreaterThan(0, $invoice->balance());
        $this->assertSame(0, $invoice->payments()->count());
    }
}
