<?php

namespace Tests\Feature;

use App\Filament\Widgets\IncomeChart;
use App\Models\Payment;
use App\Models\Rental;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

/**
 * El grafico de ingresos debe ignorar los alquileres que ya terminaron, igual
 * que hacen los otros tres widgets del panel. Antes los incluia.
 */
class IncomeChartTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function el_grafico_excluye_los_alquileres_que_ya_no_estan_activos(): void
    {
        $user = User::factory()->create();

        $activeRental = Rental::factory()->create([
            'user_id' => $user->id,
            'is_active' => true,
        ]);

        $inactiveRental = Rental::factory()->create([
            'user_id' => $user->id,
            'is_active' => false,
        ]);

        Payment::factory()->create([
            'rental_id' => $activeRental->id,
            'user_id' => $user->id,
            'date' => today(),
            'amount' => 1_000_000,
            'is_rent_paid' => true,
        ]);

        Payment::factory()->create([
            'rental_id' => $inactiveRental->id,
            'user_id' => $user->id,
            'date' => today(),
            'amount' => 9_000_000,
            'is_rent_paid' => true,
        ]);

        $this->actingAs($user);

        $expected = $this->currentMonthValue(0);
        $collected = $this->currentMonthValue(1);

        $this->assertSame(1_000_000.0, $expected);
        $this->assertSame(1_000_000.0, $collected);
    }

    #[Test]
    public function el_grafico_solo_muestra_pagos_del_usuario_actual(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        $ownerRental = Rental::factory()->create([
            'user_id' => $owner->id,
            'is_active' => true,
        ]);

        $otherRental = Rental::factory()->create([
            'user_id' => $other->id,
            'is_active' => true,
        ]);

        Payment::factory()->create([
            'rental_id' => $ownerRental->id,
            'user_id' => $owner->id,
            'date' => today(),
            'amount' => 1_500_000,
            'is_rent_paid' => true,
        ]);

        Payment::factory()->create([
            'rental_id' => $otherRental->id,
            'user_id' => $other->id,
            'date' => today(),
            'amount' => 7_500_000,
            'is_rent_paid' => true,
        ]);

        $this->actingAs($owner);

        $this->assertSame(1_500_000.0, $this->currentMonthValue(0));
        $this->assertSame(1_500_000.0, $this->currentMonthValue(1));
    }

    /**
     * getData() esta protegido porque forma parte del contrato de Filament,
     * asi que se invoca por reflexion en lugar de cambiar la visibilidad.
     */
    private function currentMonthValue(int $dataset): float
    {
        $method = new ReflectionMethod(IncomeChart::class, 'getData');

        $data = $method->invoke(new IncomeChart());

        return (float) $data['datasets'][$dataset]['data'][11];
    }
}