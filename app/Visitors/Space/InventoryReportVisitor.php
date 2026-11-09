<?php

namespace App\Visitors\Space;

use App\Models\SpaceComposite;
use App\Models\SpaceLeaf;

/**
 * Visitante que resume un espacio para la pantalla del acta.
 *
 * La hoja aporta solo sus elementos. El compuesto conserva los propios y suma
 * el total de cada hijo mediante doble despacho: no pregunta por el tipo ni
 * repite la recursion del modelo.
 */
final class InventoryReportVisitor implements SpaceVisitor
{
    public function visitLeaf(SpaceLeaf $leaf): SpaceInventoryReport
    {
        $items = $leaf->items();

        return new SpaceInventoryReport(
            kind: 'leaf',
            directItems: $items->count(),
            totalItems: $items->count(),
            items: $items,
        );
    }

    public function visitComposite(SpaceComposite $composite): SpaceInventoryReport
    {
        $items = $composite->items();
        $totalItems = $items->count();

        foreach ($composite->children() as $child) {
            /** @var SpaceInventoryReport $report */
            $report = $child->accept($this);

            $totalItems += $report->totalItems;
        }

        return new SpaceInventoryReport(
            kind: 'composite',
            directItems: $items->count(),
            totalItems: $totalItems,
            items: $items,
        );
    }
}
