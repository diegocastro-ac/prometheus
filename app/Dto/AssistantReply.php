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
 * - options: respuestas candidatas cuando la IA necesita que el
 *   usuario elija entre interpretaciones concretas (max 4). Se pintan
 *   como opciones A-D; el texto libre sigue disponible en el input.
 * - questions: ronda de preguntas aclaratorias ({q, options}) cuando la
 *   IA, tras razonar, no logra resolver la peticion con seguridad
 *   (entidad, herramienta, periodo o composicion del batch ambiguos).
 *   Cada pregunta ofrece hasta 4 respuestas candidatas mas texto libre;
 *   el usuario responde y el ciclo vuelve a clasificar con ese contexto.
 */
final class AssistantReply
{
    /**
     * @param  array<string, mixed>|null  $data
     * @param  array<string, mixed>|null  $action
     * @param  array{instruction: string, context: array<string, mixed>}|null  $draft
     * @param  list<string>  $suggestions
     * @param  list<string>  $options
     * @param  list<array{q: string, options: list<string>}>  $questions
     * @param  list<array{text: string, data: array<string, mixed>|null}>|null  $sections
     * @param  array{form: string, step: int, total_steps: int, field: string, prompt: string, quick_replies?: list<string>}|null  $pendingForm
     * @param  array<string, array<string, string>>|null  $badgeColors
     * @param  bool  $ok  false cuando la respuesta reporta un fallo
     *         (entidad inexistente, validacion, error de dominio). El
     *         batch lo usa para marcar la fila como fallida en la tabla
     *         de resultados; el resto de la UI no lo consulta.
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
        public readonly array $options = [],
        public readonly array $questions = [],
        public readonly bool $ok = true,
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
            $this->options,
            $this->questions,
            $this->ok,
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
            $this->options,
            $this->questions,
            $this->ok,
        );
    }

    /**
     * Opciones de respuesta para preguntas de la IA: textos cortos que
     * el usuario puede elegir (se re-envian por el chat) o ignorar
     * escribiendo libremente.
     *
     * @param  list<string>  $options
     */
    public function withOptions(array $options): self
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
            $this->badgeColors,
            $options,
            $this->questions,
            $this->ok,
        );
    }

    /**
     * Ronda de preguntas aclaratorias: cada una con su texto y hasta 4
     * respuestas candidatas. El chat las pinta como Q:/A: y la respuesta
     * del usuario vuelve a entrar por el pipeline normal.
     *
     * @param  list<array{q: string, options: list<string>}>  $questions
     */
    public function withQuestions(array $questions): self
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
            $this->badgeColors,
            $this->options,
            $questions,
            $this->ok,
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
            $this->options,
            $this->questions,
            $this->ok,
        );
    }

    /**
     * Marca la respuesta como fallo: la tabla de resultados del batch
     * la pinta como fallida sin cambiar el texto visible.
     */
    public function markFailed(): self
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
            $this->badgeColors,
            $this->options,
            false,
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
