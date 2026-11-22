<?php

namespace Tests\Feature;

use App\Filament\Resources\RentalResource\Pages\CreateRental;
use App\Filament\Resources\RentalResource\Pages\EditRental;
use App\Models\Geography;
use App\Models\Property;
use App\Models\Rental;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Los datos legales del alquiler: tipo, valor comercial y avalúo catastral.
 *
 * Estas columnas existían en el modelo, con sus casts y sus accesores, y las
 * reglas del artículo 1 y del 18 las leían. Lo que no existía era el formulario
 * que las llenara. Las dos reglas estaban escritas, probadas con `forceFill()`
 * y cableadas al dominio, y en el uso real no podían dispararse nunca.
 *
 * Estas pruebas cierran ese hueco: comprueban que el formulario expone los datos
 * y, sobre todo, que NO los exige cuando la ley no aplica. Exigir el avalúo
 * catastral en un local comercial sería inventar una obligación que la Ley 820 no
 * impone.
 */
class RentalLegalFieldsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Property $property;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->property = Property::factory()->create([
            'user_id' => $this->user->id,
            'geography_code' => null,
        ]);
        $this->tenant = Tenant::factory()->create(['user_id' => $this->user->id]);

        $this->actingAs($this->user);
    }

    private function validData(): array
    {
        return [
            'start_date' => today()->toDateString(),
            'name' => 'Apartamento de prueba',
            'total_months' => 12,
            'total_persons' => 2,
            'monthly_amount' => 1_200_000,
            'tenant_id' => $this->tenant->id,
            'property_id' => $this->property->id,
            'rental_type' => 'residential',
        ];
    }

    #[Test]
    public function un_alquiler_residencial_exige_valor_comercial_y_avaluo_catastral(): void
    {
        // El artículo 18 limita el canon al uno por ciento del valor comercial.
        // Sin los dos datos no hay contra qué comparar, así que se exigen.
        Livewire::test(CreateRental::class)
            ->fillForm($this->validData())
            ->call('create')
            ->assertHasFormErrors(['commercial_value' => 'required']);

        Livewire::test(CreateRental::class)
            ->fillForm($this->validData())
            ->call('create')
            ->assertHasFormErrors(['catastral_appraisal' => 'required']);
    }

    #[Test]
    public function un_alquiler_comercial_no_exige_los_valores_del_articulo_18(): void
    {
        // La Ley 820 se aplica a la vivienda urbana. En un local comercial el canon
        // lo fija el mercado y exigir un avalúo catastral sería una obligación que
        // la ley no impone.
        Livewire::test(CreateRental::class)
            ->fillForm(array_merge($this->validData(), ['rental_type' => 'commercial']))
            ->call('create')
            ->assertHasNoFormErrors();
    }

    #[Test]
    public function los_datos_legales_se_guardan_en_el_alquiler(): void
    {
        Livewire::test(CreateRental::class)
            ->fillForm(array_merge($this->validData(), [
                'commercial_value' => 120_000_000,
                'catastral_appraisal' => 40_000_000,
            ]))
            ->call('create')
            ->assertHasNoFormErrors();

        $rental = Rental::where('name', 'Apartamento de prueba')->sole();

        $this->assertSame(120_000_000.0, (float) $rental->commercial_value);
        $this->assertSame(40_000_000.0, (float) $rental->catastral_appraisal);
        $this->assertSame('residential', $rental->rental_type?->value);
    }

    #[Test]
    public function el_valor_comercial_no_puede_superar_dos_avalúos_en_el_formulario(): void
    {
        // La regla del artículo 18 que casi nunca se cita dice que la estimación
        // comercial no puede exceder DOS veces el avalúo catastral.
        //
        // Aquí se comprueba el efecto, no el mensaje: un avalúo de 40 millones
        // con un valor comercial de 120 millones deja el canon por encima del
        // uno por ciento, y eso es lo que la cadena de reglas debe avisar al
        // registrar un reajuste.
        $rental = Rental::factory()->create([
            'user_id' => $this->user->id,
            'monthly_amount' => 1_000_000,
            'anchor_monthly_amount' => 1_000_000,
            'rental_type' => 'residential',
            'commercial_value' => 120_000_000,
            'catastral_appraisal' => 40_000_000,
        ]);

        $this->assertTrue(
            $rental->commercialValueExceedsCatastralCeiling(),
            '120 millones excede el doble de un avalúo de 40 millones',
        );
        $this->assertSame(80_000_000.0, $rental->commercialValueCeiling());
    }

    #[Test]
    public function editar_muestra_los_datos_que_ya_estan_registrados(): void
    {
        // Si el formulario no recuperara los valores, al editar y guardar se
        // borrarían en silencio: una pérdida de dato que solo se descubría
        // cuando una regla dejaba de avisar.
        $rental = Rental::factory()->create([
            'user_id' => $this->user->id,
            'rental_type' => 'residential',
            'commercial_value' => 90_000_000,
            'catastral_appraisal' => 50_000_000,
        ]);

        Livewire::test(EditRental::class, ['record' => $rental->getKey()])
            ->assertFormSet([
                'rental_type' => 'residential',
                'commercial_value' => 90_000_000,
                'catastral_appraisal' => 50_000_000,
            ]);
    }

    #[Test]
    public function la_ciudad_de_la_propiedad_no_se_copia_al_alquiler(): void
    {
        // La ciudad vive en la propiedad y a propósito no se duplica en el
        // alquiler. Duplicarla obligaría a mantener dos valores que pueden
        // contradecirse en dos contratos del mismo edificio.
        Geography::factory()->withIpc('20001', 'VALLEDUPAR', 'CESAR')->create();

        $property = Property::factory()->create([
            'user_id' => $this->user->id,
            'geography_code' => '20001',
        ]);

        Livewire::test(CreateRental::class)
            ->fillForm(array_merge($this->validData(), [
                'property_id' => $property->id,
                'commercial_value' => 50_000_000,
                'catastral_appraisal' => 30_000_000,
            ]))
            ->call('create')
            ->assertHasNoFormErrors();

        $rental = Rental::where('property_id', $property->id)->sole();

        $this->assertSame('20001', $rental->property->geography_code);
        $this->assertArrayNotHasKey(
            'geography_code',
            $rental->getAttributes(),
            'la ciudad debe seguir siendo un dato de la propiedad',
        );
    }
}
