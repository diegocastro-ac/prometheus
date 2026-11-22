<?php

namespace Tests\Feature;

use App\Contracts\DocumentAccessLogger;
use App\Documents\Contracts\Document;
use App\Documents\DocumentDescriptor;
use App\Documents\DocumentProxy;
use App\Documents\DocumentService;
use App\Documents\Factories\TextDocumentFactory;
use App\Models\DocumentAccess;
use DomainException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * El Proxy de documentos: auditoria del contenido y construccion diferida.
 *
 * La metadata no se audita porque no revela el contenido. El registro se hace
 * una sola vez por instancia y antes de entregar el documento; si la auditoria
 * falla, la entrega se detiene.
 */
class DocumentProxyTest extends DocumentTestCase
{
    #[Test]
    public function la_metadata_no_registra_un_acceso(): void
    {
        $invoice = $this->issuedInvoice();
        $document = app(DocumentService::class)->invoiceFor($invoice, 'pdf');

        $this->assertSame('factura-'.$invoice->number.'.pdf', $document->filename());
        $this->assertSame('application/pdf', $document->mimeType());
        $this->assertTrue($document->isDownload());
        $this->assertNotEmpty($document->preview());
        $this->assertFalse($document->isEmpty());

        $this->assertDatabaseCount('document_accesses', 0);
    }

    #[Test]
    public function el_contenido_registra_un_unico_acceso_aunque_se_pida_varias_veces(): void
    {
        $invoice = $this->issuedInvoice();
        $document = app(DocumentService::class)->invoiceFor($invoice, 'text');

        $this->assertDatabaseCount('document_accesses', 0);

        $document->content();
        $document->content();

        $this->assertDatabaseCount('document_accesses', 1);

        $access = DocumentAccess::query()->sole();

        $this->assertSame($this->user->id, $access->user_id);
        $this->assertSame('rent_invoice', $access->document_type);
        $this->assertSame($invoice->number, $access->reference);
        $this->assertSame('text', $access->format);
        $this->assertSame(['invoice_id' => $invoice->id], $access->context);
        $this->assertNotNull($access->accessed_at);
    }

    #[Test]
    public function cada_proxy_registra_su_propio_acceso(): void
    {
        $invoice = $this->issuedInvoice();
        $service = app(DocumentService::class);

        $service->invoiceFor($invoice, 'text')->content();
        $service->invoiceFor($invoice, 'json')->content();

        $this->assertDatabaseCount('document_accesses', 2);
        $this->assertSame(
            ['text', 'json'],
            DocumentAccess::query()->orderBy('id')->pluck('format')->all(),
        );
    }

    #[Test]
    public function el_documento_real_se_construye_una_sola_vez(): void
    {
        $built = 0;
        $real = app(TextDocumentFactory::class)->rentInvoice($this->issuedInvoice());

        $proxy = new DocumentProxy(
            descriptor: new DocumentDescriptor(
                type: 'rent_invoice',
                format: 'text',
                userId: $this->user->id,
            ),
            builder: function () use (&$built, $real): Document {
                $built++;

                return $real;
            },
            logger: $this->recordingLogger(),
        );

        $this->assertSame(0, $built);

        $proxy->content();
        $proxy->content();

        $this->assertSame(1, $built);
    }

    #[Test]
    public function una_auditoria_fallida_bloquea_el_contenido(): void
    {
        $built = false;
        $real = app(TextDocumentFactory::class)->rentInvoice($this->issuedInvoice());

        $proxy = new DocumentProxy(
            descriptor: new DocumentDescriptor(
                type: 'rent_invoice',
                format: 'text',
                userId: $this->user->id,
            ),
            builder: function () use (&$built, $real): Document {
                $built = true;

                return $real;
            },
            logger: new class implements DocumentAccessLogger
            {
                public function log(DocumentDescriptor $descriptor): void
                {
                    throw new RuntimeException('Auditoria no disponible.');
                }
            },
        );

        try {
            $proxy->content();
            $this->fail('El contenido se entrego sin registrar el acceso.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Auditoria no disponible.', $exception->getMessage());
        }

        $this->assertFalse($built, 'El documento real no debe construirse si falla la auditoria.');
    }

    #[Test]
    public function el_comprobante_sin_pagar_falla_antes_de_crear_el_proxy(): void
    {
        $this->expectException(DomainException::class);

        app(DocumentService::class)->receiptFor($this->issuedInvoice(), 'text');
    }

    private function recordingLogger(): DocumentAccessLogger
    {
        return new class implements DocumentAccessLogger
        {
            /** @var list<DocumentDescriptor> */
            public array $descriptors = [];

            public function log(DocumentDescriptor $descriptor): void
            {
                $this->descriptors[] = $descriptor;
            }
        };
    }
}
