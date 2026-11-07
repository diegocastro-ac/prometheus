<?php

namespace App\Models;

use App\Visitors\Space\SpaceVisitor;
use Illuminate\Support\Collection;

class SpaceLeaf extends SpaceComponent
{
    /**
     * @param  Collection<int, ActItem>  $items
     */
    public function __construct(Space $space, Collection $items)
    {
        parent::__construct($space, $items);
    }

    public function children(): array
    {
        return [];
    }

    public function isComposite(): bool
    {
        return false;
    }

    public function accept(SpaceVisitor $visitor): mixed
    {
        return $visitor->visitLeaf($this);
    }
}
