{{--
    Reporte compuesto por la IA (business_insights / app_guide).
    La IA investiga la base de datos real (lookup_data) y arma el
    reporte eligiendo los bloques que el caso amerita: texto, metricas,
    tablas, graficas, pasos accionables, alertas y listas. Aqui solo se
    pintan los componentes ya saneados en el servidor.

    Diseno: minimalista tipo Filament — superficies neutras, bordes
    hairline, una sola tinta de acento y paleta apagada. Nada de
    tarjetas chillones.

    $compact = true  -> resumen dentro de la burbuja + boton "ver completo".
    $compact = false -> reporte completo (modal).
    $fromPreview     -> los botones prompt/url cierran el modal antes.
--}}
@php
    $report = $data['report'] ?? $payload ?? null;
    $compact = $compact ?? false;
    $fromPreview = $fromPreview ?? false;
    $previewMethod = $previewMethod ?? null;
    $previewIndex = $previewIndex ?? null;
    // Indice del mensaje en el chat para la descarga del PDF: en la
    // burbuja es previewIndex; en el modal llega como pdfIndex.
    $pdfIndex = $pdfIndex ?? $previewIndex;
    // Los botones prompt van por Alpine (sendSuggestion/sendFromPreview):
    // echo optimista + cierre instantaneo del modal sin congelar la UI.
    $alpinePromptMethod = $fromPreview ? 'sendFromPreview' : 'sendSuggestion';
    $iconBtn = 'inline-flex items-center gap-1.5 rounded-lg bg-white px-3 py-1.5 text-xs font-semibold text-gray-700 shadow-sm ring-1 ring-gray-950/10 transition hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:ring-white/10 dark:hover:bg-white/5';
    // Paleta apagada para graficas: grises primero, acentos solo para
    // distinguir series. Nada saturado.
    $chartPalette = ['#94a3b8', '#64748b', '#0ea5e9', '#f59e0b', '#14b8a6', '#8b5cf6'];

    $fmtChartValue = function (float $v): string {
        $abs = abs($v);
        if ($abs >= 1_000_000) {
            return rtrim(rtrim(number_format($v / 1_000_000, 1, ',', '.'), '0'), ',').'M';
        }
        if ($abs >= 10_000) {
            return rtrim(rtrim(number_format($v / 1_000, 1, ',', '.'), '0'), ',').'k';
        }
        if ($abs >= 1000) {
            return number_format($v, 0, ',', '.');
        }
        return rtrim(rtrim(number_format($v, 2, ',', '.'), '0'), ',');
    };
@endphp

