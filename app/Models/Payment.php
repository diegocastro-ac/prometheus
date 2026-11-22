<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    /** @use HasFactory<\Database\Factories\PaymentFactory> */
    use HasFactory;

    /**
     * Un pago es un abono contra una factura. Ya no lleva indicadores propios
     * de canon, agua, energia o gas: el estado se deduce de la factura que
     * esta cubriendo.
     *
     * @var list<string>
     */
    protected $fillable = [
        'date',
        'amount',
        'method',
        'reference',
        'receipt_image',
        'invoice_id',
        'rental_id',
        'user_id',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'date' => 'date',
        'amount' => 'float',
    ];

    /**
     * Estado del pago, deducido de la factura que cubre.
     *
     * Antes cada pago traia sus propios cuatro indicadores y el estado se
     * calculaba aqui. Ahora la unica fuente es la factura, asi que el estado de
     * un pago y el de su factura no pueden contradecirse.
     */
    public function status(): PaymentStatus
    {
        $invoice = $this->invoice;

        if ($invoice === null) {
            return PaymentStatus::PENDING;
        }

        if ($invoice->status === InvoiceStatus::ANULADA) {
            return PaymentStatus::PENDING;
        }

        if ($invoice->isPaid()) {
            return PaymentStatus::PAID;
        }

        if ($invoice->isOverdue()) {
            return PaymentStatus::OVERDUE;
        }

        return PaymentStatus::PARTIAL;
    }

    /**
     * Get the invoice this payment covers.
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * Get the user that owns the rental.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the rental associated with the payment.
     */
    public function rental(): BelongsTo
    {
        return $this->belongsTo(Rental::class);
    }

    /**
     * La forma de pago se guarda como clave para poder agregar medios de pago
     * sin migrar datos, y se traduce al mostrarla.
     *
     * Un medio desconocido cae a la propia clave en vez de imprimir "null": un
     * comprobante de pago que dice "Forma de pago: " se ve mal diligenciado.
     */
    public function methodLabel(): string
    {
        $key = (string) ($this->method ?: '');
        $label = __('invoice.methods.'.$key);

        return $label === 'invoice.methods.'.$key ? ($key !== '' ? $key : 'sin especificar') : $label;
    }
}
