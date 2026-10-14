<?php

namespace App\Services\Adjustment\Strategies;

use App\Dto\IpcSnapshot;

/**
 * Una estrategia que ya tiene la respuesta.
 *
 * Existe para un caso concreto: cuando el ano del IPC viene elegido
 * explicitamente en el formulario, la serie ya se consulto y el resultado no se
 * deduce de una fecha sino de una fila concreta. Envolver ese snapshot en una
 * clase anonima que implemente la interfaz se leeria peor que nombrarla.
 *
 * Tambien es util para las pruebas: fijar un IPC sin sembrar la serie completa.
 */
class FrozenSnapshotStrategy implements IpcBasisStrategy
{
    public function __construct(
        private readonly IpcSnapshot $snapshot,
    ) {}

    public static function of(IpcSnapshot $snapshot): self
    {
        return new self($snapshot);
    }

    public function name(): string
    {
        return 'frozen';
    }

    public function isStatutory(): bool
    {
        return $this->snapshot->isStatutory;
    }

    public function description(): string
    {
        return $this->snapshot->explanation ?? __('ipc.strategies.frozen');
    }

    public function resolve(\DateTimeInterface $effectiveFrom, ?string $geographyCode = null): IpcSnapshot
    {
        return $this->snapshot;
    }
}
