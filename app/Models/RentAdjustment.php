<?php

namespace App\Models;

use App\ValueObjects\RentAdjustment as RentAdjustmentValue;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Propuesta de reajuste del canon de un alquiler.
 *
 * El calculo vive en el value object RentAdjustmentValue; este modelo solo
 * guarda el resultado y su relacion con el alquiler, el IPC aplicado y la
 * comunicacion.
 */
class RentAdjustment extends Model
{
    /** @use HasFactory<\Database\Factories\RentAdjustmentFactory> */
    use HasFactory;

    protected $fillable = [
        'rental_id',
        'user_id',
        'ipc_rate_id',
        'previous_rent',
        'new_rent',
        'ipc_year',
        'ipc_percentage',
        'legal_cap_rent',
        'excess_over_cap',
        'requires_written_agreement',
        'effective_from',
        'notified_on',
        'notes',
    ];

    protected $casts = [
        'previous_rent' => 'float',
        'new_rent' => 'float',
        'ipc_year' => 'integer',
        'ipc_percentage' => 'float',
        'legal_cap_rent' => 'float',
        'excess_over_cap' => 'float',
        'requires_written_agreement' => 'boolean',
        'effective_from' => 'date',
        'notified_on' => 'date',
    ];

    public function rental(): BelongsTo
    {
        return $this->belongsTo(Rental::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function ipcRate(): BelongsTo
    {
        return $this->belongsTo(IpcRate::class);
    }

    public function isIncrease(): bool
    {
        return $this->new_rent > $this->previous_rent;
    }

    public function increase(): float
    {
        return round($this->new_rent - $this->previous_rent, 0);
    }

    /**
     * El incremento sobre el canon anterior, en porcentaje.
     */
    public function increasePercentage(): float
    {
        if ($this->previous_rent <= 0) {
            return 0.0;
        }

        return round((($this->new_rent - $this->previous_rent) / $this->previous_rent) * 100, 2);
    }

    public function exceedsCap(): bool
    {
        return $this->isIncrease()
            && $this->legal_cap_rent !== null
            && $this->new_rent > $this->legal_cap_rent;
    }

    /**
     * Traduce lo guardado de vuelta al value object, para que la carta de
     * reajuste tenga una sola fuente de verdad de las reglas del articulo 20.
     *
     * @see \App\ValueObjects\RentAdjustment::fromFrozen()
     */
    public function toValueObject(): ?RentAdjustmentValue
    {
        if ($this->legal_cap_rent === null || $this->ipc_percentage === null || $this->ipc_year === null) {
            return null;
        }

        return RentAdjustmentValue::fromFrozen(
            previousRent: (float) $this->previous_rent,
            requestedRent: (float) $this->new_rent,
            legalCapRent: (float) $this->legal_cap_rent,
            ipcPercentage: (float) $this->ipc_percentage,
            ipcYear: (int) $this->ipc_year,
            ipcSourceName: $this->ipcRate?->source_name ?? 'DANE',
            ipcSourceUrl: $this->ipcRate?->source_url ?? '',
        );
    }
}
