{{--
    Analisis predictivo (business_insights).
    $compact = true  -> resumen dentro de la burbuja del chat + boton
                       "ver completo" que abre el modal de preview.
    $compact = false -> tarjeta completa (modal).
    $fromPreview     -> los botones prompt cierran el modal antes de enviar.
    Todos los numeros vienen de PortfolioInsightsService: aqui solo se
    presentan. Estilo tipo seccion de Filament (header icono+titulo+
    descripcion, tarjetas con ring, stats, badges de estado).
--}}
@php
    $insights = $data['insights'] ?? $payload ?? null;
    $compact = $compact ?? false;
    $fromPreview = $fromPreview ?? false;
    $previewMethod = $previewMethod ?? null;
    $previewIndex = $previewIndex ?? null;
    $promptMethod = $fromPreview ? 'askFromPreview' : 'askSuggestion';
@endphp

@if (is_array($insights))
    @php
        $forecast = $insights['forecast'] ?? [];
        $tenants = $insights['tenants'] ?? [];
        $recs = $insights['recommendations'] ?? [];
        // Secciones visibles segun el foco de la pregunta: una pregunta
        // de riesgos no muestra el pronostico ni una de ingresos la
        // salud por inquilino.
        $sections = $insights['sections'] ?? ['forecast' => true, 'tenants' => true, 'recommendations' => true];
        $showForecast = (bool) ($sections['forecast'] ?? true);
        $showTenants = (bool) ($sections['tenants'] ?? true);
        $showRecs = (bool) ($sections['recommendations'] ?? true);
        // La tarjeta dice QUE analizo, no siempre "analisis predictivo".
        $focus = $insights['focus'] ?? null;
        $headingKey = $focus !== null ? "assistant.insights.focus_headings.{$focus}" : null;
        $heading = $headingKey !== null && __($headingKey) !== $headingKey ? __($headingKey) : __('assistant.insights.title');
        $headingSubKey = $focus !== null ? "assistant.insights.focus_subtitles.{$focus}" : null;
        $headingSub = $headingSubKey !== null && __($headingSubKey) !== $headingSubKey ? __($headingSubKey) : __('assistant.insights.subtitle');
        $fmt = fn (float $v): string => '$'.number_format($v, 0, ',', '.');
        $riskyCount = collect($tenants)->filter(fn (array $t): bool => ($t['risk_score'] ?? 0) >= 30)->count();
        $trend = $forecast['trend_pct'] ?? null;
        $trendKey = $trend === null ? null : ($trend > 5 ? 'trend_up' : ($trend < -5 ? 'trend_down' : 'trend_flat'));
        // Mismo lenguaje que el reporte compuesto: callouts con borde
        // izquierdo de tono sobre fondo neutro, sin tarjetas de color.
        $toneClasses = [
            'danger' => 'border-red-300 dark:border-red-500/40',
            'warning' => 'border-amber-300 dark:border-amber-500/40',
            'success' => 'border-emerald-300 dark:border-emerald-500/40',
            'info' => 'border-sky-300 dark:border-sky-500/40',
        ];
        $iconBtn = 'inline-flex items-center gap-1.5 rounded-lg bg-white px-3 py-1.5 text-xs font-semibold text-gray-700 shadow-sm ring-1 ring-gray-950/10 transition hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:ring-white/10 dark:hover:bg-white/5';
    @endphp

    @if ($compact)
        {{-- Resumen compacto en la burbuja: la tarjeta entera abre el
             analisis completo en el modal. --}}
        <div
            @if ($previewMethod !== null)
                x-on:click="openPreview(() => $wire.{{ $previewMethod }}({{ $previewIndex }}))"
                x-on:keydown.enter.prevent="$el.click()"
                role="button"
                tabindex="0"
            @endif
            @class([
                'group mt-3 overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/10 transition dark:bg-gray-800 dark:ring-white/10',
                'cursor-pointer hover:bg-gray-50 hover:ring-gray-300 dark:hover:bg-white/5 dark:hover:ring-white/20' => $previewMethod !== null,
            ])
        >
            <div class="flex items-center gap-3 px-3.5 py-3">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-gray-100 text-gray-500 ring-1 ring-gray-950/5 transition group-hover:bg-gray-200/70 dark:bg-white/5 dark:text-gray-400 dark:ring-white/10 dark:group-hover:bg-white/10">
                    <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18 9 11.25l4.306 4.307a11.95 11.95 0 0 1 5.814-5.519l2.74-1.22m0 0-5.94-2.281m5.94 2.28-2.28 5.941" />
                    </svg>
                </span>
                <div class="min-w-0 flex-1">
                    <p class="truncate text-xs font-bold text-gray-900 transition group-hover:text-gray-700 dark:text-white dark:group-hover:text-gray-200">{{ $heading }}</p>
                    <p class="truncate text-[11px] text-gray-500 dark:text-gray-400">
                        @if ($showForecast && ! empty($forecast['has_history']))
                            {{ __('assistant.insights.projected_label') }}: <span class="font-semibold text-gray-700 dark:text-gray-200">{{ $fmt((float) ($forecast['projected_next_month'] ?? 0)) }}</span>
                            @if ($trendKey !== null)
                                · {{ __('assistant.insights.'.$trendKey) }}
                            @endif
                            @if ($showTenants || $showRecs)
                                · {{ count($tenants) }} {{ __('assistant.insights.tenants_label') }}
                                · {{ count($recs) }} {{ __('assistant.insights.rec_label') }}
                            @endif
                        @elseif (! $showForecast && $showTenants)
                            {{ count($tenants) }} {{ __('assistant.insights.tenants_label') }}
                            @if ($showRecs)
                                · {{ count($recs) }} {{ __('assistant.insights.rec_label') }}
                            @endif
                        @else
                            {{ __('assistant.insights.no_history') }}
                        @endif
                    </p>
                </div>
                @if ($previewMethod !== null)
                    <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg text-gray-300 transition group-hover:bg-gray-200/70 group-hover:text-gray-500 dark:text-gray-600 dark:group-hover:bg-white/10 dark:group-hover:text-gray-300" title="{{ __('assistant.insights.view_full') }}">
                        <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3.75v4.5m0-4.5h4.5m-4.5 0L9 9M3.75 20.25v-4.5m0 4.5h4.5m-4.5 0L9 15M20.25 3.75h-4.5m4.5 0v4.5m0-4.5L15 9m5.25 11.25h-4.5m4.5 0v-4.5m0 4.5L15 15" />
                        </svg>
                    </span>
                @endif
            </div>
        </div>
    @else
        {{-- Tarjeta completa estilo seccion de Filament. --}}
        <div class="mt-3 overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/10 dark:bg-gray-800 dark:ring-white/10">
            {{-- Header tipo fi-section: icono + titulo + descripcion. --}}
            <div class="flex items-start gap-3 border-b border-gray-100 px-4 py-3 dark:border-white/10">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-gray-100 text-gray-500 ring-1 ring-gray-950/5 dark:bg-white/5 dark:text-gray-400 dark:ring-white/10">
                    <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18 9 11.25l4.306 4.307a11.95 11.95 0 0 1 5.814-5.519l2.74-1.22m0 0-5.94-2.281m5.94 2.28-2.28 5.941" />
                    </svg>
                </span>
                <div class="min-w-0 flex-1">
                    <h4 class="text-sm font-bold text-gray-900 dark:text-white">{{ $heading }}</h4>
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ $headingSub }}</p>
                </div>
                @if (! empty($insights['generated_at']))
                    <span class="shrink-0 rounded-md bg-gray-100 px-1.5 py-0.5 text-[10px] font-medium text-gray-500 dark:bg-white/5 dark:text-gray-400">{{ \Illuminate\Support\Carbon::parse($insights['generated_at'])->format('d/m H:i') }}</span>
                @endif
            </div>

            <div class="space-y-4 px-4 py-4">
                {{-- Pronostico: stats tipo fi-stats. --}}
                @if ($showForecast)
                <section>
                    <h5 class="mb-2 text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('assistant.insights.forecast_title') }}</h5>
                    @if (! empty($forecast['has_history']))
                        <div class="grid grid-cols-3 gap-2">
                            <div class="rounded-lg bg-gray-900 px-3 py-2.5 text-center text-white dark:bg-white dark:text-gray-900">
                                <p class="text-[10px] font-medium uppercase tracking-wide opacity-80">{{ __('assistant.insights.projected_label') }}</p>
                                <p class="mt-0.5 text-base font-bold">{{ $fmt((float) ($forecast['projected_next_month'] ?? 0)) }}</p>
                            </div>
                            <div class="rounded-lg bg-gray-50 px-3 py-2.5 text-center ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                                <p class="text-[10px] font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('assistant.insights.recent_avg_label') }}</p>
                                <p class="mt-0.5 text-base font-bold text-gray-900 dark:text-white">{{ $fmt((float) ($forecast['recent_avg'] ?? 0)) }}</p>
                            </div>
                            <div class="rounded-lg bg-gray-50 px-3 py-2.5 text-center ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                                <p class="text-[10px] font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('assistant.insights.previous_avg_label') }}</p>
                                <p class="mt-0.5 text-base font-bold text-gray-900 dark:text-white">{{ $fmt((float) ($forecast['previous_avg'] ?? 0)) }}</p>
                            </div>
                        </div>
                        @if ($trend !== null)
                            <p class="mt-2 flex items-center gap-1.5 text-xs {{ $trend > 5 ? 'text-emerald-600 dark:text-emerald-400' : ($trend < -5 ? 'text-red-600 dark:text-red-400' : 'text-gray-500 dark:text-gray-400') }}">
                                <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="{{ $trend > 5 ? 'M4.5 10.5 12 3m0 0 7.5 7.5M12 3v18' : ($trend < -5 ? 'M4.5 13.5 12 21m0 0 7.5-7.5M12 21V3' : 'M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99') }}" />
                                </svg>
                                <span class="font-semibold">{{ __('assistant.insights.'.$trendKey) }}</span>
                                <span class="text-gray-500 dark:text-gray-400">{{ __('assistant.insights.trend_vs_previous', ['pct' => abs((float) $trend)]) }}</span>
                            </p>
                        @endif
                        <p class="mt-2 text-[11px] leading-relaxed text-gray-400 dark:text-gray-500">{{ __('assistant.insights.forecast_hint', ['months' => $forecast['window_months'] ?? 3]) }}</p>
                    @else
                        <p class="rounded-lg bg-gray-50 px-3 py-2.5 text-xs text-gray-600 ring-1 ring-gray-950/5 dark:bg-white/5 dark:text-gray-300 dark:ring-white/10">{{ __('assistant.insights.no_history') }}</p>
                    @endif
                </section>
                @endif

                {{-- Salud por inquilino: filas tipo tabla de Filament. --}}
                @if ($showTenants && ! empty($tenants))
                    <section>
                        <h5 class="mb-2 text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('assistant.insights.tenants_title') }}</h5>
                        <div class="divide-y divide-gray-100 overflow-hidden rounded-lg ring-1 ring-gray-950/10 dark:divide-white/10 dark:ring-white/10">
                            @foreach ($tenants as $t)
                                @php $risk = (int) ($t['risk_score'] ?? 0); @endphp
                                <div class="bg-gray-50/50 px-3 py-2.5 dark:bg-white/[0.03]">
                                    <div class="flex items-center justify-between gap-2">
                                        <div class="min-w-0">
                                            <p class="truncate text-xs font-semibold text-gray-900 dark:text-white">{{ $t['tenant'] }} <span class="font-normal text-gray-400">· {{ $t['property'] }}</span></p>
                                            <p class="mt-0.5 text-[11px] text-gray-500 dark:text-gray-400">
                                                {{ __('assistant.insights.monthly_rent', ['amount' => $fmt((float) $t['monthly_rent'])]) }}
                                                @if ($t['tenure_months'] !== null)
                                                    · {{ __('assistant.insights.tenure', ['months' => $t['tenure_months']]) }}
                                                @endif
                                                @if (($t['open_balance'] ?? 0) > 0)
                                                    · <span class="font-semibold text-red-600 dark:text-red-400">{{ __('assistant.insights.open_balance') }}: {{ $fmt((float) $t['open_balance']) }}</span>
                                                @endif
                                            </p>
                                        </div>
                                        <div class="flex shrink-0 items-center gap-2">
                                            <span class="text-[10px] font-bold uppercase {{ $risk >= 55 ? 'text-red-600 dark:text-red-400' : ($risk >= 30 ? 'text-amber-600 dark:text-amber-400' : 'text-emerald-600 dark:text-emerald-400') }}">{{ $risk }}</span>
                                            <span class="h-1.5 w-12 overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
                                                <span class="block h-full rounded-full {{ $risk >= 55 ? 'bg-red-500' : ($risk >= 30 ? 'bg-amber-500' : 'bg-emerald-500') }}" style="width: {{ $risk }}%"></span>
                                            </span>
                                        </div>
                                    </div>
                                    @if (($t['overdue_balance'] ?? 0) > 0 || ! empty($t['flags']) || ($t['damaged_items'] ?? 0) > 0 || (($t['contract_days_left'] ?? null) !== null && $t['contract_days_left'] >= 0 && $t['contract_days_left'] <= 60))
                                        <div class="mt-1.5 flex flex-wrap items-center gap-1">
                                            @if (($t['overdue_balance'] ?? 0) > 0)
                                                <span class="inline-flex items-center gap-1 rounded-md bg-red-100 px-1.5 py-0.5 text-[10px] font-semibold text-red-700 ring-1 ring-red-200 dark:bg-red-500/15 dark:text-red-300 dark:ring-red-500/30">
                                                    {{ __('assistant.insights.overdue_balance') }}: {{ $fmt((float) $t['overdue_balance']) }} · {{ __('assistant.insights.days_late', ['days' => $t['days_late']]) }}
                                                </span>
                                            @endif
                                            @foreach (($t['flags'] ?? []) as $flag)
                                                <span class="rounded-md bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold text-amber-700 dark:bg-amber-500/15 dark:text-amber-300">
                                                    {{ __('assistant.insights.flags.'.$flag) }}
                                                </span>
                                            @endforeach
                                            @if (($t['damaged_items'] ?? 0) > 0)
                                                <span class="rounded-md bg-orange-100 px-1.5 py-0.5 text-[10px] font-semibold text-orange-700 dark:bg-orange-500/15 dark:text-orange-300" title="{{ implode(', ', array_slice($t['damaged_detail'] ?? [], 0, 5)) }}">
                                                    {{ __('assistant.insights.damaged_items', ['count' => $t['damaged_items']]) }}
                                                </span>
                                            @endif
                                            @if (($t['contract_days_left'] ?? null) !== null && $t['contract_days_left'] >= 0 && $t['contract_days_left'] <= 60)
                                                <span class="rounded-md bg-sky-100 px-1.5 py-0.5 text-[10px] font-semibold text-sky-700 dark:bg-sky-500/15 dark:text-sky-300">
                                                    {{ __('assistant.insights.contract_left', ['days' => $t['contract_days_left']]) }}
                                                </span>
                                            @endif
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                        <p class="mt-1.5 text-[10px] text-gray-400 dark:text-gray-500">{{ __('assistant.insights.tenants_hint') }}</p>
                    </section>
                @endif

                {{-- Recomendaciones: items tipo callout de Filament. --}}
                @if ($showRecs && ! empty($recs))
                    <section>
                        <h5 class="mb-2 text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('assistant.insights.rec_title') }}</h5>
                        <div class="space-y-2">
                            @foreach ($recs as $rec)
                                @php $tone = $rec['tone'] ?? 'info'; @endphp
                                <div class="rounded-lg border-l-2 {{ $toneClasses[$tone] ?? $toneClasses['info'] }} bg-gray-50 px-3 py-2.5 dark:bg-white/5">
                                    <p class="text-xs font-semibold text-gray-900 dark:text-white">{{ $rec['title'] }}</p>
                                    <p class="mt-1 text-[11px] leading-relaxed text-gray-600 dark:text-gray-300">{{ $rec['reason'] }}</p>
                                    @if (! empty($rec['action']))
                                        @if (($rec['action']['kind'] ?? '') === 'prompt' && ! empty($rec['action']['value']))
                                            <button type="button" wire:click="{{ $promptMethod }}({{ \Illuminate\Support\Js::from($rec['action']['value']) }})" wire:loading.attr="disabled" class="{{ $iconBtn }} mt-2">
                                                {{ $rec['action']['label'] }}
                                            </button>
                                        @elseif (($rec['action']['kind'] ?? '') === 'url' && ! empty($rec['action']['url']))
                                            <button type="button" wire:click="goTo('{{ $rec['action']['url'] }}')" class="{{ $iconBtn }} mt-2">
                                                {{ $rec['action']['label'] }}
                                            </button>
                                        @endif
                                    @endif
                                </div>
                            @endforeach
                        </div>
                        <p class="mt-2 text-[10px] italic leading-relaxed text-gray-400 dark:text-gray-500">{{ __('assistant.insights.disclaimer') }}</p>
                        <p class="mt-1 text-[10px] leading-relaxed text-gray-400 dark:text-gray-500">{{ __('assistant.insights.rec_hint') }}</p>
                    </section>
                @endif
            </div>
        </div>
    @endif
@endif
