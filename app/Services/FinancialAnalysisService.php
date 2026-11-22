<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Rental;
use Illuminate\Support\Carbon;

/**
 * Metricas financieras del portafolio de un arrendador.
 *
 * Todo lo que el copiloto responde sobre "como va el negocio" sale de aqui:
 * la IA narra estos numeros, nunca los calcula. Cada metrica es una consulta
 * acotada por user_id, asi que dos arrendadores nunca ven los datos del otro.
 */
class FinancialAnalysisService
{
    /**
     * @return array<string, mixed>
     */
    public function analyze(int $userId): array
    {
        return [
            'income_this_month' => $this->incomeBetween($userId, today()->startOfMonth(), today()->endOfMonth()),
            'income_previous_month' => $this->incomeBetween(
                $userId,
                today()->subMonthNoOverflow()->startOfMonth(),
                today()->subMonthNoOverflow()->endOfMonth(),
            ),
            'collection_rate' => $this->collectionRate($userId),
            'avg_days_late' => $this->averageDaysLate($userId),
            'overdue_amount' => round((float) $this->openInvoiceQuery($userId)
                ->where('status', InvoiceStatus::VENCIDA)
                ->get()
                ->sum(fn (Invoice $invoice): float => $invoice->balance()), 2),
            'overdue_count' => $this->openInvoiceQuery($userId)->where('status', InvoiceStatus::VENCIDA)->count(),
            'open_balance' => $this->openBalance($userId),
            'alerts' => $this->alertsFor($userId),
        ];
    }

    /**
     * Recaudo real: los pagos son abonos, asi que se suman directo.
     */
    public function incomeBetween(int $userId, Carbon $from, Carbon $to): float
    {
        return round((float) Payment::query()
            ->where('user_id', $userId)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->sum('amount'), 2);
    }

    /**
     * Porcentaje de facturas emitidas en los ultimos 90 dias que quedaron
     * pagadas. Las anuladas no cuentan: no son una deuda cancelada, son una
     * decision del arrendador.
     */
    public function collectionRate(int $userId): ?float
    {
        $since = today()->subDays(90)->toDateString();

        $issued = Invoice::query()
            ->where('user_id', $userId)
            ->where('issued_at', '>=', $since)
            ->whereIn('status', [InvoiceStatus::EMITIDA, InvoiceStatus::VENCIDA, InvoiceStatus::PAGADA])
            ->count();

        if ($issued === 0) {
            return null;
        }

        $paid = Invoice::query()
            ->where('user_id', $userId)
            ->where('issued_at', '>=', $since)
            ->where('status', InvoiceStatus::PAGADA)
            ->count();

        return round($paid / $issued * 100, 1);
    }

    /**
     * Dias promedio de atraso en facturas ya pagadas: paid_at contra due_at.
     * Solo cuentan las que pagaron tarde; las puntuales no mejoran el
     * promedio porque no hay atraso que promediar.
     */
    public function averageDaysLate(int $userId): ?float
    {
        $lateDays = Invoice::query()
            ->where('user_id', $userId)
            ->where('status', InvoiceStatus::PAGADA)
            ->whereNotNull('paid_at')
            ->get()
            ->filter(fn (Invoice $invoice): bool => $invoice->paid_at->startOfDay()->gt($invoice->due_at->startOfDay()))
            ->map(fn (Invoice $invoice): int => (int) $invoice->due_at->startOfDay()->diffInDays($invoice->paid_at->startOfDay()))
            ->all();

        if ($lateDays === []) {
            return null;
        }

        return round(array_sum($lateDays) / count($lateDays), 1);
    }

    /**
     * Saldo pendiente real sobre facturas abiertas (emitidas o vencidas).
     */
    public function openBalance(int $userId): float
    {
        return round((float) $this->openInvoiceQuery($userId)
            ->get()
            ->sum(fn (Invoice $invoice): float => $invoice->balance()), 2);
    }

    /**
     * Alertas operativas del portafolio: mora, reajustes que vencen y
     * contratos por terminar. Son la materia prima de los "avísame si…".
     *
     * @return list<array{type: string, rental_id: int, rental: string, detail: string}>
     */
    public function alertsFor(int $userId): array
    {
        $alerts = [];

        $overdue = $this->openInvoiceQuery($userId)
            ->where('status', InvoiceStatus::VENCIDA)
            ->with('rental:id,name')
            ->get();

        if ($overdue->isNotEmpty()) {
            $alerts[] = [
                'type' => 'overdue',
                'rental_id' => 0,
                'rental' => '',
                'detail' => __('assistant.alerts.overdue', ['count' => $overdue->count()]),
            ];
        }

        $rentals = Rental::query()
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->with('tenant:id,name')
            ->get();

        foreach ($rentals as $rental) {
            if ($rental->isDueForAdjustment()) {
                $alerts[] = [
                    'type' => 'adjustment_due',
                    'rental_id' => (int) $rental->id,
                    'rental' => $rental->name,
                    'detail' => __('assistant.alerts.adjustment_due', [
                        'tenant' => $rental->tenant?->name ?? '—',
                        'date' => $rental->nextAdjustmentDueDate()->format('Y-m-d'),
                    ]),
                ];
            }

            if ($rental->end_date !== null
                && $rental->end_date->startOfDay()->lte(today()->addDays(60)->startOfDay())
                && $rental->end_date->startOfDay()->gte(today()->startOfDay())) {
                $alerts[] = [
                    'type' => 'contract_expiring',
                    'rental_id' => (int) $rental->id,
                    'rental' => $rental->name,
                    'detail' => __('assistant.alerts.contract_expiring', [
                        'tenant' => $rental->tenant?->name ?? '—',
                        'date' => $rental->end_date->format('Y-m-d'),
                    ]),
                ];
            }
        }

        return $alerts;
    }

