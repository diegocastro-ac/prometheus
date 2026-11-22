<?php

namespace Tests\Unit;

use App\Infrastructure\Holidays\WeekendBusinessCalendar;
use App\Services\Adjustment\AdjustmentDateProposer;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\Test;

/**
 * El proponente de fechas.
 *
 * Lo que se prueba aqui es una sola cosa, pero es la que importa: la fecha
 * LEGAL nunca se mueve. El servicio devuelve el dia habil siguiente para que el
 * aviso por correo autorizado se pueda entregar, y separarlo de la fecha que
 * corre el plazo es justamente lo que evita que un detalle logistico termine
 * corriendo el computo del articulo 20 por debajo de la mesa.
 *
 * Extiende TestCase de Laravel y no de PHPUnit porque explanation() traduce sus
 * textos: sin el contenedor no hay quien traduzca.
 */
class AdjustmentDateProposerTest extends \Tests\TestCase
{
    private function proposer(): AdjustmentDateProposer
    {
        // El mismo contrato que usan las facturas. Si el servicio de descargas
        // cambia de implementacion, esta prueba sigue significando lo mismo.
        return new AdjustmentDateProposer(new WeekendBusinessCalendar);
    }

    #[Test]
    public function un_dia_habil_se_propone_tal_cual(): void
    {
        // 20 de noviembre de 2026 es viernes.
        $date = Carbon::create(2026, 11, 20);

        $this->assertSame(
            $date->toDateString(),
            $this->proposer()->suggestedDate($date)->toDateString(),
            'si ya es dia habil no hay nada que adelantar',
        );
    }

    #[Test]
    public function un_sabado_se_propone_el_lunes_siguiente(): void
    {
        // 21 de noviembre de 2026 es sabado.
        $this->assertSame(
            '2026-11-23',
            $this->proposer()->suggestedDate(Carbon::create(2026, 11, 21))->toDateString(),
        );
    }

    #[Test]
    public function un_domingo_se_propone_el_lunes_siguiente(): void
    {
        $this->assertSame(
            '2026-11-16',
            $this->proposer()->suggestedDate(Carbon::create(2026, 11, 15))->toDateString(),
        );
    }

    #[Test]
    public function la_explicacion_solo_aparece_cuando_la_fecha_se_movio(): void
    {
        $proposer = $this->proposer();

        $legal = Carbon::create(2026, 11, 15);

        $this->assertSame(
            '',
            $proposer->explanation($legal, $legal),
            'una fecha que no se movio no tiene nada que explicar',
        );

        $this->assertNotSame(
            '',
            $proposer->explanation($legal, $proposer->suggestedDate($legal)),
            'una fecha movida tiene que decir por que y cual era la legal',
        );
    }
}
