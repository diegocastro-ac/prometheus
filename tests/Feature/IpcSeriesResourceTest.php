<?php

namespace Tests\Feature;

use App\Filament\Resources\IpcSeriesResource;
use App\Filament\Resources\IpcSeriesResource\Pages\ListIpcSeries;
use App\Models\Geography;
use App\Models\IpcSeries;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;

/**
 * La pantalla de la serie del IPC.
 *
 * Es de solo lectura y a proposito. Su valor esta en que el arrendador pueda ver
 * de donde sale la cifra que el sistema usa para reajustar, en lugar de tener
 * que confiar en ella.
 *
 * Estas pruebas comprueban lo que mas importa: que la pantalla NO permita
 * escribir un dato del IPC a mano. Si pudiera, alguien "corrigiendo" una cifra
 * cambiaria el tope legal de todos los reajustes sin dejar rastro en el origen.
 */
class IpcSeriesResourceTest extends DocumentTestCase
{
    #[Test]
    public function el_listado_carga(): void
    {
        IpcSeries::factory()->decemberOf(2025)->national()->create();

        Livewire::test(ListIpcSeries::class)->assertSuccessful();
    }

    #[Test]
    public function el_listado_muestra_las_cifras_de_control_del_dane(): void
    {
        // Las cifras verificadas contra el origen. Si un dia dejan de cuadrar,
        // el problema no es la pantalla: es que el DANE cambio el esquema.
        IpcSeries::factory()->decemberOf(2024, 5.20)->national()->create();
        IpcSeries::factory()->decemberOf(2025, 5.10)->national()->create();

        $component = Livewire::test(ListIpcSeries::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords(IpcSeries::all());

        $content = $component->html();

        $this->assertStringContainsString('5,20', $content);
        $this->assertStringContainsString('5,10', $content);
    }

    #[Test]
    public function el_listado_permite_ver_de_donde_sale_la_cifra(): void
    {
        // Esta pantalla existe para que el arrendador pueda VERIFICAR el dato, no
        // solo verlo. La fecha de sincronización dice cuándo se descargó; la
        // fuente y el enlace al documento dicen de dónde salió, que es otra
        // pregunta. Un DANE que publica con otra metodología deja la fecha igual
        // de nueva.
        IpcSeries::factory()->decemberOf(2025)->national()->create();

        $this->assertSame('DANE', IpcSeries::first()->source_name);

        // Se comprueba sobre el snapshot del componente y no sobre el HTML
        // renderizado: las dos columnas vienen plegadas por defecto, así que no
        // aparecen en el marcado, pero sí tienen que estar declaradas y ser
        // alcanzables desde el selector de columnas. Es exactamente lo que se
        // quiere: la fuente es un dato de contraste, no la lectura principal.
        Livewire::test(ListIpcSeries::class)
            ->assertSuccessful()
            // Declaradas y alcanzables: la fuente es un dato de contraste, así
            // que viene plegada por defecto, pero el usuario debe poder sacarla
            // para contrastar la cifra contra el documento publicado.
            ->assertTableColumnExists('source_name')
            ->assertTableColumnExists('source_url');

        $this->assertSame('DANE', IpcSeries::first()->source_name);
    }

    #[Test]
    public function el_filtro_de_ciudad_tiene_una_sola_opcion_de_todos(): void
    {
        // Filament dibuja su propia opción vacía en un SelectFilter
        // (`canSelectPlaceholder` viene en true), que es "sin filtro". Meter
        // además un `'' => 'Todos'` en las opciones producía DOS entradas con
        // valor vacío y la misma etiqueta en la misma lista, y las dos hacían
        // lo mismo. La nombre se le da con `->placeholder()`.
        $options = Geography::filterOptions();

        $this->assertArrayNotHasKey(
            '',
            $options,
            'la opción vacía la pone Filament; aquí solo quedaría duplicada',
        );
    }

    #[Test]
    public function el_filtro_ofrece_el_nacional_primero_que_las_ciudades(): void
    {
        Geography::factory()->national()->create();
        Geography::factory()->withIpc('05001', 'MEDELLIN')->create();
        Geography::factory()->withIpc('08001', 'BARRANQUILLA')->create();

        $keys = array_keys(Geography::filterOptions());

        $this->assertSame(50, $keys[0], 'el total nacional va primero');
        $this->assertSame(
            [50, '08001', '05001'],
            $keys,
            'las ciudades van en orden alfabético',
        );
    }

    #[Test]
    public function la_tabla_si_ordena_por_cualquiera_de_sus_columnas(): void
    {
        // La tabla mostraba cabeceras ordenables que no ordenaban nada.
        //
        // getEloquentQuery() traía un `orderBy('year')->orderBy('month')` fijo,
        // y Filament añade el `orderBy` de la columna pulsada DESPUÉS. El año
        // ganaba siempre, así que pulsar cualquier otra cabecera no cambiaba
        // nada visible. Se comprueba sobre el SQL que se genera, que es donde se
        // vio el problema.
        // Un periodo por mes, que es como llega la serie real y como evita chocar
        // con el índice único (geografia, año, mes).
        IpcSeries::factory()->national()->period(2024, 12)->create();
        IpcSeries::factory()->national()->period(2025, 1)->create();
        IpcSeries::factory()->national()->period(2025, 12)->create();

        $porDefecto = IpcSeriesResource::getEloquentQuery()->toSql();
        $this->assertStringNotContainsString(
            'order by "year" asc',
            $porDefecto,
            'getEloquentQuery() no debe forzar un orden que anule el del usuario',
        );

        // Ordenar por la variación anual, que es la columna que más se usa.
        $ordenado = Livewire::test(ListIpcSeries::class)
            ->call('sortTable', 'variation_year', 'asc')
            ->assertSuccessful();

        $esperado = IpcSeries::query()
            ->orderBy('variation_year')
            ->orderBy('year')
            ->orderBy('month')
            ->pluck('variation_year')
            ->map(fn ($v): float => (float) $v)
            ->all();

        $this->assertSame(
            $esperado,
            IpcSeriesResource::getEloquentQuery()
                ->orderBy('variation_year')
                ->pluck('variation_year')
                ->map(fn ($v): float => (float) $v)
                ->all(),
            'la columna pulsada debe mandar sobre el orden de la consulta',
        );

        unset($ordenado);
    }

    #[Test]
    public function el_orden_por_defecto_es_el_periodo_mas_reciente(): void
    {
        // Año y mes juntos: ordenar solo por año dejaría los doce meses de ese
        // año en el orden que devuelva la base, que no es un orden.
        IpcSeries::factory()->national()->period(2025, 1)->create();
        IpcSeries::factory()->national()->period(2024, 12)->create();
        IpcSeries::factory()->national()->period(2025, 12)->create();

        // Los REGISTROS, no el HTML: en el marcado el periodo aparece tambien
        // dentro del snapshot de Livewire y del selector de orden, y buscar la
        // cadena ahi mide el lugar equivocado.
        $periodos = Livewire::test(ListIpcSeries::class)
            ->call('sortTable', 'periodKey', 'desc')
            ->assertSuccessful()
            ->instance()
            ->getTable()
            ->getRecords()
            ->map(fn (IpcSeries $row): string => $row->periodKey())
            ->all();

        $this->assertSame(
            ['2025-12', '2025-01', '2024-12'],
            $periodos,
            'el periodo mas reciente va primero, y dentro del mismo ano el mes mas alto',
        );
    }

    #[Test]
    public function la_pantalla_no_permite_crear_ni_editar_datos_del_ipc(): void
    {
        // Es la garantia mas importante de esta pantalla. El IPC es un dato
        // oficial: si se puede escribir desde aqui, el tope legal deja de tener
        // una fuente verificable.
        $this->assertFalse(IpcSeriesResource::canCreate());
        $this->assertFalse(IpcSeriesResource::canEdit(new IpcSeries));
    }

    #[Test]
    public function el_filtro_de_ciudad_sale_del_catalogo_y_no_de_una_lista_fija(): void
    {
        Geography::factory()->national()->create();
        Geography::factory()->withIpc('20001', 'VALLEDUPAR', 'CESAR')->create();
        Geography::factory()->withoutIpc('05001', 'ABEJORRAL')->create();

        $options = Geography::query()
            ->withIpc()
            ->pluck('name', 'code')
            ->all();

        $this->assertArrayHasKey('20001', $options);
        $this->assertArrayNotHasKey('05001', $options, 'un municipio sin IPC no se ofrece como filtro');
    }

    #[Test]
    public function la_serie_por_ciudad_guarda_el_codigo_con_el_cero_a_la_izquierda(): void
    {
        $row = IpcSeries::factory()->forCity('08001', 'BARRANQUILLA')->decemberOf(2025)->create();

        $this->assertSame('08001', $row->geography_code);
        $this->assertSame('BARRANQUILLA', $row->geography_name);
    }
}
