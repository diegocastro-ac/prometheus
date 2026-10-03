<?php

namespace App\Models;

use App\Enums\ActItemState;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActItem extends Model
{
    /** @use HasFactory<\Database\Factories\ActItemFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'space',
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
}