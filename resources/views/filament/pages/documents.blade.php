{{-- Pantalla de documentos.

     El formulario arriba elige el formato una sola vez; los botones de abajo
     imprimen en esa familia. El texto plano aparece debajo para copiarlo, y el
     PDF se descarga sin pasar por esta pantalla de nuevo. --}}
<x-filament-panels::page>
    <form wire:submit="generateStatement">
        {{ $this->form }}

        <div class="fi-section mt-6 rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <h3 class="text-base font-semibold text-gray-950 dark:text-white">
                {{ __('invoice.documents.title') }}
            </h3>

            <div class="mt-3 flex flex-wrap gap-3">
                <x-filament::button
                    type="submit"
                    wire:key="generate-statement"
                >
                    {{ __('invoice.buttons.statement') }}
                </x-filament::button>

                <x-filament::button
                    type="button"
                    color="gray"
                    wire:click="generateInvoice"
                    wire:key="generate-invoice"
                >
                    {{ __('invoice.buttons.download_invoice') }}
                </x-filament::button>

                <x-filament::button
                    type="button"
                    color="gray"
                    wire:click="generateAdjustmentLetter"
                    wire:key="generate-adjustment-letter"
                >
                    {{ __('rent_adjustment.buttons.letter') }}
                </x-filament::button>

                {{-- El comprobante se apaga mientras la factura no este pagada.
                     El bloqueo real esta en el dominio: aun con el boton
                     visible, la peticion falla. --}}
                <x-filament::button
                    type="button"
                    color="gray"
                    wire:click="generateReceipt"
                    wire:key="generate-receipt"
                    :disabled="! $this->receiptAvailable($this->data['invoice_id'] ?? null)"
                >
                    {{ __('invoice.buttons.download_receipt') }}
                </x-filament::button>
            </div>

            @unless ($this->receiptAvailable($this->data['invoice_id'] ?? null))
                <p class="mt-2 text-sm text-amber-700 dark:text-amber-400">
                    {{ __('invoice.receipt_locked') }}
                </p>
            @endunless
        </div>
    </form>

    @if ($this->textPreview !== null)
        <div class="fi-section mt-6 rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div x-data="{ copied: false }">
                <div class="flex items-center justify-between gap-3">
                    <h3 class="text-base font-semibold text-gray-950 dark:text-white">
                        {{ __('invoice.documents.title') }}
                    </h3>

                    <x-filament::button
                        size="sm"
                        type="button"
                        x-on:click="navigator.clipboard.writeText($refs.preview.value); copied = true"
                    >
                        {{ __('invoice.documents.copy') }}
                    </x-filament::button>
                </div>

                <span
                    x-cloak
                    x-show="copied"
                    x-transition
                    class="text-sm text-success-600 dark:text-success-400"
                >
                    {{ __('invoice.documents.copied') }}
                </span>

                <textarea
                    x-ref="preview"
                    readonly
                    rows="24"
                    spellcheck="false"
                    class="mt-3 w-full rounded-lg border-gray-300 font-mono text-sm dark:border-white/10 dark:bg-gray-800"
                >{{ $this->textPreview }}</textarea>
            </div>
        </div>
    @endif
</x-filament-panels::page>