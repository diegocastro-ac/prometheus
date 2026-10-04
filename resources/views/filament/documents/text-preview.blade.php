{{-- Vista previa de un documento en texto plano, con boton de copiar.

     El portapapeles se maneja con Alpine y la API nativa del navegador: no
     hace falta ninguna libreria. La textarea es readonly a proposito, para que
     el documento no se pueda editar por error desde la pantalla. --}}
<div class="fi-document-preview">
    <div
        x-data="{ copied: false }"
        class="fi-document-preview-actions"
    >
        <x-filament::button
            type="button"
            x-on:click="navigator.clipboard.writeText($refs.body.value); copied = true"
            x-ref="copyButton"
        >
            {{ __('invoice.documents.copy') }}
        </x-filament::button>

        <span
            x-cloak
            x-show="copied"
            x-transition
            class="fi-document-preview-copied"
        >
            {{ __('invoice.documents.copied') }}
        </span>
    </div>

    <textarea
        x-ref="body"
        readonly
        rows="20"
        spellcheck="false"
        class="fi-document-preview-body"
    >{{ $content }}</textarea>
</div>