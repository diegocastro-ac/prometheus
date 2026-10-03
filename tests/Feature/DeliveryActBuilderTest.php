<?php

namespace Tests\Feature;

use App\Enums\ActItemState;
use App\Models\DeliveryAct;
use App\Models\Rental;
use App\Models\User;
use App\Services\Acts\DeliveryActBuilder;
use App\Settings\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * El Builder decide que secciones existen. Estos tests comprueban justamente
 * eso: una seccion que no se pide queda en null y no se imprime.
 */
class DeliveryActBuilderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('app_settings')->insert([
            'id' => 1,
            'business_name' => 'Inmobiliaria Ejemplo S.A.S.',
            'tax_id' => '900123456-7',
            'currency' => 'COP',
            'invoice_due_days' => 5,
            'space_catalog' => json_encode(['Sala', 'Cocina', 'Baño', 'Terraza']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function builder(): DeliveryActBuilder
    {
        return app(DeliveryActBuilder::class);
    }

    private function rental(): Rental
    {
        $user = User::factory()->create();

        return Rental::factory()->create(['user_id' => $user->id]);
    }

    #[Test]
    public function arma_un_acta_minima_solo_con_las_secciones_obligatorias(): void
    {
        $rental = $this->rental();

        $act = $this->builder()
            ->forRental($rental, 'Ana Landlord', 'Luis Tenant')
            ->onDate(today())
            ->build();

        $this->assertInstanceOf(DeliveryAct::class, $act);
        $this->assertSame('Ana Landlord', $act->landlord_name);
        $this->assertSame('Luis Tenant', $act->tenant_name);
        $this->assertSame('entrega', $act->type);

        // Ninguna seccion opcional fue pedida, asi que ninguna existe.
        $this->assertNull($act->water_reading);
        $this->assertNull($act->commitments);
        $this->assertNull($act->observations);
        $this->assertCount(0, $act->items);
        $this->assertFalse($act->hasMeterReadings());
    }

    #[Test]
    public function las_secciones_pedidas_si_se_guardan(): void
    {
        $rental = $this->rental();

        $act = $this->builder()
            ->forRental($rental, 'Ana Landlord', 'Luis Tenant')
            ->onDate(today(), '10:30')
            ->ofType('recepcion')
            ->withParties(['landlord_document' => '123', 'tenant_document' => '456'])
            ->withMeterReadings(['water' => '150', 'energy' => '4200'])
            ->withCommitments('Reparar la gotera antes del 20.', 'Sin observaciones generales.')
            ->build();

        $this->assertSame('recepcion', $act->type);
        $this->assertSame(today()->format('Y-m-d').' 10:30:00', $act->scheduled_at->format('Y-m-d H:i:s'));
        $this->assertSame('123', $act->landlord_document);
        $this->assertSame('456', $act->tenant_document);
        $this->assertSame('150', $act->water_reading);
        $this->assertSame('4200', $act->energy_reading);

        // El gas no se leyo, asi que no aparece.
        $this->assertNull($act->gas_reading);
        $this->assertTrue($act->hasMeterReadings());
        $this->assertSame('Reparar la gotera antes del 20.', $act->commitments);
    }

    #[Test]
    public function el_inventario_se_agrupa_por_espacio(): void
    {
        $rental = $this->rental();

        $act = $this->builder()
            ->forRental($rental, 'Ana', 'Luis')
            ->onDate(today())
            ->withInventory([
                ['space' => 'Cocina', 'name' => 'Refrigerador', 'state' => 'bueno'],
                ['space' => 'Cocina', 'name' => 'Estufa', 'state' => 'reparable', 'note' => 'Falla un quemador'],
                ['space' => 'Sala', 'name' => 'Sofá'],
            ])
            ->build();

        $this->assertCount(3, $act->items);

        $bySpace = $act->itemsBySpace();

        $this->assertSame(['Cocina', 'Sala'], array_keys($bySpace));
        $this->assertCount(2, $bySpace['Cocina']);
        $this->assertCount(1, $bySpace['Sala']);

        $estufa = $act->items->firstWhere('name', 'Estufa');
        $this->assertSame(ActItemState::REPARABLE, $estufa->state);
        $this->assertSame('Falla un quemador', $estufa->note);
    }

    #[Test]
    public function un_estado_desconocido_cae_en_bueno(): void
    {
        $rental = $this->rental();

        $act = $this->builder()
            ->forRental($rental, 'Ana', 'Luis')
            ->onDate(today())
            ->withInventory([['space' => 'Sala', 'name' => 'Lampara', 'state' => 'inventado']])
            ->build();

        $this->assertSame(ActItemState::BUENO, $act->items->first()->state);
    }

    #[Test]
    public function un_espacio_fuera_del_catalogo_se_rechaza(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no esta en el catalogo');

        $rental = $this->rental();

        $this->builder()
            ->forRental($rental, 'Ana', 'Luis')
            ->onDate(today())
            ->withInventory([['space' => 'Bodega', 'name' => 'Estante']])
            ->build();
    }

    #[Test]
    public function sin_catalogo_configurado_no_se_puede_inventariar(): void
    {
        DB::table('app_settings')->where('id', 1)->update(['space_catalog' => json_encode([])]);
        app()->forgetInstance(AppSettings::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No hay catalogo de espacios');

        $rental = $this->rental();

        $this->builder()
            ->forRental($rental, 'Ana', 'Luis')
            ->onDate(today())
            ->withInventory([['space' => 'Sala', 'name' => 'Sofá']])
            ->build();
    }

    #[Test]
    public function un_elemento_sin_nombre_se_rechaza(): void
    {
        $this->expectException(RuntimeException::class);

        $rental = $this->rental();

        $this->builder()
            ->forRental($rental, 'Ana', 'Luis')
            ->onDate(today())
            ->withInventory([['space' => 'Sala', 'name' => '']])
            ->build();
    }

    #[Test]
    public function falta_dato_obligatorio_falla_de_forma_explicita(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('obligatorio [landlord_name]');

        $this->builder()
            ->forRental($this->rental(), '', '')
            ->onDate(today())
            ->build();
    }

    #[Test]
    public function el_builder_es_scoped_y_se_reinicia_entre_peticiones(): void
    {
        $first = app(DeliveryActBuilder::class);
        $same = app(DeliveryActBuilder::class);

        // Dentro de la misma peticion es la misma instancia.
        $this->assertSame($first, $same);

        app()->forgetScopedInstances();

        $this->assertNotSame($first, app(DeliveryActBuilder::class));
    }

    #[Test]
    public function las_firmas_marcan_el_acta_como_firmada(): void
    {
        $rental = $this->rental();

        $act = $this->builder()
            ->forRental($rental, 'Ana', 'Luis')
            ->onDate(today())
            ->withSignatures('signatures/ana.png', 'signatures/luis.png', now())
            ->build();

        $this->assertTrue($act->isSigned());
        $this->assertSame('signatures/ana.png', $act->landlord_signature_path);
    }
}