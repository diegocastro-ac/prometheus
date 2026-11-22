<div class="space-y-4">
    @forelse ($nodes as $node)
        <div @class([
            'rounded-lg border border-gray-200 p-3 dark:border-white/10',
            'bg-gray-50 dark:bg-white/5' => $node->items()->isNotEmpty(),
        ])>
            <div class="flex items-center justify-between gap-3">
                <div class="flex items-center gap-2">
                    @if ($node->isComposite())
                        <x-heroicon-o-folder class="h-5 w-5 text-primary-600" />
                    @else
                        <x-heroicon-o-home class="h-5 w-5 text-gray-400" />
                    @endif

                    <span class="font-medium text-gray-950 dark:text-white">
                        {{ $node->name() }}
                    </span>
                </div>

                <span class="text-xs text-gray-500 dark:text-gray-400">
                    {{ trans_choice('act.inventory_tree.items', $node->totalItems(), ['count' => $node->totalItems()]) }}
                </span>
            </div>

            @if ($node->items()->isNotEmpty())
                <div class="mt-3 overflow-hidden rounded-md border border-gray-200 dark:border-white/10">
                    <table class="w-full divide-y divide-gray-200 text-sm dark:divide-white/10">
                        <thead class="bg-white dark:bg-gray-900">
                            <tr>
                                <th class="px-3 py-2 text-left font-medium text-gray-600 dark:text-gray-300">
                                    {{ __('act.form.item_name') }}
                                </th>
                                <th class="px-3 py-2 text-left font-medium text-gray-600 dark:text-gray-300">
                                    {{ __('act.form.item_state') }}
                                </th>
                                <th class="px-3 py-2 text-left font-medium text-gray-600 dark:text-gray-300">
                                    {{ __('act.form.item_note') }}
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                            @foreach ($node->items() as $item)
                                <tr>
                                    <td class="px-3 py-2 text-gray-950 dark:text-white">
                                        {{ $item->name }}
                                    </td>
                                    <td class="px-3 py-2 text-gray-600 dark:text-gray-300">
                                        {{ $item->state?->label() }}
                                    </td>
                                    <td class="px-3 py-2 text-gray-600 dark:text-gray-300">
                                        {{ $item->note }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @if ($node->children() !== [])
                <div class="mt-3 border-l border-gray-200 pl-4 dark:border-white/10">
                    @include('filament.acts.inventory-tree', [
                        'nodes' => $node->children(),
                        'depth' => $depth + 1,
                    ])
                </div>
            @endif
        </div>
    @empty
        <p class="text-sm text-gray-500 dark:text-gray-400">
            {{ __('act.inventory_tree.empty') }}
        </p>
    @endforelse
</div>
