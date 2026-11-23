<?php

namespace App\Services\Assistant;

use App\Enums\ActItemState;
use App\Enums\InvoiceStatus;
use App\Models\ActItem;
use App\Models\DeliveryAct;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Rental;
use App\Services\Adjustment\AdjustmentChainCalculator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Analisis predictivo del portafolio con datos reales.
 *
 * Todo lo que devuelve es COMPUTADO aqui, nunca inventado por la IA:
 * proyeccion de ingresos de corto plazo, salud por inquilino/arriendo
 * (antiguedad, mora, inventario danado, vencimiento de contrato) y
 * recomendaciones con la razon que las sustenta. La IA solo narra el
 * resultado via draft; los numeros y flags son deterministicos.
 *
 * Ventanas deliberadamente cortas (trimestre): en arriendos informales
 * los patrones de 12+ meses no predicen nada porque la muestra es
 * chica y ruidosa.
 */
class PortfolioInsightsService
{
    /** Estados de inventario que cuentan como "danado" para el riesgo. */
    private const DAMAGED_STATES = [
        ActItemState::REPARABLE,
        ActItemState::POR_REEMPLAZAR,
        ActItemState::DESTRUIDO,
    ];

    /** Horizonte de proyeccion en meses. */
    private const FORECAST_MONTHS = 3;

    public function __construct(
        private readonly AdjustmentChainCalculator $adjustments,
    ) {}

    /**
     * @param  array{focus?: string|null, entity?: string|null, question?: string|null}  $options
     * @return array<string, mixed>
     */
    public function insightsFor(int $userId, array $options = []): array
    {
        $rentals = $this->activeRentals($userId);
        $forecast = $this->forecast($userId);
        $tenants = $rentals->map(fn (Rental $rental): array => $this->tenantHealth($rental))->all();
        $recommendations = $this->recommendations($forecast, $tenants, $rentals, $userId);

        // Foco de la pregunta: si el usuario pidio algo especifico
        // ("riesgos", "mejoras", "el inquilino X"), el analisis se
        // concentra ahi en vez de repetir siempre el mismo panorama.
        $focus = $this->normalizeFocus($options['focus'] ?? null, $options['question'] ?? null);
        $entity = trim((string) ($options['entity'] ?? ''));

        if ($entity !== '') {
            $tenants = $this->filterByEntity($tenants, $entity);
            $recommendations = $this->filterRecsForEntity($recommendations, $tenants, $entity);
        }

        return $this->applyFocus([
            'focus' => $focus,
            'entity' => $entity !== '' ? $entity : null,
            'forecast' => $forecast,
            'tenants' => $tenants,
            'recommendations' => $recommendations,
            'generated_at' => now()->toDateTimeString(),
        ], $focus, $entity !== '');
    }

    /**
     * Recorta el analisis a lo que la pregunta realmente pidio: secciones
     * visibles y recomendaciones por tema. Sin foco (o "analisis
     * completo") se muestra todo — ahi si es un panorama entero.
     *
     * @param  array<string, mixed>  $insights
     * @return array<string, mixed>
     */
    private function applyFocus(array $insights, ?string $focus, bool $hasEntity): array
    {
        $all = ['forecast' => true, 'tenants' => true, 'recommendations' => true];

        if ($hasEntity) {
            // Una entidad concreta no necesita el pronostico del
            // portafolio ni las recomendaciones de vacantes: solo lo
            // que toca a ese inquilino/arriendo.
            $insights['sections'] = ['forecast' => false, 'tenants' => true, 'recommendations' => true];
            $insights['recommendations'] = array_values(array_filter(
                $insights['recommendations'],
                fn (array $r): bool => array_intersect(
                    ['tenant', 'risk', 'acts'],
                    $r['topics'] ?? [],
                ) !== [],
            ));

            return $insights;
        }

        if ($focus === null) {
            $insights['sections'] = $all;

            return $insights;
        }

        $insights['sections'] = match ($focus) {
            'income' => ['forecast' => true, 'tenants' => false, 'recommendations' => true],
            'risk' => ['forecast' => false, 'tenants' => true, 'recommendations' => true],
            'improve' => ['forecast' => false, 'tenants' => true, 'recommendations' => true],
            'tenant' => ['forecast' => false, 'tenants' => true, 'recommendations' => true],
            'acts' => ['forecast' => false, 'tenants' => true, 'recommendations' => true],
            default => $all,
        };

        $insights['recommendations'] = array_values(array_filter(
            $insights['recommendations'],
            fn (array $r): bool => $focus === 'portfolio'
                || in_array($focus, $r['topics'] ?? ['portfolio'], true),
        ));

        return $insights;
    }

