<?php

namespace App\Dto;

/**
 * Lo que el copiloto devuelve a la pantalla de chat.
 *
 * - text: la respuesta en palabras.
 * - data: filas/valores estructurados para pintar como tabla o tarjetas.
 * - action: operacion propuesta que espera confirmacion humana
 *   ({type, params, summary}). Nunca se ejecuta desde aqui.
 * - redirect: URL de una pantalla del panel a la que el usuario puede ir.
 * - suggestions: preguntas de seguimiento sugeridas.
 * - intent: la intencion que produjo la respuesta; el chat la guarda para
 *   poder re-ejecutarla cuando el usuario pide "la tabla" o "los datos"
 *   como follow-up sin perder el contexto.
 * - sections: respuestas batch — cada entrada es {text, data} de una
 *   peticion del mensaje, para pintar texto y tablas en el orden pedido.
 */
final class AssistantReply
{
    /**
     * @param  array<string, mixed>|null  $data
     * @param  array<string, mixed>|null  $action
     * @param  array{instruction: string, context: array<string, mixed>}|null  $draft
     * @param  list<string>  $suggestions
     * @param  list<array{text: string, data: array<string, mixed>|null}>|null  $sections
     */
    public function __construct(
        public readonly string $text,
        public readonly ?array $data = null,
        public readonly ?array $action = null,
        public readonly ?string $redirect = null,
        public readonly array $suggestions = [],
        public readonly ?array $draft = null,
        public readonly ?string $intent = null,
        public readonly ?array $sections = null,
    ) {}

    public function withIntent(string $intent): self
    {
        return new self(
            $this->text,
            $this->data,
            $this->action,
            $this->redirect,
            $this->suggestions,
            $this->draft,
            $intent,
            $this->sections,
        );
    }

    public static function say(string $text, array $suggestions = []): self
    {
        return new self($text, suggestions: $suggestions);
    }

    public static function offTopic(): self
    {
        return self::say(
            __('assistant.offtopic'),
            [__('assistant.suggestions.portfolio'), __('assistant.suggestions.overdue')],
        );
    }
}
