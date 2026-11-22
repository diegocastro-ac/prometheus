<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Filament\Pages\FinanzasPage;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Rental;
use App\Models\User;
use App\Services\FinancialAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;

/**
 * La pagina de finanzas y las metricas que la alimentan.
 *
 * Lo importante aqui es el aislamiento: cada consulta del servicio debe
 * devolver solo datos del usuario autenticado.
 */
class FinancePageTest extends \Tests\TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    #[Test]
    public function la_pagina_renderiza(): void
    {
        Livewire::test(FinanzasPage::class)
            ->assertOk()
            ->assertSee(__('finance.title'));
    }

    #[Test]
    public function aging_agrupa_saldos_por_dias_de_mora(): void
    {
        $rental = Rental::factory()->create(['user_id' => $this->user->id]);

        Invoice::factory()->create([
            'user_id' => $this->user->id,
            'rental_id' => $rental->id,
            'status' => InvoiceStatus::EMITIDA,
            'amount' => 100_000,
            'due_at' => now()->addDays(10),
        ]);
        Invoice::factory()->create([
            'user_id' => $this->user->id,
            'rental_id' => $rental->id,
            'status' => InvoiceStatus::VENCIDA,
            'amount' => 200_000,
            'due_at' => now()->subDays(15),
        ]);
        Invoice::factory()->create([
            'user_id' => $this->user->id,
            'rental_id' => $rental->id,
            'status' => InvoiceStatus::PAGADA,
            'amount' => 999_999,
            'due_at' => now()->subDays(5),
        ]);

        $aging = app(FinancialAnalysisService::class)->agingBuckets($this->user->id);

        $this->assertSame(100_000.0, $aging['buckets']['current']);
        $this->assertSame(200_000.0, $aging['buckets']['d30']);
        $this->assertSame(300_000.0, $aging['total']);
    }

    #[Test]
    public function top_debtors_solo_incluye_al_dueno(): void
    {
        $own = Rental::factory()->create(['user_id' => $this->user->id]);
        $other = User::factory()->create();
        $foreign = Rental::factory()->create(['user_id' => $other->id]);

        Invoice::factory()->create([
            'user_id' => $this->user->id,
            'rental_id' => $own->id,
            'status' => InvoiceStatus::VENCIDA,
            'amount' => 500_000,
            'due_at' => now()->subDays(20),
        ]);
        Invoice::factory()->create([
            'user_id' => $other->id,
            'rental_id' => $foreign->id,
            'status' => InvoiceStatus::VENCIDA,
            'amount' => 9_999_999,
            'due_at' => now()->subDays(20),
        ]);

        $debtors = app(FinancialAnalysisService::class)->topDebtors($this->user->id);

        $this->assertCount(1, $debtors);
        $this->assertSame($own->name, $debtors[0]['rental']);
        $this->assertSame(500_000.0, $debtors[0]['balance']);
    }

    #[Test]
    public function pagos_por_metodo_y_actividad_estan_acotados(): void
    {
        $rental = Rental::factory()->create(['user_id' => $this->user->id]);
        $invoice = Invoice::factory()->create([
            'user_id' => $this->user->id,
            'rental_id' => $rental->id,
            'status' => InvoiceStatus::PAGADA,
        ]);

        Payment::factory()->create([
            'user_id' => $this->user->id,
            'rental_id' => $rental->id,
            'invoice_id' => $invoice->id,
            'amount' => 100_000,
            'method' => 'efectivo',
            'date' => now()->subDays(3),
        ]);
        Payment::factory()->create([
            'user_id' => User::factory()->create()->id,
            'rental_id' => Rental::factory()->create()->id,
            'amount' => 777_777,
            'method' => 'transferencia',
            'date' => now()->subDays(1),
        ]);

        $service = app(FinancialAnalysisService::class);
        $methods = $service->paymentsByMethod($this->user->id);
        $recent = $service->recentPayments($this->user->id);

        $this->assertCount(1, $methods);
        $this->assertSame('efectivo', $methods[0]['method']);
        $this->assertSame(100_000.0, $methods[0]['amount']);
        $this->assertCount(1, $recent);
        $this->assertSame(100_000.0, (float) $recent->first()->amount);
    }
}