    /**
     * Tema del analisis segun lo que pidio el usuario. null = completo.
     */
    private function normalizeFocus(?string $focus, ?string $question): ?string
    {
        $text = Str::lower(trim(($focus ?? '').' '.($question ?? '')));

        return match (true) {
            (bool) preg_match('/ingres|recaud|forecast|proyect|futuro|sigo asi|si sigo/i', $text) => 'income',
            (bool) preg_match('/riesgo|peligro|moros|deud|mora|atras|risk|debt/i', $text) => 'risk',
            (bool) preg_match('/mejor|recomend|suger|improve|optimiz|vacan|vacia|vacias|vacías|lleno|publica|anuncia|subir|aumentar/i', $text) => 'improve',
            (bool) preg_match('/inquilino|tenant|arrendatario|vale la pena|buen pagador|mantener|soltar|echar/i', $text) => 'tenant',
            (bool) preg_match('/acta|inventario|dañ|dano|damag/i', $text) => 'acts',
            (bool) preg_match('/propiedad|property|arriendo|rental|portafolio|negocio/i', $text) => 'portfolio',
            default => null,
        };
    }

    /**
     * @param  list<array<string, mixed>>  $tenants
     * @return list<array<string, mixed>>
     */
    private function filterByEntity(array $tenants, string $entity): array
    {
        $needle = Str::lower($entity);

        $filtered = array_values(array_filter(
            $tenants,
            fn (array $t): bool => Str::contains(Str::lower((string) ($t['tenant'] ?? '').' '.($t['property'] ?? '').' '.($t['rental'] ?? '')), $needle),
        ));

        // Si el nombre no coincide con nada se devuelve el portafolio
        // completo: mejor un analisis amplio que uno vacio.
        return $filtered !== [] ? $filtered : $tenants;
    }

    /**
     * @param  list<array<string, mixed>>  $recs
     * @param  list<array<string, mixed>>  $tenants
     * @return list<array<string, mixed>>
     */
    private function filterRecsForEntity(array $recs, array $tenants, string $entity): array
    {
        $names = collect($tenants)->pluck('tenant')->filter()->map(fn ($n) => Str::lower((string) $n))->all();

        if ($names === []) {
            return $recs;
        }

        return array_values(array_filter(
            $recs,
            function (array $rec) use ($names): bool {
                $haystack = Str::lower(($rec['title'] ?? '').' '.($rec['reason'] ?? ''));

                foreach ($names as $name) {
                    if (Str::contains($haystack, $name)) {
                        return true;
                    }
                }

                // Recomendaciones genericas de portafolio (proyeccion,
                // vacantes) se conservan: dan contexto al caso puntual.
                return in_array($rec['tone'] ?? '', ['info'], true);
            },
        ));
    }

    // ---------------------------------------------------------------
    // Pronostico de corto plazo
    // ---------------------------------------------------------------

    /**
     * Ingresos reales de los ultimos meses completos + proyeccion del
     * proximo asumiendo que sigue igual. Solo pagos (recaudo real), no
     * facturado: proyectar lo facturado seria ilusion.
     *
     * @return array<string, mixed>
     */
    private function forecast(int $userId): array
    {
        $today = today();

        // Ultimos N meses COMPLETOS: el mes en curso esta incompleto y
        // ensuciaria el promedio. Se agrupa en PHP por prefijo de fecha
        // para no depender de funciones SQL especificas del driver.
        $cutoff = $today->copy()->subMonths(self::FORECAST_MONTHS * 2)->startOfMonth();

        $sums = Payment::query()
            ->where('user_id', $userId)
            ->where('date', '>=', $cutoff)
            ->get(['date', 'amount'])
            ->groupBy(fn (Payment $p): string => $p->date->format('Y-m'))
            ->map(fn ($group): float => (float) $group->sum('amount'));

        $recent = [];
        $previous = [];

        foreach (array_slice($this->lastMonths($today, self::FORECAST_MONTHS * 2), 0, self::FORECAST_MONTHS) as $m) {
            $recent[$m] = (float) ($sums[$m] ?? 0);
        }
        foreach (array_slice($this->lastMonths($today, self::FORECAST_MONTHS * 2), self::FORECAST_MONTHS) as $m) {
            $previous[$m] = (float) ($sums[$m] ?? 0);
        }

        $recentAvg = $this->avg($recent);
        $previousAvg = $this->avg($previous);
        $hasHistory = array_sum($recent) > 0 || array_sum($previous) > 0;

        $trendPct = $previousAvg > 0
            ? round((($recentAvg - $previousAvg) / $previousAvg) * 100, 1)
            : null;

        return [
            'window_months' => self::FORECAST_MONTHS,
            'recent' => $this->moneyMap($recent),
            'previous' => $this->moneyMap($previous),
            'recent_avg' => $recentAvg,
            'previous_avg' => $previousAvg,
            'projected_next_month' => $recentAvg,
            'trend_pct' => $trendPct,
            'has_history' => $hasHistory,
        ];
    }

