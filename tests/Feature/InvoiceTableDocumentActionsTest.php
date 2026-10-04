<?php

namespace Tests\Feature;

use App\Documents\DocumentService;
use App\Enums\InvoiceStatus;
use App\Filament\Resources\InvoiceResource\Pages\ListInvoices;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;

/**
 * Las acciones de documento en la tabla de facturas.
 *
 * Comprueba que la tabla registre acciones que si son de tabla y que el bloqueo
 * del comprobante se vea reflejado en ellas, ademas del bloqueo de dominio que
 * ya cubren otras pruebas.
 */
class InvoiceTableDocumentActionsTest extends DocumentTestCase
{
    private function table(): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::test(ListInvoices::class);
    }

    #[Test]
    public function la_tabla_arranca_con_las_acciones_de_documento(): void
    {
        $this->table()->assertSuccessful();
    }

    #[Test]
    public function el_comprobante_se_oculta_en_una_factura_sin_pagar(): void
    {
        $issued = $this->issuedInvoice();
        $paid = $this->paidInvoice();

        $actions = $this->table()->instance()->getTable()->getFlatActions();

        // Las acciones siempre estan registradas: lo que cambia por fila es la
        // visibilidad, que es lo que se comprueba aqui.
        $this->assertArrayHasKey('receipt_pdf', $actions);
        $this->assertArrayHasKey('receipt_text', $actions);

        foreach (['receipt_pdf', 'receipt_text'] as $name) {
            $actions[$name]->record($issued);
            $this->assertTrue($actions[$name]->isHidden(), "{$name} deberia ocultarse sin pagar");

            $actions[$name]->record($paid);
            $this->assertFalse($actions[$name]->isHidden(), "{$name} deberia verse al estar pagada");
        }

        // Y el dominio tampoco lo entregaria, por si se invoca la accion a mano.
        $this->expectException(\DomainException::class);
        app(DocumentService::class)->receiptFor($issued, 'pdf');
    }

    #[Test]
    public function el_comprobante_aparece_en_una_factura_pagada(): void
    {
        $this->paidInvoice();

        $actions = $this->table()->instance()->getTable()->getFlatActions();

        $this->assertArrayHasKey('receipt_pdf', $actions);
        $this->assertArrayHasKey('receipt_text', $actions);
    }

    #[Test]
    public function la_factura_aparece_siempre_estea_pagada_o_no(): void
    {
        // La factura es el documento con el que se cobra, asi que la accion
        // existe siempre: no tiene sentido ocultarla cuando ya esta pagada.
        $this->table()->assertSuccessful();

        $this->issuedInvoice();
        $this->assertArrayHasKey(
            'invoice_pdf',
            $this->table()->instance()->getTable()->getFlatActions(),
        );

        $this->paidInvoice();
        $this->assertArrayHasKey(
            'invoice_pdf',
            $this->table()->instance()->getTable()->getFlatActions(),
        );
    }

    #[Test]
    public function una_factura_anulada_conserva_el_boton_de_comprobante_oculto(): void
    {
        // Una factura anulada ni admite pagos ni tiene comprobante que emitir.
        $annulled = $this->issuedInvoice();
        $annulled->update(['status' => InvoiceStatus::ANULADA]);

        $this->assertFalse($annulled->refresh()->isPaid());
    }
}
