<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Property extends Model
{
    /** @use HasFactory<\Database\Factories\PropertyFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'description',
        'address',
        'geography_code',
        'user_id',
    ];

    /**
     * Get the user that owns the property.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get all rentals associated with the property.
     */
    public function rentals(): HasMany
    {
        return $this->hasMany(Rental::class);
    }

    /**
     * La ciudad donde esta el inmueble.
     *
     * Vive en la propiedad y no en el alquiler, porque la ubicacion es del
     * inmueble: un mismo edificio puede tener varios contratos y todos son la
     * misma ciudad. Guardarla en el alquiler obligaria a repetirla en cada
     * contrato y abriria la posibilidad de que dos contratos del mismo edificio
     * se contradigan.
     */
    public function geography(): BelongsTo
    {
        return $this->belongsTo(Geography::class, 'geography_code', 'code');
    }

    /**
     * La ciudad solo si tiene IPC disponible.
     *
     * La distincion importa. La propiedad puede estar en cualquier ciudad del
     * catalogo, pero el IPC por ciudad solo existe para las 23 que el DANE
     * publica. Un null aqui significa "no hay serie para esta ciudad", y quien
     * lo consume debe poder distinguir eso de "la propiedad no tiene ciudad".
     */
    public function geographyWithIpc(): ?Geography
    {
        $geography = $this->geography;

        return $geography?->has_ipc === true ? $geography : null;
    }
}
