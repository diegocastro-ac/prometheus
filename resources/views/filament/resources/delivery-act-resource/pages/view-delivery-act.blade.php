{{--
    Consulta de un acta de entrega.
    El arbol se filtra a las ramas con inventario desde el presenter; el modo
    organigrama es una mejora progresiva y la tabla sigue siendo la fuente
    legible cuando JavaScript no esta disponible.
--}}
<x-filament-panels::page>
    @php
        $record = $this->getRecord();
        $inventory = $this->inventory();
        $stats = $inventory->stats();
        $rows = $inventory->rows();
        $chartData = $inventory->chartData();
        $orphans = $inventory->orphanItems();

        $typeLabel = __('act.types.'.$record->type);

        $stateColors = [
            'nuevo' => 'info',
            'bueno' => 'success',
            'reparable' => 'warning',
            'por_reemplazar' => 'danger',
            'destruido' => 'gray',
        ];

        $stateLabel = fn (?string $state): string => $state === null
            ? __('act.view.without_state')
            : \App\Enums\ActItemState::from($state)->label();

        $chartLabels = [
            'direct' => __('act.view.direct_items'),
            'total' => __('act.view.total_items'),
            'more' => __('act.view.more_items'),
        ];

        // Se resuelve con Vite para respetar el manifest de produccion y el
        // servidor de desarrollo. La carga real la hace Alpine con x-load-js,
        // que es el mecanismo documentado por Filament para assets de paginas.
        $chartScript = \Illuminate\Support\Facades\Vite::asset('resources/js/space-org-chart.js');
    @endphp

    <div class="space-y-6">
        <x-filament::section>
            <x-slot name="heading">{{ __('act.view.heading') }}</x-slot>

            <dl class="grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        {{ __('act.view.fields.type') }}
                    </dt>
                    <dd class="mt-1 text-sm font-medium text-gray-950 dark:text-white">
                        {{ $typeLabel }}
                    </dd>
                </div>

                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        {{ __('act.view.fields.date') }}
                    </dt>
                    <dd class="mt-1 text-sm font-medium text-gray-950 dark:text-white">
                        {{ $record->occurred_at?->format('d/m/Y') ?? '—' }}
                        @if ($record->scheduled_at)
                            · {{ $record->scheduled_at->format('H:i') }}
                        @endif
                    </dd>
                </div>

                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        {{ __('act.view.fields.signed') }}
                    </dt>
                    <dd class="mt-1">
                        {{-- El badge de Filament usa display:flex; en un <dd> suelto
                             ocuparia todo el ancho. El wrapper lo mantiene compacto. --}}
                        <span class="inline-flex">
                            <x-filament::badge :color="$record->isSigned() ? 'success' : 'warning'">
                                {{ $record->isSigned() ? __('act.view.fields.signed_yes') : __('act.view.fields.signed_no') }}
                            </x-filament::badge>
                        </span>
                    </dd>
                </div>

                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        {{ __('act.view.fields.tenant') }}
                    </dt>
                    <dd class="mt-1 text-sm font-medium text-gray-950 dark:text-white">
                        {{ $record->tenant_name }}
                    </dd>
                </div>

                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        {{ __('act.view.fields.landlord') }}
                    </dt>
                    <dd class="mt-1 text-sm font-medium text-gray-950 dark:text-white">
                        {{ $record->landlord_name }}
                    </dd>
                </div>

                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        {{ __('act.view.fields.rental') }}
                    </dt>
                    <dd class="mt-1 text-sm font-medium text-gray-950 dark:text-white">
                        {{ $record->rental?->name ?? '—' }}
                    </dd>
                </div>
            </dl>
        </x-filament::section>

        <div class="grid gap-4 sm:grid-cols-3">
            <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    {{ __('act.view.fields.spaces') }}
                </p>
                <p class="mt-2 text-3xl font-semibold text-gray-950 dark:text-white">{{ $stats['spaces'] }}</p>
            </div>

            <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    {{ __('act.view.fields.items') }}
                </p>
                <p class="mt-2 text-3xl font-semibold text-gray-950 dark:text-white">{{ $stats['items'] }}</p>
            </div>

            <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    {{ __('act.view.fields.orphans') }}
                </p>
                <p class="mt-2 text-3xl font-semibold text-gray-950 dark:text-white">{{ $stats['orphans'] }}</p>
            </div>
        </div>

        @if ($record->hasMeterReadings() || filled($record->commitments) || filled($record->observations))
            <x-filament::section collapsible>
                <x-slot name="heading">{{ __('act.sections.commitments') }}</x-slot>

                <div class="grid gap-5 md:grid-cols-2">
                    @if ($record->hasMeterReadings())
                        <div>
                            <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                {{ __('act.view.fields.readings') }}
                            </p>
                            <ul class="mt-2 space-y-1 text-sm text-gray-700 dark:text-gray-200">
                                @if (filled($record->water_reading))
                                    <li>{{ __('act.form.water_reading') }}: <strong>{{ $record->water_reading }}</strong></li>
                                @endif
                                @if (filled($record->energy_reading))
                                    <li>{{ __('act.form.energy_reading') }}: <strong>{{ $record->energy_reading }}</strong></li>
                                @endif
                                @if (filled($record->gas_reading))
                                    <li>{{ __('act.form.gas_reading') }}: <strong>{{ $record->gas_reading }}</strong></li>
                                @endif
                            </ul>
                        </div>
                    @endif

                    @if (filled($record->commitments))
                        <div>
                            <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                {{ __('act.view.fields.commitments') }}
                            </p>
                            <p class="mt-2 whitespace-pre-line text-sm text-gray-700 dark:text-gray-200">{{ $record->commitments }}</p>
                        </div>
                    @endif

                    @if (filled($record->observations))
                        <div class="md:col-span-2">
                            <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                {{ __('act.view.fields.observations') }}
                            </p>
                            <p class="mt-2 whitespace-pre-line text-sm text-gray-700 dark:text-gray-200">{{ $record->observations }}</p>
                        </div>
                    @endif
                </div>
            </x-filament::section>
        @endif

        @if ($inventory->isEmpty())
            <x-filament::section>
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('act.view.no_inventory') }}</p>
            </x-filament::section>
        @else
            <div x-data="{ mode: 'chart' }" class="space-y-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="inline-flex rounded-lg bg-gray-100 p-1 dark:bg-white/5">
                        <button
                            type="button"
                            x-on:click="mode = 'chart'"
                            x-bind:class="mode === 'chart'
                                ? 'bg-white text-gray-950 shadow-sm dark:bg-white/10 dark:text-white'
                                : 'text-gray-600 hover:text-gray-950 dark:text-gray-300 dark:hover:text-white'"
                            class="rounded-md px-4 py-2 text-sm font-medium transition"
                        >
                            {{ __('act.view.chart') }}
                        </button>

                        <button
                            type="button"
                            x-on:click="mode = 'table'"
                            x-bind:class="mode === 'table'
                                ? 'bg-white text-gray-950 shadow-sm dark:bg-white/10 dark:text-white'
                                : 'text-gray-600 hover:text-gray-950 dark:text-gray-300 dark:hover:text-white'"
                            class="rounded-md px-4 py-2 text-sm font-medium transition"
                        >
                            {{ __('act.view.table') }}
                        </button>
                    </div>

                    <p class="max-w-xl text-sm text-gray-500 dark:text-gray-400">
                        <span x-show="mode === 'chart'">{{ __('act.view.chart_help') }}</span>
                        <span x-show="mode === 'table'" x-cloak>{{ __('act.view.table_help') }}</span>
                    </p>
                </div>

                <div
                    x-show="mode === 'chart'"
                    x-load-js="[@js($chartScript)]"
                    data-js-as-module="true"
                    data-org-chart-root
                    class="space-y-3"
                >
                    <div class="flex flex-wrap gap-2">
                        <x-filament::button type="button" size="xs" color="gray" data-org-chart-action="zoom-in">
                            {{ __('act.view.zoom_in') }}
                        </x-filament::button>
                        <x-filament::button type="button" size="xs" color="gray" data-org-chart-action="zoom-out">
                            {{ __('act.view.zoom_out') }}
                        </x-filament::button>
                        <x-filament::button type="button" size="xs" color="gray" data-org-chart-action="fit">
                            {{ __('act.view.fit') }}
                        </x-filament::button>
                        <x-filament::button type="button" size="xs" color="gray" data-org-chart-action="expand-all">
                            {{ __('act.view.expand_all') }}
                        </x-filament::button>
                        <x-filament::button type="button" size="xs" color="gray" data-org-chart-action="collapse-all">
                            {{ __('act.view.collapse_all') }}
                        </x-filament::button>
                    </div>

                    <script type="application/json" id="space-org-chart-data-{{ $record->getKey() }}">@json($chartData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)</script>

                    <div
                        class="space-org-chart"
                        data-space-org-chart
                        data-chart-data="space-org-chart-data-{{ $record->getKey() }}"
                        data-labels="{{ json_encode($chartLabels, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) }}"
                    ></div>

                    <noscript>
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('act.view.table_help') }}</p>
                    </noscript>
                </div>

                <div x-show="mode === 'table'" x-cloak>
                    <div class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                        <div class="overflow-x-auto">
                            <table class="w-full table-fixed divide-y divide-gray-200 text-sm dark:divide-white/10">
                                <thead class="bg-gray-50 dark:bg-white/5">
                                    <tr>
                                        <th class="w-[42%] px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                            {{ __('act.form.space') }}
                                        </th>
                                        <th class="w-[40%] px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                            {{ __('act.view.fields.items_detail') }}
                                        </th>
                                        <th class="w-[9%] px-3 py-3 text-center text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                            {{ __('act.view.fields.direct') }}
                                        </th>
                                        <th class="w-[9%] px-3 py-3 text-center text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                            {{ __('act.view.fields.total') }}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                                    @foreach ($rows as $row)
                                        <tr class="align-top transition hover:bg-gray-50/70 dark:hover:bg-white/[0.03]">
                                            <td class="px-4 py-4">
                                                <div class="flex items-start gap-3" style="padding-left: {{ $row['depth'] * 1.15 }}rem">
                                                    <span @class([
                                                        'mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg',
                                                        'bg-primary-50 text-primary-600 dark:bg-primary-400/10 dark:text-primary-400' => $row['depth'] === 0,
                                                        'bg-gray-100 text-gray-500 dark:bg-white/5 dark:text-gray-400' => $row['depth'] !== 0,
                                                    ])>
                                                        @if ($row['composite'])
                                                            <x-heroicon-o-folder class="h-4 w-4" />
                                                        @else
                                                            <x-heroicon-o-home class="h-4 w-4" />
                                                        @endif
                                                    </span>

                                                    <div class="min-w-0">
                                                        <p class="truncate font-semibold text-gray-950 dark:text-white">{{ $row['name'] }}</p>

                                                        @if ($row['depth'] > 0)
                                                            <p class="mt-0.5 break-words text-xs text-gray-500 dark:text-gray-400">{{ $row['path'] }}</p>
                                                        @endif

                                                        @if ($row['stateCounts'] !== [])
                                                            <div class="mt-2 flex flex-wrap gap-1">
                                                                @foreach ($row['stateCounts'] as $state => $count)
                                                                    <x-filament::badge size="sm" :color="$stateColors[$state] ?? 'gray'">
                                                                        {{ $count }} {{ $stateLabel($state) }}
                                                                    </x-filament::badge>
                                                                @endforeach
                                                            </div>
                                                        @endif
                                                    </div>
                                                </div>
                                            </td>

                                            <td class="px-4 py-4">
                                                @if ($row['items'] !== [])
                                                    <details class="group">
                                                        <summary class="inline-flex cursor-pointer list-none items-center gap-1.5 rounded-md text-sm font-medium text-primary-600 hover:text-primary-500 dark:text-primary-400">
                                                            <x-heroicon-o-chevron-right class="h-4 w-4 transition-transform group-open:rotate-90" />
                                                            {{ trans_choice('act.inventory_tree.items', count($row['items']), ['count' => count($row['items'])]) }}
                                                        </summary>

                                                        <ul class="mt-2 space-y-1.5">
                                                            @foreach ($row['items'] as $item)
                                                                <li class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-white/5">
                                                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                                                        <span class="min-w-0 break-words font-medium text-gray-950 dark:text-white">{{ $item['name'] }}</span>
                                                                        <x-filament::badge size="sm" :color="$stateColors[$item['state']] ?? 'gray'">
                                                                            {{ $item['stateLabel'] ?? __('act.view.without_state') }}
                                                                        </x-filament::badge>
                                                                    </div>

                                                                    @if (filled($item['note']))
                                                                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $item['note'] }}</p>
                                                                    @endif
                                                                </li>
                                                            @endforeach
                                                        </ul>
                                                    </details>
                                                @else
                                                    <span class="text-sm text-gray-400">—</span>
                                                @endif
                                            </td>

                                            <td class="px-3 py-4 text-center">
                                                <span class="inline-flex min-w-8 items-center justify-center rounded-md bg-gray-100 px-2 py-1 text-sm font-semibold text-gray-700 dark:bg-white/5 dark:text-gray-200">
                                                    {{ $row['directItems'] }}
                                                </span>
                                            </td>

                                            <td class="px-3 py-4 text-center">
                                                <span class="inline-flex min-w-8 items-center justify-center rounded-md bg-primary-50 px-2 py-1 text-sm font-semibold text-primary-700 dark:bg-primary-400/10 dark:text-primary-300">
                                                    {{ $row['totalItems'] }}
                                                </span>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        @endif

        @if ($orphans->isNotEmpty())
            <x-filament::section>
                <x-slot name="heading">{{ __('act.view.orphans') }}</x-slot>
                <x-slot name="description">{{ __('act.view.orphans_help') }}</x-slot>

                <div class="overflow-x-auto">
                    <table class="w-full divide-y divide-gray-200 text-sm dark:divide-white/10">
                        <thead>
                            <tr>
                                <th class="px-3 py-2 text-left font-medium text-gray-600 dark:text-gray-300">{{ __('act.form.item_name') }}</th>
                                <th class="px-3 py-2 text-left font-medium text-gray-600 dark:text-gray-300">{{ __('act.form.item_state') }}</th>
                                <th class="px-3 py-2 text-left font-medium text-gray-600 dark:text-gray-300">{{ __('act.form.item_note') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                            @foreach ($orphans as $item)
                                <tr>
                                    <td class="px-3 py-2 font-medium text-gray-950 dark:text-white">{{ $item->name }}</td>
                                    <td class="px-3 py-2">
                                        <span class="inline-flex">
                                            <x-filament::badge size="sm" :color="$stateColors[$item->state?->value] ?? 'gray'">
                                                {{ $item->state?->label() ?? __('act.view.without_state') }}
                                            </x-filament::badge>
                                        </span>
                                    </td>
                                    <td class="px-3 py-2 text-gray-600 dark:text-gray-300">{{ $item->note ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        @endif
    </div>

    <style>
        .space-org-chart {
            height: 640px;
            width: 100%;
            overflow: hidden;
            border-radius: 0.75rem;
            border: 1px solid rgb(229 231 235);
            background: rgb(249 250 251);
        }

        .dark .space-org-chart {
            border-color: rgb(255 255 255 / 0.1);
            background: rgb(17 24 39 / 0.6);
        }

        .space-org-node {
            box-sizing: border-box;
            height: 100%;
            overflow: hidden;
            border: 1px solid rgb(209 213 219);
            border-radius: 0.875rem;
            background: #fff;
            padding: 0.75rem 0.875rem;
            box-shadow: 0 8px 20px rgb(15 23 42 / 0.08);
            font-family: inherit;
        }

        .dark .space-org-node {
            border-color: rgb(255 255 255 / 0.14);
            background: rgb(31 41 55);
            box-shadow: 0 8px 20px rgb(0 0 0 / 0.25);
        }

        .space-org-node--composite {
            border-color: rgb(139 92 246 / 0.65);
        }

        .space-org-node--root {
            border-color: rgb(99 102 241);
            background: linear-gradient(135deg, #eef2ff, #ffffff 65%);
        }

        .dark .space-org-node--root {
            background: linear-gradient(135deg, rgb(49 46 129 / 0.85), rgb(31 41 55) 70%);
        }

        .space-org-node__name {
            color: rgb(17 24 39);
            font-size: 0.8125rem;
            font-weight: 700;
            line-height: 1.2;
        }

        .dark .space-org-node__name {
            color: #fff;
        }

        .space-org-node__path {
            margin-top: 0.2rem;
            overflow: hidden;
            color: rgb(107 114 128);
            font-size: 0.625rem;
            line-height: 1.2;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .dark .space-org-node__path {
            color: rgb(156 163 175);
        }

        .space-org-node__meta {
            display: flex;
            gap: 0.5rem;
            margin-top: 0.45rem;
            color: rgb(75 85 99);
            font-size: 0.625rem;
        }

        .dark .space-org-node__meta {
            color: rgb(209 213 219);
        }

        .space-org-node__items {
            margin: 0.45rem 0 0;
            padding: 0;
            list-style: none;
        }

        .space-org-node__items li {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.4rem;
            overflow: hidden;
            color: rgb(55 65 81);
            font-size: 0.625rem;
            line-height: 1.5;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .dark .space-org-node__items li {
            color: rgb(229 231 235);
        }

        .space-org-node__state {
            flex-shrink: 0;
            color: rgb(107 114 128);
            font-size: 0.5625rem;
        }

        .space-org-node__more {
            margin-top: 0.2rem;
            color: rgb(79 70 229);
            font-size: 0.5625rem;
            font-weight: 600;
        }

        .dark .space-org-node__more {
            color: rgb(165 180 252);
        }
    </style>
</x-filament-panels::page>