    /**
     * Los ultimos $count meses completos como 'YYYY-MM', mas reciente
     * primero.
     *
     * @return list<string>
     */
    private function lastMonths(Carbon $today, int $count): array
    {
        $months = [];

        for ($i = 1; $i <= $count; $i++) {
            $months[] = $today->copy()->subMonths($i)->format('Y-m');
        }

        return $months;
    }

    // ---------------------------------------------------------------
    // Salud por arriendo/inquilino
    // ---------------------------------------------------------------

    /**
     * @return Collection<int, Rental>
     */
    private function activeRentals(int $userId): Collection
    {
        return Rental::query()
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->with(['tenant:id,name', 'property:id,name'])
            ->get();
    }

    /**
     * Retrato de riesgo de UN arriendo: mora, antiguedad, inventario
     * danado del ultimo acta, proximidad del fin de contrato.
     *
     * @return array<string, mixed>
     */
    private function tenantHealth(Rental $rental): array
    {
        $invoices = $rental->invoices()
            ->whereIn('status', [InvoiceStatus::EMITIDA, InvoiceStatus::VENCIDA])
            ->get();

        $openBalance = round((float) $invoices->sum(fn (Invoice $i): float => $i->balance()), 2);
        $overdue = $invoices->filter(fn (Invoice $i): bool => $i->isOverdue());
        $overdueBalance = round((float) $overdue->sum(fn (Invoice $i): float => $i->balance()), 2);
        $oldestDue = $overdue->sortBy('due_at')->first()?->due_at;
        $daysLate = $oldestDue ? (int) today()->startOfDay()->diffInDays($oldestDue->startOfDay()) : 0;

        $lastPayment = Payment::query()
            ->where('rental_id', $rental->id)
            ->max('date');

        $monthlyRent = $this->adjustments->currentRentFor($rental);
        $tenureMonths = $rental->start_date
            ? (int) $rental->start_date->diffInMonths(today())
            : null;

        [$damagedItems, $damagedDetail] = $this->latestActDamage($rental);

        $daysToEnd = $rental->end_date
            ? (int) today()->startOfDay()->diffInDays($rental->end_date->startOfDay(), false)
            : null;

        $flags = [];

        if ($openBalance > $monthlyRent * 1.5) {
            $flags[] = 'heavy_debt';
        } elseif ($overdueBalance > 0) {
            $flags[] = 'overdue';
        }

        if ($damagedItems > 0) {
            $flags[] = 'damaged_items';
        }

        if ($daysToEnd !== null && $daysToEnd >= 0 && $daysToEnd <= 60) {
            $flags[] = 'contract_expiring';
        }

        if ($tenureMonths !== null && $tenureMonths < 3) {
            $flags[] = 'new_tenant';
        }

        if ($lastPayment === null && $tenureMonths !== null && $tenureMonths >= 2) {
            $flags[] = 'never_paid';
        }

        return [
            'rental' => $rental->name,
            'tenant' => $rental->tenant?->name ?? '—',
            'property' => $rental->property?->name ?? '—',
            'monthly_rent' => round($monthlyRent, 2),
            'tenure_months' => $tenureMonths,
            'open_balance' => $openBalance,
            'overdue_balance' => $overdueBalance,
            'overdue_invoices' => $overdue->count(),
            'days_late' => $daysLate,
            'last_payment_at' => $lastPayment,
            'damaged_items' => $damagedItems,
            'damaged_detail' => $damagedDetail,
            'contract_days_left' => $daysToEnd,
            'flags' => $flags,
            'risk_score' => $this->riskScore($flags, $openBalance, $monthlyRent),
        ];
    }

