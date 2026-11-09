<?php

namespace Tests\Unit;

use App\ValueObjects\AdjustmentChainMemento;
use App\ValueObjects\AdjustmentMemento;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * El memento de la cadena es una fotografia indexada por reajuste.
 *
 * No toca la base de datos ni el modelo: solo guarda valores. Por eso se puede
 * probar aislado y el cuidador no necesita abrir el reajuste para comparar.
 */
class AdjustmentMementoTest extends TestCase
{
    #[Test]
    public function la_cadena_localiza_cada_reajuste_por_su_id(): void
    {
        $primero = new AdjustmentMemento(7, 1, 1_000.0, 1_000.0, 1_050.0, 0.0);
        $segundo = new AdjustmentMemento(8, 2, 1_050.0, 1_050.0, 1_100.0, 10.0);

        $cadena = new AdjustmentChainMemento(3, 1_050.0, [
            7 => $primero,
            8 => $segundo,
        ]);

        $this->assertSame(3, $cadena->rentalId);
        $this->assertSame(1_050.0, $cadena->monthlyAmount);
        $this->assertSame(2, $cadena->count());
        $this->assertSame($primero, $cadena->adjustment(7));
        $this->assertSame($segundo, $cadena->adjustment(8));
        $this->assertNull($cadena->adjustment(9));
    }
}
