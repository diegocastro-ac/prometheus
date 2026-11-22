<?php

namespace App\Services\Adjustment\Decorators;

use App\Dto\IpcSnapshot;
use App\Services\Adjustment\Strategies\IpcBasisStrategy;

/**
 * Limita el IPC al porcentaje que las partes pactaron por debajo (Decorator).
 *
 * El articulo 20 autoriza "hasta" el 100 % del IPC. Ese "hasta" admite que las
 * partes acuerden menos, y en la practica es lo mas comun: un arrendador que no
 * quiere perder inquilinos aplica la mitad.
 *
 * El minimo entre lo pactado y lo legal se aplica aqui y no dentro de la
 * estrategia por dos razones. La estrategia responde "que dice la ley", que es
 * una pregunta que no cambia segun el contrato. Y si el minimo viviera dentro,
 * al pedir el IPC del articulo 20 para mostrarlo en la carta se devolveria el
 * pactado, que es justo el dato que la carta tiene que mostrar aparte.
 *
 * Por eso el decorador envuelve a la estrategia y solo cambia lo que se aplica,
 * nunca lo que la ley dice. Las dos cifras siguen viajando separadas.
 */
class PactadoCapDecorator implements IpcBasisStrategy
{
    public function __construct(
        private readonly IpcBasisStrategy $inner,
        private readonly ?float $pactadoPercentage,
        private readonly ?string $geographyCode = null,
    ) {}

    public function name(): string
    {
        return $this->inner->name().'+pactado';
    }

    public function isStatutory(): bool
    {
        return $this->inner->isStatutory();
    }

    public function description(): string
    {
        return $this->inner->description();
    }

    /**
     * La estrategia envuelta, para consultar el IPC legal sin el pacto aplicado.
     */
    public function inner(): IpcBasisStrategy
    {
        return $this->inner;
    }

    public function hasPactadoCap(): bool
    {
        return $this->pactadoPercentage !== null;
    }

    /**
     * El IPC legal, antes de aplicar el pacto.
     *
     * Es la cifra que exige el articulo 20. El pacto no la toca: pactar menos
     * cambia lo que las partes acuerdan a pagar, no lo que la ley permite como
     * maximo. Por eso viaja como metodo propio y no como un parametro del
     * resolve().
     */
    public function legalSnapshot(\DateTimeInterface $effectiveFrom, ?string $geographyCode = null): IpcSnapshot
    {
        return $this->inner->resolve($effectiveFrom, $geographyCode ?? $this->geographyCode);
    }

    public function resolve(\DateTimeInterface $effectiveFrom, ?string $geographyCode = null): IpcSnapshot
    {
        $legal = $this->legalSnapshot($effectiveFrom, $geographyCode);

        if ($this->pactadoPercentage === null) {
            return $legal;
        }

        // Un pacto por encima del IPC no es un tope: es un incremento. El
        // decorador solo puede bajar, nunca subir, y por eso no se toma el max
        // con el legal. Subir lo decide el arrendador en la pantalla, donde se
        // avisa de que excede el IPC.
        $applied = min($this->pactadoPercentage, $legal->percentage);

        $explanation = $applied < $legal->percentage
            ? sprintf(
                'Las partes pactaron %.2f %%, por debajo del %.2f %% del IPC. Se aplica el pactado.',
                $applied,
                $legal->percentage,
            )
            : $legal->explanation;

        return new IpcSnapshot(
            percentage: $applied,
            year: $legal->year,
            month: $legal->month,
            geographyCode: $legal->geographyCode,
            geographyName: $legal->geographyName,
            basis: $legal->basis,
            isStatutory: $legal->isStatutory,
            index: $legal->index,
            sourceName: $legal->sourceName,
            sourceUrl: $legal->sourceUrl,
            syncedAt: $legal->syncedAt,
            explanation: $explanation,
        );
    }
}
