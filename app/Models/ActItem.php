<?php

namespace App\Models;

use App\Contracts\Prototype;
use App\Enums\ActItemState;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActItem extends Model implements Prototype
{
    /** @use HasFactory<\Database\Factories\ActItemFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'space',
        'space_id',
        'name',
        'state',
        'note',
        'photo_path',
        'delivery_act_id',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'state' => ActItemState::class,
    ];

    public function deliveryAct(): BelongsTo
    {
        return $this->belongsTo(DeliveryAct::class);
    }

    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class);
    }

    /**
     * Prototype: la copia conserva el contenido (espacio, elemento, estado,
     * nota y foto) y descarta la identidad: id, existencia y las llaves que la
     * ataban al acta original. La resolucion del espacio por nombre la hace el
     * Builder al guardar, no aqui.
     */
    public function __clone(): void
    {
        $this->id = null;
        $this->exists = false;
        $this->delivery_act_id = null;
        $this->space_id = null;
    }
}
