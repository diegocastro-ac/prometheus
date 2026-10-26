<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    /** @use HasFactory<\Database\Factories\InvoiceFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'number',
        'concept',
        'amount',
        'period',
        'issued_at',
        'due_at',
        'status',
        'paid_at',
        'notes',
        'rental_id',
        'user_id',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'amount' => 'float',
        'issued_at' => 'date',
        'due_at' => 'date',
        'paid_at' => 'datetime',
        'status' => InvoiceStatus::class,
    ];

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function rental(): BelongsTo
    {
        return $this->belongsTo(Rental::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function paidAmount(): float
    {
        return round((float) $this->payments()->sum('amount'), 2);
    }

    public function balance(): float
    {
        return round($this->amount - $this->paidAmount(), 2);
    }

    public function isPaid(): bool
    {
        return $this->status === InvoiceStatus::PAGADA;
    }

    public function canReceivePayments(): bool
    {
        return ! in_array($this->status, [InvoiceStatus::PAGADA, InvoiceStatus::ANULADA], true);
    }

    /**
     * Vencida es una factura que paso su fecha de vencimiento sin quedar
     * cubierta. Se pregunta por la fecha y no por el estado porque el estado
     * VENCIDA ya esta persistido: si se exigiera EMITIDA aqui, una factura
     * vencida dejaria de reportarse como vencida.
     *
     * La comparacion es por dia completo por la misma razon que en Payment: la
     * fecha se guarda a las 00:00.
     */
    public function isOverdue(): bool
    {
        if (in_array($this->status, [InvoiceStatus::PAGADA, InvoiceStatus::ANULADA], true)) {
            return false;
        }

        return $this->due_at->startOfDay()->isBefore(today()->startOfDay());
    }

    /**
     * Recalcula el estado a partir de lo que realmente se ha abonado.
     *
     * La regla es una sola: si lo pagado cubre el total, esta pagada; si no y
     * la fecha ya paso, esta vencida; si no, sigue emitida. El estado nunca se
     * escribe a mano, siempre se deduce.
     */
    public function refreshStatus(): void
    {
        if ($this->status?->isFinal() === true) {
            return;
        }

        $balance = $this->balance();

        if ($balance <= 0) {
            $this->status = InvoiceStatus::PAGADA;
            $this->paid_at = $this->paid_at ?? now();
            $this->save();

            return;
        }

        $this->status = $this->due_at->startOfDay()->isBefore(today()->startOfDay())
            ? InvoiceStatus::VENCIDA
            : InvoiceStatus::EMITIDA;

        // Si de nuevo queda saldo, deja de estar pagada: paid_at se limpia para
        // que no diga "pagada el 5 de marzo" sobre una factura con saldo.
        $this->paid_at = null;

        $this->save();
    }

    public function hasPartialPayment(): bool
    {
        return $this->paidAmount() > 0 && $this->balance() > 0;
    }

    /**
     * El concepto es una clave, no un texto. Se guarda como clave para poder
     * agregar conceptos nuevos sin migrar datos, y se traduce en el momento de
     * mostrarlo.
     *
     * Un concepto desconocido se muestra tal cual: un documento que dice
     * "mantenimiento" esta bien, y uno que dice "invoice.concepts.mantenimiento"
     * esta roto. Solo cae a la etiqueta de "otro" cuando no hay nada que mostrar.
     */
    public function conceptLabel(): string
    {
        return self::conceptLabelFor((string) ($this->concept ?: ''));
    }

    /**
     * @return array<string, string>
     */
    public static function conceptOptions(): array
    {
        return [
            'rent' => __('invoice.concepts.rent'),
            'services' => __('invoice.concepts.services'),
            'rent_services' => __('invoice.concepts.rent_services'),
            'adjustment' => __('invoice.concepts.adjustment'),
            'other' => __('invoice.concepts.other'),
        ];
    }

    public static function conceptLabelFor(string $concept): string
    {
        if ($concept === '') {
            return __('invoice.concepts.other');
        }

        return self::conceptOptions()[$concept] ?? $concept;
    }

    /**
     * El periodo es el mes al que se cobra, no el mes en que se emite el
     * documento. Una factura de marzo emitida en febrero es 2026-03, y por eso
     * el periodo se pasa aparte en lugar de derivarse siempre de issued_at.
     */
    public static function periodFor(\DateTimeInterface $date): string
    {
        return $date->format('Y-m');
    }
}
