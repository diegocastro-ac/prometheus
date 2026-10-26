<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\InvoiceNumberService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InvoiceNumberTest extends TestCase
{
    use RefreshDatabase;

    private InvoiceNumberService $numbers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->numbers = app(InvoiceNumberService::class);
    }

    #[Test]
    public function la_primera_factura_de_un_arrendador_empieza_en_uno(): void
    {
        $userId = User::factory()->create()->id;

        $this->assertSame('FV-2026-0001', $this->numbers->next($userId, 2026));
    }

    #[Test]
    public function el_consecutivo_incrementa_sin_repetir_numero(): void
    {
        $userId = User::factory()->create()->id;

        $first = $this->numbers->next($userId, 2026);
        $second = $this->numbers->next($userId, 2026);
        $third = $this->numbers->next($userId, 2026);

        $this->assertSame(['FV-2026-0001', 'FV-2026-0002', 'FV-2026-0003'], [$first, $second, $third]);
    }

    #[Test]
    public function dos_arrendadores_empiezan_en_uno_sin_chocar(): void
    {
        $a = User::factory()->create()->id;
        $b = User::factory()->create()->id;

        $this->assertSame('FV-2026-0001', $this->numbers->next($a, 2026));
        $this->assertSame('FV-2026-0001', $this->numbers->next($b, 2026));
        $this->assertSame('FV-2026-0002', $this->numbers->next($a, 2026));
    }

    #[Test]
    public function el_consecutivo_se_reinicia_cada_ano(): void
    {
        $userId = User::factory()->create()->id;

        $this->assertSame('FV-2026-0001', $this->numbers->next($userId, 2026));
        $this->assertSame('FV-2026-0002', $this->numbers->next($userId, 2026));
        $this->assertSame('FV-2026-0003', $this->numbers->next($userId, 2026));

        // Enero reinicia: el numero de 2027 no arrastra el de 2026.
        $this->assertSame('FV-2027-0001', $this->numbers->next($userId, 2027));
        $this->assertSame('FV-2027-0002', $this->numbers->next($userId, 2027));
    }

    #[Test]
    public function un_arrendador_no_consume_el_consecutivo_de_otro(): void
    {
        $a = User::factory()->create()->id;
        $b = User::factory()->create()->id;

        $this->assertSame('FV-2026-0001', $this->numbers->next($a, 2026));
        $this->assertSame('FV-2026-0002', $this->numbers->next($a, 2026));

        // El segundo arrendador arranca en uno aunque el primero ya emitio.
        $this->assertSame('FV-2026-0001', $this->numbers->next($b, 2026));
        $this->assertSame('FV-2026-0003', $this->numbers->next($a, 2026));
    }

    #[Test]
    public function el_ano_se_toma_del_parametro_y_no_de_la_fecha_del_sistema(): void
    {
        $userId = User::factory()->create()->id;

        // Facturar con fecha retroactiva da un numero de ese ano, sin tocar el
        // contador del ano en curso.
        $this->assertSame('FV-2025-0001', $this->numbers->next($userId, 2025));
        $this->assertSame('FV-'.now()->format('Y').'-0001', $this->numbers->next($userId));
    }

    #[Test]
    public function el_numero_se_padece_cuatro_digitos_hasta_pasar_el_mil(): void
    {
        $userId = User::factory()->create()->id;

        for ($i = 1; $i < 1000; $i++) {
            $this->numbers->next($userId, 2026);
        }

        $this->assertSame('FV-2026-1000', $this->numbers->next($userId, 2026));
    }
}
