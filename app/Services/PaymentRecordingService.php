<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Support\Carbon;

/**
 * Registro de abonos contra una factura.
 *
 * Es el unico lugar donde se crea un Payment. Concentrarlo aqui evita que cada
 * pantalla repita la misma regla: crear el pago, recalcular el estado de la
 * factura y dejarla pagada cuando el saldo llega a cero.
 */
class PaymentRecordingService
{
    /**
     * @return array{payment: Payment, invoice: Invoice, just_settled: bool}
     */
    public function record(
        Invoice $invoice,
        float $amount,
        ?string $method = null,
        ?string $reference = null,
        ?Carbon $paidAt = null,
    ): array {
        if (! $invoice->canReceivePayments()) {
            throw new \DomainException('La factura '.$invoice->number.' no admite mas pagos.');
        }

        if ($amount <= 0) {
            throw new \DomainException('El valor pagado debe ser mayor que cero.');
        }

        $payment = new Payment([
            'amount' => $amount,
            'method' => $method ?: 'efectivo',
            'reference' => $reference,
            'date' => ($paidAt ?? now())->format('Y-m-d'),
        ]);

        $payment->invoice()->associate($invoice);
        $payment->rental()->associate($invoice->rental);
        $payment->user()->associate($invoice->user);
        $payment->save();

        $invoice->refreshStatus();

        return [
            'payment' => $payment,
            'invoice' => $invoice->fresh(),
            'just_settled' => $invoice->status === InvoiceStatus::PAGADA,
        ];
    }
}
