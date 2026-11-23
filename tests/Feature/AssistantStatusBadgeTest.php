<?php

namespace Tests\Feature;

use App\Support\AssistantStatusBadge;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * El badge de estado del chat tiene tres niveles: enum conocido (color de
 * dominio), color propuesto por la IA para la columna, y gris por defecto.
 * Estos tests fijan esa precedencia y el fallback para valores libres.
 */
class AssistantStatusBadgeTest extends TestCase
{
    #[Test]
    public function enum_conocido_gana_sobre_el_color_de_la_ia(): void
    {
        $badge = AssistantStatusBadge::for('status', 'vencida', 'success');

        $this->assertNotNull($badge);
        $this->assertStringContainsString('danger', $badge['classes']);
    }

    #[Test]
    public function valor_libre_en_columna_de_estado_usa_el_color_de_la_ia(): void
    {
        $badge = AssistantStatusBadge::for('method', 'Nequi', 'info');

        $this->assertNotNull($badge);
        $this->assertSame('Nequi', $badge['label']);
        $this->assertStringContainsString('info', $badge['classes']);
    }

    #[Test]
    public function valor_libre_sin_color_de_ia_cae_al_gris_por_defecto(): void
    {
        $badge = AssistantStatusBadge::for('method', 'Nequi');

        $this->assertNotNull($badge);
        $this->assertStringContainsString('gray', $badge['classes']);
    }

    #[Test]
    public function columna_que_no_es_de_estado_no_pinta_badge_aunque_haya_color(): void
    {
        $this->assertNull(AssistantStatusBadge::for('name', 'Nequi', 'info'));
        $this->assertNull(AssistantStatusBadge::for('amount', '500000', 'danger'));
    }

    #[Test]
    public function columna_booleana_pinta_si_no_sin_importar_el_color_de_la_ia(): void
    {
        $badge = AssistantStatusBadge::for('is_active', 'Sí', 'danger');

        $this->assertNotNull($badge);
        $this->assertStringContainsString('success', $badge['classes']);
    }

    #[Test]
    public function vacios_y_guiones_no_son_badge(): void
    {
        $this->assertNull(AssistantStatusBadge::for('status', null, 'info'));
        $this->assertNull(AssistantStatusBadge::for('status', '', 'info'));
        $this->assertNull(AssistantStatusBadge::for('status', '—', 'info'));
    }
}
