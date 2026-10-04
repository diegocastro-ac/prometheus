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
        $billed = array_sum(array_map(fn (Invoice $invoice): float => $invoice->amount, $invoices));
        $paid = array_sum(array_map(fn (Invoice $invoice): float => $invoice->paidAmount(), $invoices));

        $rows = array_map(fn (Invoice $invoice): array => [
            $invoice->number,
            $invoice->conceptLabel(),
            Money::exact($invoice->amount),
            Money::exact($invoice->paidAmount()),
            Money::exact($invoice->balance()),
            __('invoice.statuses.'.$invoice->status->value),
        ], $invoices);

        $body = DocumentBody::make('Estado de cuenta')
            ->withFields([
                'Alquiler' => $invoices[0]->rental?->name ?? 'no indicado',
                'Periodo' => $period,
                'Facturas' => (string) count($invoices),
                'Total facturado' => Money::exact($billed),
                'Total abonado' => Money::exact($paid),
                'Saldo' => Money::exact($billed - $paid),
            ]);

        if ($rows !== []) {
            $body = $body->withTable(
                ['Factura', 'Concepto', 'Total', 'Abonado', 'Saldo', 'Estado'],
                $rows,
            );
        }

        return new self(
            body: $body
                ->withNotes(
                    'Las fechas de vencimiento se consultan en cada factura. Las facturas anuladas '
                    .'no secobran y se muestran solo como registro.',
                )
                ->withFooter(
                    'Documento generado por Prometheus el '.now()->format('d/m/Y \a \l\a\s H:i'),
                ),
            renderer: $renderer,
            slug: 'estado-de-cuenta-'.$period,
            period: $period,
            invoices: $invoices,
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
}