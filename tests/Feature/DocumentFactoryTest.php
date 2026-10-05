<?php

namespace Tests\Feature;

use App\Documents\DocumentFactoryLocator;
use App\Documents\DocumentService;
use App\Documents\Factories\DocumentFactory;
use App\Documents\Factories\PdfDocumentFactory;
use App\Documents\Factories\TextDocumentFactory;
use App\Documents\PaymentReceipt;
use App\Enums\InvoiceStatus;
use App\Models\RentAdjustment;
use DomainException;
use PHPUnit\Framework\Attributes\Test;

/**
 * El patron Abstract Factory: dos familias, los mismos cuatro productos.
 *
 * Lo que se verifica aqui no es que el PDF se vea bien, sino que las dos
 * familias sean intercambiables y completas. Si a una familia le faltara un
 * producto, el error apareceria al agregar el quinto documento.
 */
class DocumentFactoryTest extends DocumentTestCase
{
    /**
     * @return array<string, array{class-string<DocumentFactory>, string, string, bool}>
     */
    public static function families(): array
    {
        return [
            'texto' => [TextDocumentFactory::class, 'text', 'invoice.documents.copy', false],
            'pdf' => [PdfDocumentFactory::class, 'pdf', 'invoice.documents.download', true],
        ];
    }

    #[Test]
    public function cada_familia_se_resuelve_por_su_formato(): void
    {
        $locator = app(DocumentFactoryLocator::class);

        $this->assertInstanceOf(TextDocumentFactory::class, $locator->for('text'));
        $this->assertInstanceOf(PdfDocumentFactory::class, $locator->for('pdf'));
    }

    #[Test]
    public function sin_formato_se_usa_la_familia_de_texto(): void
    {
        $this->assertInstanceOf(TextDocumentFactory::class, app(DocumentFactoryLocator::class)->for(null));
    }

    #[Test]
    public function un_formato_desconocido_falla_en_vez_de_usar_un_valor_por_defecto(): void
    {
        // Si cayera a un valor por defecto, un typo en el selector de la
        // pantalla entregaria silenciosamente un PDF donde se pidio texto.
        $this->expectException(\InvalidArgumentException::class);

        app(DocumentFactoryLocator::class)->for('docx');
    }

    #[Test]
    public function el_locator_solo_anuncia_los_formatos_que_existen(): void
    {
        $locator = app(DocumentFactoryLocator::class);

        $this->assertTrue($locator->supports('text'));
        $this->assertTrue($locator->supports('pdf'));
        $this->assertFalse($locator->supports('docx'));
        $this->assertFalse($locator->supports(null));
    }

    #[Test]
    public function las_dos_familias_se_declaran_la_misma_interfaz(): void
    {
        // Esta es la garantia central del patron: si las dos familias
        // implementan el mismo contrato, el consumidor puede intercambiarlas.
        foreach (self::families() as [$class]) {
            $this->assertTrue(
                is_subclass_of($class, DocumentFactory::class),
                "{$class} no implementa DocumentFactory",
            );
        }
    }

    #[Test]
    public function cada_familia_entrega_los_cuatro_productos(): void
    {
        $adjustment = RentAdjustment::factory()->withinCap()->create();
        $paid = $this->paidInvoice();
        $issued = $this->issuedInvoice();
        $statement = $this->invoiceForPeriod('2026-01');

        foreach (self::families() as [$class, $format, $labelKey, $downloads]) {
            $factory = app($class);

            $this->assertSame($format, $factory->format(), "{$class} no se identifica como {$format}");

            $documents = [
                $factory->paymentReceipt($paid),
                $factory->adjustmentLetter($adjustment),
                $factory->monthlyStatement('2026-01', $statement),
                $factory->rentInvoice($issued),
            ];

            // Cuatro productos, todos del mismo contrato, todos con la etiqueta
            // y el modo de entrega que declara su familia.
            $this->assertCount(4, $documents);

            foreach ($documents as $document) {
                $this->assertInstanceOf(\App\Documents\Contracts\Document::class, $document);
                $this->assertSame(__($labelKey), $document->deliveryLabel());
                $this->assertSame($downloads, $document->isDownload());
                $this->assertStringEndsWith(
                    $downloads ? '.pdf' : '.txt',
                    $document->filename(),
                );
            }
        }
    }

