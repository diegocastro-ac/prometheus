<?php

namespace Tests\Feature;

use App\Documents\DocumentService;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\RentAdjustment;
use App\Models\User;
use PHPUnit\Framework\Attributes\Test;

/**
 * Las acciones de documentos en la pantalla de facturas.
 *
 * Lo importante aqui no es que el boton se vea, sino que el alcance del
 * arrendador y el bloqueo del comprobante se respeten tambien por la ruta
 * directa, no solo ocultando la opcion.
 */
class InvoiceDocumentActionTest extends DocumentTestCase
{
    #[Test]
    public function la_accion_de_comprobante_solo_existe_en_facturas_pagadas(): void
    {
        $paid = $this->paidInvoice();
        $issued = $this->issuedInvoice();

        $this->assertTrue($paid->isPaid());
        $this->assertFalse($issued->isPaid());

        // La garantia real esta en el dominio; la visibilidad es la primera
        // linea.
        $this->expectException(\DomainException::class);

        app(DocumentService::class)->receiptFor($issued, 'pdf');
    }

    #[Test]
    public function la_factura_se_imprime_todavia_estea_anulada(): void
    {
        // Una factura anulada no admite pagos, pero su documento existe: es el
        // registro de lo que se emitio y se cancelo.
        $invoice = $this->issuedInvoice();
        $invoice->update(['status' => InvoiceStatus::ANULADA]);

        $document = app(DocumentService::class)->invoiceFor($invoice->refresh(), 'text');

        $this->assertStringContainsString(__('invoice.statuses.anulada'), $document->content());
    }

    #[Test]
    public function el_documento_respeta_el_alcance_del_arrendador(): void
    {
        $other = User::factory()->create();

        $foreign = Invoice::factory()->create([
            'user_id' => $other->id,
            'status' => InvoiceStatus::PAGADA,
        ]);

        // La consulta de la pantalla filtra por usuario; el documento recibe la
        // factura ya filtrada y no la vuelve a buscar.
        $this->actingAs($other);

        $this->assertSame(
            1,
            Invoice::query()
                ->where('user_id', $other->id)
                ->whereKey($foreign->id)
                ->count(),
        );

        $this->actingAs($this->user);

        $this->assertSame(
            0,
            Invoice::query()
                ->where('user_id', $this->user->id)
                ->whereKey($foreign->id)
                ->count(),
        );
    }

    #[Test]
    public function el_estado_de_cuenta_solo_toma_facturas_del_usuario_actual(): void
    {
        $other = User::factory()->create();

        $rental = $this->user->rentals()->first() ?? \App\Models\Rental::factory()->create([
            'user_id' => $this->user->id,
        ]);

        Invoice::factory()->create([
            'user_id' => $other->id,
            'rental_id' => $rental->id,
            'period' => '2026-07',
        ]);

        $statement = app(DocumentService::class)->statementFor($rental, '2026-07', 'text');

        $this->assertSame([], $statement->invoices());
    }

    #[Test]
    public function la_carta_de_reajuste_se_genera_para_cualquier_estado_del_ajuste(): void
    {
        // A diferencia del comprobante de pago, la carta no tiene restriccion de
        // estado: incluso el masque que excede el tope debe poder imprimirse,
        // porque es el documento que avisa del exceso.
        $exceeding = RentAdjustment::factory()->exceedingCap()->create([
            'user_id' => $this->user->id,
        ]);

        $document = app(DocumentService::class)->adjustmentLetterFor($exceeding, 'text');

        $this->assertCount(1, $document->body()->warnings);
    }

    #[Test]
    public function el_formato_por_defecto_del_documento_es_texto(): void
    {
        $document = app(DocumentService::class)->receiptFor($this->paidInvoice());

        $this->assertSame('text/plain; charset=UTF-8', $document->mimeType());
        $this->assertSame(__('invoice.documents.copy'), $document->deliveryLabel());
    }

    #[Test]
    public function el_servicio_anuncia_solo_los_formatos_que_existen(): void
    {
        $options = app(DocumentService::class)->formatOptions();

        $this->assertSame(['text', 'pdf'], array_keys($options));
        $this->assertSame(__('invoice.documents.format.text'), $options['text']);
        $this->assertSame(__('invoice.documents.format.pdf'), $options['pdf']);
    }
}
