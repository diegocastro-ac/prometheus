<?php

namespace App\Filament\Support;

/**
 * La lista de comprobaciones legales que se renderiza DENTRO de una sección.
 *
 * AHORA VA DENTRO DE UNA SECCIÓN
 * ------------------------------
 * El título y la descripción los pone el heading de la sección que envuelve este
 * bloque. Por eso aquí NO se pinta título: hacerlo lo mostraba dos veces seguidas
 * en pantalla. Esta clase solo dibuja la lista de avisos.
 *
 * EL ARTÍCULO VA DENTRO, NO PEGADO AL FINAL
 * -----------------------------------------
 * El artículo es la referencia legal del aviso. Pegado al texto con un corchete
 * parece una errata; separado en su propia línea, con su propio estilo, se lee
 * como lo que es: el artículo en que se apoya.
 *
 * LA SEVERIDAD ES UN COLOR, NO UN SÍMBOLO
 * ---------------------------------------
 * Un emoji en un texto legal es un adorno, y en una impresión en blanco y negro
 * se convierte en un cuadrado negro al lado de la frase. El punto de color
 * comunica lo mismo, se ve bien en cualquier impresión, y la severidad queda
 * además como title accesible del aviso: «impeditivo» no es «referencia».
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
            .'<ul class="ajuste-checks__list">'.$items.'</ul>'
            .'</div>';
    }
}
