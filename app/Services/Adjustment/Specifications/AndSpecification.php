<?php

namespace App\Services\Adjustment\Specifications;

/**
 * Composicion AND.
 *
 * Existe para poder escribir condiciones compuestas legiblemente:
 *
 *     $spec = (new IsDueForReajuste($series))
 *         ->and(new HasCommercialValue())
 *         ->and(new Not(new IsPension()));
 *
 * En vez de un metodo isSatisfiedBy con cinco condiciones encadenadas, donde anadir
 * una obliga a leer todas para entender una.
 */
final readonly class AndSpecification implements Specification
{
    public function __construct(
        private Specification $left,
        private Specification $right,
    ) {}

    public function isSatisfiedBy(mixed $subject): bool
    {
        return $this->left->isSatisfiedBy($subject) && $this->right->isSatisfiedBy($subject);
    }

    public function and(Specification $other): Specification
    {
        return new self($this, $other);
    }

    public function or(Specification $other): Specification
    {
        return new OrSpecification($this, $other);
    }

    public function not(): Specification
    {
        return new NotSpecification($this);
    }
}
