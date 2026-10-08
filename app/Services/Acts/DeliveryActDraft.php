<?php

namespace App\Services\Acts;

use App\Models\ActItem;
use App\Models\DeliveryAct;

/**
 * Client (GoF): nunca construye un acta con veinte argumentos. Le pide al
 * prototipo un clone y traduce esa copia al estado que espera el formulario de
 * creacion. Quien persiste sigue siendo el DeliveryActBuilder de siempre.
 */
class DeliveryActDraft
{
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
                    'space' => $item->space,
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
}
