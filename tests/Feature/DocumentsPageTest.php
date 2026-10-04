<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Filament\Pages\DocumentsPage;
use App\Models\Invoice;
use App\Models\RentAdjustment;
use App\Models\Rental;
use App\Models\User;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;

/**
 * La pantalla de documentos.
 *
 * Se prueban las dos cosas que la pantalla responde: que el documento llegue al
 * usuario y que el bloqueo del comprobante se cumpla tambien cuando la
 * peticion viene del boton, no solo cuando el boton esta oculto.
 */
class DocumentsPageTest extends DocumentTestCase
{
    private ?Testable $screen = null;

    /**
     * La instancia se memoiza a proposito. Cada llamada a Livewire::test() crea
     * un componente nuevo, y el estado de esta pantalla vive en el componente:
     * leer textPreview de una instancia distinta a la que se genero el documento
     * daria siempre null.
     */
    private function page(): Testable
    {
        return $this->screen ??= Livewire::test(DocumentsPage::class);
    }

    private function rental(array $attributes = []): Rental
    {
        return Rental::factory()->create(array_merge([
            'user_id' => $this->user->id,
            'name' => 'Apartamento centro',
        ], $attributes));
    }

    #[Test]
    public function la_pantalla_carga(): void
    {
        $this->page()->assertSuccessful();
    }

    #[Test]
    public function el_formulario_ofrece_solo_alquileres_del_usuario_actual(): void
    {
        $mine = $this->rental(['name' => 'Apartamento propio']);
        $other = Rental::factory()->create([
            'user_id' => User::factory()->create()->id,
            'name' => 'Apartamento ajeno',
        ]);

        $options = $this->page()->instance()->form->getComponent('data.rental_id')->getOptions();

        $this->assertArrayHasKey($mine->id, $options);
        $this->assertArrayNotHasKey($other->id, $options);
    }

    #[Test]
    public function el_estado_de_cuenta_se_genera_en_texto(): void
    {
        $rental = $this->rental();

        Invoice::factory()->create([
            'user_id' => $this->user->id,
            'rental_id' => $rental->id,
            'period' => '2026-02',
        ]);

        $this->page()
            ->fillForm([
                'rental_id' => $rental->id,
                'period' => '2026-02',
                'format' => 'text',
            ])
            ->call('generateStatement')
            ->assertSuccessful();

        $this->assertStringContainsString('Estado de cuenta', (string) $this->page()->get('textPreview'));
    }

    #[Test]
    public function el_estado_de_cuenta_en_pdf_se_descarga_y_no_Deja_texto_pegado(): void
    {
        $rental = $this->rental();

        Invoice::factory()->create([
            'user_id' => $this->user->id,
            'rental_id' => $rental->id,
            'period' => '2026-02',
        ]);

        // En PDF la pantalla responde con una descarga. Se comprueban las dos
        // cosas: que el archivo llegue al navegador y que no quede el texto
        // pegado en la vista previa, que es el modo de entrega equivocado.
        //
        // Devolver la respuesta importa: si la pantalla construye el
        // StreamedResponse pero no lo retorna, Livewire no envia nada, el
        // boton no hace nada y el documento si se renderizo. Por eso la
        // asercion del archivo es la que detecta esa falla, no la del texto.
        $this->page()
            ->fillForm([
                'rental_id' => $rental->id,
                'period' => '2026-02',
                'format' => 'pdf',
            ])
            ->call('generateStatement')
            ->assertSuccessful()
            ->assertFileDownloaded('estado-de-cuenta-2026-02.pdf');

        $this->assertNull($this->page()->get('textPreview'));
    }

    #[Test]
    public function un_periodo_sin_facturas_avisa_y_no_inventa_cifras(): void
    {
        $rental = $this->rental();

        $this->page()
            ->fillForm([
                'rental_id' => $rental->id,
                'period' => '2026-11',
                'format' => 'text',
            ])
            ->call('generateStatement')
            ->assertNotified(__('invoice.documents.empty_statement'));
    }

    #[Test]
    public function la_factura_se_imprime_aunque_este_pendiente(): void
    {
        $invoice = Invoice::factory()->create([
            'user_id' => $this->user->id,
            'status' => InvoiceStatus::EMITIDA,
        ]);

        $this->page()
            ->fillForm(['invoice_id' => $invoice->id, 'format' => 'text'])
            ->call('generateInvoice')
            ->assertSuccessful();

        $this->assertStringContainsString($invoice->number, (string) $this->page()->get('textPreview'));
    }

    #[Test]
    public function el_comprobante_se_avisa_cuando_la_factura_no_esta_pagada(): void
    {
        $invoice = Invoice::factory()->create([
            'user_id' => $this->user->id,
            'status' => InvoiceStatus::EMITIDA,
        ]);

        $this->page()
            ->fillForm(['invoice_id' => $invoice->id, 'format' => 'text'])
            ->call('generateReceipt')
            ->assertNotified(__('invoice.receipt_locked'));

        // Y no se entrega nada: el bloqueo no es solo visual.
        $this->assertNull($this->page()->get('textPreview'));
    }

    #[Test]
    public function el_comprobante_se_entrega_cuando_la_factura_esta_pagada(): void
    {
        $invoice = $this->paidInvoice();

        $this->page()
            ->fillForm(['invoice_id' => $invoice->id, 'format' => 'text'])
            ->call('generateReceipt')
            ->assertSuccessful();

        $this->assertStringContainsString('Comprobante', (string) $this->page()->get('textPreview'));
    }

    #[Test]
    public function el_boton_de_comprobante_solo_habilita_en_facturas_pagadas(): void
    {
        $paid = $this->paidInvoice();
        $issued = $this->issuedInvoice();

        $screen = $this->page()->instance();

        $this->assertTrue($screen->receiptAvailable((string) $paid->id));
        $this->assertFalse($screen->receiptAvailable((string) $issued->id));
        $this->assertFalse($screen->receiptAvailable(null));
    }

    #[Test]
    public function la_carta_de_reajuste_muestra_el_aviso_del_tope_legal(): void
    {
        $adjustment = RentAdjustment::factory()->exceedingCap(180_000)->create([
            'user_id' => $this->user->id,
        ]);

        $this->page()
            ->fillForm(['adjustment_id' => $adjustment->id, 'format' => 'text'])
            ->call('generateAdjustmentLetter')
            ->assertSuccessful();

        $preview = (string) $this->page()->get('textPreview');

        $this->assertStringContainsString('AVISO', $preview);
        $this->assertStringContainsString('180.000', $preview);
    }

    #[Test]
    public function sin_alquiler_el_estado_de_cuenta_no_se_genera(): void
    {
        $this->page()
            ->fillForm(['rental_id' => null, 'period' => '2026-02', 'format' => 'text'])
            ->call('generateStatement')
            ->assertNotified();

        $this->assertNull($this->page()->get('textPreview'));
    }
}
