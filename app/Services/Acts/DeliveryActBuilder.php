<?php

namespace App\Services\Acts;

use App\Enums\ActItemState;
use App\Models\DeliveryAct;
use App\Models\Rental;
use App\Models\Space;
use App\Services\InventoryItemFactory;
use Illuminate\Support\Collection;
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

    private InventoryItemFactory $inventoryItems;

    /**
     * El pool de flyweights es opcional para que una prueba pueda construir el
     * Builder sin contenedor. En la aplicacion se inyecta la instancia scoped,
     * que es la que comparte el catalogo durante toda la peticion.
     */
    public function __construct(?InventoryItemFactory $inventoryItems = null)
    {
        $this->inventoryItems = $inventoryItems ?? app(InventoryItemFactory::class);
    }

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
     * Seccion de inventario. Los espacios se validan contra el catalogo del
     * ARRENDADOR, que vive en la tabla `spaces`.
     *
     * Antes se validaban contra `app_settings.space_catalog`, un ajuste global.
     * Eso obligaba a inventar dos cosas: la palabra "global" no era cierta (dos
     * arrendadores de la misma instalacion compartian el mismo vocabulario, cuando
     * cada uno tiene el suyo), y la palabra "ajustes" era el lugar equivocado
     * (un ajuste global no puede ser la fuente de verdad de un dato por usuario).
     *
     * Acepta `space_id`, que es la referencia que usa el formulario, y tambien
     * el nombre `space` de las actas y llamadas antiguas. En ambos casos el
     * espacio tiene que pertenecer al arrendador del acta.
     *
     * Para el elemento pasa igual: el formulario manda
     * `master_inventory_item_id`; el nombre suelto se conserva para las actas y
     * llamadas antiguas y se resuelve o crea en el catalogo maestro. El nombre
     * que se guarda en la fila es siempre el snapshot del flyweight resuelto.
     *
     * @param  list<array{space_id?: int|string|null, space?: string|null, master_inventory_item_id?: int|string|null, name?: string|null, state?: string, note?: string|null, photo_path?: string|null}>  $items
     *
     * @throws RuntimeException
     */
    public function withInventory(array $items): self
    {
        $this->inventoryRequested = true;
        $this->items = [];

        $ownerId = $this->catalogOwnerId();
        $catalog = $this->catalogFor($ownerId);

        if ($catalog->isEmpty()) {
            throw new RuntimeException(__('act.errors.no_spaces'));
        }

        foreach ($items as $item) {
            $spaceName = trim((string) ($item['space'] ?? ''));
            $spaceId = $this->blankToNull($item['space_id'] ?? null);
            $masterItemId = $this->blankToNull($item['master_inventory_item_id'] ?? null);
            $name = trim((string) ($item['name'] ?? ''));

            if ($spaceId === null && $spaceName === '') {
                throw new RuntimeException(__('act.errors.inventory_item_required'));
            }

            if ($masterItemId === null && $name === '') {
                throw new RuntimeException(__('act.errors.inventory_item_required'));
            }

            $space = $this->resolveSpace($catalog, $spaceId, $spaceName);
            $flyweight = $this->inventoryItems->resolve($ownerId, $masterItemId, $name);

            $state = ActItemState::tryFrom((string) ($item['state'] ?? ActItemState::BUENO->value))
                ?? ActItemState::BUENO;

            $this->items[] = [
                'master_inventory_item_id' => $flyweight->id(),
                'space_id' => $space->id,
                // Los nombres son snapshots: una comparacion futura no cambia
                // porque alguien renombre el catalogo de espacios o elementos.
                'space' => $space->name,
                'name' => $flyweight->name(),
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
            // Los espacios ya se resolvieron contra el catalogo del dueno del
            // acta en withInventory(). Guardar aqui no debe volver a buscarlos
            // por nombre: dos ramas del arbol pueden tener el mismo nombre.
            $act->items()->createMany($this->items);
        }

        return $act->load('items');
    }

    /**
     * El dueño del catálogo de espacios que corresponde a este builder.
     *
     * El Builder es scoped: hay uno por peticion, y se construye antes de saber
     * de que alquiler viene el acta. Por eso se resuelve en este orden:
     *
     *   1. el alquiler que ya se habia fijado con forRental(), que es la fuente
     *      real de verdad;
     *   2. la sesion, que es lo unico disponible si el inventario se cargo antes.
     *
     * Si no hay ninguna de las dos, el inventario no se puede validar contra un
     * catalogo y vale mas fallar con un mensaje claro que crear un espacio sin
     * dueno.
     */
    private function catalogOwnerId(): int
    {
        if (isset($this->sections['rental_id'])) {
            $ownerId = Rental::query()->whereKey($this->sections['rental_id'])->value('user_id');

            if ($ownerId !== null) {
                return (int) $ownerId;
            }
        }

        return (int) auth()->id();
    }

    private function catalogFor(int $ownerId): Collection
    {
        return Space::query()
            ->where('user_id', $ownerId)
            ->orderBy('name')
            ->get();
    }

    /**
     * Resuelve la referencia nueva (`space_id`) o la antigua (nombre).
     *
     * El formulario manda el id, que no se confunde aunque dos ramas del arbol
     * tengan un espacio con el mismo nombre. El nombre se conserva para las
     * actas y pruebas que llaman al Builder sin pasar por la pantalla.
     *
     * @param  Collection<int, Space>  $catalog
     */
    private function resolveSpace(Collection $catalog, mixed $spaceId, string $spaceName): Space
    {
        if ($spaceId !== null) {
            $space = $catalog->first(
                fn (Space $candidate): bool => (string) $candidate->getKey() === (string) $spaceId,
            );

            if ($space instanceof Space) {
                return $space;
            }

            $this->throwSpaceNotInCatalog($spaceName !== '' ? $spaceName : '#'.$spaceId, $catalog);
        }

        $normalised = mb_strtolower(trim($spaceName));

        $space = $catalog
            ->filter(fn (Space $candidate): bool => mb_strtolower(trim($candidate->name)) === $normalised)
            ->sortBy(fn (Space $candidate): string => $candidate->path())
            ->first();

        if ($space instanceof Space) {
            return $space;
        }

        $this->throwSpaceNotInCatalog($spaceName, $catalog);
    }

    /**
     * @param  Collection<int, Space>  $catalog
     */
    private function throwSpaceNotInCatalog(string $space, Collection $catalog): never
    {
        $paths = $catalog
            ->map(fn (Space $candidate): string => $candidate->path())
            ->sort()
            ->values()
            ->all();

        throw new RuntimeException(__('act.errors.space_not_in_catalog', [
            'space' => $space,
            'catalog' => implode(', ', $paths),
        ]));
    }

    private function blankToNull(mixed $value): mixed
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        return $value;
    }
}
