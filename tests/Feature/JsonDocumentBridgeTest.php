<?php

namespace Tests\Feature;

use App\Documents\DocumentFactoryLocator;
use App\Documents\Factories\JsonDocumentFactory;
use App\Documents\Factories\TextDocumentFactory;
use App\Documents\Rendering\PdfRenderer;
use App\Filament\Support\DocumentAction;
use App\Models\RentAdjustment;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * El Bridge en su tercera familia: JSON.
 *
 * Lo que se verifica aqui es que el mismo DocumentBody se serialice en JSON sin
 * tocar los documentos de dominio, que el cuerpo conozca al documento que lo
 * creo (prerrequisito del PDF) y que la entrega pase por content().
 */
class JsonDocumentBridgeTest extends DocumentTestCase
{
    /**
     * @return array{0: JsonDocumentFactory, 1: array<int, array{0: string, 1: \App\Documents\Contracts\Document, 2: string}>}
     */
    private function jsonDocuments(): array
    {
        $adjustment = RentAdjustment::factory()->withinCap()->create();
        $paid = $this->paidInvoice();
        $issued = $this->issuedInvoice();
        $statementInvoices = $this->invoiceForPeriod('2026-01');

        $factory = app(JsonDocumentFactory::class);

        return [$factory, [
            ['comprobante', $factory->paymentReceipt($paid), 'PaymentReceipt'],
            ['carta', $factory->adjustmentLetter($adjustment), 'AdjustmentLetter'],
            ['estado', $factory->monthlyStatement('2026-01', $statementInvoices), 'MonthlyStatement'],
            ['factura', $factory->rentInvoice($issued), 'RentInvoice'],
        ]];
    }

    #[Test]
    public function los_cuatro_documentos_se_exportan_como_json_valido(): void
    {
        [, $documents] = $this->jsonDocuments();

        foreach ($documents as [$label, $document, $type]) {
            $content = $document->content();
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

            $this->assertSame('application/json', $document->mimeType(), $label);
            $this->assertStringEndsWith('.json', $document->filename(), $label);
            $this->assertSame($type, $decoded['document_type'], $label);

            foreach (['title', 'fields', 'table', 'warnings', 'notes', 'footer'] as $key) {
                $this->assertArrayHasKey($key, $decoded, "{$label}: falta {$key}");
            }

            $this->assertArrayNotHasKey('meta', $decoded, $label);
            $this->assertStringNotContainsString('data:image', $content, $label);
        }
    }

    #[Test]
    public function el_json_conserva_los_mismos_datos_que_el_texto(): void
    {
        $invoice = $this->paidInvoice(['amount' => 800_000, 'concept' => 'rent']);

        $text = app(TextDocumentFactory::class)->paymentReceipt($invoice);
        $json = app(JsonDocumentFactory::class)->paymentReceipt($invoice);

        $decoded = json_decode($json->content(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame($text->body()->title, $decoded['title']);
        $this->assertSame($text->body()->fields, $decoded['fields']);
        $this->assertSame($text->body()->headers, $decoded['table']['headers']);
        $this->assertSame($text->body()->rows, $decoded['table']['rows']);
        $this->assertSame($text->body()->warnings, $decoded['warnings']);
        $this->assertSame($text->body()->notes, $decoded['notes']);
        $this->assertSame($text->body()->footer, $decoded['footer']);
    }

    #[Test]
    public function el_estado_de_cuenta_vacio_exporta_la_tabla_como_nula(): void
    {
        $statement = app(JsonDocumentFactory::class)->monthlyStatement('2026-06', []);

        $decoded = json_decode($statement->content(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertNull($decoded['table']);
        $this->assertSame('2026-06', $decoded['fields'][__('document.period')]);
        $this->assertSame('0', $decoded['fields'][__('document.invoices')]);
    }

    #[Test]
    public function la_descarga_json_declara_su_mime_y_entrega_un_json_valido(): void
    {
        $invoice = $this->paidInvoice();
        $document = app(JsonDocumentFactory::class)->paymentReceipt($invoice);

        $response = DocumentAction::download($document);

        $this->assertInstanceOf(StreamedResponse::class, $response);
        $this->assertSame('application/json', $response->headers->get('Content-Type'));
        $this->assertStringContainsString(
            $document->filename(),
            (string) $response->headers->get('Content-Disposition'),
        );

        ob_start();
        $response->sendContent();
        $content = (string) ob_get_clean();

        $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('PaymentReceipt', $decoded['document_type']);
    }

    #[Test]
    public function el_locator_resuelve_la_familia_json(): void
    {
        $locator = app(DocumentFactoryLocator::class);

        $this->assertTrue($locator->supports('json'));
        $this->assertInstanceOf(JsonDocumentFactory::class, $locator->for('json'));
    }

    #[Test]
    public function el_pdf_elige_la_plantilla_del_documento_y_no_la_generica(): void
    {
        $adjustment = RentAdjustment::factory()->withinCap()->create();
        $paid = $this->paidInvoice();
        $issued = $this->issuedInvoice();
        $statementInvoices = $this->invoiceForPeriod('2026-02');

        $factory = app(JsonDocumentFactory::class);

        $cases = [
            [$factory->paymentReceipt($paid), 'documents.receipt'],
            [$factory->adjustmentLetter($adjustment), 'documents.adjustment-letter'],
            [$factory->monthlyStatement('2026-02', $statementInvoices), 'documents.monthly-statement'],
            [$factory->rentInvoice($issued), 'documents.invoice'],
        ];

        $renderer = app(PdfRenderer::class);
        $selectView = new ReflectionMethod($renderer, 'selectView');
        $selectView->setAccessible(true);

        foreach ($cases as [$document, $view]) {
            $this->assertSame($document, $document->body()->document());
            $this->assertSame($view, $selectView->invoke($renderer, $document));
        }
    }
}
