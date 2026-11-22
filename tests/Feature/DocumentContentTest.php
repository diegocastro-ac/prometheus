<?php

namespace Tests\Feature;

use App\Documents\Factories\TextDocumentFactory;
use App\Documents\Money;
use App\Documents\MonthlyStatement;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\RentAdjustment;
use PHPUnit\Framework\Attributes\Test;

/**
 * Contenido de los cuatro documentos, en texto plano.
 *
 * Se prueba el cuerpo sin formato porque el dato es lo que importa: que el PDF
 * se vea bien es matter de maquetado, y que el comprobante declare el saldo
 * correcto o que la carta cite el IPC correcto es matter de verdad.
 */
class DocumentContentTest extends DocumentTestCase
{
    private function factory(): TextDocumentFactory
    {
        return app(TextDocumentFactory::class);
    }

    #[Test]
    public function el_comprobante_lista_los_abonos_y_cierra_en_saldo_cero(): void
    {
        $invoice = $this->paidInvoice(['amount' => 800_000, 'concept' => 'rent']);

        $body = $this->factory()->paymentReceipt($invoice)->body();

        $this->assertSame(__('document.payment_receipt'), $body->title);
        $this->assertSame($invoice->number, $body->fields[__('document.invoice_number')]);
        $this->assertSame(Money::exact(800_000), $body->fields[__('document.invoice_amount')]);
        $this->assertSame(Money::exact(800_000), $body->fields[__('document.paid_amount')]);
        $this->assertSame(Money::exact(0), $body->fields[__('document.balance')]);

        // Una tabla de abonos con las cuatro columnas que el comprobante emite.
        $this->assertSame([
            __('document.date'),
            __('document.amount_paid'),
            __('document.method'),
            __('document.reference'),
        ], $body->headers);
        $this->assertCount(1, $body->rows);
    }

    #[Test]
    public function el_comprobante_ordena_los_abonos_por_fecha(): void
    {
        $invoice = $this->issuedInvoice(['amount' => 900_000]);

        // Se registran en orden inverso a propósito: el documento los ordena.
        $invoice->payments()->create([
            'user_id' => $this->user->id,
            'rental_id' => $invoice->rental_id,
            'date' => '2026-01-20',
            'amount' => 300_000,
            'method' => 'transferencia',
        ]);
        $invoice->payments()->create([
            'user_id' => $this->user->id,
            'rental_id' => $invoice->rental_id,
            'date' => '2026-01-05',
            'amount' => 600_000,
            'method' => 'efectivo',
        ]);
        $invoice->refreshStatus();

        $this->assertSame(InvoiceStatus::PAGADA, $invoice->status);

        $rows = $this->factory()->paymentReceipt($invoice->refresh())->body()->rows;

        $this->assertCount(2, $rows);
        $this->assertStringContainsString('600.000', $rows[0][1]);
        $this->assertStringContainsString('300.000', $rows[1][1]);
    }

    #[Test]
    public function el_comprobante_traduce_la_forma_de_pago(): void
    {
        $invoice = $this->paidInvoice(['amount' => 100_000]);

        // El metodo se guarda como clave: en el documento debe salir la etiqueta.
        $invoice->payments()->update(['method' => 'consignacion']);

        $rows = $this->factory()->paymentReceipt($invoice->refresh())->body()->rows;

        $this->assertSame(__('invoice.methods.consignacion'), $rows[0][2]);
    }

    #[Test]
    public function el_comprobante_avisa_que_no_sustituye_la_factura_electronica(): void
    {
        $body = $this->factory()->paymentReceipt($this->paidInvoice())->body();

        $this->assertNotEmpty($body->notes);
        $this->assertStringContainsString(
            'no sustituye la factura',
            mb_strtolower(implode(' ', $body->notes)),
        );
    }

    #[Test]
    public function la_factura_muestra_el_saldo_cuando_el_pago_es_parcial(): void
    {
        $invoice = $this->issuedInvoice(['amount' => 1_000_000]);

        $invoice->payments()->create([
            'user_id' => $this->user->id,
            'rental_id' => $invoice->rental_id,
            'date' => now()->toDateString(),
            'amount' => 400_000,
            'method' => 'efectivo',
        ]);

        $body = $this->factory()->rentInvoice($invoice->refresh())->body();

        $this->assertSame(__('document.invoice'), $body->title);
        $this->assertSame(Money::exact(1_000_000), $body->fields[__('document.total_due')]);
        $this->assertSame(__('invoice.statuses.emitida'), $body->fields[__('document.status')]);
        $this->assertCount(1, $body->rows, 'El abono parcial debe listarse.');
    }

    #[Test]
    public function la_factura_lleva_el_aviso_de_que_no_es_factura_dian(): void
    {
        $body = $this->factory()->rentInvoice($this->issuedInvoice())->body();

        $this->assertContains(__('invoice.descriptions.legal'), $body->notes);
    }

    #[Test]
    public function el_estado_de_cuenta_cierra_con_el_saldo_real_del_periodo(): void
    {
        $invoices = $this->invoiceForPeriod('2026-01');

        $statement = $this->factory()->monthlyStatement('2026-01', $invoices);

        $billed = array_sum(array_map(fn (Invoice $invoice): float => $invoice->amount, $invoices));

        $this->assertInstanceOf(MonthlyStatement::class, $statement);
        $this->assertFalse($statement->isEmpty());
        $this->assertSame(__('document.monthly_statement'), $statement->body()->title);
        $this->assertSame('2026-01', $statement->body()->fields[__('document.period')]);
        $this->assertSame((string) count($invoices), $statement->body()->fields[__('document.invoices')]);
        $this->assertSame(Money::exact($billed), $statement->body()->fields[__('document.total_billed')]);

        // Las facturas del periodo estan sin pagar, asi que el saldo es igual al
        // facturado. Un saldo en cero aqui significaria que el estado de cuenta
        // esta leyendo algo que no es.
        $this->assertSame(
            Money::exact($billed),
            $statement->body()->fields[__('document.outstanding_balance')],
        );
        $this->assertSame(Money::exact(0), $statement->body()->fields[__('document.total_paid')]);
    }

