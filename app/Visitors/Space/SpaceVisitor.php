<?php

namespace App\Visitors\Space;

use App\Models\SpaceComposite;
use App\Models\SpaceLeaf;

/**
 * Visitor (GoF) sobre el Composite de espacios.
 *
 * La estructura solo sabe recibir al visitante; cada operacion concreta decide
 * que hacer con una hoja y con un compuesto. Asi se agregan operaciones sin
 * tocar SpaceLeaf ni SpaceComposite.
 */
interface SpaceVisitor
{
    public function visitLeaf(SpaceLeaf $leaf): mixed;

    public function visitComposite(SpaceComposite $composite): mixed;
}
