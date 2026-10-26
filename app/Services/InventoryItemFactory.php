<?php

namespace App\Services;

use App\Contracts\InventoryItemFlyweight;
use App\Models\MasterInventoryItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Fabrica y pool de flyweights de inventario.
 *
 * El catalogo se carga una vez por arrendador y por peticion. Dos actas que
 * usan el mismo elemento reciben la misma instancia de `MasterInventoryItem`,
 * no dos copias con el mismo contenido. El estado propio de cada acta (espacio,
 * estado, nota, foto) no vive aqui.
 *
 * Es scoped: al terminar la peticion el pool se descarta y no se comparte entre
 * usuarios ni entre procesos.
 */
final class InventoryItemFactory
{
    /**
     * @var array<int, Collection<int|string, MasterInventoryItem>>
     */
    private array $catalogs = [];

    /**
     * @var array<int, array<string, MasterInventoryItem>>
     */
    private array $byName = [];

    /**
     * @return Collection<int|string, MasterInventoryItem>
     */
    public function all(int $ownerId): Collection
    {
        return $this->catalog($ownerId);
    }

    /**
     * @return array<int, string>
     */
    public function options(int $ownerId): array
    {
        return $this->all($ownerId)
            ->sortBy(fn (MasterInventoryItem $item): string => $item->name())
            ->mapWithKeys(fn (MasterInventoryItem $item): array => [
                (int) $item->id() => $item->name(),
            ])
            ->all();
    }

    public function find(int $ownerId, mixed $id): ?MasterInventoryItem
    {
        if ($id === null || $id === '' || $id === []) {
            return null;
        }

        return $this->catalog($ownerId)->first(
            fn (MasterInventoryItem $item): bool => (string) $item->id() === (string) $id,
        );
    }

    public function findByName(int $ownerId, string $name): ?MasterInventoryItem
    {
        $normalised = $this->normalise($name);

        if ($normalised === '') {
            return null;
        }

        $this->catalog($ownerId);

        return $this->byName[$ownerId][$normalised] ?? null;
    }

    /**
     * Resuelve la referencia nueva por id o la antigua por nombre.
     *
     * Un id de otro arrendador no se acepta aunque el nombre coincida: la
     * referencia tiene que apuntar a una fila del catalogo del dueno del acta.
     */
    public function resolve(int $ownerId, mixed $id, ?string $name = null): InventoryItemFlyweight
    {
        if ($id !== null && $id !== '' && $id !== []) {
            $item = $this->find($ownerId, $id);

            if ($item instanceof MasterInventoryItem) {
                return $item;
            }

            $this->throwNotInCatalog($ownerId, $id, trim((string) $name));
        }

        $name = trim((string) $name);

        if ($name === '') {
            throw new RuntimeException(__('act.errors.master_item_name_required'));
        }

        return $this->findByName($ownerId, $name) ?? $this->firstOrCreate($ownerId, $name);
    }

    public function firstOrCreate(int $ownerId, string $name): MasterInventoryItem
    {
        $name = trim($name);

        if ($name === '') {
            throw new RuntimeException(__('act.errors.master_item_name_required'));
        }

        $existing = $this->findByName($ownerId, $name);

        if ($existing instanceof MasterInventoryItem) {
            return $existing;
        }

        $existing = $this->queryByName($ownerId, $name)->first();

        if ($existing instanceof MasterInventoryItem) {
            return $this->remember($existing);
        }

        try {
            $item = MasterInventoryItem::query()->create([
                'user_id' => $ownerId,
                'name' => $name,
            ]);
        } catch (QueryException $exception) {
            // Otra peticion pudo crear el mismo nombre entre la consulta y el
            // insert. Se reutiliza la fila ganadora en vez de mostrar un error.
            $existing = $this->queryByName($ownerId, $name)->first();

            if (! $existing instanceof MasterInventoryItem) {
                throw $exception;
            }

            return $this->remember($existing);
        }

        return $this->remember($item);
    }

    public function updateName(MasterInventoryItem $item, string $name): MasterInventoryItem
    {
        $name = trim($name);

        if ($name === '') {
            throw new RuntimeException(__('act.errors.master_item_name_required'));
        }

        $ownerId = (int) $item->ownerId();
        $previousName = $item->name();

        $duplicate = $this->queryByName($ownerId, $name)
            ->whereKeyNot($item->getKey())
            ->exists();

        if ($duplicate) {
            throw new RuntimeException(__('act.errors.master_item_duplicate'));
        }

        $item->update(['name' => $name]);

        unset($this->byName[$ownerId][$this->normalise($previousName)]);
        $this->byName[$ownerId][$this->normalise($name)] = $item;

        return $item;
    }

    /**
     * @return Collection<int|string, MasterInventoryItem>
     */
    private function catalog(int $ownerId): Collection
    {
        if (isset($this->catalogs[$ownerId])) {
            return $this->catalogs[$ownerId];
        }

        $items = MasterInventoryItem::query()
            ->where('user_id', $ownerId)
            ->orderBy('name')
            ->get()
            ->keyBy(fn (MasterInventoryItem $item): int|string => $item->id());

        $this->catalogs[$ownerId] = $items;
        $this->byName[$ownerId] = [];

        foreach ($items as $item) {
            $this->byName[$ownerId][$this->normalise($item->name())] = $item;
        }

        return $items;
    }

    private function remember(MasterInventoryItem $item): MasterInventoryItem
    {
        $ownerId = (int) $item->ownerId();

        $this->catalog($ownerId);
        $this->catalogs[$ownerId]->put($item->id(), $item);
        $this->byName[$ownerId][$this->normalise($item->name())] = $item;

        return $item;
    }

    private function queryByName(int $ownerId, string $name): Builder
    {
        return MasterInventoryItem::query()
            ->where('user_id', $ownerId)
            ->whereRaw('LOWER(name) = ?', [$this->normalise($name)]);
    }

    private function throwNotInCatalog(int $ownerId, mixed $id, string $name): never
    {
        $catalog = $this->all($ownerId)
            ->map(fn (MasterInventoryItem $item): string => $item->name())
            ->sort()
            ->values()
            ->implode(', ');

        throw new RuntimeException(__('act.errors.master_item_not_in_catalog', [
            'item' => $name !== '' ? $name : '#'.$id,
            'catalog' => $catalog !== '' ? $catalog : __('act.errors.master_catalog_empty'),
        ]));
    }

    private function normalise(string $name): string
    {
        return mb_strtolower(trim($name));
    }
}
