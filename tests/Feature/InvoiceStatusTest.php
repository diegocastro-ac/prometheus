<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Rental;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * El estado de la factura no se escribe a mano: se deduce de lo que se ha
 * abonado contra ella. Estos tests fijan esa unica regla.
 *
 * Reemplazan a los que fijaban la precedencia de los cuatro indicadores de
 * servicio, que ya no existen en el modelo.
 */
class InvoiceStatusTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Rental $rental;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->rental = Rental::factory()->create(['user_id' => $this->user->id]);
    }

    private function invoice(array $attributes = []): Invoice
    {
        return Invoice::factory()->create(array_merge([
            'rental_id' => $this->rental->id,
            'user_id' => $this->user->id,
            'amount' => 1_000_000,
            'status' => InvoiceStatus::EMITIDA,
        ], $attributes));
    }

    private function pay(Invoice $invoice, float $amount, ?string $date = null): Payment
    {
        return Payment::factory()->create([
            'invoice_id' => $invoice->id,
            'rental_id' => $invoice->rental_id,
            'user_id' => $invoice->user_id,
            'amount' => $amount,
            'date' => $date ?? today()->toDateString(),
        ]);
    }

    #[Test]
    public function una_factura_sin_pagos_y_sin_vencer_queda_emitida(): void
    {
        $invoice = $this->invoice([
            'due_at' => today()->addDays(5)->toDateString(),
        ]);

        $invoice->refreshStatus();

        $this->assertSame(InvoiceStatus::EMITIDA, $invoice->status);
        $this->assertFalse($invoice->isOverdue());
        $this->assertSame(1_000_000.0, $invoice->balance());
    }

    #[Test]
    public function un_abono_parcial_deja_el_saldo_y_no_cambia_el_estado_si_no_vencio(): void
    {
        $invoice = $this->invoice([
            'due_at' => today()->addDays(5)->toDateString(),
        ]);

        $this->pay($invoice, 400_000);
        $invoice->refreshStatus();

        $this->assertSame(InvoiceStatus::EMITIDA, $invoice->status);
        $this->assertTrue($invoice->hasPartialPayment());
        $this->assertSame(600_000.0, $invoice->balance());
    }

    #[Test]
    public function cubrir_el_total_cambia_la_factura_a_pagada(): void
    {
        $invoice = $this->invoice([
            'due_at' => today()->addDays(5)->toDateString(),
        ]);

        $this->pay($invoice, 400_000);
        $this->pay($invoice, 600_000);

        $invoice->refreshStatus();

        $this->assertSame(InvoiceStatus::PAGADA, $invoice->status);
        $this->assertTrue($invoice->isPaid());
        $this->assertSame(0.0, $invoice->balance());
        $this->assertNotNull($invoice->paid_at);
    }

    #[Test]
    public function una_factura_vencida_que_no_se_paga_queda_vencida(): void
    {
        $invoice = $this->invoice([
            'due_at' => today()->subDay()->toDateString(),
        ]);

        $invoice->refreshStatus();

        $this->assertSame(InvoiceStatus::VENCIDA, $invoice->status);
        $this->assertTrue($invoice->isOverdue());
    }

    #[Test]
    public function una_factura_vencida_con_abono_parcial_sigue_vencida(): void
    {
        $invoice = $this->invoice([
            'due_at' => today()->subDays(10)->toDateString(),
        ]);

        $this->pay($invoice, 300_000);
        $invoice->refreshStatus();

        $this->assertSame(InvoiceStatus::VENCIDA, $invoice->status);
        $this->assertTrue($invoice->isOverdue());
    }

    #[Test]
    public function una_factura_que_venece_hoy_no_esta_vencida(): void
    {
        // La fecha se guarda a las 00:00, asi que comparar contra la hora
        // actual marcaria como vencida una factura que aun no vence.
        $invoice = $this->invoice([
            'due_at' => today()->toDateString(),
        ]);

        $invoice->refreshStatus();

        $this->assertSame(InvoiceStatus::EMITIDA, $invoice->status);
        $this->assertFalse($invoice->isOverdue());
    }

    #[Test]
    public function pagar_una_factura_vencida_la_pasa_a_pagada(): void
    {
        $invoice = $this->invoice([
            'due_at' => today()->subDays(10)->toDateString(),
        ]);

        $invoice->refreshStatus();
        $this->assertSame(InvoiceStatus::VENCIDA, $invoice->status);

        $this->pay($invoice, 1_000_000);
        $invoice->refreshStatus();

        $this->assertSame(InvoiceStatus::PAGADA, $invoice->status);
        $this->assertFalse($invoice->isOverdue());
    }

    #[Test]
    public function una_factura_anulada_no_cambia_de_estado_sola(): void
    {
        $invoice = $this->invoice([
            'due_at' => today()->subDays(10)->toDateString(),
            'status' => InvoiceStatus::ANULADA,
        ]);

        $this->pay($invoice, 1_000_000);
        $invoice->refreshStatus();

        $this->assertSame(InvoiceStatus::ANULADA, $invoice->status);
    }

    #[Test]
    public function una_factura_anulada_no_admite_pagos(): void
    {
        $invoice = $this->invoice(['status' => InvoiceStatus::ANULADA]);

        $this->assertFalse($invoice->canReceivePayments());
    }

    #[Test]
    public function una_factura_pagada_no_admite_mas_pagos(): void
    {
        $invoice = $this->invoice(['status' => InvoiceStatus::PAGADA]);

        $this->assertFalse($invoice->canReceivePayments());
    }

    #[Test]
    public function el_pago_hereda_el_estado_de_su_factura(): void
    {
        $paid = $this->invoice(['due_at' => today()->addDay()->toDateString()]);
        $this->pay($paid, 1_000_000);
        $paid->refreshStatus();

        $overdue = $this->invoice(['due_at' => today()->subDay()->toDateString()]);
        $overdue->refreshStatus();
        $this->pay($overdue, 100_000);

        $partial = $this->invoice(['due_at' => today()->addDays(5)->toDateString()]);
        $this->pay($partial, 100_000);

        $orphan = Payment::factory()->create([
            'invoice_id' => null,
            'rental_id' => $this->rental->id,
            'user_id' => $this->user->id,
        ]);

        $this->assertSame(\App\Enums\PaymentStatus::PAID, $paid->payments()->first()->status());
        $this->assertSame(\App\Enums\PaymentStatus::OVERDUE, $overdue->payments()->first()->status());
        $this->assertSame(\App\Enums\PaymentStatus::PARTIAL, $partial->payments()->first()->status());
        $this->assertSame(\App\Enums\PaymentStatus::PENDING, $orphan->status());
    }

    #[Test]
    public function un_pago_no_puede_dejar_la_factura_con_paid_at_si_queda_saldo(): void
    {
        $invoice = $this->invoice(['due_at' => today()->addDays(5)->toDateString()]);

        $this->pay($invoice, 1_000_000);
        $invoice->refreshStatus();
        $this->assertNotNull($invoice->paid_at);

        // Un abono a favor dejaria saldo, lo que reabre la factura.
        $invoice->payments()->first()->update(['amount' => 400_000]);
        $invoice->refreshStatus();

        $this->assertSame(InvoiceStatus::EMITIDA, $invoice->fresh()->status);
        $this->assertNull($invoice->fresh()->paid_at);
    }
}
