<x-filament-panels::page>
    <div class="space-y-6">
        {{-- Descripción --}}
        <x-filament::section>
            <div class="text-center">
                <p class="text-lg text-gray-700 dark:text-gray-500 leading-relaxed">
                    {{ __('contact.content.description') }}
                </p>
            </div>
        </x-filament::section>

        {{-- Información de contacto --}}
        <x-filament::section>
            <x-slot name="heading">
                <div class="flex items-center gap-2">
                    <x-filament::icon icon="heroicon-o-information-circle" class="h-5 w-5" />
                    {{ __('contact.sections.contact_info') }}
                </div>
            </x-slot>

            <div class="space-y-6">
                {{-- Email --}}
                <div class="flex items-start gap-4 p-4 bg-gray-50 dark:bg-gray-800 rounded-lg">
                    <div class="flex-shrink-0">
                        <x-filament::icon icon="heroicon-o-envelope"
                            class="h-8 w-8 text-primary-600 dark:text-primary-400" />
                    </div>
                    <div class="flex-1">
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-1">
                            {{ __('contact.content.email') }}
                        </h3>
                        <a href="mailto:{{ __('contact.content.email_value') }}"
                            class="text-primary-600 dark:text-primary-400 hover:underline">
                            {{ __('contact.content.email_value') }}
                        </a>
                    </div>
                </div>

                {{-- Tiempo de respuesta --}}
                <div class="flex items-start gap-4 p-4 bg-gray-50 dark:bg-gray-800 rounded-lg">
                    <div class="flex-shrink-0">
                        <x-filament::icon icon="heroicon-o-clock"
                            class="h-8 w-8 text-primary-600 dark:text-primary-400" />
                    </div>
                    <div class="flex-1">
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-1">
                            {{ __('contact.content.response_time') }}
                        </h3>
                        <p class="text-gray-600 dark:text-gray-500">
                            {{ __('contact.content.response_time_value') }}
                        </p>
                    </div>
                </div>
            </div>
        </x-filament::section>

        {{-- Nota importante --}}
        <x-filament::section>
            <x-slot name="heading">
                <div class="flex items-center gap-2">
                    <x-filament::icon icon="heroicon-o-exclamation-triangle" class="h-5 w-5 text-warning-600" />
                    {{ __('contact.content.note') }}
                </div>
            </x-slot>

            <div
                class="bg-warning-50 dark:bg-warning-900/20 border border-warning-200 dark:border-warning-800 rounded-lg p-4">
                <p class="text-warning-800 dark:text-warning-200 leading-relaxed">
                    {{ __('contact.content.note_value') }}
                </p>
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
