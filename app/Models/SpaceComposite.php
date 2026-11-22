<?php

namespace App\Models;

use Illuminate\Support\Collection;

class SpaceComposite extends SpaceComponent
{
    /**
     * @param  list<SpaceComponent>  $children
     * @param  Collection<int, ActItem>  $items
     */
    public function __construct(Space $space, private readonly array $children, Collection $items)
    {
        parent::__construct($space, $items);
    }

    public function children(): array
    {
        return $this->children;
    }

    public function isComposite(): bool
    {
        return true;
    }
}
