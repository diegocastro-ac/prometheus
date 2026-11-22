<?php

namespace Tests\Feature;

use App\Filament\Resources\DeliveryActResource;
use App\Filament\Resources\DeliveryActResource\Pages\ListDeliveryActs;
use App\Filament\Resources\DeliveryActResource\Pages\ViewDeliveryAct;
use App\Models\ActItem;
use App\Models\DeliveryAct;
use App\Models\Rental;
use App\Models\Space;
use App\Models\User;
use App\Services\Acts\DeliveryActInventoryPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DeliveryActInventoryViewTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Rental $rental;

    private DeliveryAct $act;

    private Space $terraza;

    private Space $puestoComidas;

    private Space $puestoCocina;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->user = User::factory()->create();
        $this->rental = Rental::factory()->create(['user_id' => $this->user->id]);
        $this->act = DeliveryAct::factory()->create([
            'user_id' => $this->user->id,
            'rental_id' => $this->rental->id,
            'tenant_name' => 'Luis Tenant',
        ]);

        $this->terraza = Space::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'Terraza',
        ]);

        $this->puestoComidas = Space::factory()->childOf($this->terraza)->create([
            'name' => 'Puesto de comidas',
        ]);

        $this->puestoCocina = Space::factory()->childOf($this->puestoComidas)->create([
            'name' => 'Puesto de cocina',
        ]);

        $this->actingAs($this->user);
    }

    private function item(Space $space, string $name): ActItem
    {
        return ActItem::factory()->create([
            'delivery_act_id' => $this->act->id,
            'space_id' => $space->id,
            'space' => $space->name,
            'name' => $name,
        ]);
    }

    #[Test]
    public function el_presenter_conserva_la_rama_profunda_y_descarta_las_vacias(): void
    {
        Space::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'Bodega vacia',
        ]);

        $this->item($this->puestoCocina, 'Silla');
        $this->item($this->puestoCocina, 'Mesa');

        $presenter = new DeliveryActInventoryPresenter($this->act);

        $tree = $presenter->tree();

        $this->assertCount(1, $tree);
        $this->assertSame('Terraza', $tree[0]->name());

        $rows = $presenter->rows();

        $this->assertSame(
            ['Terraza', 'Puesto de comidas', 'Puesto de cocina'],
            array_column($rows, 'name'),
        );
        $this->assertSame('Terraza / Puesto de comidas / Puesto de cocina', $rows[2]['path']);
        $this->assertSame([0, 1, 2], array_column($rows, 'depth'));
        $this->assertSame(2, $rows[2]['directItems']);
        $this->assertSame(2, $rows[0]['totalItems']);
        $this->assertCount(4, $presenter->chartData());
    }

    #[Test]
    public function la_pagina_muestra_el_organigrama_y_la_tabla_con_la_jerarquia(): void
    {
        $this->item($this->puestoCocina, 'Silla');
        $this->item($this->puestoCocina, 'Mesa');

        Livewire::test(ViewDeliveryAct::class, ['record' => $this->act->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('Terraza')
            ->assertSee('Puesto de comidas')
            ->assertSee('Puesto de cocina')
            ->assertSee('Silla')
            ->assertSee('Mesa')
            ->assertSee(__('act.view.chart'))
            ->assertSee(__('act.view.table'))
            ->assertSee('x-load-js', false)
            ->assertSee('data-js-as-module="true"', false)
            ->assertSee('data-space-org-chart', false);
    }

    #[Test]
    public function los_elementos_sin_espacio_se_muestran_en_una_seccion_aparte(): void
    {
        ActItem::factory()->create([
            'delivery_act_id' => $this->act->id,
            'space_id' => null,
            'space' => 'Terraza eliminada',
            'name' => 'Mesa vieja',
        ]);

        $presenter = new DeliveryActInventoryPresenter($this->act);

        $this->assertCount(1, $presenter->orphanItems());

        Livewire::test(ViewDeliveryAct::class, ['record' => $this->act->getRouteKey()])
            ->assertSuccessful()
            ->assertSee(__('act.view.orphans'))
            ->assertSee('Mesa vieja');
    }

    #[Test]
    public function la_pagina_carga_las_utilidades_tailwind_propias_despues_del_css_de_filament(): void
    {
        config(['app.env' => 'local']);

        $html = $this->get(DeliveryActResource::getUrl('view', ['record' => $this->act]))
            ->assertSuccessful()
            ->getContent();

        $filamentCss = strpos($html, 'css/filament/filament/app.css');
        $utilitiesCss = strpos($html, 'css/filament/app-utilities.css');

        $this->assertNotFalse($filamentCss);
        $this->assertNotFalse($utilitiesCss);
        $this->assertGreaterThan($filamentCss, $utilitiesCss);
    }

    #[Test]
    public function el_listado_puede_generar_las_urls_de_la_pagina_de_consulta(): void
    {
        Livewire::test(ListDeliveryActs::class)
            ->assertSuccessful();
    }

    #[Test]
    public function la_ruta_de_consulta_carga_y_respeta_el_alcance_del_arrendador(): void
    {
        // El panel solo deja entrar en local a usuarios que no implementan
        // FilamentUser; el resto de pruebas vive en testing.
        config(['app.env' => 'local']);

        $this->item($this->puestoCocina, 'Silla');

        $this->get(DeliveryActResource::getUrl('view', ['record' => $this->act]))
            ->assertSuccessful()
            ->assertSee('Puesto de cocina');

        $otherUser = User::factory()->create();
        $this->actingAs($otherUser);

        $this->get(DeliveryActResource::getUrl('view', ['record' => $this->act]))
            ->assertNotFound();
    }
}
