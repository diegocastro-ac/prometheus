<?php

namespace App\Dto;

/**
 * Lo que el copiloto devuelve a la pantalla de chat.
 *
 * - text: la respuesta en palabras.
 * - data: filas/valores estructurados para pintar como tabla o tarjetas.
 * - action: operacion propuesta que espera confirmacion humana
 *   ({type, params, summary, changes?, editable?}). Nunca se ejecuta
 *   desde aqui.
 * - redirect: URL de una pantalla del panel a la que el usuario puede ir.
 * - suggestions: preguntas de seguimiento sugeridas.
 * - intent: la intencion que produjo la respuesta; el chat la guarda para
 *   poder re-ejecutarla cuando el usuario pide "la tabla" o "los datos"
 *   como follow-up sin perder el contexto.
 * - sections: respuestas batch — cada entrada es {text, data} de una
 *   peticion del mensaje, para pintar texto y tablas en el orden pedido.
 * - pendingForm: formulario guiado en curso ({form, step, total_steps,
 *   field, prompt, quick_replies?}). El chat lo muestra como barra de
 *   progreso sobre el input hasta que se completa o se cancela.
 * - badgeColors: mapa {tabla: {columna: color}} propuesto por la IA
 *   para pintar badges de estado. Solo aplica cuando el valor no
 *   matchea un enum conocido; los colores de dominio siempre ganan.
 */
final class AssistantReply
{
    /**
     * @param  array<string, mixed>|null  $data
     * @param  array<string, mixed>|null  $action
     * @param  array{instruction: string, context: array<string, mixed>}|null  $draft
     * @param  list<string>  $suggestions
     * @param  list<array{text: string, data: array<string, mixed>|null}>|null  $sections
     * @param  array{form: string, step: int, total_steps: int, field: string, prompt: string, quick_replies?: list<string>}|null  $pendingForm
     * @param  array<string, array<string, string>>|null  $badgeColors
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
        public readonly ?array $pendingForm = null,
        public readonly ?array $badgeColors = null,
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
            $this->pendingForm,
            $this->badgeColors,
        );
    }

    /**
     * Reemplaza las sugerencias (las de la IA llegan sanitizadas desde el
     * adapter; si vienen vacias se conservan las del invoker).
     *
     * @param  list<string>  $suggestions
     */
    public function withSuggestions(array $suggestions): self
    {
        return new self(
            $this->text,
            $this->data,
            $this->action,
            $this->redirect,
            $suggestions,
            $this->draft,
            $this->intent,
            $this->sections,
            $this->pendingForm,
            $this->badgeColors,
        );
    }

    /**
     * @param  array<string, array<string, string>>|null  $badgeColors
     */
    public function withBadgeColors(?array $badgeColors): self
    {
        return new self(
            $this->text,
            $this->data,
            $this->action,
            $this->redirect,
            $this->suggestions,
            $this->draft,
            $this->intent,
            $this->sections,
            $this->pendingForm,
            $badgeColors,
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
