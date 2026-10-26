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
        'master_inventory_item_id',
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
        return $this->belongsTo(Space::class, 'space_id');
    }

    /**
     * El nombre `space` ya lo ocupa el snapshot historico. En Eloquent una
     * columna con ese nombre gana sobre el metodo al leer `$item->space`, asi
     * que el codigo nuevo usa este alias para acceder al nodo del arbol.
     */
    public function spaceNode(): BelongsTo
    {
        return $this->space();
    }

    public function masterItem(): BelongsTo
    {
        return $this->belongsTo(MasterInventoryItem::class, 'master_inventory_item_id');
    }

    /**
     * Prototype: la copia conserva el contenido (espacio, elemento, estado,
     * nota y foto) y descarta la identidad: id, existencia y la llave que la
     * ataba al acta original. `space_id` y `master_inventory_item_id` se
     * conservan porque son referencias del inventario, no la identidad del
     * evento; el nombre sigue siendo el snapshot que se imprime.
     */
    public function __clone(): void
    {
        $this->id = null;
        $this->exists = false;
        $this->delivery_act_id = null;
    }
}