    #[Test]
    public function la_familia_de_texto_no_genera_pdf(): void
    {
        $receipt = app(TextDocumentFactory::class)->paymentReceipt($this->paidInvoice());

        $this->assertSame('text/plain; charset=UTF-8', $receipt->mimeType());
        $this->assertStringStartsNotWith('%PDF', $receipt->content());
    }

    #[Test]
    public function la_familia_pdf_si_genera_pdf(): void
    {
        $receipt = app(PdfDocumentFactory::class)->paymentReceipt($this->paidInvoice());

        $this->assertSame('application/pdf', $receipt->mimeType());
        $this->assertStringStartsWith('%PDF', $receipt->content());
    }

    #[Test]
    public function el_mismo_documento_cambia_de_nombre_y_de_contenido_segun_la_familia(): void
    {
        $invoice = $this->paidInvoice();

        $text = app(TextDocumentFactory::class)->paymentReceipt($invoice);
        $pdf = app(PdfDocumentFactory::class)->paymentReceipt($invoice);

        $this->assertSame('comprobante-'.$invoice->number.'.txt', $text->filename());
        $this->assertSame('comprobante-'.$invoice->number.'.pdf', $pdf->filename());
        $this->assertNotSame($text->content(), $pdf->content());

        // Pero el dato es el mismo: los dos cuerpos declaran la misma factura.
        $this->assertSame($text->body()->title, $pdf->body()->title);
        $this->assertSame($text->body()->fields, $pdf->body()->fields);
    }

    #[Test]
    public function el_contenido_se_genera_una_sola_vez_por_documento(): void
    {
        // El PDF es caro de producir. Si se regenerara en cada llamada, una
        // misma respuesta HTTP lo construiria varias veces.
        $document = app(PdfDocumentFactory::class)->paymentReceipt($this->paidInvoice());

        $this->assertSame($document->content(), $document->content());
    }

    #[Test]
    public function la_interfaz_por_defecto_es_la_familia_de_texto(): void
    {
        $this->assertInstanceOf(TextDocumentFactory::class, app(DocumentFactory::class));
    }

    #[Test]
    public function el_locator_no_crea_dos_documentos_al_entregar_una_familia(): void
    {
        $service = app(DocumentService::class);

        $this->assertInstanceOf(PaymentReceipt::class, $service->receiptFor($this->paidInvoice(), 'text'));
        $this->assertInstanceOf(PaymentReceipt::class, $service->receiptFor($this->paidInvoice(), 'pdf'));
    }

    #[Test]
    public function el_comprobante_no_se_construye_para_una_factura_sin_pagar(): void
    {
        // El bloqueo es del dominio, no de la pantalla. Se prueba con las dos
        // familias porque ambas deben respetar la misma regla.
        $unpaid = $this->issuedInvoice();

        foreach (self::families() as [$class]) {
            try {
                app($class)->paymentReceipt($unpaid);
                $this->fail('La familia '.$class.' entrego un comprobante de una factura sin pagar.');
            } catch (DomainException $exception) {
                $this->assertStringContainsString($unpaid->number, $exception->getMessage());
            }
        }
    }

    #[Test]
    public function la_factura_si_se_imprime_estando_pendiente(): void
    {
        // Al reves del comprobante: la factura existe precisamente para cobrar.
        $invoice = $this->issuedInvoice();

        $this->assertSame(InvoiceStatus::EMITIDA, $invoice->status);

        $document = app(TextDocumentFactory::class)->rentInvoice($invoice);

        // El texto se compara contra la traduccion, no contra una palabra fija:
        // la suite corre en ingles y una etiqueta fija en espanol haria fallar la
        // prueba sin que hubiera ningun error en el documento.
        $this->assertStringContainsString(__('invoice.statuses.emitida'), $document->content());
    }
}