    #[Test]
    public function el_estado_de_cuenta_refleja_los_abonos_parciales(): void
    {
        $invoices = $this->invoiceForPeriod('2026-02');

        $invoices[0]->payments()->create([
            'user_id' => $this->user->id,
            'rental_id' => $invoices[0]->rental_id,
            'date' => now()->toDateString(),
            'amount' => 100_000,
            'method' => 'efectivo',
        ]);

        $statement = $this->factory()->monthlyStatement('2026-02', $invoices);

        $this->assertSame(Money::exact(100_000), $statement->body()->fields[__('document.total_paid')]);

        // El saldo se calcula sobre todas las facturas del periodo, no solo
        // sobre la que se abono.
        $billed = array_sum(array_map(fn (Invoice $invoice): float => $invoice->amount, $invoices));

        $this->assertSame(
            Money::exact($billed - 100_000),
            $statement->body()->fields[__('document.outstanding_balance')],
        );
    }

    #[Test]
    public function el_estado_de_cuenta_solo_toma_las_facturas_del_periodo(): void
    {
        $invoices = $this->invoiceForPeriod('2026-03');

        // Una factura de otro mes, del mismo alquiler: no debe aparecer.
        Invoice::factory()->create([
            'user_id' => $this->user->id,
            'rental_id' => $invoices[0]->rental_id,
            'period' => '2026-04',
        ]);

        $statement = $this->factory()->monthlyStatement('2026-03', $invoices);

        $this->assertCount(count($invoices), $statement->invoices());
        $this->assertSame('2026-03', $statement->period());
    }

    #[Test]
    public function un_periodo_sin_facturas_produce_un_estado_de_cuenta_en_blanco(): void
    {
        $invoices = $this->invoiceForPeriod('2026-05');

        $statement = $this->factory()->monthlyStatement('2026-06', []);

        // No debe fallar ni inventar cifras: un estado de cuenta vacio es la
        // respuesta correcta a "no me deben nada".
        $this->assertTrue($statement->isEmpty());
        $this->assertSame([], $statement->invoices());
        $this->assertSame(Money::exact(0), $statement->body()->fields[__('document.outstanding_balance')]);
        $this->assertSame([], $statement->body()->rows);
    }

    #[Test]
    public function la_carta_de_reajuste_cita_el_ipc_y_su_fuente(): void
    {
        $adjustment = RentAdjustment::factory()->withinCap()->create([
            'previous_rent' => 1_000_000,
            'new_rent' => 1_092_800,
            'ipc_year' => 2025,
            'ipc_percentage' => 9.28,
            'legal_cap_rent' => 1_092_800,
        ]);

        $body = $this->factory()->adjustmentLetter($adjustment)->body();

        $this->assertSame(__('document.adjustment_letter'), $body->title);
        $this->assertStringContainsString('9,28', $body->fields[__('document.ipc_applied')]);
        $this->assertStringContainsString('2025', $body->fields[__('document.ipc_applied')]);
        $this->assertSame(Money::pesos(1_000_000), $body->fields[__('document.previous_rent')]);
        $this->assertSame(Money::pesos(1_092_800), $body->fields[__('document.new_rent_amount')]);

        // La fuente del dato tiene que quedar en el documento: es lo que
        // sustenta el cobro delante del arrendatario.
        $notes = implode(' ', $body->notes);
        $this->assertStringContainsString($adjustment->ipcRate->source_url, $notes);
    }

    #[Test]
    public function la_carta_dentro_del_tope_no_lleva_aviso(): void
    {
        $adjustment = RentAdjustment::factory()->withinCap()->create();

        $body = $this->factory()->adjustmentLetter($adjustment)->body();

        $this->assertSame([], $body->warnings);
    }

    #[Test]
    public function la_carta_que_excede_el_tope_avisa_y_dice_canon_vigente(): void
    {
        $adjustment = RentAdjustment::factory()->exceedingCap(200_000)->create([
            'previous_rent' => 1_000_000,
            'legal_cap_rent' => 1_092_800,
            'new_rent' => 1_292_800,
        ]);

        $body = $this->factory()->adjustmentLetter($adjustment)->body();

        // El aviso no es decorativo: debe decir cuanto se pasa y cual es el
        // canon que queda vigente si no hay acuerdo escrito.
        $this->assertCount(1, $body->warnings);

        $warning = $body->warnings[0];
        $this->assertStringContainsString('200.000', $warning);
        $this->assertStringContainsString(Money::pesos(1_092_800), $warning);
        $this->assertStringContainsString('Ley 820', $warning);
    }

    #[Test]
    public function la_carta_indica_si_ya_fue_comunicada(): void
    {
        $pending = RentAdjustment::factory()->withinCap()->create();
        $notified = RentAdjustment::factory()->withinCap()->create([
            'notified_on' => '2026-01-15',
        ]);

        $this->assertStringNotContainsString(
            'Comunicada el',
            implode(' ', $this->factory()->adjustmentLetter($pending)->body()->notes),
        );

        $this->assertStringContainsString(
            'Comunicada el',
            implode(' ', $this->factory()->adjustmentLetter($notified)->body()->notes),
        );
    }
}
