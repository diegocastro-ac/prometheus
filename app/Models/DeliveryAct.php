<?php

namespace App\Models;

use App\Contracts\Prototype;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeliveryAct extends Model implements Prototype
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
            ->load('space')
            ->sortBy(fn ($item) => $item->space?->name ?? $item->space)
            ->groupBy(fn ($item) => $item->space?->name ?? $item->space)
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
        // Se considera firmada si tiene ambas firmas subidas O si tiene fecha de firma
        return ($this->landlord_signature_path !== null && $this->tenant_signature_path !== null)
            || $this->signed_at !== null;
    }

    /**
     * Prototype: copia en profundidad. El inventario es lo caro de rehacer, asi
     * que cada fila se clona por separado; si no, la copia seguira apuntando a
     * la misma coleccion que el acta original.
     *
     * Se limpia lo que describe un evento concreto: identidad, firmas,
     * lecturas, compromisos y programacion. La fecha de visita pasa a ser hoy.
     */
    public function __clone(): void
    {
        // Se leen antes de borrar el id: es la llave con la que carga la
        // relacion, y sin ella la coleccion llegaria vacia.
        $items = $this->items
            ->map(fn (ActItem $item): ActItem => clone $item)
            ->values();

        $this->id = null;
        $this->exists = false;

        $this->scheduled_at = null;
        $this->water_reading = null;
        $this->energy_reading = null;
        $this->gas_reading = null;
        $this->commitments = null;
        $this->observations = null;

        $this->landlord_signature_path = null;
        $this->tenant_signature_path = null;
        $this->signed_at = null;

        $this->occurred_at = today();

        $this->setRelation('items', $items);
    }
}
