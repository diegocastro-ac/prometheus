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
        style="width: 100%; font-family: 'Courier New', monospace; font-size: 13px; line-height: 1.4; padding: 12px; border: 1px solid #d1d5db; border-radius: 6px; background: #f9fafb; resize: vertical; white-space: pre; overflow-x: auto;"
    >{{ $content }}</textarea>
</div>