<?php

namespace App\Services\Assistant;

use App\Dto\PortfolioDigest;
use App\Models\Rental;
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
        );
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
