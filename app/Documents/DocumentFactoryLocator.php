<?php

namespace App\Documents;

use App\Documents\Factories\DocumentFactory;
use InvalidArgumentException;

/**
 * Resuelve la familia de documentos a partir de un formato.
 *
 * Existe porque la interfaz (DocumentFactory) tiene dos implementaciones y en
 * algun punto hay que elegir una. Ese punto no puede ser cada documento, o se
 * pierde la garantia del patron: si cada llamada eligiera formato, una misma
 * factura podria imprimirse como texto y su comprobante como PDF sin que nada
 * lo advierta.
 *
 * Por eso el formato se resuelve una vez por peticion y se pasa la fabrica ya
 * resuelta a quien construye el documento. Un formato desconocido falla en
 * lugar de recurrir a un valor por defecto silencioso: si la pantalla ofrece un
 * formato que el patron no soporta, es mejor un error visible que un PDF
 * disfrazado de texto.
 */
class DocumentFactoryLocator
{
    /**
     * @var list<string>
     */
    public const FORMATS = ['text', 'pdf'];

    /**
     * @var array<string, class-string<DocumentFactory>>
     */
    private const IMPLEMENTATIONS = [
        'text' => Factories\TextDocumentFactory::class,
        'pdf' => Factories\PdfDocumentFactory::class,
    ];

    public function for(?string $format): DocumentFactory
    {
        $format = $format ?? self::FORMATS[0];

        if (! array_key_exists($format, self::IMPLEMENTATIONS)) {
            throw new InvalidArgumentException(sprintf(
                'Formato de documento desconocido [%s]. Disponibles: %s.',
                $format,
                implode(', ', self::FORMATS),
            ));
        }

        return app(self::IMPLEMENTATIONS[$format]);
    }

    public function supports(?string $format): bool
    {
        return $format !== null && array_key_exists($format, self::IMPLEMENTATIONS);
    }
}