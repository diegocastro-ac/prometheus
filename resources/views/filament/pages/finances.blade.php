<x-filament-panels::page>
    {{-- Filtro de periodo: recarga la pagina con ?months=N para que todos
         los widgets y secciones se monten con la ventana elegida. --}}
    <div class="flex flex-wrap items-center gap-2">
        <span class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('finance.period.label') }}:</span>
        @foreach ($report['periods'] as $option)
            <a
                href="{{ request()->fullUrlWithQuery(['months' => $option]) }}"
                @class([
                    'rounded-full px-3 py-1 text-xs font-semibold transition',
                    'bg-primary-600 text-white shadow-sm' => $report['months'] === $option,
                    'bg-white text-gray-600 ring-1 ring-gray-950/10 hover:bg-gray-50 dark:bg-white/5 dark:text-gray-300 dark:ring-white/10 dark:hover:bg-white/10' => $report['months'] !== $option,
                ])
            >
                {{ __('finance.period.months', ['count' => $option]) }}
            </a>
        @endforeach
    </div>

    {{-- ================================================================
         SUBSECCION 1 · RESUMEN GENERAL
         ================================================================ --}}
    <div>
        <h3 class="mb-3 text-base font-bold text-gray-900 dark:text-white">{{ __('finance.sections.summary') }}</h3>
        <p class="-mt-2 mb-4 text-sm text-gray-500 dark:text-gray-400">{{ __('finance.sections.summary_hint') }}</p>

        {{-- Stats renderizados aqui y no como header widgets: Filament los
             pintaria otra vez arriba de la pagina y saldrian duplicados. --}}
        <x-filament-widgets::widgets
            :widgets="[
                new \Filament\Widgets\WidgetConfiguration(\App\Filament\Widgets\FinanceStatsWidget::class),
            ]"
            :columns="1"
        />
    </div>

    {{-- ================================================================
         SUBSECCION 2 · CARTERA POR COBRAR
         ================================================================ --}}
    <div>
        <div class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
            <div>
                <h3 class="text-base font-bold text-gray-900 dark:text-white">{{ __('finance.sections.portfolio') }}</h3>
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('finance.sections.portfolio_hint') }}</p>
            </div>
            <span @class([
                'rounded-full px-3 py-1 text-sm font-bold',
                'bg-danger-50 text-danger-700 ring-1 ring-danger-600/20 dark:bg-danger-500/10 dark:text-danger-300' => $report['aging']['total'] > 0,
                'bg-success-50 text-success-700 ring-1 ring-success-600/20 dark:bg-success-500/10 dark:text-success-300' => $report['aging']['total'] <= 0,
            ])>
                {{ __('finance.aging.total') }}: {{ '$'.number_format($report['aging']['total'], 0, ',', '.') }}
            </span>
        </div>

        <div class="grid gap-6 lg:grid-cols-3">
            <x-filament::section>
                <x-slot name="heading">{{ __('finance.aging.title') }}</x-slot>

                <div class="space-y-4">
                    @php($maxBucket = max($report['aging']['buckets']) ?: 1)
                    @foreach (['current', 'd30', 'd60', 'd90', 'd90plus'] as $bucket)
                        @php($amount = $report['aging']['buckets'][$bucket])
                        <div>
                            <div class="mb-1 flex items-center justify-between text-sm">
                                <span class="text-gray-600 dark:text-gray-400">{{ __("finance.aging.buckets.$bucket") }}</span>
                                <span class="font-medium">{{ '$'.number_format($amount, 0, ',', '.') }}</span>
                            </div>
                            <div class="h-2 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800">
                                <div class="h-full rounded-full {{ match ($bucket) {
                                    'current' => 'bg-success-500',
                                    'd30' => 'bg-warning-500',
                                    'd60' => 'bg-orange-500',
                                    'd90' => 'bg-danger-500',
                                    default => 'bg-danger-700',
                                } }}" style="width: {{ round($amount / $maxBucket * 100) }}%"></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </x-filament::section>

            <div class="lg:col-span-2">
                <h4 class="mb-2 text-sm font-bold text-gray-900 dark:text-white">{{ __('finance.debtors.title') }}</h4>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 dark:bg-white/5">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('finance.columns.tenant') }}</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('finance.columns.rental') }}</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('finance.columns.invoices') }}</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('finance.columns.oldest_due') }}</th>
                                <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('finance.columns.balance') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                            @forelse ($report['debtors'] as $debtor)
                                <tr class="transition hover:bg-gray-50 dark:hover:bg-white/5">
                                    <td class="px-4 py-3 font-medium text-gray-900 dark:text-white">{{ $debtor['tenant'] }}</td>
                                    <td class="px-4 py-3 text-gray-600 dark:text-gray-300">{{ $debtor['rental'] }}</td>
                                    <td class="px-4 py-3 text-gray-600 dark:text-gray-300">{{ $debtor['invoices'] }}</td>
                                    <td class="px-4 py-3 text-gray-600 dark:text-gray-300">{{ $debtor['oldest_due'] ?? '—' }}</td>
                                    <td class="px-4 py-3 text-right font-semibold text-gray-900 dark:text-white">{{ '$'.number_format($debtor['balance'], 0, ',', '.') }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                                        {{ __('finance.debtors.empty') }}
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    {{-- ================================================================
         SUBSECCION 3 · RECAUDO Y DESEMPENHO
         ================================================================ --}}
    <div>
        <div class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
            <div>
                <h3 class="text-base font-bold text-gray-900 dark:text-white">{{ __('finance.sections.collections') }}</h3>
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('finance.sections.collections_hint', ['months' => $report['months']]) }}</p>
            </div>
            <span class="rounded-full bg-success-50 px-3 py-1 text-sm font-bold text-success-700 ring-1 ring-success-600/20 dark:bg-success-500/10 dark:text-success-300">
                {{ __('finance.sections.period_total') }}: {{ '$'.number_format($report['period_income'], 0, ',', '.') }}
            </span>
        </div>

        <x-filament-widgets::widgets
            :widgets="[
                new \Filament\Widgets\WidgetConfiguration(\App\Filament\Widgets\FinanceIncomeChart::class, ['months' => $report['months']]),
            ]"
            :columns="1"
        />

        <x-filament-widgets::widgets
            class="mt-6"
            :widgets="[
                new \Filament\Widgets\WidgetConfiguration(\App\Filament\Widgets\PaymentsStatusChart::class),
                new \Filament\Widgets\WidgetConfiguration(\App\Filament\Widgets\PaymentsByMethodChart::class, ['months' => $report['months']]),
            ]"
            :columns="2"
        />
    </div>

    {{-- ================================================================
         SUBSECCION 4 · ACTIVIDAD RECIENTE
         ================================================================ --}}
    <div>
        <h3 class="mb-3 text-base font-bold text-gray-900 dark:text-white">{{ __('finance.sections.activity') }}</h3>
        <p class="-mt-2 mb-4 text-sm text-gray-500 dark:text-gray-400">{{ __('finance.sections.activity_hint') }}</p>

        <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-white/5">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('finance.columns.date') }}</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('finance.columns.tenant') }}</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('finance.columns.rental') }}</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('finance.columns.invoice') }}</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('finance.columns.method') }}</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('finance.columns.amount') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @forelse ($report['recentPayments'] as $payment)
                            <tr class="transition hover:bg-gray-50 dark:hover:bg-white/5">
                                <td class="px-4 py-3 whitespace-nowrap text-gray-600 dark:text-gray-300">{{ $payment->date->format('d/m/Y') }}</td>
                                <td class="px-4 py-3 font-medium text-gray-900 dark:text-white">{{ $payment->rental?->tenant?->name ?? '—' }}</td>
                                <td class="px-4 py-3 text-gray-600 dark:text-gray-300">{{ $payment->rental?->name ?? '—' }}</td>
                                <td class="px-4 py-3 text-gray-600 dark:text-gray-300">{{ $payment->invoice?->number ?? '—' }}</td>
                                <td class="px-4 py-3 text-gray-600 dark:text-gray-300">{{ $payment->methodLabel() }}</td>
                                <td class="px-4 py-3 text-right font-semibold text-gray-900 dark:text-white">{{ '$'.number_format($payment->amount, 0, ',', '.') }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                                    {{ __('finance.activity.empty') }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
        </div>
    </div>
</x-filament-panels::page>
