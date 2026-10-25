<?php

namespace App\Services\Adjustment\Specifications;

/**
 * Composicion OR.
 *
 * Se usa menos que AND, pero hace falta para preguntas como "el alquiler ya
 * cumple los doce meses O tiene un reajuste pendiente de aplicar", que es la
 * forma correcta de decidir si hay algo que hacer con el.
 */
final readonly class OrSpecification implements Specification
{
    public function __construct(
        private Specification $left,
        private Specification $right,
    ) {}

    public function isSatisfiedBy(mixed $subject): bool
    {
        return $this->left->isSatisfiedBy($subject) || $this->right->isSatisfiedBy($subject);
    }

    public function and(Specification $other): Specification
    {
        return new AndSpecification($this, $other);
    }

    public function or(Specification $other): Specification
    {
        return new self($this, $other);
    }

    public function not(): Specification
    {
        return new NotSpecification($this);
    }
}
