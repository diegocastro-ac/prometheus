<?php

namespace App\Filament\Support;

use App\Documents\Contracts\Document;
use Closure;
use Filament\Actions\MountableAction;
use Filament\Support\Enums\MaxWidth;
use Filament\Tables\Actions\Action as TableAction;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Acciones de Filament que entregan documentos producidos por el Abstract
 * Factory.
 *
 * Hay dos acciones y no una con un desplegable de formato, y es a proposito:
 * cada accion pide una familia completa a la fabrica (PDF o texto) y entrega el
 * resultado por el mecanismo que corresponde a esa familia. Que el boton diga
 * "Descargar PDF" o "Copiar texto" no es una decision de la pantalla, es la
 * consecuencia de la familia elegida.
 *
 * La clase de accion se recibe por parametro porque Filament tiene dos y no
 * son intercambiables: una accion de tabla implementa HasTable y la de pagina
 * no. Se elige la correcta en el punto de uso y aqui no se adivina.
 *
 * Agregar un quinto documento no obliga a tocar esta clase: basta pedir otro
 * producto a la fabrica.
 */
class DocumentAction
{
    /**
     * Descarga el documento como archivo.
     *
     * @param  Closure(mixed, string): Document  $builder  Recibe el registro y
     *                                                     el formato pedido, y
     *                                                     devuelve el documento
     *                                                     ya renderizado.
     * @param  class-string<MountableAction>  $actionClass
     */
    public static function pdf(
        string $name,
        Closure $builder,
        ?string $label = null,
        string $actionClass = TableAction::class,
    ): MountableAction {
        return $actionClass::make($name)
            ->label($label ?? __('invoice.buttons.download_document_pdf'))
            ->icon('heroicon-o-arrow-down-tray')
            ->action(fn (mixed $record) => self::download($builder($record, 'pdf')));
    }

    /**
     * Muestra el documento en texto plano para copiarlo.
     *
     * El documento se construye una sola vez y se reutiliza entre el encabezado
     * y el cuerpo del modal. El renderizado de texto es barato, pero armarlo dos
     * veces por el mismo clic es la clase de descuido que despues cuesta
     * encontrar cuando el documento empieza a hacer consultas.
     *
     * @param  Closure(mixed, string): Document  $builder
     * @param  class-string<MountableAction>  $actionClass
     */
    public static function text(
        string $name,
        Closure $builder,
        ?string $label = null,
        string $actionClass = TableAction::class,
    ): MountableAction {
        $cached = null;

        // El closure captura la referencia, asi que el documento sobrevive entre
        // las varias veces que Filament evalua encabezado y contenido del modal.
        $resolve = static function (mixed $record) use ($builder, &$cached): Document {
            return $cached ??= $builder($record, 'text');
        };

        return $actionClass::make($name)
            ->label($label ?? __('invoice.buttons.copy_document_text'))
            ->icon('heroicon-o-clipboard-document')
            ->modalWidth(MaxWidth::TwoExtraLarge)
            ->modalHeading(fn (mixed $record): string => $resolve($record)->preview())
            ->modalContent(fn (mixed $record): string => view('filament.documents.text-preview', [
                'content' => $resolve($record)->content(),
            ]))
            ->modalSubmitAction(false);
    }

    /**
     * Entrega el documento por descarga.
     *
     * El nombre del archivo lo decide el documento y no la pantalla: el
     * comprobante se llama comprobante-FV-2026-0001.pdf porque lo asi armo el
     * producto, no porque la accion lo renombre.
     *
     * Devuelve la respuesta a proposito. Filament y Livewire solo envian el
     * archivo cuando la accion la retorna; construirla y Tirarla produce un
     * boton que no hace nada y una prueba que pasa igual, porque el documento
     * si se renderizo.
     */
    public static function download(Document $document): StreamedResponse
    {
        return response()->streamDownload(
            fn () => print($document->content()),
            $document->filename(),
            ['Content-Type' => $document->mimeType()],
        );
    }
}