<?php

namespace App\Filament\Resources\DeliveryActResource\Pages;

use App\Enums\ActItemState;
use App\Filament\Resources\DeliveryActResource;
use App\Models\DeliveryAct;
use App\Models\Rental;
use App\Services\Acts\DeliveryActBuilder;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * La creacion del acta no la hace Filament directamente: pasa por el Builder.
 *
 * Solo se invocan los metodos with... de las secciones que la pantalla realmente
 * lleno. Si el arrendador dejo vacias las lecturas, el Builder nunca recibe esa
 * seccion y el acta se guarda sin ella.
 */
class CreateDeliveryAct extends CreateRecord
{
    protected static string $resource = DeliveryActResource::class;

    protected static ?string $title = null;

    public function getTitle(): string
    {
        return __('act.buttons.create');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): DeliveryAct
    {
        try {
            $act = $this->assemble($data);
        } catch (RuntimeException $exception) {
            // El Builder lanza aqui sus reglas de negocio: espacio fuera del
            // catalogo, elemento sin nombre, dato obligatorio faltante. Se
            // avisa en pantalla en vez de mostrar una pantalla de error 500.
            Notification::make()
                ->danger()
                ->title($exception->getMessage())
                ->send();

            $this->halt();
        }

        Notification::make()
            ->success()
            ->title(__('act.created'))
            ->send();

        return $act;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assemble(array $data): DeliveryAct
    {
        $rental = Rental::findOrFail($data['rental_id']);

        $builder = app(DeliveryActBuilder::class)
            ->forRental($rental, $data['landlord_name'], $data['tenant_name'])
            ->ofType($data['type'])
            ->onDate(
                Carbon::parse($data['occurred_at']),
                $this->blankToNull($data['scheduled_at'] ?? null),
            )
            ->withParties([
                'landlord_document' => $this->blankToNull($data['landlord_document'] ?? null),
                'tenant_document' => $this->blankToNull($data['tenant_document'] ?? null),
            ]);

        // Seccion de medidores: solo si el arrendador escribio al menos uno.
        $readings = array_filter([
            'water' => $this->blankToNull($data['water_reading'] ?? null),
            'energy' => $this->blankToNull($data['energy_reading'] ?? null),
            'gas' => $this->blankToNull($data['gas_reading'] ?? null),
        ], fn ($value): bool => $value !== null);

        if ($readings !== []) {
            $builder = $builder->withMeterReadings($readings);
        }

        // Seccion de inventario: solo si hay elementos con nombre.
        $items = array_values(array_filter(
            (array) ($data['items'] ?? []),
            fn (array $item): bool => trim((string) ($item['name'] ?? '')) !== '',
        ));

        if ($items !== []) {
            $builder = $builder->withInventory(array_map(fn (array $item): array => [
                'space' => $item['space'] ?? '',
                'name' => $item['name'] ?? '',
                'state' => $this->blankToNull($item['state'] ?? null) ?? ActItemState::BUENO->value,
                'note' => $this->blankToNull($item['note'] ?? null),
                'photo_path' => $this->firstPath($item['photo_path'] ?? null),
            ], $items));
        }

        // Seccion de compromisos: solo si hay texto.
        $commitments = $this->blankToNull($data['commitments'] ?? null);
        $observations = $this->blankToNull($data['observations'] ?? null);

        if ($commitments !== null || $observations !== null) {
            $builder = $builder->withCommitments($commitments, $observations);
        }

        // Seccion de firmas: solo si hay al menos una firma.
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
     * Los campos de archivo y los select vacios llegan como '' o [] y el Builder
     * los trata como secciones ausentes, no como secciones en blanco.
     */
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

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}