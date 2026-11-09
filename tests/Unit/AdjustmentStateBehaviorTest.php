<?php

namespace Tests\Unit;

use App\Enums\AdjustmentState;
use App\States\Adjustment\AppliedState;
use App\States\Adjustment\NotifiedState;
use App\States\Adjustment\RecordedState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Matriz de capacidades de los estados del reajuste.
 *
 * El patron State se justifica si cada estado decide por si mismo y el enum solo
 * delega. Estas pruebas fijan esa matriz para que un cambio de estado no altere
 * en silencio lo que la pantalla permite hacer.
 */
class AdjustmentStateBehaviorTest extends TestCase
{
    /**
     * @return array<string, array{AdjustmentState, bool, bool, bool, bool}>
     */
    public static function states(): array
    {
        return [
            'registrado' => [AdjustmentState::Recorded, true, false, true, false],
            'notificado' => [AdjustmentState::Notified, false, true, true, false],
            'aplicado' => [AdjustmentState::Applied, false, false, false, true],
        ];
    }

    #[Test]
    #[DataProvider('states')]
    public function cada_estado_decide_sus_capacidades(
        AdjustmentState $state,
        bool $canNotify,
        bool $canApply,
        bool $canEdit,
        bool $countsTowardsChain,
    ): void {
        $behavior = $state->behavior();

        $this->assertSame($canNotify, $behavior->canNotify());
        $this->assertSame($canApply, $behavior->canApply());
        $this->assertSame($canEdit, $behavior->canEdit());
        $this->assertSame($countsTowardsChain, $behavior->countsTowardsChain());
    }

    #[Test]
    public function el_enum_delega_en_el_estado_concreto(): void
    {
        $this->assertInstanceOf(RecordedState::class, AdjustmentState::Recorded->behavior());
        $this->assertInstanceOf(NotifiedState::class, AdjustmentState::Notified->behavior());
        $this->assertInstanceOf(AppliedState::class, AdjustmentState::Applied->behavior());
    }

    #[Test]
    public function los_metodos_del_enum_conservan_los_atajos_existentes(): void
    {
        $this->assertTrue(AdjustmentState::Recorded->canNotify());
        $this->assertTrue(AdjustmentState::Notified->canApply());
        $this->assertFalse(AdjustmentState::Applied->canEdit());
        $this->assertTrue(AdjustmentState::Applied->countsTowardsChain());
    }
}
