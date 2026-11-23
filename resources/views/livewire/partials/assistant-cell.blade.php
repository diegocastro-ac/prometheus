{{-- Celda de tabla del asistente. Espera $cell y $column (nombre de la
     columna, para detectar estados). Una celda que es lista de filas se
     pinta como control de expansion dentro del x-data del tbody padre. --}}
@php
    $isNestedRows = is_array($cell) && isset($cell[0]) && is_array($cell[0]);
    $badge = is_array($cell) ? null : \App\Support\AssistantStatusBadge::for($column ?? null, $cell, $aiColor ?? null);
@endphp
@if (is_array($cell) && $cell === [])
    <span class="text-gray-400 dark:text-gray-500">—</span>
@elseif ($isNestedRows && ($allowExpand ?? true))
    <button
        type="button"
        x-on:click="open = ! open"
        class="inline-flex items-center gap-1 rounded-full bg-gray-100 px-2 py-0.5 text-[10px] font-semibold text-gray-600 ring-1 ring-gray-950/5 transition hover:bg-gray-200 dark:bg-white/10 dark:text-gray-300 dark:ring-white/10 dark:hover:bg-white/15"
    >
        <span x-text="open ? '▾' : '▸'"></span>
        {{ __('assistant.nested.count', ['count' => count($cell)]) }}
    </button>
@elseif (is_array($cell))
    {{ json_encode($cell, JSON_UNESCAPED_UNICODE) }}
@elseif ($cell === null || $cell === '')
    <span class="text-gray-400 dark:text-gray-500">—</span>
@elseif ($badge !== null)
    <span class="{{ $badge['classes'] }}">{{ $badge['label'] }}</span>
@else
    {{ $cell }}
@endif
