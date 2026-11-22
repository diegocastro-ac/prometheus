<?php

namespace Tests\Unit;

use App\Dto\IpcSnapshot;
use App\Enums\IpcBasis;
use App\Services\Adjustment\Decorators\PactadoCapDecorator;
use App\Services\Adjustment\Strategies\IpcBasisStrategy;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * El decorador cambia lo aplicado, nunca lo que dice la ley.
 *
 * La separacion entre legalSnapshot() y resolve() es la que permite que la
 * carta muestre el 9,28 % del IPC aunque las partes hayan pactado un 3 %. Si
 * ambas cifras salieran del mismo metodo, el tope legal se confundiria con la
 * cifra acordada.
 */
class PactadoCapDecoratorTest extends TestCase
{
    private function effectiveFrom(): \DateTimeInterface
    {
        return new \DateTimeImmutable('2026-06-01');
    }

    private function inner(float $percentage = 9.28): IpcBasisStrategy
    {
        return new class($percentage) implements IpcBasisStrategy
        {
            public function __construct(private readonly float $percentage) {}

            public function name(): string
            {
                return 'annual_variation';
            }

            public function resolve(\DateTimeInterface $effectiveFrom, ?string $geographyCode = null): IpcSnapshot
            {
                return $this->snapshot()->withExplanation('IPC legal.');
            }

            public function isStatutory(): bool
            {
                return true;
            }

            public function description(): string
            {
                return 'Variacion anual';
            }

            private function snapshot(): IpcSnapshot
            {
                return new IpcSnapshot(
                    percentage: $this->percentage,
                    year: 2025,
                    month: 12,
                    geographyCode: '00',
                    geographyName: 'NACIONAL',
                    basis: IpcBasis::National->value,
                    isStatutory: true,
                );
            }
        };
    }

    #[Test]
    public function el_pacto_baja_lo_aplicado_pero_no_el_ipc_legal(): void
    {
        $decorator = new PactadoCapDecorator($this->inner(9.28), 3.0);

        $this->assertSame(9.28, $decorator->legalSnapshot($this->effectiveFrom())->percentage);
        $this->assertSame(3.0, $decorator->resolve($this->effectiveFrom())->percentage);
        $this->assertStringContainsString('pactaron 3.00 %', $decorator->resolve($this->effectiveFrom())->explanation);
    }

    #[Test]
    public function el_pacto_no_puede_subir_por_encima_del_ipc(): void
    {
        $decorator = new PactadoCapDecorator($this->inner(9.28), 15.0);

        $this->assertSame(9.28, $decorator->resolve($this->effectiveFrom())->percentage);
        $this->assertSame(9.28, $decorator->legalSnapshot($this->effectiveFrom())->percentage);
    }

    #[Test]
    public function sin_pacto_el_decorador_deja_pasar_el_snapshot_legal(): void
    {
        $decorator = new PactadoCapDecorator($this->inner(9.28), null);

        $this->assertFalse($decorator->hasPactadoCap());
        $this->assertSame(9.28, $decorator->resolve($this->effectiveFrom())->percentage);
        $this->assertSame('IPC legal.', $decorator->resolve($this->effectiveFrom())->explanation);
    }

    #[Test]
    public function delega_la_identidad_de_la_estrategia_envuelta(): void
    {
        $inner = $this->inner();
        $decorator = new PactadoCapDecorator($inner, 3.0);

        $this->assertSame($inner, $decorator->inner());
        $this->assertSame('annual_variation+pactado', $decorator->name());
        $this->assertTrue($decorator->isStatutory());
        $this->assertSame('Variacion anual', $decorator->description());
    }
}
