<?php

namespace Tests\Feature;

use App\Commands\Adjustment\ApplyRentAdjustment;
use App\Commands\Adjustment\NotifyRentAdjustment;
use App\Commands\Adjustment\RecalculateRentAdjustmentChain;
use App\Enums\NotificationMethod;
use App\Models\IpcRate;
use App\Models\RentAdjustment;
use App\Models\Rental;
use App\Services\Adjustment\AdjustmentChainRecalculator;
use App\Services\RentAdjustmentService;
use App\Services\RentalFacade;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * Las acciones de reajuste como comandos.
 *
 * Cada comando encapsula la peticion y su receptor; el servicio y la fachada
 * conservan sus metodos como invocadores compatibles. Lo que se fija aqui es
 * que ejecutar el comando produce el mismo resultado que la operacion que ya
 * existia.
 */
class AdjustmentCommandsTest extends DocumentTestCase
{
    private function service(): RentAdjustmentService
    {
        return app(RentAdjustmentService::class);
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
            'name' => 'Alquiler comandos',
        ]);
    }

    private function adjustment(): RentAdjustment
    {
        IpcRate::factory()->forYear(2025, 9.28)->create();

        return $this->service()->record(
            rental: $this->rental(),
            newRent: 1_092_800,
            effectiveFrom: today()->startOfYear(),
        );
    }

    #[Test]
    public function notificar_deja_la_constancia_de_fecha_y_medio(): void
    {
        $adjustment = $this->adjustment();

        $result = (new NotifyRentAdjustment(
            $adjustment,
            today()->addMonths(2),
            NotificationMethod::Postal,
        ))->execute();

        $this->assertSame(
            today()->addMonths(2)->toDateString(),
            $result->notified_on->toDateString(),
        );
        $this->assertSame(NotificationMethod::Postal, $result->notification_method);
    }

    #[Test]
    public function notificar_sin_medio_falla_con_el_mensaje_legal(): void
    {
        $adjustment = $this->adjustment();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('medio por el que se comunico');

        (new NotifyRentAdjustment($adjustment, today()))->execute();
    }

    #[Test]
    public function aplicar_marca_el_reajuste_y_sincroniza_el_canon(): void
    {
        $adjustment = $this->adjustment();
        $rental = $adjustment->rental;

        $result = (new ApplyRentAdjustment(
            app(AdjustmentChainRecalculator::class),
            $adjustment,
        ))->execute();

        $this->assertSame($rental->id, $result->id);
        $this->assertNotNull($adjustment->refresh()->applied_on);
        $this->assertSame(1_092_800.0, (float) $result->monthly_amount);
    }

    #[Test]
    public function aplicar_sin_fecha_de_vigencia_falla(): void
    {
        $adjustment = $this->adjustment();
        $adjustment->forceFill(['effective_from' => null])->saveQuietly();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('fecha de vigencia');

        (new ApplyRentAdjustment(
            app(AdjustmentChainRecalculator::class),
            $adjustment->refresh(),
        ))->execute();
    }

    #[Test]
    public function recalcular_devuelve_el_resultado_con_memento(): void
    {
        $adjustment = $this->adjustment();

        $result = (new RecalculateRentAdjustmentChain(
            app(AdjustmentChainRecalculator::class),
            $adjustment->rental,
        ))->execute();

        $this->assertArrayHasKey('touched', $result);
        $this->assertArrayHasKey('current_rent', $result);
        $this->assertArrayHasKey('changed_ids', $result);
        $this->assertArrayHasKey('memento', $result);
        $this->assertNotNull($result['memento']->adjustment((int) $adjustment->id));
    }

    #[Test]
    public function la_fachada_sigue_ejecutando_las_mismas_operaciones(): void
    {
        $adjustment = $this->adjustment();
        $facade = app(RentalFacade::class);

        $notified = $facade->markAdjustmentNotified(
            $adjustment,
            today(),
            NotificationMethod::Postal,
        );

        $this->assertSame(NotificationMethod::Postal, $notified->notification_method);

        $applied = $facade->applyAdjustment($notified);

        $this->assertNotNull($notified->refresh()->applied_on);
        $this->assertSame(1_092_800.0, (float) $applied->monthly_amount);

        $result = $facade->recalculateAdjustmentChain($applied);

        $this->assertArrayHasKey('memento', $result);
    }
}
