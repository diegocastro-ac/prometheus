<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use RuntimeException;

class Space extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'user_id',
        'parent_id',
    ];

    protected static function booted(): void
    {
        static::saving(function (Space $space): void {
            $space->assertSiblingNameIsUnique();
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ActItem::class);
    }

    /**
     * La ruta desde la raiz hasta este espacio.
     *
     * Se calcula subiendo por los padres en vez de guardar una columna con la
     * ruta completa: un espacio se renombra o se mueve, y una ruta duplicada
     * quedaria obsoleta en cada fila del inventario.
     */
    public function path(string $separator = ' / '): string
    {
        $names = [];
        $seen = [];
        $node = $this;

        while ($node instanceof self) {
            $key = $node->getKey();

            if ($key !== null && isset($seen[$key])) {
                break;
            }

            if ($key !== null) {
                $seen[$key] = true;
            }

            array_unshift($names, $node->name);
            $node = $node->parent;
        }

        return implode($separator, $names);
    }

    /**
     * @return Collection<int, Space>
     */
    public function descendants(): Collection
    {
        $seen = [];

        if ($this->getKey() !== null) {
            $seen[$this->getKey()] = true;
        }

        return $this->collectDescendants($seen);
    }

    /**
     * @param  array<int|string, bool>  $seen
     * @return Collection<int, Space>
     */
    private function collectDescendants(array &$seen): Collection
    {
        $descendants = collect();

        foreach ($this->children()->get() as $child) {
            if (isset($seen[$child->getKey()])) {
                continue;
            }

            $seen[$child->getKey()] = true;
            $descendants->push($child);
            $descendants->push(...$child->collectDescendants($seen));
        }

        return $descendants;
    }

    public function asComponent(?Collection $items = null): SpaceComponent
    {
        $children = $this->children()
            ->orderBy('name')
            ->get()
            ->map(fn (Space $child): SpaceComponent => $child->asComponent())
            ->values()
            ->all();

        return $children === []
            ? new SpaceLeaf($this, $items ?? collect())
            : new SpaceComposite($this, $children, $items ?? collect());
    }

    public function canBeParentedBy(?int $parentId): bool
    {
        if ($parentId === null) {
            return true;
        }

        if ($parentId === $this->getKey()) {
            return false;
        }

        $parent = self::query()
            ->where('user_id', $this->user_id)
            ->find($parentId);

        if ($parent === null) {
            return false;
        }

        return ! $this->descendants()->contains(
            fn (Space $descendant): bool => $descendant->getKey() === $parentId,
        );
    }

    public function moveTo(?int $parentId): self
    {
        if (! $this->canBeParentedBy($parentId)) {
            throw new RuntimeException(__('act.errors.invalid_space_parent'));
        }

        $this->parent_id = $parentId;
        $this->save();

        return $this;
    }

    /**
     * La base de datos no puede impedir dos raices con el mismo nombre porque
     * en un indice unico los NULL no colisionan entre si. La regla se refuerza
     * aqui, que es el unico camino de escritura del modelo.
     */
    private function assertSiblingNameIsUnique(): void
    {
        $query = self::query()
            ->where('user_id', $this->user_id)
            ->where('name', $this->name);

        if ($this->parent_id === null) {
            $query->whereNull('parent_id');
        } else {
            $query->where('parent_id', $this->parent_id);
        }

        if ($this->exists) {
            $query->whereKeyNot($this->getKey());
        }

        if ($query->exists()) {
            throw new RuntimeException(__('act.errors.space_duplicate'));
        }
    }
}