    /**
     * Inventario danado del acta mas reciente del arriendo.
     *
     * @return array{0: int, 1: list<string>}
     */
    private function latestActDamage(Rental $rental): array
    {
        $act = DeliveryAct::query()
            ->where('rental_id', $rental->id)
            ->latest('occurred_at')
            ->first();

        if ($act === null) {
            return [0, []];
        }

        $damaged = ActItem::query()
            ->where('delivery_act_id', $act->id)
            ->whereIn('state', array_map(fn (ActItemState $s): string => $s->value, self::DAMAGED_STATES))
            ->get(['name', 'state']);

        return [
            $damaged->count(),
            $damaged->map(fn (ActItem $item): string => $item->name.' ('.$item->state->label().')')->all(),
        ];
    }

    /**
     * Puntaje 0-100 para ordenar de mayor a menor riesgo.
     *
     * @param  list<string>  $flags
     */
    private function riskScore(array $flags, float $openBalance, float $monthlyRent): int
    {
        $score = 0;

        foreach ($flags as $flag) {
            $score += match ($flag) {
                'heavy_debt' => 40,
                'overdue' => 25,
                'damaged_items' => 15,
                'contract_expiring' => 10,
                'never_paid' => 20,
                'new_tenant' => -10, // resta: poco tiempo no es culpa del inquilino
                default => 0,
            };
        }

        if ($monthlyRent > 0 && $openBalance / $monthlyRent >= 3) {
            $score += 15;
        }

        return max(0, min(100, $score));
    }

    // ---------------------------------------------------------------
    // Recomendaciones con razon explicita
    // ---------------------------------------------------------------

