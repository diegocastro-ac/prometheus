{{--
    Guia de uso interactiva (app_guide).
    $compact = true  -> resumen en la burbuja + boton "ver guia completa"
                       que abre el modal de preview (necesita $messageIndex).
    $compact = false -> tarjeta completa (modal o se usa suelta).
    $fromPreview     -> los botones prompt cierran el modal antes de enviar.
--}}
@php
    $guide = $data['guide'] ?? $payload ?? null;
    $compact = $compact ?? false;
    $fromPreview = $fromPreview ?? false;
    $previewMethod = $previewMethod ?? null;
    $previewIndex = $previewIndex ?? null;
    $promptMethod = $fromPreview ? 'askFromPreview' : 'askSuggestion';
    $visibleSteps = $compact ? array_slice($guide['steps'] ?? [], 0, 2) : ($guide['steps'] ?? []);
@endphp

@if (is_array($guide) && ! empty($guide['steps']))
    @php $cardClickable = $compact && $previewMethod !== null; @endphp
    <div
        @if ($cardClickable)
            x-on:click="openPreview(() => $wire.{{ $previewMethod }}({{ $previewIndex }}))"
            x-on:keydown.enter.prevent="$el.click()"
            role="button"
            tabindex="0"
        @endif
        @class([
            'group mt-3 overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/10 transition dark:bg-gray-800 dark:ring-white/10',
            'cursor-pointer hover:bg-gray-50 hover:ring-gray-300 dark:hover:bg-white/5 dark:hover:ring-white/20' => $cardClickable,
        ])
    >
        {{-- Header tipo fi-section. --}}
        <div class="flex items-start gap-3 border-b border-gray-100 px-4 py-3 dark:border-white/10">
            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-gray-100 text-gray-500 ring-1 ring-gray-950/5 transition group-hover:bg-gray-200/70 dark:bg-white/5 dark:text-gray-400 dark:ring-white/10 dark:group-hover:bg-white/10">
                <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25" />
                </svg>
            </span>
            <div class="min-w-0 flex-1">
                <h4 class="truncate text-sm font-bold text-gray-900 transition group-hover:text-gray-700 dark:text-white dark:group-hover:text-gray-200">{{ $guide['title'] ?? __('assistant.guide.fallback_title') }}</h4>
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    {{ __('assistant.guide.steps_count', ['count' => count($guide['steps'])]) }}
                    @if (! empty($guide['intro']))
                        · {{ \Illuminate\Support\Str::limit($guide['intro'], 90) }}
                    @endif
                </p>
            </div>
            @if ($cardClickable)
                <span class="mt-1 flex h-7 w-7 shrink-0 items-center justify-center rounded-lg text-gray-300 transition group-hover:bg-gray-200/70 group-hover:text-gray-500 dark:text-gray-600 dark:group-hover:bg-white/10 dark:group-hover:text-gray-300" title="{{ __('assistant.guide.view_full') }}">
                    <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3.75v4.5m0-4.5h4.5m-4.5 0L9 9M3.75 20.25v-4.5m0 4.5h4.5m-4.5 0L9 15M20.25 3.75h-4.5m4.5 0v4.5m0-4.5L15 9m5.25 11.25h-4.5m4.5 0v-4.5m0 4.5L15 15" />
                    </svg>
                </span>
            @endif
        </div>

        @if ($compact)
            {{-- Preview: primeros pasos resumidos. --}}
            <ol class="divide-y divide-gray-100 dark:divide-white/10">
                @foreach ($visibleSteps as $step)
                    <li class="flex gap-3 px-4 py-2.5">
                        <span class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-gray-100 text-[10px] font-bold text-gray-600 ring-1 ring-gray-950/5 dark:bg-white/10 dark:text-gray-300 dark:ring-white/10">{{ $loop->iteration }}</span>
                        <p class="min-w-0 truncate text-xs font-medium text-gray-700 dark:text-gray-200">{{ $step['title'] }}</p>
                    </li>
                @endforeach
            </ol>
            @if (count($guide['steps']) > count($visibleSteps))
                <p class="border-t border-gray-100 px-4 py-2 text-[10px] text-gray-400 dark:border-white/10 dark:text-gray-500">
                    + {{ count($guide['steps']) - count($visibleSteps) }} {{ __('assistant.guide.more_steps') }}
                </p>
            @endif
        @else
            <ol class="divide-y divide-gray-100 dark:divide-white/10">
                @foreach ($guide['steps'] as $step)
                    <li class="flex gap-3 px-4 py-3">
                        <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-gray-100 text-[11px] font-bold text-gray-600 ring-1 ring-gray-950/5 dark:bg-white/10 dark:text-gray-300 dark:ring-white/10">{{ $loop->iteration }}</span>
                        <div class="min-w-0 flex-1">
                            <p class="text-xs font-semibold text-gray-900 dark:text-white">{{ $step['title'] }}</p>
                            @if (! empty($step['text']))
                                <p class="mt-1 text-[11px] leading-relaxed text-gray-500 dark:text-gray-400">{{ $step['text'] }}</p>
                            @endif
                            @if (! empty($step['action']))
                                @if ($step['action']['kind'] === 'url' && ! empty($step['action']['url']))
                                    <button
                                        type="button"
                                        @if ($fromPreview) x-on:click="dismissPreviewForChat()" @endif
                                        wire:click="goTo('{{ $step['action']['url'] }}')"
                                        class="mt-2 inline-flex items-center gap-1.5 rounded-lg bg-white px-2.5 py-1.5 text-[11px] font-semibold text-gray-700 shadow-sm ring-1 ring-gray-950/10 transition hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:ring-white/10 dark:hover:bg-white/5"
                                    >
                                        {{ $step['action']['label'] }}
                                        <svg class="h-3 w-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" /></svg>
                                    </button>
                                @elseif ($step['action']['kind'] === 'prompt' && ! empty($step['action']['prompt']))
                                    <button
                                        type="button"
                                        @if ($fromPreview) x-on:click="dismissPreviewForChat()" @endif
                                        wire:click="{{ $promptMethod }}({{ \Illuminate\Support\Js::from($step['action']['prompt']) }})"
                                        wire:loading.attr="disabled"
                                        class="mt-2 inline-flex items-center gap-1.5 rounded-lg bg-gray-900 px-2.5 py-1.5 text-[11px] font-semibold text-white transition hover:bg-gray-700 disabled:opacity-50 dark:bg-white dark:text-gray-900 dark:hover:bg-gray-200"
                                    >
                                        <svg class="h-3 w-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 0 0-2.456 2.456Z" /></svg>
                                        {{ $step['action']['label'] }}
                                    </button>
                                @endif
                            @endif
                        </div>
                    </li>
                @endforeach
            </ol>
        @endif
    </div>
@endif
