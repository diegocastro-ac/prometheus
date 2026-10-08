<?php

namespace Tests\Feature;

use App\Contracts\Prototype;
use App\Enums\ActItemState;
use App\Models\ActItem;
use App\Models\DeliveryAct;
use App\Models\Rental;
use App\Models\User;
use App\Services\Acts\DeliveryActDraft;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

/**
 * El lado del Prototype que no depende de la pantalla: el clon en profundidad
 * de un acta y la tradaccion de esa copia al estado del formulario.
 *
 * El objetivo es que lo caro de rehacer (el inventario) se conserve y que todo
 * lo que describe el evento anterior (firmas, lecturas, fechas) no viaje.
 */
class DeliveryActPrototypeTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Rental $rental;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->rental = Rental::factory()->create(['user_id' => $this->user->id]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function sourceAct(array $overrides = []): DeliveryAct
    {
        $act = DeliveryAct::factory()->create(array_merge([
            'rental_id' => $this->rental->id,
            'user_id' => $this->user->id,
            'type' => 'recepcion',
            'landlord_name' => 'Ana Landlord',
            'landlord_document' => '111',
            'tenant_name' => 'Luis Tenant',
            'tenant_document' => '222',
            'occurred_at' => '2026-01-15',
        ], $overrides));

        ActItem::factory()->create([
            'delivery_act_id' => $act->id,
            'space' => 'Cocina',
            'name' => 'Refrigerador',
            'state' => ActItemState::REPARABLE,
            'note' => 'Gotea',
            'photo_path' => 'act-items/refrigerador.jpg',
        ]);

        ActItem::factory()->create([
            'delivery_act_id' => $act->id,
            'space' => 'Sala',
            'name' => 'Televisor',
            'state' => ActItemState::NUEVO,
            'note' => null,
            'photo_path' => null,
        ]);

        return $act->load('items');
    }

    #[Test]
    public function la_copia_es_profunda_y_el_original_queda_intacto(): void
    {
        $act = $this->sourceAct();

        $clone = clone $act;

        $this->assertNull($clone->id);
        $this->assertFalse($clone->exists);
        $this->assertCount(2, $clone->items);

        // Cada fila es un objeto distinto, sin identidad propia.
        foreach ($clone->items as $index => $item) {
            $this->assertNotSame($act->items[$index], $item);
            $this->assertInstanceOf(ActItem::class, $item);
            $this->assertNull($item->id);
            $this->assertFalse($item->exists);
            $this->assertNull($item->delivery_act_id);
            $this->assertNull($item->space_id);
        }

        // El acta original no pierde nada de lo suyo.
        $this->assertNotNull($act->items[0]->id);
        $this->assertSame($act->id, $act->items[0]->delivery_act_id);
        $this->assertCount(2, $act->items);

        // Y el contenido, que es lo que se quiere conservar, viaja completo.
        $this->assertSame('Cocina', $clone->items[0]->space);
        $this->assertSame('Refrigerador', $clone->items[0]->name);
        $this->assertSame(ActItemState::REPARABLE, $clone->items[0]->state);
        $this->assertSame('Gotea', $clone->items[0]->note);
        $this->assertSame('act-items/refrigerador.jpg', $clone->items[0]->photo_path);
    }

    #[Test]
    public function la_copia_conserva_el_inventario_y_descarta_el_evento_anterior(): void
    {
        $act = $this->sourceAct([
            'scheduled_at' => now()->addDay()->setTime(14, 30),
            'water_reading' => '150',
            'energy_reading' => '2000',
            'gas_reading' => '45',
            'commitments' => 'Reparar la gotera antes del 20.',
            'observations' => 'Se revisa el patio.',
            'landlord_signature_path' => 'signatures/landlord.png',
            'tenant_signature_path' => 'signatures/tenant.png',
            'signed_at' => now(),
        ]);

        $clone = clone $act;

        // Se limpia: es otro evento.
        $this->assertNull($clone->scheduled_at);
        $this->assertNull($clone->water_reading);
        $this->assertNull($clone->energy_reading);
        $this->assertNull($clone->gas_reading);
        $this->assertFalse($clone->hasMeterReadings());
        $this->assertNull($clone->commitments);
        $this->assertNull($clone->observations);
        $this->assertNull($clone->landlord_signature_path);
        $this->assertNull($clone->tenant_signature_path);
        $this->assertNull($clone->signed_at);
        $this->assertFalse($clone->isSigned());

        // Se redefine: la visita es de hoy.
        $this->assertSame(today()->toDateString(), $clone->occurred_at->toDateString());

        // Se conserva: la estructura del acta y todo el inventario.
        $this->assertSame($act->rental_id, $clone->rental_id);
        $this->assertSame($act->user_id, $clone->user_id);
        $this->assertSame('recepcion', $clone->type);
        $this->assertSame('Ana Landlord', $clone->landlord_name);
        $this->assertSame('111', $clone->landlord_document);
        $this->assertSame('Luis Tenant', $clone->tenant_name);
        $this->assertSame('222', $clone->tenant_document);
        $this->assertCount(2, $clone->items);

        // Y el original sigue firmado y con sus lecturas.
        $this->assertTrue($act->isSigned());
        $this->assertTrue($act->hasMeterReadings());
        $this->assertSame('2026-01-15', $act->occurred_at->toDateString());
    }

    #[Test]
    public function tocar_la_copia_no_alcanza_al_original(): void
    {
        $act = $this->sourceAct();

        $clone = clone $act;
        $clone->items[0]->name = 'Congelador';
        $clone->items[1]->photo_path = 'act-items/otra.jpg';

        $this->assertSame('Refrigerador', $act->items[0]->name);
        $this->assertNull($act->items[1]->photo_path);
    }

    #[Test]
    public function el_borrador_le_pide_el_clon_al_prototipo_y_no_lo_muta(): void
    {
        $act = $this->sourceAct([
            'water_reading' => '150',
            'commitments' => 'Reparar la gotera antes del 20.',
        ]);

        $state = (new DeliveryActDraft($act))->formState();

        // El estado usa los nombres exactos del formulario del resource.
        $this->assertSame([
            'rental_id',
            'type',
            'landlord_name',
            'landlord_document',
            'tenant_name',
            'tenant_document',
            'occurred_at',
            'scheduled_at',
            'water_reading',
            'energy_reading',
            'gas_reading',
            'items',
            'commitments',
            'observations',
            'landlord_signature_path',
            'tenant_signature_path',
            'signed_at',
        ], array_keys($state));

        $this->assertSame($act->rental_id, $state['rental_id']);
        $this->assertSame('recepcion', $state['type']);
        $this->assertSame('Ana Landlord', $state['landlord_name']);
        $this->assertSame(today()->toDateString(), $state['occurred_at']);
        $this->assertNull($state['water_reading']);
        $this->assertNull($state['commitments']);
        $this->assertNull($state['landlord_signature_path']);
        $this->assertNull($state['signed_at']);

        $this->assertCount(2, $state['items']);
        $this->assertSame([
            'space' => 'Cocina',
            'name' => 'Refrigerador',
            'state' => 'reparable',
            'note' => 'Gotea',
            'photo_path' => 'act-items/refrigerador.jpg',
        ], $state['items'][0]);

        // El cliente trabaja sobre una copia: el prototipo no cambia.
        $this->assertSame('150', $act->water_reading);
        $this->assertSame('Reparar la gotera antes del 20.', $act->commitments);
        $this->assertSame('2026-01-15', $act->occurred_at->toDateString());
    }

    #[Test]
    public function los_prototipos_anuncian_la_copia_por_interfaz(): void
    {
        $act = $this->sourceAct();

        $this->assertInstanceOf(Prototype::class, $act);
        $this->assertInstanceOf(Prototype::class, $act->items[0]);

        $methods = (new ReflectionClass(Prototype::class))->getMethods();

        $this->assertCount(1, $methods);
        $this->assertSame('__clone', $methods[0]->getName());
    }
}
