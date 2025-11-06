<x-filament-panels::page>
    <div class="space-y-6">
        {{-- Encabezado --}}
        <x-filament::section>
            <div class="text-center space-y-3">
                <h1 class="text-3xl font-bold text-primary-600 dark:text-primary-400">
                    {{ __('about.content.name') }}
                </h1>
                <p class="text-xl text-gray-600 dark:text-gray-400">
                    {{ __('about.content.subtitle') }}
                </p>
                <p class="text-lg italic text-gray-500 dark:text-gray-500">
                    "{{ __('about.content.slogan') }}"
                </p>
            </div>
        </x-filament::section>

        {{-- ¿Qué es Prometheus? --}}
        <x-filament::section>
            <x-slot name="heading">
                <div class="flex items-center gap-2">
                    <x-filament::icon icon="heroicon-o-question-mark-circle" class="h-5 w-5" />
                    {{ __('about.sections.what_is') }}
                </div>
            </x-slot>

            <p class="text-gray-700 dark:text-gray-500 leading-relaxed">
                {{ __('about.content.description') }}
            </p>
        </x-filament::section>

        {{-- ¿Qué hace? --}}
        <x-filament::section>
            <x-slot name="heading">
                <div class="flex items-center gap-2">
                    <x-filament::icon icon="heroicon-o-check-circle" class="h-5 w-5" />
                    {{ __('about.sections.what_does') }}
                </div>
            </x-slot>

            <ul class="space-y-3">
                <li class="flex items-start gap-3">
                    <x-filament::icon icon="heroicon-m-home-modern" class="h-5 w-5 text-success-600 mt-0.5" />
                    <span class="text-gray-700 dark:text-gray-500">
                        {{ __('about.content.features.property_management') }}
                    </span>
                </li>
                <li class="flex items-start gap-3">
                    <x-filament::icon icon="heroicon-m-chart-bar" class="h-5 w-5 text-success-600 mt-0.5" />
                    <span class="text-gray-700 dark:text-gray-500">
                        {{ __('about.content.features.statistics') }}
                    </span>
                </li>
            </ul>
        </x-filament::section>

        {{-- ¿Qué NO es? --}}
        <x-filament::section>
            <x-slot name="heading">
                <div class="flex items-center gap-2">
                    <x-filament::icon icon="heroicon-o-x-circle" class="h-5 w-5" />
                    {{ __('about.sections.what_not') }}
                </div>
            </x-slot>

            <div class="space-y-4">
                <p class="text-gray-700 dark:text-gray-500 leading-relaxed">
                    {{ __('about.content.what_not_is') }}
                </p>

                <ul class="space-y-3">
                    <li class="flex items-start gap-3">
                        <x-filament::icon icon="heroicon-m-x-mark" class="h-5 w-5 text-danger-600 mt-0.5" />
                        <span class="text-gray-700 dark:text-gray-500">
                            {{ __('about.content.what_not_does.no_publication') }}
                        </span>
                    </li>
                    <li class="flex items-start gap-3">
                        <x-filament::icon icon="heroicon-m-x-mark" class="h-5 w-5 text-danger-600 mt-0.5" />
                        <span class="text-gray-700 dark:text-gray-500">
                            {{ __('about.content.what_not_does.no_tenant_help') }}
                        </span>
                    </li>
                </ul>
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