@if (is_array($report) && ! empty($report['sections']))
    @php
        $sectionCount = count($report['sections']);
        $blockCount = collect($report['sections'])->sum(fn (array $s): int => count($s['blocks'] ?? []));
    @endphp

    @if ($compact)
        {{-- Resumen compacto en la burbuja: la tarjeta entera abre el
             reporte completo en el modal (icono de expansion a la
             derecha, mismo patron que la tarjeta de archivo). --}}
        <div
            @if ($previewMethod !== null)
                wire:click="{{ $previewMethod }}({{ $previewIndex }})"
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
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3v11.25A2.25 2.25 0 0 0 6 16.5h2.25M3.75 3h-1.5m1.5 0h16.5m0 0h1.5m-1.5 0v11.25A2.25 2.25 0 0 1 18 16.5h-2.25m-7.5 0h7.5m-7.5 0-1 3m8.5-3 1 3m0 0 .5 1.5m-.5-1.5h-9.5m0 0-.5 1.5M9 11.25v1.5M12 9v3.75m3-6v6" />
                    </svg>
                </span>
                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-1.5">
                        <p class="truncate text-xs font-bold text-gray-900 transition group-hover:text-gray-700 dark:text-white dark:group-hover:text-gray-200">{{ $report['title'] }}</p>
                        @if ($pdfIndex !== null)
                            <button
                                type="button"
                                x-on:click.stop="$wire.exportReportPdf({{ $pdfIndex }})"
                                title="{{ __('assistant.report.pdf_download') }}"
                                class="flex h-6 w-6 shrink-0 items-center justify-center rounded-md text-gray-300 transition hover:bg-gray-200/70 hover:text-gray-500 focus-visible:opacity-100 dark:text-gray-600 dark:hover:bg-white/10 dark:hover:text-gray-300"
                            >
                                <svg class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" />
                                </svg>
                            </button>
                        @endif
                    </div>
                    <p class="truncate text-[11px] text-gray-500 dark:text-gray-400">
                        {{ $sectionCount }} {{ __('assistant.report.sections_label') }} · {{ $blockCount }} {{ __('assistant.report.blocks_label') }}
                    </p>
                </div>
                @if ($previewMethod !== null)
                    <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg text-gray-300 transition group-hover:bg-gray-200/70 group-hover:text-gray-500 dark:text-gray-600 dark:group-hover:bg-white/10 dark:group-hover:text-gray-300" title="{{ __('assistant.report.view_full') }}">
                        <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3.75v4.5m0-4.5h4.5m-4.5 0L9 9M3.75 20.25v-4.5m0 4.5h4.5m-4.5 0L9 15M20.25 3.75h-4.5m4.5 0v4.5m0-4.5L15 9m5.25 11.25h-4.5m4.5 0v-4.5m0 4.5L15 15" />
                        </svg>
                    </span>
                @endif
            </div>
        </div>
    @else
        {{-- Reporte completo: header + intro + secciones con sus bloques. --}}
        <div class="mt-3 overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/10 dark:bg-gray-800 dark:ring-white/10">
            <div class="flex items-start gap-3 border-b border-gray-100 px-4 py-3 dark:border-white/10">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-gray-100 text-gray-500 ring-1 ring-gray-950/5 dark:bg-white/5 dark:text-gray-400 dark:ring-white/10">
                    <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3v11.25A2.25 2.25 0 0 0 6 16.5h2.25M3.75 3h-1.5m1.5 0h16.5m0 0h1.5m-1.5 0v11.25A2.25 2.25 0 0 1 18 16.5h-2.25m-7.5 0h7.5m-7.5 0-1 3m8.5-3 1 3m0 0 .5 1.5m-.5-1.5h-9.5m0 0-.5 1.5M9 11.25v1.5M12 9v3.75m3-6v6" />
                    </svg>
                </span>
                <div class="min-w-0 flex-1">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <h4 class="text-sm font-bold text-gray-900 dark:text-white">{{ $report['title'] }}</h4>
                            @if (($report['intro'] ?? '') !== '')
                                <p class="mt-0.5 text-xs leading-relaxed text-gray-500 dark:text-gray-400">{{ $report['intro'] }}</p>
                            @endif
                        </div>
                        @if ($pdfIndex !== null)
                            <button
                                type="button"
                                wire:click="exportReportPdf({{ $pdfIndex }})"
                                wire:loading.attr="disabled"
                                wire:target="exportReportPdf"
                                title="{{ __('assistant.report.pdf_download') }}"
                                class="inline-flex shrink-0 items-center gap-1.5 rounded-lg bg-white px-2.5 py-1.5 text-[11px] font-semibold text-gray-600 shadow-sm ring-1 ring-gray-950/10 transition hover:bg-gray-50 disabled:opacity-50 dark:bg-gray-800 dark:text-gray-300 dark:ring-white/10 dark:hover:bg-white/5"
                            >
                                <svg class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" />
                                </svg>
                                PDF
                            </button>
                        @endif
                    </div>
                </div>
            </div>

            <div class="space-y-5 px-4 py-4">
                @foreach ($report['sections'] as $section)
                    <section>
                        @if (($section['title'] ?? '') !== '')
                            <h5 class="mb-2 border-b border-gray-100 pb-1 text-[11px] font-bold uppercase tracking-wider text-gray-500 dark:border-white/10 dark:text-gray-400">{{ $section['title'] }}</h5>
                        @endif
                        <div class="space-y-3">
                            @foreach ($section['blocks'] ?? [] as $block)
                                @switch($block['type'] ?? '')
                                    @case('text')
                                        <div class="assistant-md text-sm leading-relaxed text-gray-700 dark:text-gray-200">{!! \Illuminate\Support\Str::markdown((string) $block['text'], ['html_input' => 'escape', 'allow_unsafe_links' => false]) !!}</div>
                                        @break
                                    @case('metrics')
                                        <div class="grid grid-cols-2 gap-px overflow-hidden rounded-lg bg-gray-100 ring-1 ring-gray-950/5 dark:bg-white/10 dark:ring-white/10 sm:grid-cols-3">
                                            @foreach ($block['items'] as $metric)
                                                @php $tone = $metric['tone'] ?? 'default'; @endphp
                                                <div class="bg-white px-3 py-2.5 dark:bg-gray-800">
                                                    <p class="flex items-center gap-1.5 text-[10px] font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                                        @if (in_array($tone, ['danger', 'warning', 'success', 'info'], true))
                                                            <span class="h-1.5 w-1.5 rounded-full {{ ['danger' => 'bg-red-400', 'warning' => 'bg-amber-400', 'success' => 'bg-emerald-400', 'info' => 'bg-sky-400'][$tone] }}"></span>
                                                        @endif
                                                        {{ $metric['label'] }}
                                                    </p>
                                                    <p class="mt-0.5 text-base font-bold text-gray-900 dark:text-white">{{ $metric['value'] }}</p>
                                                    @if (($metric['hint'] ?? '') !== '')
                                                        <p class="mt-0.5 text-[10px] leading-snug text-gray-400 dark:text-gray-500">{{ $metric['hint'] }}</p>
                                                    @endif
                                                </div>
                                            @endforeach
                                        </div>
                                        @break
                                    @case('chart')
                                        @php
                                            $chartKind = $block['chart'] ?? 'bar';
                                            $chartLabels = $block['labels'] ?? [];
                                            $chartValues = array_map('floatval', $block['values'] ?? []);
                                            $chartMax = $chartValues === [] ? 0 : max($chartValues);
                                            $chartMin = $chartValues === [] ? 0 : min($chartValues);
                                            $chartRange = max($chartMax - min(0, $chartMin), 0.0001);
                                            $chartTotal = array_sum($chartValues);
                                        @endphp
                                        <div>
                                            @if (($block['title'] ?? '') !== '')
                                                <p class="mb-1.5 text-[11px] font-semibold text-gray-500 dark:text-gray-400">{{ $block['title'] }}</p>
                                            @endif
                                            @if ($chartKind === 'bar')
                                                <div class="flex h-36 items-end gap-1.5">
                                                    @foreach ($chartValues as $i => $value)
                                                        @php
                                                            $height = $chartMax > 0 ? max(3, (int) round(($value / $chartMax) * 100)) : 3;
                                                            $isMax = $value === $chartMax && $chartMax > 0;
                                                        @endphp
                                                        <div class="flex min-w-0 flex-1 flex-col items-center justify-end gap-1 self-stretch">
                                                            <span class="text-[9px] font-medium tabular-nums text-gray-500 dark:text-gray-400">{{ $fmtChartValue($value) }}</span>
                                                            <div class="flex w-full flex-1 items-end">
                                                                <div class="w-full rounded-t-sm {{ $isMax ? 'bg-gray-700 dark:bg-gray-300' : 'bg-gray-300 dark:bg-gray-600' }}" style="height: {{ $height }}%"></div>
                                                            </div>
                                                            <span class="w-full truncate text-center text-[9px] text-gray-400 dark:text-gray-500">{{ $chartLabels[$i] ?? '' }}</span>
                                                        </div>
                                                    @endforeach
                                                </div>
                                            @elseif ($chartKind === 'line')
                                                @php
                                                    $w = 320; $h = 120; $padX = 8; $padY = 14;
                                                    $n = count($chartValues);
                                                    $points = [];
                                                    foreach ($chartValues as $i => $value) {
                                                        $x = $n === 1 ? $w / 2 : $padX + ($i / ($n - 1)) * ($w - 2 * $padX);
                                                        $y = $padY + (1 - (($value - $chartMin) / $chartRange)) * ($h - 2 * $padY);
                                                        $points[] = [$x, $y];
                                                    }
                                                    $linePath = collect($points)->map(fn ($p, $i) => ($i === 0 ? 'M' : 'L').round($p[0], 1).','.round($p[1], 1))->implode(' ');
                                                    $firstX = $points[0][0] ?? 0;
                                                    $lastX = $points[$n - 1][0] ?? 0;
                                                    $baseY = $h - $padY;
                                                    $areaPath = $linePath.' L'.round($lastX, 1).','.$baseY.' L'.round($firstX, 1).','.$baseY.' Z';
                                                @endphp
                                                <svg viewBox="0 0 {{ $w }} {{ $h }}" class="h-32 w-full" preserveAspectRatio="none" aria-hidden="true">
                                                    <path d="{{ $areaPath }}" fill="#64748b" opacity="0.08"/>
                                                    <path d="{{ $linePath }}" fill="none" stroke="#475569" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>
                                                    @foreach ($points as [$px, $py])
                                                        <circle cx="{{ round($px, 1) }}" cy="{{ round($py, 1) }}" r="2.5" fill="#fff" stroke="#475569" stroke-width="1.5"/>
                                                    @endforeach
                                                </svg>
                                                <div class="mt-1 flex justify-between text-[9px] text-gray-400 dark:text-gray-500">
                                                    <span>{{ $chartLabels[0] ?? '' }}</span>
                                                    <span>{{ $chartLabels[$n - 1] ?? '' }}</span>
                                                </div>
                                            @else
                                                {{-- donut: composicion del total --}}
                                                @php
                                                    $cx = 60; $cy = 60; $r = 44; $circ = 2 * M_PI * $r;
                                                    $offset = 0;
                                                    $segments = [];
                                                    foreach ($chartValues as $i => $value) {
                                                        $frac = $chartTotal > 0 ? $value / $chartTotal : 0;
                                                        $segments[] = [
                                                            'dash' => round($frac * $circ, 2),
                                                            'gap' => round($circ - $frac * $circ, 2),
                                                            'offset' => round(-$offset, 2),
                                                            'color' => $chartPalette[$i % count($chartPalette)],
                                                            'label' => $chartLabels[$i] ?? '',
                                                            'value' => $value,
                                                        ];
                                                        $offset += $frac * $circ;
                                                    }
                                                @endphp
                                                <div class="flex items-center gap-4">
                                                    <svg viewBox="0 0 120 120" class="h-28 w-28 shrink-0" aria-hidden="true">
                                                        <circle cx="{{ $cx }}" cy="{{ $cy }}" r="{{ $r }}" fill="none" stroke-width="14" class="stroke-gray-100 dark:stroke-white/10"/>
                                                        @foreach ($segments as $seg)
                                                            <circle cx="{{ $cx }}" cy="{{ $cy }}" r="{{ $r }}" fill="none" stroke="{{ $seg['color'] }}" stroke-width="14" stroke-dasharray="{{ $seg['dash'] }} {{ $seg['gap'] }}" stroke-dashoffset="{{ $seg['offset'] }}" transform="rotate(-90 {{ $cx }} {{ $cy }})"/>
                                                        @endforeach
                                                        <text x="{{ $cx }}" y="{{ $cy - 2 }}" text-anchor="middle" class="fill-gray-900 text-[13px] font-bold dark:fill-white">{{ $fmtChartValue($chartTotal) }}</text>
                                                        <text x="{{ $cx }}" y="{{ $cy + 10 }}" text-anchor="middle" class="fill-gray-400 text-[8px]">{{ __('assistant.report.chart_total') }}</text>
                                                    </svg>
                                                    <ul class="min-w-0 flex-1 space-y-1">
                                                        @foreach ($segments as $seg)
                                                            <li class="flex items-center gap-1.5 text-[11px] text-gray-600 dark:text-gray-300">
                                                                <span class="h-2 w-2 shrink-0 rounded-full" style="background-color: {{ $seg['color'] }}"></span>
                                                                <span class="min-w-0 flex-1 truncate">{{ $seg['label'] }}</span>
                                                                <span class="shrink-0 font-medium tabular-nums text-gray-900 dark:text-white">{{ $fmtChartValue($seg['value']) }}</span>
                                                            </li>
                                                        @endforeach
                                                    </ul>
                                                </div>
                                            @endif
                                        </div>
                                        @break
                                    @case('table')
                                        <div>
                                            @if (($block['title'] ?? '') !== '')
                                                <p class="mb-1.5 text-[11px] font-semibold text-gray-500 dark:text-gray-400">{{ $block['title'] }}</p>
                                            @endif
                                            <div class="overflow-x-auto rounded-lg ring-1 ring-gray-950/10 dark:ring-white/10">
                                                <table class="w-full text-xs">
                                                    <thead class="bg-gray-50 dark:bg-gray-800">
                                                        <tr>
                                                            @foreach ($block['columns'] as $column)
                                                                <th class="px-2.5 py-2 text-left font-semibold text-gray-500 dark:text-gray-400">{{ $column }}</th>
                                                            @endforeach
                                                        </tr>
                                                    </thead>
                                                    <tbody class="divide-y divide-gray-950/5 dark:divide-white/5">
                                                        @foreach ($block['rows'] as $row)
                                                            <tr>
                                                                @foreach ($row as $cell)
                                                                    <td class="px-2.5 py-2 text-gray-700 dark:text-gray-200">{{ $cell }}</td>
                                                                @endforeach
                                                            </tr>
                                                        @endforeach
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                        @break
                                    @case('steps')
                                        <ol class="space-y-2">
                                            @foreach ($block['items'] as $stepItem)
                                                <li class="flex gap-2.5">
                                                    <span class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-gray-100 text-[10px] font-bold text-gray-600 ring-1 ring-gray-950/5 dark:bg-white/10 dark:text-gray-300 dark:ring-white/10">{{ $loop->iteration }}</span>
                                                    <div class="min-w-0 flex-1">
                                                        <p class="text-xs font-semibold text-gray-900 dark:text-white">{{ $stepItem['title'] }}</p>
                                                        @if (($stepItem['text'] ?? '') !== '')
                                                            <p class="mt-0.5 text-[11px] leading-relaxed text-gray-500 dark:text-gray-400">{{ $stepItem['text'] }}</p>
                                                        @endif
                                                        @if (! empty($stepItem['action']))
                                                            @if (($stepItem['action']['kind'] ?? '') === 'url' && ! empty($stepItem['action']['url']))
                                                                <button type="button" @if ($fromPreview) x-on:click="dismissPreviewForChat()" @endif wire:click="goTo('{{ $stepItem['action']['url'] }}')" class="{{ $iconBtn }} mt-1.5">
                                                                    {{ $stepItem['action']['label'] }}
                                                                </button>
                                                            @elseif (($stepItem['action']['kind'] ?? '') === 'prompt' && ! empty($stepItem['action']['prompt']))
                                                                <button type="button" x-on:click="{{ $alpinePromptMethod }}({{ \Illuminate\Support\Js::from($stepItem['action']['prompt']) }})" x-bind:disabled="sending" class="{{ $iconBtn }} mt-1.5">
                                                                    {{ $stepItem['action']['label'] }}
                                                                </button>
                                                            @endif
                                                        @endif
                                                    </div>
                                                </li>
                                            @endforeach
                                        </ol>
                                        @break
                                    @case('callouts')
                                        <div class="space-y-2">
                                            @foreach ($block['items'] as $callout)
                                                @php
                                                    $tone = $callout['tone'] ?? 'info';
                                                    $edge = ['danger' => 'border-red-300 dark:border-red-500/40', 'warning' => 'border-amber-300 dark:border-amber-500/40', 'success' => 'border-emerald-300 dark:border-emerald-500/40', 'info' => 'border-sky-300 dark:border-sky-500/40'][$tone] ?? 'border-gray-300 dark:border-white/15';
                                                @endphp
                                                <div class="rounded-lg border-l-2 {{ $edge }} bg-gray-50 px-3 py-2.5 dark:bg-white/5">
                                                    @if (($callout['title'] ?? '') !== '')
                                                        <p class="text-xs font-semibold text-gray-900 dark:text-white">{{ $callout['title'] }}</p>
                                                    @endif
                                                    @if (($callout['text'] ?? '') !== '')
                                                        <p class="mt-1 text-[11px] leading-relaxed text-gray-600 dark:text-gray-300">{{ $callout['text'] }}</p>
                                                    @endif
                                                </div>
                                            @endforeach
                                        </div>
                                        @break
                                    @case('list')
                                        <ul class="list-disc space-y-1 pl-5 text-sm leading-relaxed text-gray-700 dark:text-gray-200">
                                            @foreach ($block['items'] as $item)
                                                <li>{{ $item }}</li>
                                            @endforeach
                                        </ul>
                                        @break
                                @endswitch
                            @endforeach
                        </div>
                    </section>
                @endforeach
            </div>
        </div>
    @endif
@endif
