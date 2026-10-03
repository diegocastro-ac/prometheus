<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeliveryAct extends Model
{
    /** @use HasFactory<\Database\Factories\DeliveryActFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'type',
        'landlord_name',
        'landlord_document',
        'tenant_name',
        'tenant_document',
        'occurred_at',
        'scheduled_at',
        'water_reading',
        'energy_reading',
        'gas_reading',
        'commitments',
        'observations',
        'landlord_signature_path',
        'tenant_signature_path',
        'signed_at',
        'rental_id',
        'user_id',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'occurred_at' => 'date',
        'scheduled_at' => 'datetime',
        'signed_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(ActItem::class);
    }

    public function rental(): BelongsTo
    {
        return $this->belongsTo(Rental::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * El inventario agrupado por espacio, que es como se imprime.
     *
     * @return array<string, list<ActItem>>
     */
    public function itemsBySpace(): array
    {
        return $this->items
            ->sortBy('space')
            ->groupBy('space')
            ->all();
    }

    /**
     * Una lectura en null significa que ese medidor no se leyo, por lo que la
     * seccion no debe imprimirse. Por eso se cuenta, no se pregunta por null.
     */
    public function hasMeterReadings(): bool
    {
        return $this->water_reading !== null
            || $this->energy_reading !== null
            || $this->gas_reading !== null;
    }

    public function isSigned(): bool
    {
        return $this->signed_at !== null;
    }
}