<?php

namespace Tests\Feature;

use App\Enums\ActItemState;
use App\Filament\Resources\DeliveryActResource\Pages\CreateDeliveryAct;
use App\Filament\Resources\DeliveryActResource\Pages\ListDeliveryActs;
use App\Models\ActItem;
use App\Models\DeliveryAct;
use App\Models\Rental;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * La accion "Crear acta a partir de esta" y el formulario que ella prellena.
 *
 * Comprueba el flujo completo del Prototype: la tabla ofrece la accion, la
 * pagina recibe ?from=, copia el acta indicado con alcance por usuario y deja
 * el formulario listo para que el Builder guarde un acta nuevo.
 */
class CreateDeliveryActFromTest extends TestCase
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
            'photo_path' => null,
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

    private function deriveUrl(DeliveryAct $act): string
    {
        $actions = Livewire::test(ListDeliveryActs::class)
            ->instance()
            ->getTable()
            ->getFlatActions();

        $this->assertArrayHasKey('derive', $actions);

        return $actions['derive']->record($act)->getUrl();
    }

    #[Test]
    public function la_tabla_ofrece_la_accion_de_derivar(): void
    {
        $act = $this->sourceAct();

        $url = $this->deriveUrl($act);

        $this->assertStringContainsString('from='.$act->id, $url);
        $this->assertStringContainsString('create', $url);
    }

    #[Test]
    public function el_formulario_arranca_preenllenado_con_la_copia(): void
    {
        $act = $this->sourceAct();

        $component = Livewire::withQueryParams(['from' => $act->id])
            ->test(CreateDeliveryAct::class);

        $component
            ->assertFormSet([
                'rental_id' => $act->rental_id,
                'type' => 'recepcion',
                'landlord_name' => 'Ana Landlord',
                'landlord_document' => '111',
                'tenant_name' => 'Luis Tenant',
                'tenant_document' => '222',
                'occurred_at' => today()->toDateString(),
            ])
            ->assertNotified();

        // El repeater renombra las filas con uuid: se comprueba por contenido.
        $items = collect(array_values($component->get('data.items')));

        $this->assertCount(2, $items);
        $this->assertSame('Cocina', $items->firstWhere('name', 'Refrigerador')['space']);
        $this->assertSame('reparable', $items->firstWhere('name', 'Refrigerador')['state']);
        $this->assertSame('Gotea', $items->firstWhere('name', 'Refrigerador')['note']);
        $this->assertSame('Sala', $items->firstWhere('name', 'Televisor')['space']);
    }

    #[Test]
    public function la_copia_no_arrastra_firmas_lecturas_ni_compromisos(): void
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

        $state = Livewire::withQueryParams(['from' => $act->id])
            ->test(CreateDeliveryAct::class)
            ->get('data');

        $this->assertNull($state['scheduled_at']);
        $this->assertNull($state['water_reading']);
        $this->assertNull($state['energy_reading']);
        $this->assertNull($state['gas_reading']);
        $this->assertNull($state['commitments']);
        $this->assertNull($state['observations']);

        // El FileUpload normaliza el campo vacio a []: es lo que tambien hace
        // un formulario recien abierto, asi que no hay firma que arrastrar.
        $this->assertEmpty($state['landlord_signature_path']);
        $this->assertEmpty($state['tenant_signature_path']);
        $this->assertNull($state['signed_at']);

        // En cambio la visita si se redefine: es de hoy.
        $this->assertSame(today()->toDateString(), $state['occurred_at']);

        // Y el acta del que se copio sigue tal cual.
        $this->assertTrue($act->isSigned());
        $this->assertTrue($act->hasMeterReadings());
    }

    #[Test]
    public function la_foto_del_acta_anterior_sigue_disponible(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('act-items/refrigerador.jpg', 'binario');

        $act = $this->sourceAct();

        ActItem::query()
            ->where('delivery_act_id', $act->id)
            ->where('name', 'Refrigerador')
            ->update(['photo_path' => 'act-items/refrigerador.jpg']);

        $items = collect(array_values(
            Livewire::withQueryParams(['from' => $act->id])
                ->test(CreateDeliveryAct::class)
                ->get('data.items'),
        ));

        $photo = $items->firstWhere('name', 'Refrigerador')['photo_path'];

        // El FileUpload deja la ruta existente tal cual; si el archivo no
        // existiera, la hidratacion lo descartaria y el campo llegaria vacio.
        $this->assertSame('act-items/refrigerador.jpg', array_values($photo)[0] ?? null);
    }

    #[Test]
    public function guardar_crea_un_acta_nuevo_sin_tocar_al_original(): void
    {
        $act = $this->sourceAct();

        Livewire::withQueryParams(['from' => $act->id])
            ->test(CreateDeliveryAct::class)
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(2, DeliveryAct::count());

        $new = DeliveryAct::query()->latest('id')->first();

        $this->assertNotSame($act->id, $new->id);
        $this->assertSame($this->user->id, $new->user_id);
        $this->assertSame('recepcion', $new->type);
        $this->assertSame(today()->toDateString(), $new->occurred_at->toDateString());
        $this->assertCount(2, $new->items);

        // El original no se modifica ni se duplican sus filas.
        $original = $act->load('items');
        $this->assertCount(2, $original->items);
        $this->assertSame(
            $original->items->pluck('id')->all(),
            $act->fresh()->items()->pluck('id')->all(),
        );
    }

    #[Test]
    public function una_acta_ajena_no_se_puede_copiar(): void
    {
        $other = User::factory()->create();
        $otherRental = Rental::factory()->create(['user_id' => $other->id]);

        $foreign = DeliveryAct::factory()->create([
            'rental_id' => $otherRental->id,
            'user_id' => $other->id,
            'landlord_name' => 'Ajeno',
        ]);

        Livewire::withQueryParams(['from' => $foreign->id])
            ->test(CreateDeliveryAct::class)
            ->assertFormSet([
                'landlord_name' => null,
                'rental_id' => null,
            ]);
    }

    #[Test]
    public function una_acta_inexistente_deja_el_formulario_vacio(): void
    {
        Livewire::withQueryParams(['from' => 999999])
            ->test(CreateDeliveryAct::class)
            ->assertFormSet([
                'landlord_name' => null,
                'rental_id' => null,
            ]);
    }

    #[Test]
    public function sin_el_parametro_from_el_formulario_arranca_vacio(): void
    {
        $component = Livewire::test(CreateDeliveryAct::class);

        $component->assertFormSet([
            'landlord_name' => null,
            'rental_id' => null,
        ]);

        $this->assertEmpty($component->get('data.items'));
    }
}
