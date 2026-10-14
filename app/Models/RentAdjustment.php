<?php

namespace App\Models;

use App\Enums\AdjustmentState;
use App\Enums\IpcBasis;
use App\Enums\NotificationMethod;
use App\ValueObjects\RentAdjustment as RentAdjustmentValue;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Propuesta de reajuste del canon de un alquiler.
 *
 * El calculo vive en el value object RentAdjustmentValue; este modelo guarda el
 * resultado y su relacion con el alquiler, el IPC aplicado y la comunicacion.
 *
 * Lo que cambio con la llegada del IPC dinamico es el papel de previous_rent.
 * Antes era una copia del canon vigente en el momento de crear el registro, y
 * por eso se quedaba obsoleta en cuanto se editaba un ajuste anterior. Ahora es
 * un dato DERIVADO: lo calcula la cadena al reprocesarse desde el ancla del
 * alquiler, y por eso todos los ajustes de la cadena tienen que encajar.
 */
class RentAdjustment extends Model
{
    /** @use HasFactory<\Database\Factories\RentAdjustmentFactory> */
    use HasFactory;

    protected $fillable = [
        'rental_id',
        'user_id',
        'ipc_rate_id',
        'sequence',
        'previous_rent',
        'anchor_rent',
        'new_rent',
        'ipc_year',
        'ipc_percentage',
        'legal_pct',
        'applied_pct',
        'ipc_basis',
        'geography_code',
        'geography_name',
        'ipc_month',
        'ipc_synced_at',
        'ipc_source_url',
        'legal_cap_rent',
        'excess_over_cap',
        'requires_written_agreement',
        'chain_hash',
        'effective_from',
        'notified_on',
        'notification_method',
        'applied_on',
        'notes',
    ];

    protected $casts = [
        'previous_rent' => 'float',
        'anchor_rent' => 'float',
        'new_rent' => 'float',
        'ipc_year' => 'integer',
        'ipc_month' => 'integer',
        'sequence' => 'integer',
        'ipc_percentage' => 'float',
        'legal_pct' => 'float',
        'applied_pct' => 'float',
        'ipc_synced_at' => 'datetime',
        'legal_cap_rent' => 'float',
        'excess_over_cap' => 'float',
        'requires_written_agreement' => 'boolean',
        'effective_from' => 'date',
        'notified_on' => 'date',
        'applied_on' => 'date',
        'ipc_basis' => IpcBasis::class,
        'notification_method' => NotificationMethod::class,
    ];

    protected $attributes = [
        'sequence' => 1,
        'ipc_basis' => IpcBasis::National->value,
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

    /**
     * El estado se deduce de las fechas, no de una columna.
     *
     * Guardarlo seria una segunda fuente de verdad que puede contradecir a las
     * fechas, que son las que tienen valor probatorio frente a un juez.
     */
    public function state(): AdjustmentState
    {
        if ($this->applied_on !== null) {
            return AdjustmentState::Applied;
        }

        if ($this->notified_on !== null) {
            return AdjustmentState::Notified;
        }

        return AdjustmentState::Recorded;
    }

    /**
     * Un reajuste solo entra en la cadena cuando su canon quedo vigente.
     *
     * Un ajuste registrado pero no aplicado no cambio el canon, y encadenar el
     * siguiente sobre el haria calcularlo sobre una cifra que nunca rigio.
     */
    public function countsTowardsChain(): bool
    {
        return $this->state()->countsTowardsChain();
    }

    /**
     * El IPC legal del art. 20, o el que guardado si es antiguo.
     */
    public function legalPercentage(): float
    {
        return (float) ($this->legal_pct ?? $this->ipc_percentage ?? 0.0);
    }

    /**
     * El porcentaje que realmente se aplico, que puede ser menor por pacto.
     */
    public function appliedPercentage(): float
    {
        return (float) ($this->applied_pct ?? $this->ipc_percentage ?? 0.0);
    }

    public function isNationalBasis(): bool
    {
        return ($this->ipc_basis ?? IpcBasis::default())->isStatutory();
    }

    /**
     * Si el IPC del articulo 20 y el aplicado difieren, es porque hubo pacto.
     *
     * El articulo 20 dice "hasta", asi que acordar menos que el IPC es legitimo y
     * frecuente. Lo que no es legitimo es presentarlo como si fuera el resultado
     * del calculo, por eso la cifra se muestra aparte en la carta y en la tabla.
     */
    public function hasPactadoCap(): bool
    {
        if ($this->legal_pct === null || $this->applied_pct === null) {
            return false;
        }

        return (float) $this->legal_pct > (float) $this->applied_pct;
    }

    /**
     * El factor con el que se multiplico el canon anterior.
     */
    public function appliedFactor(): float
    {
        return 1 + ($this->appliedPercentage() / 100);
    }

    /**
     * Si el IPC que se uso se aparta del que fija el articulo 20.
     *
     * Hay dos formas de apartarse y las dos son legitimas si el arrendador las
     * asume por escrito: elegir otro ano, o tomar el IPC de una ciudad cuando el
     * articulo habla del indice sin mas.
     */
    public function deviatesFromArticle20(?\DateTimeInterface $reference = null): bool
    {
        if (! $this->isNationalBasis()) {
            return true;
        }

        $effectiveFrom = $reference ?? $this->effective_from;

        if ($effectiveFrom === null || $this->ipc_year === null) {
            return false;
        }

        return $this->ipc_year !== ((int) $effectiveFrom->format('Y') - 1);
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

    /**
     * El incremento acumulado sobre el canon original del alquiler.
     *
     * Es el porcentaje que ve el inquilino comparando lo que pago al empezar con
     * lo que paga hoy, que no es el del ultimo reajuste: es la composicion de
     * todos.
     */
    public function cumulativeIncreasePercentage(): float
    {
        $anchor = (float) ($this->anchor_rent ?? $this->previous_rent);

        if ($anchor <= 0) {
            return 0.0;
        }

        return round((($this->new_rent - $anchor) / $anchor) * 100, 2);
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
            ipcSourceUrl: $this->ipc_rate_id ? ($this->ipcRate?->source_url ?? '') : ($this->ipc_source_url ?? ''),
        );
    }
}
