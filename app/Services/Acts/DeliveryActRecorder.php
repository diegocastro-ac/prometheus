<?php

namespace App\Services\Acts;

use App\Enums\ActItemState;
use App\Models\DeliveryAct;
use App\Models\Rental;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use RuntimeException;

/**
 * Persiste un acta de entrega/recepcion a partir de datos de formulario.
 *
 * Es la unica fuente de las reglas de ensamblado: la pantalla Filament
 * (CreateDeliveryAct) y el asistente de chat pasan por aqui, asi el Builder
 * recibe exactamente las mismas secciones opcionales en ambos caminos y no
 * hay dos interpretaciones del mismo formulario.
 *
 * El alquiler se resuelve con alcance por usuario: un rental_id ajeno no
 * encuentra registro y falla en vez de crear un acta sobre datos ajenos.
 */
class DeliveryActRecorder
{
    /**
     * @param  array<string, mixed>  $data  Estado del formulario del acta.
     *
     * @throws RuntimeException  Reglas del Builder (espacio fuera de catalogo,
     *                           dato obligatorio faltante, etc.).
     * @throws ModelNotFoundException  El alquiler no existe para este usuario.
     */
    public function record(array $data, int $userId): DeliveryAct
    {
        $rental = Rental::query()
            ->where('user_id', $userId)
            ->findOrFail((int) ($data['rental_id'] ?? 0));

        $builder = app(DeliveryActBuilder::class)
            ->forRental($rental, (string) $data['landlord_name'], (string) $data['tenant_name'])
            ->ofType((string) ($data['type'] ?? 'entrega'))
            ->onDate(
                Carbon::parse((string) $data['occurred_at']),
                $this->blankToNull($data['scheduled_at'] ?? null),
            )
            ->withParties([
                'landlord_document' => $this->blankToNull($data['landlord_document'] ?? null),
                'tenant_document' => $this->blankToNull($data['tenant_document'] ?? null),
            ]);

        $readings = array_filter([
            'water' => $this->blankToNull($data['water_reading'] ?? null),
            'energy' => $this->blankToNull($data['energy_reading'] ?? null),
            'gas' => $this->blankToNull($data['gas_reading'] ?? null),
        ], fn ($value): bool => $value !== null);

        if ($readings !== []) {
            $builder = $builder->withMeterReadings($readings);
        }

        $items = array_values(array_filter(
            (array) ($data['items'] ?? []),
            fn (array $item): bool => $this->blankToNull($item['master_inventory_item_id'] ?? null) !== null
                || trim((string) ($item['name'] ?? '')) !== '',
        ));

        if ($items !== []) {
            $builder = $builder->withInventory(array_map(fn (array $item): array => [
                'space_id' => $item['space_id'] ?? null,
                'space' => $item['space'] ?? null,
                'master_inventory_item_id' => $item['master_inventory_item_id'] ?? null,
                'name' => $item['name'] ?? '',
                'state' => $this->blankToNull($item['state'] ?? null) ?? ActItemState::BUENO->value,
                'note' => $this->blankToNull($item['note'] ?? null),
                'photo_path' => $this->firstPath($item['photo_path'] ?? null),
            ], $items));
        }

        $commitments = $this->blankToNull($data['commitments'] ?? null);
        $observations = $this->blankToNull($data['observations'] ?? null);

        if ($commitments !== null || $observations !== null) {
            $builder = $builder->withCommitments($commitments, $observations);
        }

        $landlordSignature = $this->firstPath($data['landlord_signature_path'] ?? null);
        $tenantSignature = $this->firstPath($data['tenant_signature_path'] ?? null);
        $signedAt = $this->blankToNull($data['signed_at'] ?? null);

        if ($landlordSignature !== null || $tenantSignature !== null) {
            $builder = $builder->withSignatures(
                $landlordSignature,
                $tenantSignature,
                $signedAt !== null ? Carbon::parse($signedAt) : null,
            );
        }

        return $builder->build();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function missingRequired(array $data): array
    {
        $missing = [];

        foreach (['rental_id', 'landlord_name', 'tenant_name', 'occurred_at', 'type'] as $field) {
            $value = $this->blankToNull($data[$field] ?? null);

            if ($value === null) {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    private function blankToNull(mixed $value): mixed
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        return $value;
    }

    private function firstPath(mixed $value): ?string
    {
        if (is_array($value)) {
            return $value[0] ?? null;
        }

        return $this->blankToNull($value);
    }
}
