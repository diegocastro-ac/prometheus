<?php

namespace App\Services\Adjustment\Specifications;

use App\Models\Rental;
use App\Services\Ipc\IpcSeriesRepository;

/**
 * "¿Le toca reajustarse a este alquiler?"
 *
 * Compuesta con AND, y con AND a proposito. Un alquiler sin canon base no se
 * puede recalcular, y uno sin el IPC del ano tampoco. Que faltan datos no es una
 * excepcion: es la respuesta mas util que el sistema puede dar, porque señala
 * exactamente que hay que hacer antes de poder reajustar.
 *
 * Se responde distinto al articulo 20, que cuenta los doce meses "bajo un mismo
 * precio". Aqui la pregunta es de disponibilidad, no de plazo legal: el plazo lo
 * comprueba WithinTwelveMonthsSamePrice con su mensaje y su severidad. Aqui solo
 * se determina si el sistema PUEDE calcular, para no ofrecer un reajuste que va a
 * fallar.
 */
class IsDueForReajuste implements Specification
{
    public function __construct(
        private readonly IpcSeriesRepository $series,
    ) {}

    public function isSatisfiedBy(mixed $subject): bool
    {
        if (! $subject instanceof Rental) {
            return false;
        }

        return $this->hasBaseAmount($subject)
            && $this->hasIpcAvailable($subject)
            && $this->notYetAdjusted($subject);
    }

    public function and(Specification $other): Specification
    {
        return new AndSpecification($this, $other);
    }

    public function or(Specification $other): Specification
    {
        return new OrSpecification($this, $other);
    }

    public function not(): Specification
    {
        return new NotSpecification($this);
    }

    /**
     * Hay que poder calcular sobre un monto conocido.
     */
    private function hasBaseAmount(Rental $rental): bool
    {
        return $rental->anchorRent() !== null;
    }

    /**
     * Hay que tener el IPC del ano anterior al de vigencia.
     */
    private function hasIpcAvailable(Rental $rental): bool
    {
        $dueDate = $rental->nextAdjustmentDueDate();

        return $this->series->statutoryForAdjustmentYear(
            \App\Dto\Geography::nationalCode(),
            (int) $dueDate->format('Y'),
        ) !== null;
    }

    /**
     * No se ofrece reajustar un alquiler cuyo ultimo reajuste ya esta en vigor y
     * no hay nada mas que ajustar. Un reajuste registrado y sin aplicar, en
     * cambio, si se ofrece: es un paso pendiente, no una tarea nueva.
     */
    private function notYetAdjusted(Rental $rental): bool
    {
        $pending = $rental->rentAdjustments()
            ->whereNull('applied_on')
            ->exists();

        return $pending || ! $rental->rentAdjustments()->exists();
    }
}
