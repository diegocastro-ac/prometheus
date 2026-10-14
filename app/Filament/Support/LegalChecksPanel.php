<?php

namespace App\Filament\Support;

/**
 * El aviso de comprobaciones legales, como bloque en línea y no como sección.
 *
 * POR QUÉ NO ES UNA SECCIÓN
 * ------------------------
 * Una sección de Filament es un contenedor con título, descripción y opción de
 * plegarse. Eso comunica «aquí hay una parte más del formulario que hay que
 * revisar», y para un aviso que aparece y desaparece no es lo que quiere decir.
 *
 * Lo que hay que comunicar es otro: *esto es una observación sobre lo que estás
 * escribiendo*, que se lee y se cierra. Por eso es una lista de avisos
 * insertada entre los campos, sin caja propia.
 *
 * EL ARTÍCULO VA DENTRO, NO PEGADO AL FINAL
 * -----------------------------------------
 * El artículo es la referencia legal del aviso. Pegado al texto con un corchete
 * parece una errata; separado en su propia línea, con su propio estilo, se lee
 * como lo que es: el artículo en que se apoya.
 *
 * LA SEVERIDAD ES UNA PALABRA, NO UN SÍMBOLO
 * -------------------------------------------
 * Un emoji en un texto legal es un adorno, y en una impresión en blanco y negro
 * se convierte en un cuadrado negro al lado de la palabra que explica el recuadro.
 * El color comunica lo mismo, se ve bien en cualquier impresión, y la palabra dice
 * además qué hacer con el aviso: «impeditivo» no es «referencia».
 *
 * El color sale de las variables del panel y no de una paleta propia. Si estos
 * avisos tuvieran su propio color, cambiar el tema de la aplicación los dejaría
 * como los únicos que no cambian, que es exactamente como se nota que algo
 * quedó atrás.
 */
final class LegalChecksPanel
{
    /**
     * @param  list<array{severity: string, message: string, article: string|null}>  $messages
     */
    private function __construct(
        private readonly array $messages,
    ) {}

    /**
     * @param  list<array{severity: string, message: string, article: string|null}>  $messages
     */
    public static function make(array $messages): self
    {
        return new self($messages);
    }

    public function isEmpty(): bool
    {
        return $this->messages === [];
    }

    /**
     * Las severidades presentes, de la más grave a la menos.
     *
     * El orden importa: un aviso que impide registrar no puede quedar debajo de
     * uno puramente informativo, porque entonces se lee como si todo estuviera
     * bien.
     *
     * @return list<string>
     */
    public function levels(): array
    {
        $present = array_unique(array_column($this->messages, 'severity'));

        return array_values(array_filter(
            ['blocking', 'warning', 'info'],
            static fn (string $level): bool => in_array($level, $present, true),
        ));
    }

    public function render(): string
    {
        if ($this->isEmpty()) {
            return '';
        }

        $items = '';

        foreach ($this->messages as $message) {
            $level = match ($message['severity']) {
                'blocking' => 'blocking',
                'warning' => 'warning',
                default => 'info',
            };

            $article = ($message['article'] ?? null) === null
                ? ''
                : '<span class="ajuste-checks__article">'.e((string) $message['article']).'</span>';

            $items .= '<li class="ajuste-checks__item ajuste-checks__item--'.$level.'">'
                .'<span class="ajuste-checks__badge" aria-hidden="true"></span>'
                .'<div class="ajuste-checks__body">'
                .'<p class="ajuste-checks__text">'.e((string) $message['message']).'</p>'
                .$article
                .'</div>'
                .'</li>';
        }

        return '<div class="ajuste-checks">'
            .'<p class="ajuste-checks__title">'.e(__('rent_adjustment.checks.title')).'</p>'
            .'<ul class="ajuste-checks__list">'.$items.'</ul>'
            .'</div>';
    }
}