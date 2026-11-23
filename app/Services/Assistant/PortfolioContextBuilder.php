<?php

namespace App\Services\Assistant;

use App\Dto\PortfolioDigest;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Rental;
use App\Models\Tenant;
use App\Services\Adjustment\AdjustmentChainCalculator;
use App\Services\FinancialAnalysisService;

/**
 * Arma el "digest" del portafolio que viaja en el prompt de la IA.
 *
 * Sin este contexto el modelo solo tendria la pregunta y tenderia a inventar
 * cifras. Con el, cada respuesta se apoya en datos reales y acotados al
 * usuario: es la diferencia entre un chatbot generico y un copiloto que
 * conoce el negocio.
 */
class PortfolioContextBuilder
{
    public function __construct(
        private readonly FinancialAnalysisService $analysis,
        private readonly AdjustmentChainCalculator $adjustments,
    ) {}

    public function digestFor(int $userId): PortfolioDigest
    {
        $rentals = Rental::query()
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->with('tenant:id,name', 'property:id,name')
            ->orderBy('name')
            ->get();

        return new PortfolioDigest(
            userId: $userId,
            rentals: $rentals->map(fn (Rental $rental): array => $this->rentalSummary($rental))->all(),
            totals: $this->totalsFor($userId),
            alerts: $this->analysis->alertsFor($userId),
            pendingInvoices: $this->pendingInvoicesFor($userId),
            catalog: $this->catalogFor($userId),
        );
    }

    /**
     * Universo completo de entidades del usuario: TODOS los inquilinos,
     * propiedades y arriendos (activos e inactivos). Sin esto el modelo
     * no puede resolver referencias a entidades fuera del digest de
     * arriendos activos ("el arriendo viejo de Laura", "la propiedad
     * que no tiene arriendo"). Es el mapa de nombres para que el modelo
     * elija el param correcto sin tener que adivinar.
     *
     * @return array<string, mixed>
     */
    private function catalogFor(int $userId): array
    {
        return [
            'tenants' => Tenant::query()
                ->where('user_id', $userId)
                ->orderBy('name')
                ->limit(100)
                ->pluck('name')
                ->all(),
            'properties' => Property::query()
                ->where('user_id', $userId)
                ->orderBy('name')
                ->limit(100)
                ->get(['name', 'address'])
                ->map(fn (Property $property): array => [
                    'name' => $property->name,
                    'address' => $property->address,
                ])
                ->all(),
            'rentals' => Rental::query()
                ->where('user_id', $userId)
                ->with(['tenant:id,name', 'property:id,name'])
                ->orderBy('name')
                ->limit(100)
                ->get(['id', 'name', 'tenant_id', 'property_id', 'is_active'])
                ->map(fn (Rental $rental): array => [
                    'name' => $rental->name,
                    'tenant' => $rental->tenant?->name,
                    'property' => $rental->property?->name,
                    'active' => (bool) $rental->is_active,
                ])
                ->all(),
        ];
    }

    /**
     * Facturas con saldo pendiente, una por una y con su vencimiento.
     *
     * Sin esta lista el modelo no puede resolver referencias relativas
     * ("la factura mas antigua de Valentina", "la ultima vencida"): los
     * arriendos agregados no dicen nada de numeros de factura. Los pagos
     * se suman en UNA consulta agrupada para no hacer N+1 por factura.
     *
     * @return list<array<string, mixed>>
     */
    private function pendingInvoicesFor(int $userId): array
    {
        $invoices = Invoice::query()
            ->where('user_id', $userId)
            ->whereIn('status', [InvoiceStatus::EMITIDA, InvoiceStatus::VENCIDA])
            ->with(['rental:id,name,tenant_id', 'rental.tenant:id,name'])
            ->orderBy('due_at')
            ->get();

        if ($invoices->isEmpty()) {
            return [];
        }

        $paid = Payment::query()
            ->whereIn('invoice_id', $invoices->pluck('id'))
            ->selectRaw('invoice_id, SUM(amount) as paid')
            ->groupBy('invoice_id')
            ->pluck('paid', 'invoice_id');

        return $invoices
            ->map(fn (Invoice $invoice): array => [
                'number' => $invoice->number,
                'rental' => $invoice->rental?->name,
                'tenant' => $invoice->rental?->tenant?->name,
                'period' => $invoice->period,
                'amount' => round((float) $invoice->amount, 2),
                'balance' => round((float) $invoice->amount - (float) ($paid[$invoice->id] ?? 0), 2),
                'status' => $invoice->status->value,
                'due_at' => $invoice->due_at?->toDateString(),
                'days_overdue' => $invoice->due_at !== null && $invoice->due_at->isBefore(today()->startOfDay())
                    ? (int) $invoice->due_at->startOfDay()->diffInDays(today()->startOfDay())
                    : 0,
            ])
            ->filter(fn (array $row): bool => $row['balance'] > 0)
            ->take(60)
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function rentalSummary(Rental $rental): array
    {
        $dueDate = $rental->nextAdjustmentDueDate();

        return [
            'id' => (int) $rental->id,
            'name' => $rental->name,
            'tenant' => $rental->tenant?->name,
            'property' => $rental->property?->name,
            'current_rent' => round($this->adjustments->currentRentFor($rental), 2),
            'billing_cadence' => $rental->billing_cadence?->value,
            'start_date' => $rental->start_date?->toDateString(),
            'end_date' => $rental->end_date?->toDateString(),
            'adjustment_due_date' => $dueDate->toDateString(),
            'adjustment_due_in_days' => (int) $dueDate->startOfDay()->diffInDays(today()->startOfDay()),
            'adjustment_overdue' => $rental->isDueForAdjustment(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function totalsFor(int $userId): array
    {
        $metrics = $this->analysis->analyze($userId);

        return [
            'active_rentals' => Rental::query()
                ->where('user_id', $userId)
                ->where('is_active', true)
                ->count(),
            'open_balance' => $metrics['open_balance'],
            'overdue_amount' => $metrics['overdue_amount'],
            'overdue_count' => $metrics['overdue_count'],
            'income_this_month' => $metrics['income_this_month'],
            'income_previous_month' => $metrics['income_previous_month'],
            'collection_rate_90d' => $metrics['collection_rate'],
            'avg_days_late' => $metrics['avg_days_late'],
        ];
    }
}
