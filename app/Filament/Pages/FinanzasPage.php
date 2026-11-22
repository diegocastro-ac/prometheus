<?php

namespace App\Filament\Pages;

use App\Contracts\CurrentUserContextInterface;
use App\Filament\Widgets\FinanceStatsWidget;
use App\Services\FinancialAnalysisService;
use Filament\Pages\Page;

class FinanzasPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-chart-pie';

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'administration/finances';

    protected static string $view = 'filament.pages.finances';

    /** Ventanas de periodo ofrecidas por el filtro de la pagina. */
    private const PERIODS = [3, 6, 12, 24];

    public static function getNavigationGroup(): ?string
    {
        return __('finance.navigation.group');
    }

    public static function getNavigationLabel(): string
    {
        return __('finance.navigation_label');
    }

    public function getTitle(): string
    {
        return __('finance.title');
    }

    /**
     * Meses del filtro de periodo. Viaja por query string (?months=6) para
     * que la pagina completa — incluidos los widgets de Filament — se
     * monte ya con el valor correcto.
     */
    public function months(): int
    {
        $months = (int) request()->query('months', 12);

        return in_array($months, self::PERIODS, true) ? $months : 12;
    }

    protected function getHeaderWidgets(): array
    {
        return [FinanceStatsWidget::class];
    }

    /**
     * Datos de las secciones personalizadas (aging, deudores, actividad).
     * El servicio ya filtra por el usuario activo.
     *
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $analysis = app(FinancialAnalysisService::class);
        $userId = (int) app(CurrentUserContextInterface::class)->id();
        $months = $this->months();

        return [
            'report' => [
                'months' => $months,
                'periods' => self::PERIODS,
                'aging' => $analysis->agingBuckets($userId),
                'debtors' => $analysis->topDebtors($userId),
                'recentPayments' => $analysis->recentPayments($userId),
                'methods' => $analysis->paymentsByMethod($userId, $months),
                'period_income' => $analysis->incomeBetween(
                    $userId,
                    today()->subMonths($months - 1)->startOfMonth(),
                    today(),
                ),
            ],
        ];
    }
}
