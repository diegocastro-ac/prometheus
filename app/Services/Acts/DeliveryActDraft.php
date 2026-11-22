<?php

namespace App\Services\Acts;

use App\Models\ActItem;
use App\Models\DeliveryAct;
use App\Models\MasterInventoryItem;
use App\Models\Space;

/**
 * Client (GoF): nunca construye un acta con veinte argumentos. Le pide al
 * prototipo un clone y traduce esa copia al estado que espera el formulario de
 * creacion. Quien persiste sigue siendo el DeliveryActBuilder de siempre.
 */
class DeliveryActDraft
{
    /**
     * @var array<string, int>|null
     */
    private ?array $spaceIdsByName = null;

    /**
     * @var array<string, int>|null
     */
    private ?array $masterItemIdsByName = null;

    public function __construct(private readonly DeliveryAct $source) {}

    /**
     * El estado del formulario de CreateDeliveryAct, con los nombres exactos
     * de los campos del resource. Lo que el clone dejo en null llega vacio y el
     * Builder lo tratara como seccion ausente, igual que si nadie lo escribio.
     *
     * @return array<string, mixed>
     */
    public function formState(): array
    {
        $act = clone $this->source;

        return [
            'rental_id' => $act->rental_id,
            'type' => $act->type,
            'landlord_name' => $act->landlord_name,
            'landlord_document' => $act->landlord_document,
            'tenant_name' => $act->tenant_name,
            'tenant_document' => $act->tenant_document,
            'occurred_at' => $act->occurred_at?->toDateString(),
            'scheduled_at' => $act->scheduled_at?->format('H:i'),
            'water_reading' => $act->water_reading,
            'energy_reading' => $act->energy_reading,
            'gas_reading' => $act->gas_reading,
            'items' => $act->items
                ->map(fn (ActItem $item): array => [
                    'space_id' => $this->spaceIdFor($item),
                    'master_inventory_item_id' => $this->masterItemIdFor($item),
                    'name' => $item->name,
                    'state' => $item->state?->value,
                    'note' => $item->note,
                    'photo_path' => $item->photo_path,
                ])
                ->values()
                ->all(),
            'commitments' => $act->commitments,
            'observations' => $act->observations,
            'landlord_signature_path' => $act->landlord_signature_path,
            'tenant_signature_path' => $act->tenant_signature_path,
            'signed_at' => $act->signed_at?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Las actas anteriores al arbol pueden no tener space_id. En ese caso se
     * resuelve por el nombre guardado para que el formulario pueda mostrar el
     * espacio en el selector nuevo.
     */
    private function spaceIdFor(ActItem $item): ?int
    {
        if ($item->space_id !== null) {
            return (int) $item->space_id;
        }

        $name = $this->normalise((string) $item->space);

        if ($name === '') {
            return null;
        }

        return $this->spaceIdsByName()[$name] ?? null;
    }

    /**
     * @return array<string, int>
     */
    private function spaceIdsByName(): array
    {
        if ($this->spaceIdsByName !== null) {
            return $this->spaceIdsByName;
        }

        $spaces = Space::query()
            ->where('user_id', $this->source->user_id)
            ->get()
            ->sortBy(fn (Space $space): int => $space->parent_id === null ? 0 : 1);

        $this->spaceIdsByName = [];

        foreach ($spaces as $space) {
            $this->spaceIdsByName[$this->normalise($space->name)] ??= (int) $space->getKey();
        }

        return $this->spaceIdsByName;
    }

    /**
     * Las actas anteriores al catalogo maestro pueden no tener la referencia.
     * Se resuelve por el nombre del elemento para que el selector nuevo muestre
     * algo conocido; si el nombre ya no existe, el usuario tendra que elegir o
     * crear uno al guardar.
     */
    private function masterItemIdFor(ActItem $item): ?int
    {
        if ($item->master_inventory_item_id !== null) {
            return (int) $item->master_inventory_item_id;
        }

        $name = $this->normalise((string) $item->name);

        if ($name === '') {
            return null;
        }

        return $this->masterItemIdsByName()[$name] ?? null;
    }

    /**
     * @return array<string, int>
     */
    private function masterItemIdsByName(): array
    {
        if ($this->masterItemIdsByName !== null) {
            return $this->masterItemIdsByName;
        }

        $this->masterItemIdsByName = [];

        MasterInventoryItem::query()
            ->where('user_id', $this->source->user_id)
            ->orderBy('name')
            ->get()
            ->each(function (MasterInventoryItem $item): void {
                $this->masterItemIdsByName[$this->normalise($item->name())] ??= (int) $item->id();
            });

        return $this->masterItemIdsByName;
    }

    private function normalise(string $name): string
    {
        return mb_strtolower(trim($name));
    }
}
