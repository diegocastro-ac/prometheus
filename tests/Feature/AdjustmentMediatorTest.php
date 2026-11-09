<?php

namespace Tests\Feature;

use App\Enums\NotificationMethod;
use App\Models\IpcRate;
use App\Models\Rental;
use App\Services\Adjustment\AdjustmentMediator;
use App\Services\RentAdjustmentService;
use App\Services\RentalFacade;
use PHPUnit\Framework\Attributes\Test;

/**
 * La coordinacion del flujo de reajustes.
 *
 * Lo que se fija aqui es que el mediador es el unico que conoce la secuencia
 * (validar -> registrar -> recalcular) y el unico que arma los comandos. El
 * servicio y la fachada conservan sus firmas, pero delegan.
 */
class AdjustmentMediatorTest extends DocumentTestCase
{
    private function mediator(): AdjustmentMediator
    {
        return app(AdjustmentMediator::class);
    }

    private function rental(float $monthlyAmount = 1_000_000): Rental
    {
        $start = today()->subYears(2);

        return Rental::factory()->create([
            'user_id' => $this->user->id,
            'monthly_amount' => $monthlyAmount,
            'anchor_monthly_amount' => $monthlyAmount,
            'start_date' => $start->toDateString(),
            'end_date' => $start->copy()->addYear()->toDateString(),
            'anchor_established_on' => $start->toDateString(),
            'name' => 'Alquiler mediador',
        ]);
    }

    #[Test]
    public function registra_el_reajuste_y_deja_la_cadena_coherente(): void
    {
        IpcRate::factory()->forYear(2025, 9.28)->create();

        $rental = $this->rental();

        $adjustment = $this->mediator()->record(
            rental: $rental,
            newRent: 1_092_800,
            effectiveFrom: today()->startOfYear(),
        );

        $this->assertSame(1, (int) $adjustment->sequence);
        $this->assertSame(1_000_000.0, (float) $adjustment->previous_rent);
        $this->assertSame(1_092_800.0, (float) $adjustment->legal_cap_rent);
        $this->assertSame(0.0, (float) $adjustment->excess_over_cap);

        // Registrar no cambia el canon del alquiler: eso pasa al aplicar.
        $this->assertSame(1_000_000.0, (float) $rental->refresh()->monthly_amount);
        $this->assertSame(1_000_000.0, $this->mediator()->currentRentFor($rental));
    }

    #[Test]
    public function previsualiza_y_valida_la_misma_combinacion(): void
    {
        IpcRate::factory()->forYear(2025, 9.28)->create();

        $rental = $this->rental();

        $preview = $this->mediator()->previewIpc(
            rental: $rental,
            effectiveFrom: today()->startOfYear(),
        );

        $this->assertSame(2025, $preview->year);
        $this->assertSame(9.28, $preview->percentage);

        $result = $this->mediator()->validate(
            rental: $rental,
            effectiveFrom: today()->startOfYear(),
            requestedRent: 1_092_800,
        );

        $this->assertArrayHasKey('summary', $result);
        $this->assertArrayHasKey('messages', $result);
        $this->assertFalse($result['summary']->isBlocking());
    }

    #[Test]
    public function notificar_aplicar_y_recalcular_pasan_por_los_comandos(): void
    {
        IpcRate::factory()->forYear(2025, 9.28)->create();

        $rental = $this->rental();

        $adjustment = $this->mediator()->record(
            rental: $rental,
            newRent: 1_092_800,
            effectiveFrom: today()->startOfYear(),
        );

        $notified = $this->mediator()->markNotified(
            $adjustment,
            today()->addMonths(2),
            NotificationMethod::Postal,
        );

        $this->assertSame(NotificationMethod::Postal, $notified->notification_method);

        $applied = $this->mediator()->applyToRental($notified);

        $this->assertNotNull($notified->refresh()->applied_on);
        $this->assertSame(1_092_800.0, (float) $applied->monthly_amount);

        $result = $this->mediator()->recalculateChain($applied);

        $this->assertArrayHasKey('memento', $result);
        $this->assertNotNull($result['memento']->adjustment((int) $adjustment->id));
    }

    #[Test]
    public function el_servicio_y_la_fachada_conservan_el_mismo_flujo(): void
    {
        IpcRate::factory()->forYear(2025, 9.28)->create();

        $rental = $this->rental();

        $adjustment = app(RentAdjustmentService::class)->record(
            rental: $rental,
            newRent: 1_092_800,
            effectiveFrom: today()->startOfYear(),
        );

        $facade = app(RentalFacade::class);

        $notified = $facade->markAdjustmentNotified(
            $adjustment,
            today(),
            NotificationMethod::Postal,
        );

        $applied = $facade->applyAdjustment($notified);
        $result = $facade->recalculateAdjustmentChain($applied);

        $this->assertSame(NotificationMethod::Postal, $notified->notification_method);
        $this->assertSame(1_092_800.0, (float) $applied->monthly_amount);
        $this->assertArrayHasKey('memento', $result);
    }
}
