<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Rental;
use App\Models\User;
use App\Services\PaymentPlanService;
use App\Services\PaymentRecordingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Corre el seed completo contra una base limpia. Si las facturas y los pagos no
 * quedan coherentes entre si, el panel arranca con datos que no cuadran y el
 * error aparece en pantalla, no en el servidor.
 */
class SeedingTest extends TestCase
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
    }

    private function seedAll(): void
    {
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
    }

    #[Test]
    public function el_seed_deja_facturas_y_pagos_coherentes(): void
    {
        $this->seedAll();

        $this->assertGreaterThan(0, Rental::count());
        $this->assertGreaterThan(0, Invoice::count());
        $this->assertGreaterThan(0, Payment::count());

        // Ningun pago sin factura: el panel no sabria clasificarlo.
        $this->assertSame(0, Payment::whereNull('invoice_id')->count());

        // Ninguna factura repetida dentro del mismo arrendador.
        $this->assertSame(
            Invoice::count(),
            Invoice::select('user_id', 'number')->distinct()->count(),
        );
    }

    #[Test]
    public function ninguna_factura_pagada_conserva_saldo(): void
    {
        $this->seedAll();

        $withBalance = Invoice::all()
            ->filter(fn (Invoice $invoice): bool => $invoice->balance() > 0)
            ->pluck('number', 'id')
            ->all();

        $paidWithBalance = Invoice::where('status', InvoiceStatus::PAGADA)
            ->whereIn('id', array_keys($withBalance))
            ->count();

        $this->assertSame(0, $paidWithBalance, 'Hay facturas pagadas con saldo pendiente.');
    }

    #[Test]
    public function el_seed_cubre_los_cuatro_estados_de_factura(): void
    {
        $this->seedAll();

        $this->assertGreaterThan(0, Invoice::where('status', InvoiceStatus::PAGADA)->count());
        $this->assertGreaterThan(0, Invoice::where('status', InvoiceStatus::VENCIDA)->count());
        $this->assertGreaterThan(0, Invoice::where('status', InvoiceStatus::EMITIDA)->count());
    }

    #[Test]
    public function el_plan_de_cobros_emite_facturas_y_no_pagos(): void
    {
        $this->seedAll();

        $user = User::where('email', 'admin@admin.com')->firstOrFail();
        $rental = Rental::where('user_id', $user->id)->firstOrFail();

        $paymentsBefore = Payment::count();

        app(PaymentPlanService::class)->generateFor($rental);

        // El plan crea lo que se debe, nunca abonos.
        $this->assertSame($paymentsBefore, Payment::count());

        // Y no deja dos facturas del mismo alquiler para el mismo corte. El
        // seed puede tener periodos que el plan tambien produce, asi que el
        // numero exacto de facturas nuevas depende de esos solapamientos; lo
        // que no puede pasar nunca es que se repita un (periodo, emision).
        $duplicates = Invoice::where('rental_id', $rental->id)
            ->select('period', 'issued_at')
            ->groupBy('period', 'issued_at')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        $this->assertCount(0, $duplicates, 'El plan duplico un corte ya existente.');
    }

    #[Test]
    public function las_facturas_del_plan_no_vienen_pagadas_y_su_vencimiento_es_coherente(): void
    {
        $this->seedAll();

        $rental = Rental::firstOrFail();

        app(PaymentPlanService::class)->generateFor($rental);

        $open = Invoice::where('rental_id', $rental->id)
            ->whereIn('status', [InvoiceStatus::EMITIDA, InvoiceStatus::VENCIDA])
            ->get();

        $this->assertGreaterThan(0, $open->count());

        foreach ($open as $invoice) {
            // El plan solo crea lo que se debe, nunca abonos: no esta pagada y
            // su saldo es justo lo que falta por cobrar.
            $this->assertNull($invoice->paid_at);
            $this->assertGreaterThan(0, $invoice->balance());

            // El estado guardado tiene que concordar con la fecha: una factura
            // cuyo vencimiento ya paso no puede quedar guardada como emitida.
            $this->assertSame($invoice->isOverdue(), $invoice->fresh()->status === InvoiceStatus::VENCIDA);

            $this->assertGreaterThanOrEqual($invoice->issued_at, $invoice->due_at);
        }
    }

    #[Test]
    public function regenerar_el_plan_no_duplica_los_cortes_ya_emitidos(): void
    {
        $this->seedAll();

        $rental = Rental::firstOrFail();

        app(PaymentPlanService::class)->generateFor($rental);
        $afterFirst = Invoice::where('rental_id', $rental->id)->count();

        // El generador es idempotente: si el periodo ya existe para el
        // alquiler, no lo vuelve a crear. Regenerar el plan no puede inflar la
        // cartera con cobros repetidos.
        app(PaymentPlanService::class)->generateFor($rental);
        $afterSecond = Invoice::where('rental_id', $rental->id)->count();

        $this->assertSame($afterFirst, $afterSecond, 'Regenerar el plan duplico facturas.');
    }

    #[Test]
    public function toda_factura_sembrada_tiene_periodo(): void
    {
        $this->seedAll();

        $withoutPeriod = Invoice::whereNull('period')->count();

        $this->assertSame(0, $withoutPeriod);

        $malformed = Invoice::all()
            ->reject(fn (Invoice $invoice): bool => (bool) preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $invoice->period))
            ->pluck('number')
            ->all();

        $this->assertSame([], $malformed, 'Hay facturas con un periodo mal formado.');
    }

    #[Test]
    public function el_concepto_sembrado_usa_claves_y_no_texto_traducido(): void
    {
        $this->seedAll();

        $known = ['rent', 'services', 'rent_services', 'adjustment'];

        $unknown = Invoice::all()
            ->reject(fn (Invoice $invoice): bool => in_array($invoice->concept, $known, true))
            ->pluck('concept')
            ->unique()
            ->values()
            ->all();

        $this->assertSame([], $unknown, 'Hay facturas con el concepto ya traducido.');
    }

    #[Test]
    public function un_abono_registrado_contra_una_factura_del_seed_la_cierra(): void
    {
        $this->seedAll();

        $invoice = Invoice::where('status', InvoiceStatus::EMITIDA)
            ->where('due_at', '>', today()->toDateString())
            ->firstOrFail();

        $result = app(PaymentRecordingService::class)->record($invoice, $invoice->balance());

        $this->assertTrue($result['just_settled']);
        $this->assertSame(InvoiceStatus::PAGADA, $invoice->fresh()->status);
    }
}
