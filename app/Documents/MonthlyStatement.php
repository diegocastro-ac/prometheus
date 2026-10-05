<?php

namespace App\Documents;

use App\Documents\Rendering\DocumentBody;
use App\Documents\Rendering\DocumentRenderer;
use App\Models\Invoice;

/**
 * Estado de cuenta de un alquiler en un periodo.
 *
 * Es el documento que responde "¿que debo hasta hoy?". A diferencia de la
 * factura, no muestra una sola operacion: recorre todas las del periodo con su
 * estado y cierra con el saldo real. Por eso es el unico de los cuatro que
 * necesita la lista completa de facturas, y no un unico registro.
 */
class MonthlyStatement extends AbstractDocument
{
    /**
     * @param  list<Invoice>  $invoices
     */
    private function __construct(
        DocumentBody $body,
        DocumentRenderer $renderer,
        string $slug,
        private readonly string $period,
        private readonly array $invoices,
    ) {
        parent::__construct($body, $renderer, $slug);
    }

    /**
     * Las facturas deben venir ya filtradas por periodo y ordenadas: la
     * fachada las entrega asi y el documento no vuelve a consultar la base de
     * datos.
     *
     * @param  list<Invoice>  $invoices
     */
    public static function forPeriod(string $period, array $invoices, DocumentRenderer $renderer): self
    {
        $body = self::buildBody($period, $invoices);

        $document = new self(
            body: $body,
            renderer: $renderer,
            slug: 'estado-de-cuenta-'.$period,
            period: $period,
            invoices: $invoices,
        );

        // Adjuntar el documento al body después de la instanciación
        $document->body = $document->body->withDocument($document);

        return $document;
    }

    private static function buildBody(string $period, array $invoices): DocumentBody
    {
        $billed = array_sum(array_map(fn (Invoice $invoice): float => $invoice->amount, $invoices));
        $paid = array_sum(array_map(fn (Invoice $invoice): float => $invoice->paidAmount(), $invoices));

        $rows = array_map(fn (Invoice $invoice): array => [
            'number' => $invoice->number,
            'concept' => $invoice->conceptLabel(),
            'period' => $invoice->period,
            'issued_at' => $invoice->issued_at?->format('d/m/Y') ?? '-',
            'amount' => $invoice->amount,
            'paid' => $invoice->paidAmount(),
            'balance' => $invoice->balance(),
            'status' => __('invoice.statuses.'.$invoice->status->value),
            'status_class' => $invoice->status->value,
        ], $invoices);

        $body = DocumentBody::make(__('document.monthly_statement'))
            ->withFields([
                'Total facturado' => Money::exact($billed),
                'Total abonado' => Money::exact($paid),
                'Saldo pendiente' => Money::exact($billed - $paid),
            ]);

        if ($rows !== []) {
            $body = $body->withTable(
                ['Factura', 'Concepto', 'Periodo', 'Fecha', 'Total', 'Pagado', 'Saldo', 'Estado'],
                $rows,
            );
        }

        return $body
            ->withNotes(
                'Las fechas de vencimiento se consultan en cada factura. Las facturas anuladas '
                .'no se cobran y se muestran solo como registro.',
            )
            ->withFooter(
                __('document.generated_by').' '.now()->format('d/m/Y \a \l\a\s H:i'),
            );
    }

    public function period(): string
    {
        return $this->period;
    }

    /**
     * @return list<Invoice>
     */
    public function invoices(): array
    {
        return $this->invoices;
    }

    public function preview(): string
    {
        $balance = array_sum(array_map(
            fn (Invoice $invoice): float => $invoice->balance(),
            $this->invoices,
        ));

        return 'Estado de cuenta '.$this->period
            .' con '.count($this->invoices).' factura(s), saldo '.Money::exact($balance);
    }

    public function tenantName(): string
    {
        return $this->invoices[0]->rental?->tenant?->name ?? 'No indicado';
    }

    public function propertyName(): string
    {
        return $this->invoices[0]->rental?->property?->name ?? 'No indicado';
    }

    public function periodStart(): \Illuminate\Support\Carbon
    {
        return \Illuminate\Support\Carbon::parse($this->period . '-01');
    }

    public function periodEnd(): \Illuminate\Support\Carbon
    {
        return \Illuminate\Support\Carbon::parse($this->period . '-01')->endOfMonth();
    }

    public function transactions(): array
    {
        return array_map(fn (Invoice $invoice): array => [
            'number' => $invoice->number,
            'period' => $invoice->period,
            'concept' => $invoice->conceptLabel(),
            'status' => $invoice->status->value,
            'status_class' => $invoice->status->value,
            'amount' => $invoice->amount,
            'paid' => $invoice->paidAmount(),
            'balance' => $invoice->balance(),
        ], $this->invoices);
    }

    public function totalInvoiced(): float
    {
        return array_sum(array_map(fn (Invoice $invoice): float => $invoice->amount, $this->invoices));
    }

    public function totalPaid(): float
    {
        return array_sum(array_map(fn (Invoice $invoice): float => $invoice->paidAmount(), $this->invoices));
    }

    public function outstandingBalance(): float
    {
        return array_sum(array_map(fn (Invoice $invoice): float => $invoice->balance(), $this->invoices));
    }
}