<?php

namespace Tests\Feature;

use App\Models\ActItem;
use App\Models\Space;
use App\Models\SpaceComposite;
use App\Models\SpaceLeaf;
use App\Visitors\Space\InventoryReportVisitor;
use App\Visitors\Space\SpaceVisitor;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * El visitante del Composite de espacios.
 *
 * Lo que se fija aqui es el doble despacho y el reporte que reemplazo a los
 * condicionales por tipo del presenter: la hoja aporta lo propio y el compuesto
 * suma el total de sus hijos.
 */
class SpaceVisitorTest extends TestCase
{
    private function space(string $name): Space
    {
        return new Space(['name' => $name]);
    }

    /**
     * @return Collection<int, ActItem>
     */
    private function items(int $count): Collection
    {
        return collect($count > 0 ? range(1, $count) : [])
            ->map(fn (int $index): ActItem => new ActItem([
                'name' => "Item {$index}",
                'state' => 'bueno',
            ]))
            ->values();
    }

    #[Test]
    public function accept_despacha_al_metodo_del_tipo_concreto(): void
    {
        $leaf = new SpaceLeaf($this->space('Alacena'), collect());
        $composite = new SpaceComposite($this->space('Casa'), [$leaf], collect());

        $visitor = new class implements SpaceVisitor
        {
            /** @var list<string> */
            public array $calls = [];

            public function visitLeaf(SpaceLeaf $leaf): mixed
            {
                $this->calls[] = 'leaf';

                return null;
            }

            public function visitComposite(SpaceComposite $composite): mixed
            {
                $this->calls[] = 'composite';

                return null;
            }
        };

        $composite->accept($visitor);
        $leaf->accept($visitor);

        $this->assertSame(['composite', 'leaf'], $visitor->calls);
    }

    #[Test]
    public function la_hoja_reporta_solo_sus_elementos(): void
    {
        $leaf = new SpaceLeaf($this->space('Alacena'), $this->items(2));

        /** @var \App\Visitors\Space\SpaceInventoryReport $report */
        $report = $leaf->accept(new InventoryReportVisitor);

        $this->assertSame('leaf', $report->kind);
        $this->assertSame(2, $report->directItems);
        $this->assertSame(2, $report->totalItems);
        $this->assertSame(2, $report->items->count());
    }

    #[Test]
    public function el_compuesto_suma_el_total_de_sus_hijos(): void
    {
        $alacena = new SpaceLeaf($this->space('Alacena'), $this->items(2));
        $cocina = new SpaceComposite($this->space('Cocina'), [$alacena], collect());
        $casa = new SpaceComposite($this->space('Casa'), [$cocina], $this->items(1));

        /** @var \App\Visitors\Space\SpaceInventoryReport $report */
        $report = $casa->accept(new InventoryReportVisitor);

        $this->assertSame('composite', $report->kind);
        $this->assertSame(1, $report->directItems);
        $this->assertSame(3, $report->totalItems);
        $this->assertSame(1, $report->items->count());
    }

    #[Test]
    public function total_items_conserva_el_resultado_anterior(): void
    {
        $alacena = new SpaceLeaf($this->space('Alacena'), $this->items(2));
        $cocina = new SpaceComposite($this->space('Cocina'), [$alacena], collect());
        $casa = new SpaceComposite($this->space('Casa'), [$cocina], $this->items(1));
        $vacia = new SpaceComposite($this->space('Vacia'), [], collect());

        $this->assertSame(3, $casa->totalItems());
        $this->assertSame(2, $cocina->totalItems());
        $this->assertSame(2, $alacena->totalItems());
        $this->assertSame(0, $vacia->totalItems());
    }
}
