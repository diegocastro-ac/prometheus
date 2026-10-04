<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Filament\Resources\InvoiceResource\Pages\CreateInvoice;
use App\Filament\Resources\InvoiceResource\Pages\ListInvoices;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Rental;
use App\Models\User;
use App\Settings\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * La pantalla de facturas sustituyo a la de pagos por alquiler. Estos tests
 * fijan las tres reglas que la sostienen: el consecutivo lo genera el sistema,
 * el saldo se calcula contra la factura y nadie ve los datos de otro
 * arrendador.
 */
class InvoiceResourceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Rental $rental;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('app_settings')->insert([
            'id' => 1,
            'business_name' => 'Inmobiliaria Ejemplo S.A.S.',
            'tax_id' => '900123456-7',
            'currency' => 'COP',
            'invoice_due_days' => 5,
            'space_catalog' => json_encode(['Sala']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->user = User::factory()->create();
        $this->rental = Rental::factory()->create(['user_id' => $this->user->id]);

        $this->actingAs($this->user);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function baseForm(array $overrides = []): array
    {
        return array_merge([
            'rental_id' => $this->rental->id,
            'concept' => 'rent',
            'custom_concept' => null,
            'amount' => 1_200_000,
            'period' => '2026-03',
            'issued_at' => today()->format('Y-m-d'),
            'due_at' => today()->addDays(5)->format('Y-m-d'),
            'notes' => null,
        ], $overrides);
    }

    #[Test]
    public function crea_la_factura_con_numero_asignado_por_el_sistema(): void
    {
        // El numero no viaja en el formulario: si se enviara, dos usuarios
        // podrian escribir el mismo.
        Livewire::test(CreateInvoice::class)
            ->fillForm($this->baseForm())
            ->call('create')
            ->assertHasNoFormErrors();

        $invoice = Invoice::sole();

        $this->assertSame('FV-'.now()->format('Y').'-0001', $invoice->number);
        $this->assertSame(InvoiceStatus::EMITIDA, $invoice->status);
        $this->assertSame($this->user->id, $invoice->user_id);
        $this->assertNull($invoice->paid_at);
    }

    #[Test]
    public function el_vencimiento_por_defecto_toma_los_dias_de_ajustes(): void
    {
        $this->assertSame(
            today()->addDays(app(AppSettings::class)->invoiceDueDays())->toDateString(),
            Livewire::test(CreateInvoice::class)->get('data.due_at'),
        );
    }

    #[Test]
    public function el_concepto_otros_se_guarda_con_el_texto_escrito(): void
    {
        Livewire::test(CreateInvoice::class)
            ->fillForm($this->baseForm([
                'concept' => 'other',
                'custom_concept' => 'Reparacion de gotera',
            ]))
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame('Reparacion de gotera', Invoice::sole()->concept);
    }

    #[Test]
    public function la_creacion_no_pide_concepto_personalizado_cuando_no_es_otros(): void
    {
        Livewire::test(CreateInvoice::class)
            ->fillForm($this->baseForm())
            ->call('create')
            ->assertHasNoFormErrors();

        // custom_concept es un campo de apoyo: nunca debe terminar en la tabla.
        $this->assertArrayNotHasKey('custom_concept', Invoice::sole()->getAttributes());
    }

    #[Test]
    public function una_factura_necesita_alquiler_concepto_valor_y_periodo(): void
    {
        Livewire::test(CreateInvoice::class)
            ->fillForm($this->baseForm([
                'rental_id' => null,
                'amount' => null,
                'period' => null,
            ]))
            ->call('create')
            ->assertHasFormErrors(['rental_id', 'amount', 'period']);
    }

    #[Test]
    public function el_periodo_se_guarda_tal_cual_se_escribio(): void
    {
        // Puede no coincidir con el mes de emision: facturar por adelantado es
        // normal y el periodo es lo que dice que mes se esta cobrando.
        Livewire::test(CreateInvoice::class)
            ->fillForm($this->baseForm([
                'period' => '2026-04',
                'issued_at' => today()->format('Y-m-d'),
            ]))
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame('2026-04', Invoice::sole()->period);
    }

    #[Test]
    public function un_periodo_que_no_es_mes_valido_lo_rechaza_la_validacion(): void
    {
        foreach (['2026-13', '2026-00', 'marzo-2026', '2026-3', '20263'] as $invalid) {
            Livewire::test(CreateInvoice::class)
                ->fillForm($this->baseForm(['period' => $invalid]))
                ->call('create')
                ->assertHasFormErrors(['period']);
        }

        $this->assertSame(0, Invoice::count());
    }

    #[Test]
    public function el_periodo_acepta_un_mes_valido_como_diciembre(): void
    {
        Livewire::test(CreateInvoice::class)
            ->fillForm($this->baseForm(['period' => '2026-12']))
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame('2026-12', Invoice::sole()->period);
    }

    #[Test]
    public function la_lista_solo_muestra_las_facturas_del_usuario_actual(): void
    {
        $other = User::factory()->create();
        $otherRental = Rental::factory()->create(['user_id' => $other->id]);

        Invoice::factory()->create([
            'rental_id' => $this->rental->id,
            'user_id' => $this->user->id,
        ]);

        Invoice::factory()->create([
            'rental_id' => $otherRental->id,
            'user_id' => $other->id,
        ]);

        Livewire::test(ListInvoices::class)
            ->assertCanSeeTableRecords(Invoice::where('user_id', $this->user->id)->get())
            ->assertCanNotSeeTableRecords(Invoice::where('user_id', $other->id)->get());
    }

    #[Test]
    public function la_accion_de_pago_registra_el_abono_y_cierra_la_factura(): void
    {
        $invoice = Invoice::factory()->create([
            'rental_id' => $this->rental->id,
            'user_id' => $this->user->id,
            'amount' => 1_000_000,
            'status' => InvoiceStatus::EMITIDA,
            'due_at' => today()->addDays(5)->toDateString(),
        ]);

        Livewire::test(ListInvoices::class)
            ->callTableAction('record_payment', $invoice, data: [
                'amount' => 1_000_000,
                'date' => today()->toDateString(),
                'method' => 'transferencia',
                'reference' => 'REF-9',
            ])
            ->assertHasNoActionErrors();

        $payment = Payment::sole();

        $this->assertSame($invoice->id, $payment->invoice_id);
        $this->assertSame('transferencia', $payment->method);
        $this->assertSame('REF-9', $payment->reference);
        $this->assertSame(InvoiceStatus::PAGADA, $invoice->fresh()->status);
    }

    #[Test]
    public function un_abono_parcial_no_cierra_la_factura(): void
    {
        $invoice = Invoice::factory()->create([
            'rental_id' => $this->rental->id,
            'user_id' => $this->user->id,
            'amount' => 1_000_000,
            'status' => InvoiceStatus::EMITIDA,
            'due_at' => today()->addDays(5)->toDateString(),
        ]);

        Livewire::test(ListInvoices::class)
            ->callTableAction('record_payment', $invoice, data: [
                'amount' => 250_000,
                'date' => today()->toDateString(),
                'method' => 'efectivo',
            ])
            ->assertHasNoActionErrors();

        $this->assertSame(750_000.0, $invoice->fresh()->balance());
        $this->assertSame(InvoiceStatus::EMITIDA, $invoice->fresh()->status);
    }

    #[Test]
    public function el_boton_de_pago_no_aparece_en_una_factura_pagada(): void
    {
        $paid = Invoice::factory()->create([
            'rental_id' => $this->rental->id,
            'user_id' => $this->user->id,
            'status' => InvoiceStatus::PAGADA,
        ]);

        $open = Invoice::factory()->create([
            'rental_id' => $this->rental->id,
            'user_id' => $this->user->id,
            'status' => InvoiceStatus::EMITIDA,
        ]);

        Livewire::test(ListInvoices::class)
            ->assertTableActionHidden('record_payment', $paid)
            ->assertTableActionVisible('record_payment', $open);
    }

    #[Test]
    public function anular_una_factura_impide_seguir_abonando(): void
    {
        $invoice = Invoice::factory()->create([
            'rental_id' => $this->rental->id,
            'user_id' => $this->user->id,
            'amount' => 1_000_000,
            'status' => InvoiceStatus::EMITIDA,
        ]);

        Livewire::test(ListInvoices::class)
            ->callTableAction('annul', $invoice);

        $this->assertSame(InvoiceStatus::ANULADA, $invoice->fresh()->status);

        // La regla se comprueba en el servicio, no solo ocultando el boton.
        $this->expectException(\DomainException::class);

        app(\App\Services\PaymentRecordingService::class)->record($invoice->fresh(), 100_000);
    }

    #[Test]
    public function el_filtro_por_estado_separa_las_facturas_pendientes(): void
    {
        Invoice::factory()->create([
            'rental_id' => $this->rental->id,
            'user_id' => $this->user->id,
            'status' => InvoiceStatus::PAGADA,
        ]);

        $pending = Invoice::factory()->create([
            'rental_id' => $this->rental->id,
            'user_id' => $this->user->id,
            'status' => InvoiceStatus::EMITIDA,
        ]);

        Livewire::test(ListInvoices::class)
            ->filterTable('status', InvoiceStatus::PAGADA->value)
            ->assertCanSeeTableRecords(Invoice::where('status', InvoiceStatus::PAGADA)->get())
            ->assertCanNotSeeTableRecords([$pending]);
    }
}
