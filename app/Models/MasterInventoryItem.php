<?php

namespace App\Models;

use App\Contracts\InventoryItemFlyweight;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MasterInventoryItem extends Model implements InventoryItemFlyweight
{
    /** @use HasFactory<\Database\Factories\MasterInventoryItemFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'name',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function actItems(): HasMany
    {
        return $this->hasMany(ActItem::class);
    }

    public function id(): int|string|null
    {
        return $this->getKey();
    }

    public function name(): string
    {
        return (string) $this->getAttribute('name');
    }

    public function ownerId(): int|string|null
    {
        return $this->getAttribute('user_id');
    }
}