    /**
     * Cartera por edades: saldo abierto agrupado por dias de mora de cada
     * factura. Las que aun no vencen caen en 'current'.
     *
     * @return array{buckets: array{current: float, d30: float, d60: float, d90: float, d90plus: float}, total: float}
     */
    public function agingBuckets(int $userId): array
    {
        $buckets = ['current' => 0.0, 'd30' => 0.0, 'd60' => 0.0, 'd90' => 0.0, 'd90plus' => 0.0];

        $this->openInvoiceQuery($userId)->get()->each(function (Invoice $invoice) use (&$buckets): void {
            $daysLate = (int) $invoice->due_at->startOfDay()->diffInDays(today()->startOfDay());
            $key = match (true) {
                $daysLate <= 0 => 'current',
                $daysLate <= 30 => 'd30',
                $daysLate <= 60 => 'd60',
                $daysLate <= 90 => 'd90',
                default => 'd90plus',
            };
            $buckets[$key] += $invoice->balance();
        });

        return [
            'buckets' => array_map(fn (float $v): float => round($v, 2), $buckets),
            'total' => round(array_sum($buckets), 2),
        ];
    }

    /**
     * Recaudo de los ultimos N meses desglosado por metodo de pago.
     *
     * @return list<array{method: string, label: string, amount: float, count: int}>
     */
    public function paymentsByMethod(int $userId, int $months = 12): array
    {
        return Payment::query()
            ->where('user_id', $userId)
            ->where('date', '>=', today()->subMonths($months)->toDateString())
            ->selectRaw('method, SUM(amount) as total, COUNT(*) as total_count')
            ->groupBy('method')
            ->orderByDesc('total')
            ->get()
            ->map(function ($row): array {
                $method = (string) ($row->method ?? '');
                $label = __('invoice.methods.'.$method);

                return [
                    'method' => $method,
                    'label' => $label === 'invoice.methods.'.$method ? ($method !== '' ? $method : '—') : $label,
                    'amount' => round((float) $row->total, 2),
                    'count' => (int) $row->total_count,
                ];
            })
            ->all();
    }

    /**
     * Mayores deudores: saldo abierto consolidado por arriendo.
     *
     * @return list<array{rental: string, tenant: string, invoices: int, balance: float, oldest_due: string|null}>
     */
    public function topDebtors(int $userId, int $limit = 5): array
    {
        return $this->openInvoiceQuery($userId)
            ->with('rental:id,name', 'rental.tenant:id,name')
            ->get()
            ->groupBy('rental_id')
            ->map(function ($group): array {
                $first = $group->first();

                return [
                    'rental' => $first->rental?->name ?? '—',
                    'tenant' => $first->rental?->tenant?->name ?? '—',
                    'invoices' => $group->count(),
                    'balance' => round((float) $group->sum(fn (Invoice $i): float => $i->balance()), 2),
                    'oldest_due' => $group->min('due_at')?->toDateString(),
                ];
            })
            ->sortByDesc('balance')
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * Total facturado en el mes en curso (todas las facturas no anuladas).
     */
    public function expectedThisMonth(int $userId): float
    {
        return round((float) Invoice::query()
            ->where('user_id', $userId)
            ->where('issued_at', '>=', today()->startOfMonth()->toDateString())
            ->where('issued_at', '<=', today()->endOfMonth()->toDateString())
            ->where('status', '!=', InvoiceStatus::ANULADA)
            ->sum('amount'), 2);
    }

    /**
     * Arriendos activos del usuario (para KPIs de ocupacion).
     */
    public function activeRentalsCount(int $userId): int
    {
        return Rental::query()
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->count();
    }

    /**
     * Ultimos pagos registrados, para la seccion de actividad reciente.
     *
     * @return \Illuminate\Support\Collection<int, Payment>
     */
    public function recentPayments(int $userId, int $limit = 8)
    {
        return Payment::query()
            ->where('user_id', $userId)
            ->with('rental:id,name', 'rental.tenant:id,name', 'invoice:id,number')
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * Base común: facturas que aún tienen saldo por cobrar.
     */
    private function openInvoiceQuery(int $userId)
    {
        return Invoice::query()
            ->where('user_id', $userId)
            ->whereIn('status', [InvoiceStatus::EMITIDA, InvoiceStatus::VENCIDA]);
    }
}
