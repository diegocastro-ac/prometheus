<?php

namespace App\Services\Acts;

use App\Enums\ActItemState;
use App\Models\DeliveryAct;
use App\Models\Rental;
use App\Models\Space;
use App\Settings\AppSettings;
use RuntimeException;

/**
 * Builder del acta de entrega y recepcion.
 *
 * El acta tiene secciones obligatorias (partes, fecha) y secciones opcionales
 * (medidores, inventario, compromisos, firmas). Este Builder existe para que
 * quien arma el acta decida cuales existen, en lugar de pasar veinte parametros
 * a un constructor y tener que distinguir "vacio" de "no aplica".
 *
 * Cada metodo with... registra una seccion. build() solo persiste las
 * secciones que se registraron: las demas quedan en null y no se imprimen.
 * Los metodos devuelven $this para poder encadenarlos.
 */
class DeliveryActBuilder
{
    /**
     * Secciones opcionales que se han pedido, aun no guardadas.
     *
     * @var array<string, mixed>
     */
    private array $sections = [];

    /**
     * @var list<array<string, mixed>>
     */
    private array $items = [];

    private bool $inventoryRequested = false;

    public function __construct(
        private readonly AppSettings $settings,
    ) {}

    /**
     * Datos obligatorios: el alquiler y la fecha de la visita. El Builder los
     * deduce del alquiler en vez de pedirlos, para que la pantalla no pueda
     * escribir el nombre de un arrendatario que no corresponde.
     */
    public function forRental(Rental $rental, string $landlordName, string $tenantName): self
    {
        $this->sections['rental_id'] = $rental->id;
        $this->sections['user_id'] = $rental->user_id;
        $this->sections['landlord_name'] = $landlordName;
        $this->sections['tenant_name'] = $tenantName;

        return $this;
    }

    public function ofType(string $type): self
    {
        $this->sections['type'] = $type;

        return $this;
    }

    public function onDate(\DateTimeInterface $occurredAt, ?string $scheduledAt = null): self
    {
        $this->sections['occurred_at'] = $occurredAt->format('Y-m-d');
        $this->sections['scheduled_at'] = $scheduledAt === null
            ? null
            : $occurredAt->format('Y-m-d').' '.$scheduledAt.':00';

        return $this;
    }

    /**
     * @param  array{landlord_document?: string|null, tenant_document?: string|null}  $documents
     */
    public function withParties(array $documents = []): self
    {
        $this->sections['landlord_document'] = $documents['landlord_document'] ?? null;
        $this->sections['tenant_document'] = $documents['tenant_document'] ?? null;

        return $this;
    }

    /**
     * Seccion de medidores. Si no se llama, el acta se imprime sin ella.
     * Los servicios no medidos se dejan en null y no aparecen en la tabla.
     *
     * @param  array{water?: string|null, energy?: string|null, gas?: string|null}  $readings
     */
    public function withMeterReadings(array $readings): self
    {
        $this->sections['water_reading'] = $readings['water'] ?? null;
        $this->sections['energy_reading'] = $readings['energy'] ?? null;
        $this->sections['gas_reading'] = $readings['gas'] ?? null;

        return $this;
    }

    /**
     * Seccion de inventario. Los espacios se validan contra el catalogo que
     * vive en AppSettings, que es el unico lugar donde se configura.
     *
     * @param  list<array{space: string, name: string, state?: string, note?: string|null, photo_path?: string|null}>  $items
     *
     * @throws RuntimeException
     */
    public function withInventory(array $items): self
    {
        $this->inventoryRequested = true;
        $this->items = [];

        foreach ($items as $item) {
            $space = trim((string) ($item['space'] ?? ''));
            $name = trim((string) ($item['name'] ?? ''));

            if ($space === '' || $name === '') {
                throw new RuntimeException('Cada elemento del inventario necesita un espacio y un nombre.');
            }

            $this->assertSpaceIsCatalogued($space);

            $state = ActItemState::tryFrom((string) ($item['state'] ?? ActItemState::BUENO->value))
                ?? ActItemState::BUENO;

            $this->items[] = [
                'space' => $space,
                'name' => $name,
                'state' => $state,
                'note' => $item['note'] ?? null,
                'photo_path' => $item['photo_path'] ?? null,
            ];
        }

        return $this;
    }

    public function withCommitments(?string $commitments, ?string $observations = null): self
    {
        $this->sections['commitments'] = $commitments;
        $this->sections['observations'] = $observations;

        return $this;
    }

    public function withSignatures(?string $landlordPath, ?string $tenantPath, ?\DateTimeInterface $signedAt = null): self
    {
        $this->sections['landlord_signature_path'] = $landlordPath;
        $this->sections['tenant_signature_path'] = $tenantPath;
        $this->sections['signed_at'] = $signedAt?->format('Y-m-d H:i:s');

        return $this;
    }

    /**
     * Ensambla el acta. Falla de forma explicita si falta lo obligatorio, en
     * lugar de guardar un acta a medio construir.
     */
    public function build(): DeliveryAct
    {
        foreach (['rental_id', 'landlord_name', 'tenant_name', 'occurred_at'] as $required) {
            $value = $this->sections[$required] ?? null;

            if ($value === null || (is_string($value) && trim($value) === '')) {
                throw new RuntimeException("El acta necesita el dato obligatorio [{$required}].");
            }
        }

        $act = new DeliveryAct($this->sections);

        if (! isset($act->type)) {
            $act->type = 'entrega';
        }

        $act->save();

        if ($this->inventoryRequested && $this->items !== []) {
            $itemsWithSpaceId = array_map(function ($item) {
                $space = Space::query()
                    ->where('user_id', auth()->id())
                    ->where('name', $item['space'])
                    ->first();

                $item['space_id'] = $space->id ?? null;
                return $item;
            }, $this->items);

            $act->items()->createMany($itemsWithSpaceId);
        }

        return $act->load('items');
    }

    /**
     * Asegura que el espacio exista en el catálogo. Si no existe, lo crea.
     */
    private function assertSpaceIsCatalogued(string $space): void
    {
        $existingSpace = Space::query()
            ->where('user_id', auth()->id())
            ->where('name', $space)
            ->first();

        if (!$existingSpace) {
            Space::query()->create([
                'name' => $space,
                'user_id' => auth()->id(),
            ]);
        }
    }
}