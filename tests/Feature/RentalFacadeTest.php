<?php

namespace Tests\Feature;

use App\Documents\Contracts\Document;
use App\Enums\BillingCadence;
use App\Enums\InvoiceStatus;
use App\Enums\NotificationMethod;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\RentAdjustment;
use App\Models\Rental;
use App\Services\RentalFacade;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * La fachada es una puerta, no una segunda implementacion.
 *
 * Por eso lo que se prueba no es cada regla de negocio -cada servicio ya tiene
 * sus pruebas-, sino que el consumidor pueda pedir cobros, reajustes y
 * documentos sin resolver dependencias por su cuenta y que la respuesta siga
 * viniendo del mismo lugar.
 */
class RentalFacadeTest extends DocumentTestCase
{
    private RentalFacade $facade;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('app_settings')->insert([
            'id' => 1,
            'business_name' => 'Inmobiliaria Ejemplo S.A.S.',
            'tax_id' => '900123456-7',
            'currency' => 'COP',
            'invoice_due_days' => 5,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->facade = app(RentalFacade::class);
    }

    #[Test]
    public function la_fachada_es_un_singleton(): void
    {
        $this->assertSame($this->facade, app(RentalFacade::class));
    }

    #[Test]
    public function genera_el_plan_de_cobros(): void
    {
        $rental = Rental::factory()->create([
            'user_id' => $this->user->id,
            'start_date' => '2026-01-01',
            'end_date' => '2026-02-28',
            'total_months' => 2,
            'monthly_amount' => 1_000_000,
            'billing_cadence' => BillingCadence::MENSUAL,
        ]);

        $this->facade->generatePaymentPlan($rental);

        $this->assertSame(
            2,
            Invoice::where('rental_id', $rental->id)->count(),
        );
    }

    #[Test]
    public function registra_un_abono_con_las_mismas_reglas_del_servicio(): void
    {
        $invoice = Invoice::factory()->create([
            'rental_id' => Rental::factory()->create(['user_id' => $this->user->id])->id,
            'user_id' => $this->user->id,
            'amount' => 1_000_000,
            'status' => InvoiceStatus::EMITIDA,
            'due_at' => today()->addDays(5)->toDateString(),
        ]);

        $result = $this->facade->recordPayment($invoice, 1_000_000, 'transferencia', 'REF-1');

        $this->assertInstanceOf(Payment::class, $result['payment']);
        $this->assertTrue($result['just_settled']);
        $this->assertSame(InvoiceStatus::PAGADA, $result['invoice']->status);
    }

    #[Test]
    public function marca_un_reajuste_como_notificado(): void
    {
        $rental = Rental::factory()->create(['user_id' => $this->user->id]);
        $adjustment = RentAdjustment::factory()->create([
            'rental_id' => $rental->id,
            'user_id' => $this->user->id,
            'notified_on' => null,
            'notification_method' => null,
        ]);

        $result = $this->facade->markAdjustmentNotified(
            $adjustment,
            Carbon::parse('2026-03-10'),
            NotificationMethod::Postal,
        );

        $this->assertSame('2026-03-10', $result->notified_on->toDateString());
        $this->assertSame(NotificationMethod::Postal, $result->notification_method);
    }

    #[Test]
    public function expone_la_cadena_y_los_documentos_sin_cambiar_sus_contratos(): void
    {
        $rental = Rental::factory()->create([
            'user_id' => $this->user->id,
            'monthly_amount' => 1_500_000,
            'anchor_monthly_amount' => 1_500_000,
        ]);

        $this->assertSame(1_500_000.0, $this->facade->currentRentFor($rental));

        $this->assertSame(
            ['text', 'pdf', 'json'],
            array_keys($this->facade->documentFormats()),
        );

        $invoice = $this->paidInvoice([
            'rental_id' => $rental->id,
            'amount' => 500_000,
        ]);

        $receipt = $this->facade->receiptFor($invoice, 'text');

        $this->assertInstanceOf(Document::class, $receipt);
        $this->assertStringContainsString($invoice->number, $receipt->content());
    }
}
