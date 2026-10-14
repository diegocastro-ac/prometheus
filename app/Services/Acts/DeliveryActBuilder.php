<?php

namespace App\Services\Acts;

use App\Enums\ActItemState;
use App\Models\DeliveryAct;
use App\Models\Rental;
use App\Models\Space;
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

    /*
    | Sin dependencias inyectadas.
    |
    | Antes recibía AppSettings, el único uso que tenía era leer el catálogo de
    | espacios. Con el catálogo en `spaces` ya no necesita nada del contenedor: el
    | constructor vacío es la señal de que el Builder no depende del contexto, que
    | es lo que hace que una prueba pueda ejercitarlo sin montar la aplicación.
    */
    public function __construct() {}

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

            $this->assertSpaceIsCatalogued($space, $this->catalogOwnerId());

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
            // El catálogo de espacios es POR ARRENDADOR, asi que se resuelve con
            // el dueño del acta y no con quien este autenticado en este momento.
            //
            // Son la misma persona en el uso normal de la pantalla, pero no en una
            // prueba, ni en un comando de consola, ni en un trabajo en cola. Con
            // auth()->id() el acta se guardaba con espacios de null, que es
            // exactamente el dato que despues no se puede recuperar.
            $ownerId = (int) $act->user_id;

            $itemsWithSpaceId = array_map(function (array $item) use ($ownerId): array {
                $space = Space::query()
                    ->where('user_id', $ownerId)
                    ->where('name', $item['space'])
                    ->first();

                $item['space_id'] = $space?->id;

                return $item;
            }, $this->items);

            $act->items()->createMany($itemsWithSpaceId);
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

    /**
     * Comprueba que el espacio exista en el catálogo del arrendador.
     *
     * ANTES este metodo CREABA el espacio si no existia. Eso era un error con dos
     * caras: por un lado llenaba el catalogo de basura con cada palabra mal
     * escrita, y por otro lo hacia sin dueno cuando no habia sesion, que es lo
     * que reventaba la columna user_id.
     *
     * El catálogo es vocabulario del arrendador, no un registro que se
     * improvise. Si "Bodega" no esta en el catalogo, el dato correcto es avisar:
     * puede ser que el espacio se llame de otra forma, y una acta con espacios
     * inventados no sirve para comparar el estado de entrega con un inventario
     * posterior.
     *
     * Ahora el aviso dice CREAR el espacio, no "agregalo en Ajustes": el
     * formulario del inventario tiene un boton para eso, y enviar al usuario a
     * otra pantalla a(configurar un dato que necesita ahora es la razon por la
     * que antes no lo hacia.
     *
     * @param  int  $ownerId  el arrendador dueno del catalogo.
     */
    private function assertSpaceIsCatalogued(string $space, int $ownerId): void
    {
        $catalog = Space::query()
            ->where('user_id', $ownerId)
            ->orderBy('name')
            ->pluck('name');

        if ($catalog->isEmpty()) {
            throw new RuntimeException(__('act.errors.no_spaces'));
        }

        $normalised = array_map(
            static fn (string $entry): string => mb_strtolower(trim($entry)),
            $catalog->all(),
        );

        if (! in_array(mb_strtolower($space), $normalised, true)) {
            throw new RuntimeException(__('act.errors.space_not_in_catalog', [
                'space' => $space,
                'catalog' => implode(', ', $catalog->all()),
            ]));
        }
    }
}
