{{-- Tablas de datos del asistente: una por cada clave del mapa `data`.
     Espera $data (array<string, list<array>>) y $messageIndex para el
     boton "Ver todo" que abre el modal de preview.
     Una celda que contiene una lista de filas (ej: 'acts' dentro de cada
     propiedad) se pinta como detalle expandible, no como JSON. --}}
@if (is_array($data) && $data !== [])
    <div class="mt-3 space-y-2">
        @foreach ($data as $key => $rows)
            {{-- 'candidates' y 'pending_resolution' no son tablas: los
                 candidatos se pintan como chips y la resolucion es metadata
                 interna para re-ejecutar la peticion. --}}
            @if (! in_array($key, ['candidates', 'pending_resolution'], true) && is_array($rows) && $rows !== [] && isset($rows[0]) && is_array($rows[0]))
                @php
                    // `_titles` permite que la respuesta traiga un titulo
                    // contextual ("Arriendo de X") que manda sobre el
                    // titulo generico del idioma.
                    $customTitle = $data['_titles'][$key] ?? null;
                    $titleKey = "assistant.data_titles.{$key}";
                    $tableTitle = is_string($customTitle) && $customTitle !== ''
                        ? $customTitle
                        : __($titleKey);
                    if ($tableTitle === $titleKey) {
                        $columnKey = "assistant.columns.{$key}";
                        $tableTitle = __($columnKey) !== $columnKey ? __($columnKey) : ucfirst(str_replace('_', ' ', $key));
                    }
                    $columns = array_keys($rows[0]);
                    // Colores de badge propuestos por la IA para esta tabla
                    // ({columna: color}); solo aplican a estados sin enum.
                    $tableColors = $badgeColors[$key] ?? [];
                @endphp
                <div>
                    <p class="mb-1 px-0.5 text-[10px] font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500">{{ $tableTitle }}</p>
                    <div class="overflow-x-auto rounded-lg ring-1 ring-gray-950/10 dark:ring-white/10">
                    <table class="w-full text-xs">
                        <thead class="bg-gray-50 dark:bg-gray-900">
                            <tr>
                                @foreach ($columns as $column)
                                    <th class="px-2.5 py-1.5 text-left font-semibold text-gray-500 dark:text-gray-400">
                                        {{ __("assistant.columns.{$column}") !== "assistant.columns.{$column}" ? __("assistant.columns.{$column}") : str_replace('_', ' ', ucfirst($column)) }}
                                    </th>
                                @endforeach
                            </tr>
                        </thead>
                        @foreach (array_slice($rows, 0, 5) as $row)
                            @php
                                // Celdas que son listas de filas: se renderizan
                                // como detalle expandible debajo de la fila.
                                $nestedCells = collect($row)->filter(
                                    fn ($cell): bool => is_array($cell) && isset($cell[0]) && is_array($cell[0]),
                                );
                            @endphp
                            <tbody
                                x-data="{ open: false }"
                                class="border-t border-gray-950/5 dark:border-white/5"
                            >
                                <tr>
                                    @foreach ($row as $column => $cell)
                                        <td class="px-2.5 py-1.5">
                                            @include('livewire.partials.assistant-cell', ['cell' => $cell, 'column' => is_string($column) ? $column : null, 'aiColor' => $tableColors[is_string($column) ? $column : ''] ?? null])
                                        </td>
                                    @endforeach
                                </tr>
                                @if ($nestedCells->isNotEmpty())
                                    <tr x-show="open">
                                        <td colspan="{{ count($columns) }}" class="bg-gray-50/60 px-3 py-2.5 dark:bg-white/5">
                                            <div class="space-y-2">
                                                @foreach ($nestedCells as $nestedRows)
                                                    <table class="w-full text-[11px]">
                                                        <thead>
                                                            <tr>
                                                                @foreach (array_keys($nestedRows[0]) as $nestedColumn)
                                                                    <th class="px-2 py-1 text-left font-semibold text-gray-400 dark:text-gray-500">
                                                                        {{ __("assistant.columns.{$nestedColumn}") !== "assistant.columns.{$nestedColumn}" ? __("assistant.columns.{$nestedColumn}") : str_replace('_', ' ', ucfirst($nestedColumn)) }}
                                                                    </th>
                                                                @endforeach
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            @foreach ($nestedRows as $nestedRow)
                                                                <tr class="border-t border-gray-950/5 dark:border-white/5">
                                                                    @foreach ($nestedRow as $nestedCellColumn => $nestedCell)
                                                                        <td class="px-2 py-1">@include('livewire.partials.assistant-cell', ['cell' => $nestedCell, 'column' => is_string($nestedCellColumn) ? $nestedCellColumn : null, 'aiColor' => $tableColors[is_string($nestedCellColumn) ? $nestedCellColumn : ''] ?? null, 'allowExpand' => false])</td>
                                                                    @endforeach
                                                                </tr>
                                                            @endforeach
                                                        </tbody>
                                                    </table>
                                                @endforeach
                                            </div>
                                        </td>
                                    </tr>
                                @endif
                            </tbody>
                        @endforeach
                    </table>
                    </div>
                    {{-- El modal muestra la tabla completa sin el recorte
                         de 5 filas: disponible para cualquier tamaño. --}}
                    <button
                        type="button"
                        x-on:click="previewBusy = true; $wire.previewData({{ $messageIndex }}, '{{ $key }}').finally(() => previewBusy = false)"
                        class="mt-1.5 inline-flex items-center gap-1 text-[11px] font-semibold text-primary-600 transition hover:text-primary-500 dark:text-primary-400"
                    >
                        <svg class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                        </svg>
                        {{ __('assistant.preview.view_all', ['count' => count($rows)]) }}
                    </button>
                </div>
            @endif
        @endforeach
    </div>
@endif
