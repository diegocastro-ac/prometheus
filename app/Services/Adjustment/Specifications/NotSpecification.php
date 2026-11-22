<?php

namespace App\Services\Adjustment\Specifications;

/**
 * Negacion.
 *
 * Existe para dejar explicito un "no" en un nombre en vez de esconderlo en una
 * comparacion negativa dentro de otra condicion. "notYetAdjusted" se lee; "no
 * tiene applied_on" tambien, pero solo si ya sabes que eso significa.
 */
final readonly class NotSpecification implements Specification
{
    public function __construct(
        private Specification $inner,
    ) {}

    public function isSatisfiedBy(mixed $subject): bool
    {
        return ! $this->inner->isSatisfiedBy($subject);
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
        return new self($this);
    }
}