    /**
     * Reglas de recomendacion: cada una cita el dato que la sustenta
     * para que el usuario pueda verificarla, no un "confia en mi".
     *
     * @param  array<string, mixed>  $forecast
     * @param  list<array<string, mixed>>  $tenants
     * @param  Collection<int, Rental>  $rentals
     * @return list<array{tone: string, title: string, reason: string, action?: array{kind: string, label: string, value: string}}>
     */
    private function recommendations(array $forecast, array $tenants, Collection $rentals, int $userId): array
    {
        $out = [];

        // --- Tendencia de ingresos -------------------------------------
        if ($forecast['has_history'] && $forecast['trend_pct'] !== null) {
            $trend = $forecast['trend_pct'];

            if ($trend <= -15) {
                $out[] = [
                    'tone' => 'danger',
                    'title' => __('assistant.insights.rec_income_drop_title'),
                    'reason' => __('assistant.insights.rec_income_drop_reason', [
                        'pct' => abs($trend),
                        'recent' => $this->moneyShort($forecast['recent_avg']),
                        'previous' => $this->moneyShort($forecast['previous_avg']),
                    ]),
                    'action' => ['kind' => 'prompt', 'label' => __('assistant.insights.rec_see_debtors'), 'value' => __('assistant.suggestions.top_debtors')],
                    'topics' => ['income', 'risk', 'improve', 'portfolio'],
                ];
            } elseif ($trend >= 15) {
                $out[] = [
                    'tone' => 'success',
                    'title' => __('assistant.insights.rec_income_rise_title'),
                    'reason' => __('assistant.insights.rec_income_rise_reason', [
                        'pct' => $trend,
                        'projected' => $this->moneyShort($forecast['projected_next_month']),
                    ]),
                    'topics' => ['income', 'portfolio'],
                ];
            }
        }

        // --- Vacantes: propiedades sin arriendo activo ------------------
        $activePropertyIds = $rentals->pluck('property_id')->filter()->all();
        $vacant = Property::query()
            ->where('user_id', $userId)
            ->when($activePropertyIds !== [], fn ($q) => $q->whereNotIn('id', $activePropertyIds))
            ->count();

        if ($vacant > 0) {
            $out[] = [
                'tone' => 'warning',
                'title' => __('assistant.insights.rec_vacancy_title', ['count' => $vacant]),
                'reason' => __('assistant.insights.rec_vacancy_reason'),
                'action' => [
                    'kind' => 'url',
                    'label' => __('assistant.guide.go_to', ['section' => __('assistant.sections.properties')]),
                    'url' => AppGuide::URLS['properties']::getUrl(),
                ],
                'topics' => ['improve', 'income', 'portfolio'],
            ];
        }

        // --- Salud por arriendo: top 3 riesgos --------------------------
        $risky = collect($tenants)
            ->filter(fn (array $t): bool => $t['risk_score'] >= 30)
            ->sortByDesc('risk_score')
            ->take(3);

        foreach ($risky as $t) {
            $reasons = [];
            $actions = [];

            if ($t['overdue_balance'] > 0) {
                $reasons[] = __('assistant.insights.reason_overdue', [
                    'amount' => $this->moneyShort($t['overdue_balance']),
                    'days' => $t['days_late'],
                ]);
                $actions[] = ['kind' => 'prompt', 'label' => __('assistant.insights.rec_remind'), 'value' => "redáctame un recordatorio de pago para {$t['tenant']}"];
            }

            if (($t['open_balance'] ?? 0) > ($t['monthly_rent'] ?? 0) * 1.5 && $t['overdue_balance'] <= 0) {
                $reasons[] = __('assistant.insights.reason_open_debt', [
                    'amount' => $this->moneyShort($t['open_balance']),
                    'rent' => $this->moneyShort($t['monthly_rent']),
                ]);
                $actions[] = ['kind' => 'prompt', 'label' => __('assistant.insights.rec_remind'), 'value' => "redáctame un recordatorio de pago para {$t['tenant']}"];
            }

            if ($t['damaged_items'] > 0) {
                $reasons[] = __('assistant.insights.reason_damaged', [
                    'count' => $t['damaged_items'],
                    'items' => implode(', ', array_slice($t['damaged_detail'], 0, 3)),
                ]);
                $actions[] = ['kind' => 'prompt', 'label' => __('assistant.insights.rec_review_act'), 'value' => "cuéntame del arriendo de {$t['tenant']}"];
            }

            if ($t['contract_days_left'] !== null && $t['contract_days_left'] >= 0 && $t['contract_days_left'] <= 60) {
                $reasons[] = __('assistant.insights.reason_expiring', ['days' => $t['contract_days_left']]);
                $actions[] = ['kind' => 'prompt', 'label' => __('assistant.insights.rec_next_adjustment'), 'value' => "¿cuándo toca el reajuste del arriendo de {$t['tenant']}?"];
            }

            if (in_array('new_tenant', $t['flags'], true) && count($t['flags']) === 1) {
                $reasons[] = __('assistant.insights.reason_new_tenant', ['months' => $t['tenure_months']]);
            }

            if ($reasons === []) {
                continue;
            }

            $out[] = [
                'tone' => $t['risk_score'] >= 55 ? 'danger' : 'warning',
                'title' => __('assistant.insights.rec_tenant_title', ['tenant' => $t['tenant']]),
                'reason' => implode(' ', $reasons),
                'action' => $actions[0] ?? null,
                'topics' => ['risk', 'tenant', 'improve', 'portfolio'],
            ];
        }

        // --- Sin acta de entrega: riesgo legal ---------------------------
        $withoutAct = $rentals->filter(function (Rental $rental): bool {
            return ! DeliveryAct::query()->where('rental_id', $rental->id)->exists();
        });

        if ($withoutAct->isNotEmpty()) {
            $names = $withoutAct->take(3)->map(fn (Rental $r): string => $r->tenant?->name ?? $r->name)->implode(', ');

            $out[] = [
                'tone' => 'warning',
                'title' => __('assistant.insights.rec_no_act_title', ['count' => $withoutAct->count()]),
                'reason' => __('assistant.insights.rec_no_act_reason', ['names' => $names]),
                'action' => ['kind' => 'prompt', 'label' => __('assistant.insights.rec_create_act'), 'value' => 'crea un acta de entrega'],
                'topics' => ['acts', 'risk', 'improve', 'portfolio'],
            ];
        }

        // --- Proyeccion positiva: preparar caja --------------------------
        if ($forecast['has_history'] && $forecast['projected_next_month'] > 0) {
            $out[] = [
                'tone' => 'info',
                'title' => __('assistant.insights.rec_project_title', [
                    'amount' => $this->moneyShort($forecast['projected_next_month']),
                ]),
                'reason' => __('assistant.insights.rec_project_reason', [
                    'amount' => $this->moneyShort($forecast['projected_next_month']),
                    'window' => self::FORECAST_MONTHS,
                ]),
                'topics' => ['income', 'portfolio'],
            ];
        }

        return array_slice($out, 0, 8);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /** @param array<string, float> $values */
    private function avg(array $values): float
    {
        // Los meses en cero cuentan: un mes sin recaudo es dato real,
        // no ruido. Solo si TODO es cero el promedio es cero.
        $total = array_sum($values);

        return $values === [] ? 0.0 : round($total / count($values), 2);
    }

    /** @param array<string, float> $map */
    private function moneyMap(array $map): array
    {
        return array_map(fn (float $v): float => round($v, 2), $map);
    }

    private function moneyShort(float $amount): string
    {
        if (abs($amount) >= 1_000_000) {
            return '$'.rtrim(rtrim(number_format($amount / 1_000_000, 1, ',', '.'), '0'), ',').'M';
        }

        return '$'.number_format($amount, 0, ',', '.');
    }
}
