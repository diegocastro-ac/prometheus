{{-- Vista previa de un documento en texto plano, con boton de copiar.

     El portapapeles se maneja con Alpine y la API nativa del navegador: no
     hace falta ninguna libreria. La textarea es readonly a proposito, para que
     el documento no se pueda editar por error desde la pantalla. --}}
<div x-data="{ copied: false }" style="padding: 16px;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
        <x-filament::button
            type="button"
            x-on:click="navigator.clipboard.writeText($refs.body.value); copied = true; setTimeout(() => copied = false, 2000)"
            x-ref="copyButton"
        >
            {{ __('invoice.documents.copy') }}
        </x-filament::button>

        <span
            x-cloak
            x-show="copied"
            x-transition
            style="color: #10b981; font-weight: 500; font-size: 14px;"
        >
            ✓ {{ __('invoice.documents.copied') }}
        </span>
    </div>

    <textarea
        x-ref="body"
        readonly
        rows="20"
        spellcheck="false"
        class="fi-px mt-4 block w-full rounded-lg border-0 bg-white py-2.5 px-3 text-sm text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-primary-600 dark:bg-gray-800 dark:text-gray-100 dark:ring-gray-600 dark:focus:ring-white/50"
        style="font-family: 'Courier New', monospace; line-height: 1.4; white-space: pre; overflow-x: auto; resize: vertical;"
    >{{ $content }}</textarea>
</div>