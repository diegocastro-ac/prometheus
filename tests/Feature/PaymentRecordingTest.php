<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Rental;
use App\Models\User;
use App\Services\PaymentRecordingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Este servicio es el unico lugar donde nace un pago, asi que las reglas de
 * "cuando puede abonarse y que pasa con la factura" se fijan aqui.
 */
class PaymentRecordingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Rental $rental;

    private Invoice $invoice;

    private PaymentRecordingService $payments;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->rental = Rental::factory()->create(['user_id' => $this->user->id]);

        $this->invoice = Invoice::factory()->create([
            'rental_id' => $this->rental->id,
            'user_id' => $this->user->id,
            'amount' => 1_000_000,
            'status' => InvoiceStatus::EMITIDA,
            'due_at' => today()->addDays(5)->toDateString(),
        ]);

        $this->payments = app(PaymentRecordingService::class);
    }

    #[Test]
    public function registra_el_abono_y_lo_asigna_a_su_factura_y_alquiler(): void
    {
        $result = $this->payments->record($this->invoice, 300_000, 'transferencia', 'REF-1');

        $this->assertInstanceOf(Payment::class, $result['payment']);
        $this->assertSame($this->invoice->id, $result['payment']->invoice_id);
        $this->assertSame($this->rental->id, $result['payment']->rental_id);
        $this->assertSame($this->user->id, $result['payment']->user_id);
        $this->assertSame('transferencia', $result['payment']->method);
        $this->assertSame('REF-1', $result['payment']->reference);
        $this->assertFalse($result['just_settled']);
    }

    #[Test]
    public function un_abono_parcial_deja_saldo_y_la_factura_emitida(): void
    {
        $result = $this->payments->record($this->invoice, 300_000);

        $this->assertSame(700_000.0, $result['invoice']->balance());
        $this->assertSame(InvoiceStatus::EMITIDA, $result['invoice']->status);
        $this->assertFalse($result['just_settled']);
    }

    #[Test]
    public function cubrir_el_total_informa_que_la_factura_quedo_cerrada(): void
    {
        $result = $this->payments->record($this->invoice, 1_000_000);

        $this->assertTrue($result['just_settled']);
        $this->assertSame(InvoiceStatus::PAGADA, $result['invoice']->status);
        $this->assertSame(0.0, $result['invoice']->balance());
        $this->assertNotNull($result['invoice']->paid_at);
    }

    #[Test]
    public function dos_abonos_consecutivos_cobran_todo_y_lo_registran(): void
    {
        $this->payments->record($this->invoice, 400_000);
        $result = $this->payments->record($this->invoice, 600_000);

        $this->assertTrue($result['just_settled']);
        $this->assertSame(2, Payment::where('invoice_id', $this->invoice->id)->count());
    }

    #[Test]
    public function un_abono_a_favor_paga_mas_de_lo_que_se_debia_y_tambien_cierra(): void
    {
        // Se acepta a proposito: en la vida real un abono puede traicionar de
        // mas. La regla no es bloquearlo, es que la factura quede pagada.
        $result = $this->payments->record($this->invoice, 1_200_000);

        $this->assertTrue($result['just_settled']);
        $this->assertSame(InvoiceStatus::PAGADA, $result['invoice']->status);
    }

    #[Test]
    public function sin_metodo_el_abono_queda_como_efectivo(): void
    {
        $result = $this->payments->record($this->invoice, 100_000);

        $this->assertSame('efectivo', $result['payment']->method);
    }

    #[Test]
    public function no_se_paga_una_factura_ya_pagada(): void
    {
        $this->payments->record($this->invoice, 1_000_000);

        $this->expectException(\DomainException::class);

        $this->payments->record($this->invoice->fresh(), 100_000);
    }

    #[Test]
    public function no_se_paga_una_factura_anulada(): void
    {
        $invoice = Invoice::factory()->create([
            'rental_id' => $this->rental->id,
            'user_id' => $this->user->id,
            'status' => InvoiceStatus::ANULADA,
        ]);

        $this->expectException(\DomainException::class);

        $this->payments->record($invoice, 100_000);
    }

    #[Test]
    public function no_se_acepta_un_abono_de_cero_o_negativo(): void
    {
        $this->expectException(\DomainException::class);

        $this->payments->record($this->invoice, 0);
    }

    #[Test]
    public function un_rechazo_no_deja_abono_registrado(): void
    {
        try {
            $this->payments->record($this->invoice, -50_000);
        } catch (\DomainException) {
            // esperado
        }

        $this->assertSame(0, Payment::where('invoice_id', $this->invoice->id)->count());
        $this->assertSame(1_000_000.0, $this->invoice->fresh()->balance());
    }

    #[Test]
    public function un_abono_a_una_factura_vencida_la_deja_pagada_y_no_vencida(): void
    {
        $invoice = Invoice::factory()->create([
            'rental_id' => $this->rental->id,
            'user_id' => $this->user->id,
            'amount' => 500_000,
            'due_at' => today()->subDays(15)->toDateString(),
        ]);

        $invoice->refreshStatus();
        $this->assertSame(InvoiceStatus::VENCIDA, $invoice->status);

        $result = $this->payments->record($invoice, 500_000);

        $this->assertSame(InvoiceStatus::PAGADA, $result['invoice']->status);
        $this->assertFalse($result['invoice']->isOverdue());
    }
}
