<?php

namespace Tests\Feature;

use App\Enums\ActItemState;
use App\Filament\Resources\DeliveryActResource\Pages\CreateDeliveryAct;
use App\Filament\Resources\DeliveryActResource\Pages\ListDeliveryActs;
use App\Models\DeliveryAct;
use App\Models\Rental;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Comprueba que la pantalla solo invoca las secciones que el arrendador lleno.
 * Si la pantalla mandara secciones vacias, el Builder las guardaria y el acta
 * imprimiria tablas que nunca se llenaron.
 */
class CreateDeliveryActTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Rental $rental;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('app_settings')->insert([
            'id' => 1,
            'business_name' => 'Inmobiliaria Ejemplo S.A.S.',
            'tax_id' => '900123456-7',
            'currency' => 'COP',
            'invoice_due_days' => 5,
            'space_catalog' => json_encode(['Sala', 'Cocina', 'Baño']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->user = User::factory()->create();
        $this->rental = Rental::factory()->create(['user_id' => $this->user->id]);

        $this->actingAs($this->user);
    }

    private function baseForm(array $overrides = []): array
    {
        return array_merge([
            'rental_id' => $this->rental->id,
            'type' => 'entrega',
            'landlord_name' => 'Ana Landlord',
            'landlord_document' => '111',
            'tenant_name' => 'Luis Tenant',
            'tenant_document' => '222',
            'occurred_at' => today()->format('Y-m-d'),
        ], $overrides);
    }

    #[Test]
    public function crea_un_acta_minima_sin_secciones_opcionales(): void
    {
        Livewire::test(CreateDeliveryAct::class)
            ->fillForm($this->baseForm())
            ->call('create')
            ->assertHasNoFormErrors();

        $act = DeliveryAct::sole();

        $this->assertSame('Ana Landlord', $act->landlord_name);
        $this->assertNull($act->water_reading);
        $this->assertNull($act->commitments);
        $this->assertNull($act->signed_at);
        $this->assertCount(0, $act->items);
        $this->assertFalse($act->hasMeterReadings());
    }

    #[Test]
    public function guarda_las_lecturas_solo_si_se_llenaron(): void
    {
        Livewire::test(CreateDeliveryAct::class)
            ->fillForm($this->baseForm([
                'water_reading' => '150',
                'energy_reading' => '',
                'gas_reading' => '',
            ]))
            ->call('create')
            ->assertHasNoFormErrors();

        $act = DeliveryAct::sole();

        $this->assertSame('150', $act->water_reading);
        $this->assertNull($act->energy_reading);
        $this->assertNull($act->gas_reading);
        $this->assertTrue($act->hasMeterReadings());
    }

    #[Test]
    public function guarda_el_inventario_agrupado_por_espacio(): void
    {
        Livewire::test(CreateDeliveryAct::class)
            ->fillForm($this->baseForm([
                'items' => [
                    ['space' => 'Cocina', 'name' => 'Refrigerador', 'state' => 'bueno', 'note' => '', 'photo_path' => null],
                    ['space' => 'Cocina', 'name' => 'Lavadora', 'state' => 'reparable', 'note' => 'Gotea', 'photo_path' => null],
                    ['space' => 'Sala', 'name' => 'Televisor', 'state' => 'nuevo', 'note' => '', 'photo_path' => null],
                ],
            ]))
            ->call('create')
            ->assertHasNoFormErrors();

        $act = DeliveryAct::sole()->load('items');

        $this->assertCount(3, $act->items);
        $this->assertSame(['Cocina', 'Sala'], array_keys($act->itemsBySpace()));
        $this->assertSame(ActItemState::REPARABLE, $act->items->firstWhere('name', 'Lavadora')->state);
    }

    #[Test]
    public function un_elemento_sin_nombre_lo_rechaza_la_validacion_del_formulario(): void
    {
        // El formulario exige el nombre, asi que una fila vacia nunca llega al
        // Builder. El Builder igual lo valida, pero la primera linea de defensa
        // es la pantalla.
        Livewire::test(CreateDeliveryAct::class)
            ->fillForm($this->baseForm([
                'items' => [
                    ['space' => 'Cocina', 'name' => '', 'state' => 'bueno', 'note' => '', 'photo_path' => null],
                ],
            ]))
            ->call('create')
            ->assertHasFormErrors(['items.0.name']);

        $this->assertSame(0, DeliveryAct::count());
    }

    #[Test]
    public function un_espacio_fuera_del_catalogo_muestra_el_error_del_builder(): void
    {
        Livewire::test(CreateDeliveryAct::class)
            ->fillForm($this->baseForm([
                'items' => [
                    ['space' => 'Bodega', 'name' => 'Estante', 'state' => 'bueno', 'note' => '', 'photo_path' => null],
                ],
            ]))
            ->call('create')
            ->assertNotified();

        $this->assertSame(0, DeliveryAct::count());
    }

    #[Test]
    public function guarda_compromisos_cuando_hay_texto(): void
    {
        Livewire::test(CreateDeliveryAct::class)
            ->fillForm($this->baseForm([
                'commitments' => 'Reparar la gotera antes del 20.',
                'observations' => '',
            ]))
            ->call('create')
            ->assertHasNoFormErrors();

        $act = DeliveryAct::sole();

        $this->assertSame('Reparar la gotera antes del 20.', $act->commitments);
        $this->assertNull($act->observations);
    }

    #[Test]
    public function las_firmas_marcan_el_acta_como_firmada(): void
    {
        Livewire::test(CreateDeliveryAct::class)
            ->fillForm($this->baseForm([
                'landlord_signature_path' => ['signatures/ana.png'],
                'tenant_signature_path' => null,
                'signed_at' => now()->format('Y-m-d H:i:s'),
            ]))
            ->call('create')
            ->assertHasNoFormErrors();

        $act = DeliveryAct::sole();

        $this->assertTrue($act->isSigned());
        $this->assertSame('signatures/ana.png', $act->landlord_signature_path);
        $this->assertNull($act->tenant_signature_path);
    }

    #[Test]
    public function el_alquiler_seleccionado_define_el_usuario_dueno_del_acta(): void
    {
        Livewire::test(CreateDeliveryAct::class)
            ->fillForm($this->baseForm())
            ->call('create');

        $this->assertSame($this->user->id, DeliveryAct::sole()->user_id);
    }

    #[Test]
    public function la_lista_solo_muestra_las_actas_del_usuario_actual(): void
    {
        $other = User::factory()->create();
        $otherRental = Rental::factory()->create(['user_id' => $other->id]);

        DeliveryAct::factory()->create([
            'rental_id' => $this->rental->id,
            'user_id' => $this->user->id,
        ]);

        DeliveryAct::factory()->create([
            'rental_id' => $otherRental->id,
            'user_id' => $other->id,
        ]);

        Livewire::test(ListDeliveryActs::class)
            ->assertCanSeeTableRecords(DeliveryAct::where('user_id', $this->user->id)->get())
            ->assertCanNotSeeTableRecords(DeliveryAct::where('user_id', $other->id)->get());
    }
}