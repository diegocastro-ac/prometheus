<?php

namespace Tests\Feature;

use App\Filament\Resources\RentAdjustmentResource\Pages\CreateRentAdjustment;
use App\Filament\Resources\RentAdjustmentResource\Pages\ListRentAdjustments;
use App\Models\IpcRate;
use App\Models\Rental;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;

/**
 * El formulario de registro de reajustes.
 *
 * El foco esta en el par ano de IPC y vigencia. El articulo 20 ata el IPC al
 * ano calendario anterior al de vigencia, asi que el formulario tiene que
 * proponer un par que ya cumpla la regla, y si la persona elige otra
 * combinacion, tiene que exigir confirmacion y explicacion antes de guardar.
 */
class RentAdjustmentFormTest extends DocumentTestCase
{
    private function form(): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::test(CreateRentAdjustment::class);
    }

    /**
     * Estado del formulario ya con los valores por defecto resueltos.
     *
     * @return array<string, mixed>
     */
    private function defaults(): array
    {
        return (array) $this->form()->get('data');
    }

    private function rental(float $monthlyAmount = 1_000_000): Rental
    {
        return Rental::factory()->create([
            'user_id' => $this->user->id,
            'monthly_amount' => $monthlyAmount,
            'is_active' => true,
        ]);
    }

    /**
     * El IPC sembrado es el ultimo disponible y el dia de hoy pertenece al ano
     * siguiente, asi que la vigencia legal del IPC sembrado es hoy.
     */
    private function effectiveFromForLastIpc(): string
    {
        return today()->toDateString();
    }

    #[Test]
    public function la_vigencia_propuesta_es_coherente_con_el_ultimo_ipc(): void
    {
        IpcRate::factory()->forYear(2025, 9.28)->create();

        $defaults = $this->defaults();

        $this->assertSame('2025', (string) ($defaults['ipc_year'] ?? ''));

        $effective = (string) ($defaults['effective_from'] ?? '');

        $this->assertNotSame('', $effective, 'el formulario debe proponer una vigencia');

        // El par por defecto tiene que cumplir la regla sin que nadie marque
        // nada: el IPC del ano anterior al de vigencia.
        $this->assertSame(
            ((int) date('Y', strtotime($effective))) - 1,
            (int) ($defaults['ipc_year'] ?? 0),
            'el IPC propuesto debe ser el del año calendario anterior a la vigencia',
        );
    }

    #[Test]
    public function cambiar_el_anio_del_ipc_recalcula_la_vigencia(): void
    {
        // Un IPC cuya vigencia legal aun no llega sirve para distinguir el
        // recalculo: con el ultimo IPC disponible toda vigencia se recorta a hoy
        // y el cambio seria invisible.
        $futureYear = (int) today()->addYears(3)->format('Y');

        IpcRate::factory()->forYear($futureYear, 4.20)->create();

        $expected = Carbon::create($futureYear + 1, 1, 1)->toDateString();

        $data = $this->form()->fillForm(['ipc_year' => (string) $futureYear])->get('data');

        $this->assertSame($expected, (string) $data['effective_from']);
    }

    #[Test]
    public function un_ipc_cuyo_ano_de_vigencia_ya_paso_no_propone_el_pasado(): void
    {
        // La vigencia legal del IPC del ano anterior fue el 1 de enero de este
        // ano, que ya paso. La propuesta cae en hoy en vez de inventar una fecha
        // que descuadre las facturas.
        IpcRate::factory()->forYear((int) today()->subYear()->format('Y'), 9.28)->create();

        $effective = (string) ($this->defaults()['effective_from'] ?? '');

        $this->assertSame(today()->toDateString(), $effective);
    }

    #[Test]
    public function la_confirmacion_solo_aparece_cuando_el_par_se_aparta_de_la_ley(): void
    {
        IpcRate::factory()->forYear(2025, 9.28)->create();
        IpcRate::factory()->forYear(2026, 4.15)->create();

        // Con el ultimo IPC disponible la vigencia por defecto ya cumple la
        // regla, asi que no hay nada que confirmar y la casilla ni se muestra.
        $this->form()
            ->assertFormFieldDoesNotExist('ipc_outside_statutory_year');

        // Al pedir el IPC de 2026 para una vigencia de 2026, la pareja se aparta
        // de la ley y la casilla aparece.
        $this->form()
            ->fillForm([
                'ipc_year' => '2026',
                'effective_from' => $this->effectiveFromForLastIpc(),
            ])
            ->assertFormFieldExists('ipc_outside_statutory_year', fn ($field): bool => $field->isVisible());
    }

    #[Test]
    public function la_vigencia_propuesta_nunca_es_pasada(): void
    {
        // El ultimo IPC sembrado es del ano anterior, cuya vigencia legal
        // (1 de enero de este ano) ya paso. La propuesta cae en el dia de hoy,
        // que sigue siendo del ano correcto.
        IpcRate::factory()->forYear(2025, 9.28)->create();

        $effective = (string) ($this->defaults()['effective_from'] ?? '');

        $this->assertGreaterThanOrEqual(
            today()->toDateString(),
            $effective,
            'no se puede proponer un reajuste en el pasado',
        );
    }

    #[Test]
    public function registrar_un_reajuste_dentro_de_la_ley_no_pide_confirmacion(): void
    {
        IpcRate::factory()->forYear(2025, 9.28)->create();
        $rental = $this->rental(1_000_000);

        Livewire::test(CreateRentAdjustment::class)
            ->fillForm([
                'rental_id' => $rental->id,
                'new_rent' => 1_092_800,
                'ipc_year' => '2025',
                'effective_from' => $this->effectiveFromForLastIpc(),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('rent_adjustments', [
            'rental_id' => $rental->id,
            'ipc_year' => 2025,
        ]);
    }

    #[Test]
    public function un_ipc_que_no_corresponde_no_se_guarda_en_silencio(): void
    {
        IpcRate::factory()->forYear(2025, 9.28)->create();
        IpcRate::factory()->forYear(2026, 4.15)->create();
        $rental = $this->rental(1_000_000);

        // Vigencia en 2027 con el IPC de 2026 es la combinacion que la ley si
        // permite. Vigencia en 2026 con el IPC de 2026 es la que no.
        Livewire::test(CreateRentAdjustment::class)
            ->fillForm([
                'rental_id' => $rental->id,
                'new_rent' => 1_041_500,
                'ipc_year' => '2026',
                'effective_from' => $this->effectiveFromForLastIpc(),
            ])
            ->call('create')
            ->assertNotified();

        $this->assertDatabaseMissing('rent_adjustments', ['rental_id' => $rental->id]);
    }

    #[Test]
    public function apartarse_del_ano_legal_con_motivo_escrito_si_se_registra(): void
    {
        IpcRate::factory()->forYear(2025, 9.28)->create();
        IpcRate::factory()->forYear(2026, 4.15)->create();
        $rental = $this->rental(1_000_000);

        Livewire::test(CreateRentAdjustment::class)
            ->fillForm([
                'rental_id' => $rental->id,
                'new_rent' => 1_041_500,
                'ipc_year' => '2026',
                'effective_from' => $this->effectiveFromForLastIpc(),
                'ipc_outside_statutory_year' => true,
                'notes' => 'El IPC de 2025 fue revisado por el DANE.',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('rent_adjustments', [
            'rental_id' => $rental->id,
            'ipc_year' => 2026,
        ]);
    }

    #[Test]
    public function el_listado_de_reajustes_carga(): void
    {
        IpcRate::factory()->forYear(2025, 9.28)->create();

        Livewire::test(ListRentAdjustments::class)->assertSuccessful();
    }
}