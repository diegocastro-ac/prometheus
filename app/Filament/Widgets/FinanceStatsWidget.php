<?php

namespace App\Filament\Widgets;

use App\Contracts\CurrentUserContextInterface;
use App\Services\FinancialAnalysisService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * KPIs financieros del portafolio para la pagina de Finanzas. Todo sale de
 * FinancialAnalysisService: la vista nunca calcula, solo pinta.
 */
class FinanceStatsWidget extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $userId = (int) app(CurrentUserContextInterface::class)->id();
        $analysis = app(FinancialAnalysisService::class);
        $metrics = $analysis->analyze($userId);

        $previous = $metrics['income_previous_month'];
        $change = $previous > 0
            ? round(($metrics['income_this_month'] - $previous) / $previous * 100, 1)
            : null;

        $money = fn (float $amount): string => '$'.number_format($amount, 0, ',', '.');

        return [
            Stat::make(__('finance.stats.open_balance'), $money($metrics['open_balance']))
                ->description(__('finance.stats.open_balance_hint'))
                ->descriptionIcon('heroicon-m-wallet')
                ->color($metrics['open_balance'] > 0 ? 'warning' : 'success'),

            Stat::make(__('finance.stats.overdue'), $money($metrics['overdue_amount']))
                ->description(__('finance.stats.overdue_hint', ['count' => $metrics['overdue_count']]))
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($metrics['overdue_count'] > 0 ? 'danger' : 'success'),

            Stat::make(__('finance.stats.income_month'), $money($metrics['income_this_month']))
                ->description($change === null
                    ? __('finance.stats.income_no_prev')
                    : __('finance.stats.income_change', ['change' => ($change >= 0 ? '+' : '').$change.'%']))
                ->descriptionIcon($change !== null && $change >= 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
                ->color('success'),

            Stat::make(__('finance.stats.collection_rate'), $metrics['collection_rate'] === null ? '—' : $metrics['collection_rate'].'%')
                ->description(__('finance.stats.collection_rate_hint'))
                ->descriptionIcon('heroicon-m-check-badge')
                ->color($metrics['collection_rate'] !== null && $metrics['collection_rate'] >= 80 ? 'success' : 'warning'),

            Stat::make(__('finance.stats.avg_days_late'), $metrics['avg_days_late'] === null ? '—' : $metrics['avg_days_late'])
                ->description(__('finance.stats.avg_days_late_hint'))
                ->descriptionIcon('heroicon-m-clock')
                ->color($metrics['avg_days_late'] !== null && $metrics['avg_days_late'] <= 5 ? 'success' : 'warning'),

            Stat::make(__('finance.stats.expected_month'), $money($analysis->expectedThisMonth($userId)))
                ->description(__('finance.stats.expected_month_hint'))
                ->descriptionIcon('heroicon-m-document-text')
                ->color('gray'),

            Stat::make(__('finance.stats.active_rentals'), (string) $analysis->activeRentalsCount($userId))
                ->description(__('finance.stats.active_rentals_hint'))
                ->descriptionIcon('heroicon-m-building-office')
                ->color('primary'),
        ];
    }
}
