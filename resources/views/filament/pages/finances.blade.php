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

        <x-filament-widgets::widgets
            :widgets="$this->getVisibleHeaderWidgets()"
            :columns="$this->getHeaderWidgetsColumns()"
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

            <x-filament::section class="lg:col-span-2">
                <x-slot name="heading">{{ __('finance.debtors.title') }}</x-slot>

                <x-filament-tables::table>
                    <x-slot name="header">
                        <x-filament-tables::header-cell>{{ __('finance.columns.tenant') }}</x-filament-tables::header-cell>
                        <x-filament-tables::header-cell>{{ __('finance.columns.rental') }}</x-filament-tables::header-cell>
                        <x-filament-tables::header-cell>{{ __('finance.columns.invoices') }}</x-filament-tables::header-cell>
                        <x-filament-tables::header-cell>{{ __('finance.columns.oldest_due') }}</x-filament-tables::header-cell>
                        <x-filament-tables::header-cell class="text-right">{{ __('finance.columns.balance') }}</x-filament-tables::header-cell>
                    </x-slot>

                    @forelse ($report['debtors'] as $debtor)
                        <x-filament-tables::row>
                            <x-filament-tables::cell>{{ $debtor['tenant'] }}</x-filament-tables::cell>
                            <x-filament-tables::cell>{{ $debtor['rental'] }}</x-filament-tables::cell>
                            <x-filament-tables::cell>{{ $debtor['invoices'] }}</x-filament-tables::cell>
                            <x-filament-tables::cell>{{ $debtor['oldest_due'] ?? '—' }}</x-filament-tables::cell>
                            <x-filament-tables::cell class="text-right font-medium">{{ '$'.number_format($debtor['balance'], 0, ',', '.') }}</x-filament-tables::cell>
                        </x-filament-tables::row>
                    @empty
                        <x-filament-tables::row>
                            <x-filament-tables::cell colspan="5" class="py-6 text-center text-gray-500">
                                {{ __('finance.debtors.empty') }}
                            </x-filament-tables::cell>
                        </x-filament-tables::row>
                    @endforelse
                </x-filament-tables::table>
            </x-filament::section>
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

        <x-filament::section>
            <x-filament-tables::table>
                <x-slot name="header">
                    <x-filament-tables::header-cell>{{ __('finance.columns.date') }}</x-filament-tables::header-cell>
                    <x-filament-tables::header-cell>{{ __('finance.columns.tenant') }}</x-filament-tables::header-cell>
                    <x-filament-tables::header-cell>{{ __('finance.columns.rental') }}</x-filament-tables::header-cell>
                    <x-filament-tables::header-cell>{{ __('finance.columns.invoice') }}</x-filament-tables::header-cell>
                    <x-filament-tables::header-cell>{{ __('finance.columns.method') }}</x-filament-tables::header-cell>
                    <x-filament-tables::header-cell class="text-right">{{ __('finance.columns.amount') }}</x-filament-tables::header-cell>
                </x-slot>

                @forelse ($report['recentPayments'] as $payment)
                    <x-filament-tables::row>
                        <x-filament-tables::cell>{{ $payment->date->format('d/m/Y') }}</x-filament-tables::cell>
                        <x-filament-tables::cell>{{ $payment->rental?->tenant?->name ?? '—' }}</x-filament-tables::cell>
                        <x-filament-tables::cell>{{ $payment->rental?->name ?? '—' }}</x-filament-tables::cell>
                        <x-filament-tables::cell>{{ $payment->invoice?->number ?? '—' }}</x-filament-tables::cell>
                        <x-filament-tables::cell>{{ $payment->methodLabel() }}</x-filament-tables::cell>
                        <x-filament-tables::cell class="text-right font-medium">{{ '$'.number_format($payment->amount, 0, ',', '.') }}</x-filament-tables::cell>
                    </x-filament-tables::row>
                @empty
                    <x-filament-tables::row>
                        <x-filament-tables::cell colspan="6" class="py-6 text-center text-gray-500">
                            {{ __('finance.activity.empty') }}
                        </x-filament-tables::cell>
                    </x-filament-tables::row>
                @endforelse
            </x-filament-tables::table>
        </x-filament::section>
    </div>
</x-filament-panels::page>
