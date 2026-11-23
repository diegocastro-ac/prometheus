<?php

namespace App\Infrastructure\Ai;

use App\Contracts\DataAssistantInterface;
use App\Dto\AssistantReply;
use App\Models\User;
use App\Services\Assistant\AppGuide;
use App\Services\Assistant\AssistantFormSession;
use App\Services\Assistant\AssistantToolInvoker;
use App\Services\Assistant\DatabaseSchemaDigest;
use App\Services\Assistant\PortfolioContextBuilder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Adapter del copiloto contra la API de Gemini.
 *
 * La division de trabajo es deliberada:
 *
 *   Gemini  -> entiende la pregunta, valida que sea del dominio de la app,
 *              clasifica la intencion y extrae parametros.
 *   Dominio -> AssistantToolInvoker responde con datos reales del usuario.
 *   Gemini  -> solo vuelve a intervenir para REDACTAR textos (recordatorios,
 *              avisos) cuando el invocador lo pide via reply->draft.
 *
 * Asi la IA nunca tiene oportunidad de inventar una cifra: los numeros los
 * pone el sistema, la IA solo los viste de lenguaje natural.
 */
class GeminiDataAssistant implements DataAssistantInterface
{
    private const BASE_URL = 'https://generativelanguage.googleapis.com/v1beta/models';

    /** Colores de badge que AssistantStatusBadge sabe pintar. */
    private const BADGE_COLORS = ['success', 'info', 'warning', 'danger', 'default'];

    /**
     * Catalogo de intenciones que el modelo puede devolver. El enum del schema
     * obliga a elegir una; 'unknown' cubre lo que no mapea.
     *
     * @var list<string>
     */
    private const INTENTS = [
        'greeting',
        'portfolio_summary',
        'pending_balance',
        'overdue_invoices',
        'income_period',
        'financial_analysis',
        'next_adjustments',
        'expiring_contracts',
        'list_tenants',
        'list_properties',
        'list_rentals',
        'list_invoices',
        'list_payments',
        'list_delivery_acts',
        'list_properties_with_acts',
        'draft_reminder',
        'draft_notice',
        'propose_generate_invoices',
        'propose_record_payment',
        'create_tenant',
        'create_property',
        'create_rental',
        'show_profile',
        'propose_update_profile',
        'update_tenant',
        'update_property',
        'update_rental',
        'list_adjustments',
        'adjustment_detail',
        'preview_adjustment_ipc',
        'record_adjustment',
        'apply_adjustment',
        'mark_adjustment_notified',
        'recalculate_adjustments',
        'annul_invoice',
        'generate_document',
        'bill_single_rental',
        'aging_report',
        'top_debtors',
        'payments_by_method',
        'collection_rate',
        'create_delivery_act',
        'form_field',
        'cancel_form',
        'show_settings',
        'update_settings',
        'ipc_status',
        'sync_ipc',
        'entity_detail',
        'export_csv',
        'navigate',
        'query_data',
        'lookup_data',
        'general_answer',
        'app_guide',
        'business_insights',
        'unknown',
    ];

    public function __construct(
        private readonly AssistantToolInvoker $invoker,
        private readonly PortfolioContextBuilder $context,
        private readonly AssistantFormSession $forms,
        private readonly DatabaseSchemaDigest $schema,
    ) {}

    public function ask(string $question, array $history, int $userId): AssistantReply
    {
        $activeForm = $this->forms->form($userId);

        // Sin API key no hay razonamiento posible: el chat lo dice
        // explicitamente en vez de responder con plantillas locales que
        // pierden el hilo de la conversacion. Los wizards siguen por su
        // via determinista, que no necesita IA.
        if (! $this->isConfigured()) {
            if ($activeForm !== null) {
                $reply = $this->invoker->handleFormField($question, $userId);

                return $this->maybeDraft($this->withBadgeColors($reply, $question, $userId), $userId);
            }

            return AssistantReply::say(__('assistant.unavailable'));
        }

        $digest = $this->context->digestFor($userId);

        // Wizard en curso: el mensaje se clasifica CON contexto del
        // formulario para distinguir una respuesta del wizard
        // (form_field) de una peticion nueva. Antes TODO mensaje iba al
        // handler y una peticion distinta quedaba secuestrada por el
        // formulario activo.
        $formNote = $activeForm !== null ? $this->formContextNote($activeForm) : null;

        $parsed = $this->classify(
            $formNote !== null ? $question."\n\n".$formNote : $question,
            $history,
            $digest->toArray(),
            $userId,
        );

        // Si Gemini no responde (red, TLS, cuota, 5xx) se muestra el error
        // generico: una respuesta local sin razonamiento haria ruido.
        // Excepcion: con un wizard activo el handler determinista sigue
        // pudiendo interpretar la respuesta del formulario sin IA.
        if ($parsed === null) {
            if ($activeForm !== null) {
                $reply = $this->invoker->handleFormField($question, $userId);

                return $this->maybeDraft($this->withBadgeColors($reply, $question, $userId), $userId);
            }

            return AssistantReply::say(__('assistant.error'));
        }

        // Razonamiento con datos reales: si el modelo pidio lookup_data
        // (una consulta de apoyo para resolver una referencia), se
        // ejecuta su SQL y se re-clasifica con las filas reales. El
        // modelo decide que buscar y que hacer con el resultado.
        $parsed = $this->resolveLookups($question, $history, $digest->toArray(), $parsed, $userId) ?? $parsed;

        if (($parsed['domain'] ?? 'app') === 'offtopic') {
            return $this->maybeCompose(AssistantReply::offTopic(), $question, $history, $userId);
        }

        // Peticiones multiples en un solo mensaje ("muéstrame inquilinos y
        // propiedades", "registra estos 3 inquilinos y 2 propiedades"): el
        // modelo devuelve `requests` con una entrada por cada peticion.
        $confidence = (float) ($parsed['confidence'] ?? 0);
        $requests = $this->extractRequests($parsed);

        // Respuesta del wizard activo: valores crudos ("sí", una fecha,
        // un telefono), intents form_field/cancel_form o clasificaciones
        // de baja confianza van al handler determinista del formulario.
        // Una intencion real del dominio — o varias peticiones en un
        // mensaje — sigue el pipeline normal y no es secuestrada.
        if ($activeForm !== null && $this->isWizardAnswer($requests, $confidence)) {
            $reply = $this->invoker->handleFormField($question, $userId);

            return $this->maybeDraft($this->withBadgeColors($reply, $question, $userId), $userId);
        }

        if (count($requests) > 1) {
            $batch = $this->invoker->invokeBatch($requests, $userId);
            $batch = $this->withAiSuggestions($batch, $parsed);
            $batch = $this->withBadgeColors($batch, $question, $userId);

            return $this->maybeDraft($this->maybeCompose($batch, $question, $history, $userId), $userId);
        }

        $intent = $requests[0]['intent'];
        $params = $requests[0]['params'];

        if ($confidence < 0.4 || $intent === 'unknown') {
            return $this->maybeCompose(
                $this->withOptionsFromModel(
                    AssistantReply::say(__('assistant.unknown'), $this->defaultSuggestions()),
                    $parsed,
                ),
                $question,
                $history,
                $userId,
            );
        }

        // El modelo insistio en lookup_data tras el maximo de rondas:
        // se ejecuta su consulta como respuesta de datos — mejor mostrar
        // lo que encontro que un "no entendi".
        if ($intent === 'lookup_data') {
            $reply = $this->invoker->invoke('query_data', $params + ['_question' => $question], $userId);
            $reply = $this->withBadgeColors($reply, $question, $userId);
            $reply = $this->withAiSuggestions($reply, $parsed);

            return $this->maybeDraft($reply, $userId);
        }

        // Analisis predictivo y guias de uso: en vez de una plantilla
        // determinista por "foco"/"tema", la IA investiga la base de
        // datos por su cuenta (lookup_data) y compone la respuesta como
        // un REPORTE estructurado de componentes UI. Si la composicion
        // falla o no hay API, cae al camino determinista del invoker.
        if (in_array($intent, ['business_insights', 'app_guide'], true)) {
            $report = $this->composeReport($question, $history, $digest->toArray(), $userId);

            if ($report !== null) {
                return new AssistantReply(
                    text: $report['intro'] !== '' ? $report['intro'] : $report['title'],
                    data: ['report' => $report],
                    suggestions: $report['suggestions'],
                );
            }
        }

        // La pregunta original viaja como contexto interno: los intents
        // que razonan sobre el pedido (insights, drafts) la necesitan
        // para no responder algo generico.
        $params['_question'] = $question;
        $reply = $this->invoker->invoke($intent, $params, $userId);

        // query_data con SQL invalido o fallido: una sola oportunidad de
        // autocorreccion. Se re-clasifica con el error como contexto y el
        // modelo arregla su propia consulta en vez de mostrar un error
        // seco al usuario.
        if ($intent === 'query_data' && $this->invoker->lastQueryError !== null) {
            $retry = $this->classify(
                $question."\n\n[Tu consulta SQL anterior fue rechazada: ".$this->invoker->lastQueryError.'. Genera una consulta SELECT valida de solo lectura o, si no es posible, usa la herramienta mas cercana.]',
                $history,
                $digest->toArray(),
                $userId,
            );

            if ($retry !== null) {
                $retried = $this->extractRequests($retry);

                if (count($retried) === 1) {
                    $reply = $this->invoker->invoke($retried[0]['intent'], $retried[0]['params'], $userId);
                }
            }
        }

        $reply = $this->withAiSuggestions($reply, $parsed);
        $reply = $this->withBadgeColors($reply, $question, $userId);
        $reply = $this->maybeDraft($this->maybeCompose($reply, $question, $history, $userId), $userId);

        return $this->withOptionsFromModel($reply, $parsed);
    }

    /**
     * Respuestas de solo texto (aclaraciones, errores de dominio, listas
     * de candidatos, "no hay datos en ese periodo"): el resultado
     * determinista ya es correcto, pero la redaccion final la hace la IA
     * con la pregunta y el historial — nada de plantillas que pierden el
     * hilo de la conversacion. No aplica a respuestas con accion, tabla,
     * redirect, formulario o draft (esas ya tienen su propio flujo).
     *
     * @param  list<array{role: string, text: string}>  $history
     */
    private function maybeCompose(AssistantReply $reply, string $question, array $history, int $userId): AssistantReply
    {
        if (! $this->isConfigured()
            || ! config('services.gemini.compose_replies', true)
            || $reply->action !== null
            || $reply->data !== null
            || $reply->redirect !== null
            || $reply->pendingForm !== null
            || $reply->sections !== null
            || $reply->draft !== null) {
            return $reply;
        }

        $composed = $this->composeReplyText($question, $history, $reply->text, $userId);

        if ($composed === null) {
            return $reply;
        }

        return new AssistantReply(
            text: $composed,
            suggestions: $reply->suggestions,
            intent: $reply->intent,
            badgeColors: $reply->badgeColors,
            options: $reply->options,
        );
    }

    /**
     * Una llamada ligera: el modelo recibe la pregunta, los ultimos
     * turnos y el resultado que el sistema ya calculo, y devuelve la
     * respuesta final redactada. Si falla, null y queda el texto
     * determinista.
     *
     * @param  list<array{role: string, text: string}>  $history
     */
    private function composeReplyText(string $question, array $history, string $result, int $userId): ?string
    {
        $turns = implode("\n", array_map(
            fn (array $turn): string => ($turn['role'] === 'assistant' ? 'Asistente' : 'Usuario').': '.mb_substr($turn['text'], 0, 300),
            array_slice($history, -6),
        ));

        $body = [
            'system_instruction' => [
                'parts' => [['text' => implode("\n", [
                    'Eres el asistente del copiloto de Prometheus (gestion de arriendos en Colombia).',
                    'El sistema YA resolvio la consulta del usuario y produjo el RESULTADO determinista que se te da abajo: es correcto, no lo contradigas ni inventes datos, cifras, entidades ni acciones que no esten ahi.',
                    'Tu trabajo es solo la REDACCION FINAL: responde la pregunta del usuario de forma natural y con el contexto de la conversacion, en el MISMO idioma en que escribio. Si el resultado es una pregunta de aclaracion o un aviso, formula la misma peticion de manera conversacional. Si ya es una buena respuesta, puedes devolverla casi igual pero mas natural.',
                    'Responde SOLO el texto de la respuesta: sin JSON, sin markdown de codigo, sin comillas alrededor, maximo ~120 palabras salvo que el resultado sea largo.',
                ])]],
            ],
            'contents' => [[
                'role' => 'user',
                'parts' => [['text' => "Conversacion reciente:\n".($turns !== '' ? $turns : '(inicio)')
                    ."\n\nPregunta actual del usuario: ".$question
                    ."\n\nRESULTADO del sistema (correcto, usar tal cual):\n".mb_substr($result, 0, 2000)]],
            ]],
            'generationConfig' => [
                'temperature' => 0.4,
                'maxOutputTokens' => 400,
            ],
        ];

        $text = trim($this->generate($body, null, $userId));

        return $text === '' ? null : $text;
    }

    /**
     * Sugerencias de seguimiento generadas por la IA: el modelo devuelve
     * 2-3 preguntas cortas relacionadas con lo que el usuario pidio. Se
     * sanitizan (texto plano, max 80 chars, max 4) y solo se aplican si
     * quedaron algunas; si no, las sugerencias del invoker se conservan.
     *
     * @param  array<string, mixed>  $parsed
     */
    private function withAiSuggestions(AssistantReply $reply, array $parsed): AssistantReply
    {
        $suggestions = $this->cleanSuggestions($parsed['suggestions'] ?? null);

        return $suggestions === [] ? $reply : $reply->withSuggestions($suggestions);
    }

    /**
     * Opciones A-D que el modelo propone cuando la pregunta es
     * genuinamente ambigua entre interpretaciones: cada opcion es un
     * texto corto que el usuario puede elegir (se re-envia por el chat)
     * o ignorar escribiendo libremente. No pisa opciones que el
     * invoker ya haya puesto (preguntas guiadas del dominio).
     *
     * @param  array<string, mixed>  $parsed
     */
    private function withOptionsFromModel(AssistantReply $reply, array $parsed): AssistantReply
    {
        // Las opciones A-D son respuestas a una PREGUNTA de la IA: solo
        // tienen sentido en replies de solo texto. Si la respuesta ya
        // trae accion, tabla, redirect o formulario, ofrecer opciones
        // duplicaria la decision que el flujo guiado ya resuelve.
        if ($reply->action !== null
            || $reply->data !== null
            || $reply->redirect !== null
            || $reply->pendingForm !== null
            || $reply->sections !== null
            || $reply->options !== []) {
            return $reply;
        }

        $options = $this->cleanOptions($parsed['options'] ?? null);

        return $options === [] ? $reply : $reply->withOptions($options);
    }

    /**
     * @return list<string>
     */
    private function cleanOptions(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $options = [];

        foreach ($raw as $item) {
            if (! is_string($item)) {
                continue;
            }

            // Una sola linea: el texto viaja como literal JS en el boton.
            $item = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[*_`#>]+/u', '', $item)));

            if ($item === '' || mb_strlen($item) > 90 || in_array($item, $options, true)) {
                continue;
            }

            $options[] = $item;

            if (count($options) >= 4) {
                break;
            }
        }

        return $options;
    }

    /**
     * Normaliza la salida del modelo a una lista de {intent, params}.
     * Si el modelo uso `requests` (varias peticiones) se respetan todas;
     * si no, la intencion simple de siempre.
     *
     * @param  array<string, mixed>  $parsed
     * @return list<array{intent: string, params: array<string, mixed>}>
     */
    private function extractRequests(array $parsed): array
    {
        $batch = $parsed['requests'] ?? null;

        if (is_array($batch)) {
            $requests = [];

            foreach ($batch as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $intent = (string) ($item['intent'] ?? 'unknown');

                if (! in_array($intent, self::INTENTS, true) || $intent === 'unknown') {
                    continue;
                }

                $requests[] = [
                    'intent' => $intent,
                    'params' => is_array($item['params'] ?? null) ? $item['params'] : [],
                ];
            }

            if ($requests !== []) {
                return $requests;
            }
        }

        return [[
            'intent' => (string) ($parsed['intent'] ?? 'unknown'),
            'params' => is_array($parsed['params'] ?? null) ? $parsed['params'] : [],
        ]];
    }

    /**
     * Colores de badge propuestos por la IA. Cuando la respuesta trae
     * tablas, una segunda llamada barata (timeout corto, solo JSON) mira
     * las columnas categoricas y sus valores de ejemplo para asignar un
     * color coherente con el contexto. El resultado viaja en el payload
     * del mensaje y AssistantStatusBadge solo lo usa cuando el valor no
     * matchea un enum conocido: el color de dominio siempre gana.
     *
     * Es cosmetico: cualquier fallo devuelve el reply intacto y las
     * celdas caen al gris por defecto.
     */
    private function withBadgeColors(AssistantReply $reply, string $question, int $userId): AssistantReply
    {
        if (! $this->isConfigured() || $reply->badgeColors !== null) {
            return $reply;
        }

        $tables = $this->badgeTableSamples($reply);

        if ($tables === []) {
            return $reply;
        }

        $body = [
            'system_instruction' => [
                'parts' => [['text' => $this->badgeColorPrompt()]],
            ],
            'contents' => [[
                'role' => 'user',
                'parts' => [['text' => json_encode([
                    'pregunta' => $question,
                    'tablas' => $tables,
                ], JSON_UNESCAPED_UNICODE)]],
            ]],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'temperature' => 0.1,
            ],
        ];

        // Timeout mas corto que el de clasificacion: es adorno, no debe
        // estirar la respuesta del chat.
        $text = $this->generate($body, 6, $userId);

        if ($text === '') {
            return $reply;
        }

        $decoded = json_decode($text, true);

        if (! is_array($decoded)) {
            return $reply;
        }

        $colors = [];

        foreach ($tables as $key => $columns) {
            foreach (array_keys($columns) as $column) {
                $color = $decoded[$key][$column] ?? null;

                if (is_string($color) && in_array($color, self::BADGE_COLORS, true)) {
                    $colors[$key][$column] = $color;
                }
            }
        }

        return $colors === [] ? $reply : $reply->withBadgeColors($colors);
    }

    /**
     * Extrae {tabla: {columna: [valores de ejemplo]}} de las tablas del
     * reply (data simple y secciones batch). Solo columnas con pocos
     * valores distintos y cortos: son las unicas que pueden ser badges.
     *
     * @return array<string, array<string, list<string>>>
     */
    private function badgeTableSamples(AssistantReply $reply): array
    {
        $sources = [];

        if (is_array($reply->data)) {
            $sources[] = $reply->data;
        }

        foreach ($reply->sections ?? [] as $section) {
            if (is_array($section['data'] ?? null)) {
                $sources[] = $section['data'];
            }
        }

        $tables = [];

        foreach ($sources as $data) {
            foreach ($data as $key => $rows) {
                if (! is_array($rows) || $rows === [] || ! isset($rows[0]) || ! is_array($rows[0])) {
                    continue;
                }

                foreach (array_keys($rows[0]) as $column) {
                    // Solo columnas que el renderer pintaria como badge:
                    // el resto del muestreo seria tokens tirados.
                    if (! \App\Support\AssistantStatusBadge::isStatusColumn($column)) {
                        continue;
                    }

                    $values = [];

                    foreach (array_slice($rows, 0, 30) as $row) {
                        $value = $row[$column] ?? null;

                        if (is_string($value) && trim($value) !== ''
                            && mb_strlen($value) <= 40
                            && ! in_array($value, $values, true)) {
                            $values[] = $value;
                        }

                        if (count($values) > 10) {
                            break;
                        }
                    }

                    // Mas de 10 valores distintos no es una categoria:
                    // no tiene sentido pintarla como badge.
                    if ($values !== [] && count($values) <= 10) {
                        $tables[(string) $key][$column] = $values;
                    }
                }
            }
        }

        return $tables;
    }

    private function badgeColorPrompt(): string
    {
        return <<<'PROMPT'
Eres un experto en diseno de interfaces. Recibes tablas de datos de un sistema de administracion de arriendos y la pregunta del usuario.

Para CADA columna que contenga estados o categorias cortas (ej: status, state, method, type, cadence, estado de pago), elige el color de badge mas coherente con el significado de sus valores:

- success: positivo, pagado, activo, entregado, firmado, correcto
- info: informativo, pendiente neutro, programado, en proceso
- warning: atencion, proximo a vencer, parcial, reparable, no entregado
- danger: critico, vencido, anulado, impago, rechazado, extraviado, inactivo
- default: neutro, sin semantica clara, metodos de pago o tipos sin carga emocional

Responde SOLO con JSON valido: {"nombre_tabla":{"nombre_columna":"color"}}.
No incluyas columnas que no sean categoricas (nombres, montos, fechas, numeros, textos largos).
Si una columna no merece badge, omitela. No agregues explicaciones.
PROMPT;
    }

    private function isConfigured(): bool
    {
        return (string) config('services.gemini.key') !== '';
    }

    public function title(string $firstMessage): ?string
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $body = [
            'system_instruction' => [
                'parts' => [['text' => 'Generas titulos de 3 a 6 palabras para conversaciones de un asistente de gestion de arriendos. Responde SOLO el titulo, sin comillas ni puntuacion final, en el mismo idioma del mensaje.']],
            ],
            'contents' => [[
                'role' => 'user',
                'parts' => [['text' => $firstMessage]],
            ]],
            'generationConfig' => [
                'temperature' => 0.3,
                'maxOutputTokens' => 30,
            ],
        ];

        $text = trim($this->generate($body, 6, null));

        return $text === '' ? null : mb_substr($text, 0, 80);
    }

    public function draft(string $instruction, array $context, ?int $userId = null): ?string
    {
        $body = [
            'system_instruction' => [
                'parts' => [['text' => $this->draftSystemPrompt()]],
            ],
            'contents' => [[
                'role' => 'user',
                'parts' => [['text' => $instruction."\n\nDatos reales (usarlos tal cual, sin inventar):\n".json_encode($context, JSON_UNESCAPED_UNICODE)]],
            ]],
            'generationConfig' => [
                'temperature' => 0.6,
                'maxOutputTokens' => 800,
            ],
        ];

        $text = $this->generate($body, null, $userId);

        return $text === '' ? null : $text;
    }

    // ---------------------------------------------------------------
    // Clasificacion
    // ---------------------------------------------------------------

    /**
     * @param  list<array{role: string, text: string}>  $history
     * @param  array<string, mixed>  $digest
     * @return array{domain?: string, intent?: string, params?: array<string, mixed>, confidence?: float}|null
     */
    /**
     * Nota de contexto que se agrega a la pregunta cuando hay un wizard
     * activo, para que el modelo distinga "respuesta del formulario" de
     * "peticion nueva".
     */
    private function formContextNote(string $form): string
    {
        $what = match ($form) {
            'delivery_act' => 'un wizard de acta de entrega que esta recopilando datos por pasos',
            'update_clarify' => 'un formulario de actualizacion de entidad que espera saber QUE campo quiere cambiar el usuario',
            'update_value' => 'un formulario de actualizacion que espera el NUEVO valor de un campo concreto',
            default => 'un formulario guiado del asistente a la espera de datos del usuario',
        };

        return '[CONTEXTO: hay '.$what.'. Si el mensaje responde el dato que el formulario pide — un valor, una fecha, un nombre, "si"/"no", "siguiente", "omitir" — devuelve intent="form_field"; si pide cancelar el formulario, "cancel_form". Solo clasifica otra intencion si el mensaje pide claramente OTRA cosa del negocio.]';
    }

    /**
     * Decide si el mensaje clasificado es una respuesta del wizard activo
     * o una peticion nueva. Los valores crudos del wizard suelen salir
     * como unknown o con confianza baja: los parsers deterministas del
     * formulario saben re-preguntar si no eran el dato esperado, asi que
     * en la duda se los damos a el.
     *
     * @param  list<array{intent: string, params: array<string, mixed>}>  $requests
     */
    private function isWizardAnswer(array $requests, float $confidence): bool
    {
        if (count($requests) !== 1) {
            return false;
        }

        $intent = $requests[0]['intent'] ?? 'unknown';

        if (in_array($intent, ['form_field', 'cancel_form'], true)) {
            return true;
        }

        return $intent === 'unknown' || $confidence < 0.4;
    }

    private function classify(string $question, array $history, array $digest, int $userId): ?array
    {
        $contents = [];

        foreach (array_slice($history, -10) as $turn) {
            $contents[] = [
                'role' => $turn['role'] === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => $turn['text']]],
            ];
        }

        $contents[] = ['role' => 'user', 'parts' => [['text' => $question]]];

        $body = [
            'system_instruction' => [
                'parts' => [['text' => $this->systemPrompt($digest, $userId)]],
            ],
            'contents' => $contents,
            'generationConfig' => [
                // Sin responseSchema: un `params` de tipo object sin
                // propiedades declaradas hace que Gemini emita {} vacio.
                // El JSON libre + validacion manual extrae mejor los params.
                'responseMimeType' => 'application/json',
                'temperature' => 0.1,
            ],
        ];

        $text = $this->generate($body, null, $userId);

        if ($text === '') {
            return null;
        }

        $decoded = json_decode($text, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Loop de consulta de apoyo: mientras la respuesta del modelo sea
     * un unico intent lookup_data, se ejecuta su SQL contra la BD real
     * y se re-clasifica con las filas como contexto. Maximo 2 rondas
     * para no encadenar consultas infinitas; si el modelo sigue
     * pidiendo lookup se devuelve tal cual y ask() lo muestra como
     * tabla de datos.
     *
     * @param  array<string, mixed>  $digest
     * @param  array<string, mixed>  $parsed
     * @return array<string, mixed>|null
     */
    private function resolveLookups(string $question, array $history, array $digest, array $parsed, int $userId): ?array
    {
        for ($round = 0; $round < 2; $round++) {
            $requests = $this->extractRequests($parsed);

            if (count($requests) !== 1 || $requests[0]['intent'] !== 'lookup_data') {
                return $parsed;
            }

            $lookup = $this->invoker->lookupData($requests[0]['params'], $userId);

            $note = isset($lookup['rows'])
                ? '[Ya ejecutaste tu consulta de apoyo y estos son los RESULTADOS REALES de la base de datos (datos o ficha de tabla, JSON): '.json_encode(array_slice($lookup['rows'], 0, 60), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).'. Con estos datos resuelve la solicitud del usuario: devuelve la intencion y los params finales de la accion o respuesta. No devuelvas lookup_data otra vez.]'
                : '[Tu consulta de apoyo fue rechazada o fallo: '.($lookup['error'] ?? 'error desconocido').'. Escribe una consulta SELECT valida de solo lectura con params.sql, pide la ficha de una tabla con params.table, o usa la herramienta mas cercana.]';

            $parsed = $this->classify(
                $question."\n\n".$note,
                $history,
                $digest,
                $userId,
            );

            if ($parsed === null) {
                return null;
            }
        }

        return $parsed;
    }

    /**
     * Compositor de reportes: la IA decide QUE investigar (lookup_data
     * en SQL o ficha de tabla) y luego QUE componentes UI necesita la
     * respuesta (metricas, tablas, pasos, alertas). No hay focos ni
     * temas prefijados: el modelo explora los datos reales y arma el
     * reporte que mejor responde la pregunta del usuario.
     *
     * Devuelve null si el modelo no logra producir un reporte valido:
     * el llamador cae entonces a la respuesta determinista clasica.
     *
     * @param  array<string, mixed>  $digest
     * @return array{title: string, intro: string, sections: list<array<string, mixed>>, suggestions: list<string>}|null
     */
    private function composeReport(string $question, array $history, array $digest, int $userId): ?array
    {
        $notes = [];

        for ($round = 0; $round < 4; $round++) {
            $parsed = $this->classifyReport($question, $history, $digest, $notes, $userId);

            if ($parsed === null) {
                return null;
            }

            $lookup = $parsed['lookup'] ?? null;

            if (is_array($lookup) && $lookup !== [] && $round < 3) {
                $result = $this->invoker->lookupData($lookup, $userId);

                $notes[] = isset($result['rows'])
                    ? '[Tu consulta de apoyo '.$round.' — RESULTADOS REALES de la base de datos (JSON): '.json_encode(array_slice($result['rows'], 0, 60), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).'. Continúa investigando con otra consulta si hace falta, o devuelve el reporte final.]'
                    : '[Tu consulta de apoyo '.$round.' fue rechazada o fallo: '.($result['error'] ?? 'error desconocido').'. Ajusta params.sql/params.table o responde con lo que ya tienes.]';

                continue;
            }

            $report = $this->sanitizeReport($parsed['report'] ?? null);

            if ($report === null) {
                return null;
            }

            $report['suggestions'] = $this->cleanSuggestions($parsed['suggestions'] ?? null);

            return $report;
        }

        return null;
    }

    /**
     * Una ronda del compositor: mismo protocolo HTTP que classify()
     * pero con el system prompt del reporter y las notas de consultas
     * de apoyo ya ejecutadas anexadas a la pregunta.
     *
     * @param  array<string, mixed>  $digest
     * @param  list<string>  $notes
     * @return array<string, mixed>|null
     */
    private function classifyReport(string $question, array $history, array $digest, array $notes, int $userId): ?array
    {
        $contents = [];

        foreach (array_slice($history, -10) as $turn) {
            $contents[] = [
                'role' => $turn['role'] === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => $turn['text']]],
            ];
        }

        $fullQuestion = $notes === []
            ? $question
            : $question."\n\n".implode("\n", $notes)."\n\nResponde ahora: otra consulta de apoyo ({\"lookup\": ...}) o el reporte final ({\"report\": ...}).";

        $contents[] = ['role' => 'user', 'parts' => [['text' => $fullQuestion]]];

        $body = [
            'system_instruction' => [
                'parts' => [['text' => $this->reportSystemPrompt($digest, $userId)]],
            ],
            'contents' => $contents,
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'temperature' => 0.2,
            ],
        ];

        $text = $this->generate($body, null, $userId);

        if ($text === '') {
            return null;
        }

        $decoded = json_decode($text, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Normaliza el reporte del modelo a la estructura que el Blade
     * sabe pintar. Cualquier bloque o accion fuera del vocabulario
     * permitido se descarta; si no queda nada util, null (fallback).
     *
     * @return array{title: string, intro: string, sections: list<array<string, mixed>>}|null
     */
    private function sanitizeReport(mixed $raw): ?array
    {
        if (! is_array($raw) || ! isset($raw['sections']) || ! is_array($raw['sections'])) {
            return null;
        }

        $title = $this->cleanReportText($raw['title'] ?? '', 120);
        $intro = $this->cleanReportText($raw['intro'] ?? '', 800);
        $sections = [];

        foreach (array_slice($raw['sections'], 0, 6) as $section) {
            if (! is_array($section)) {
                continue;
            }

            $blocks = $this->sanitizeReportBlocks($section['blocks'] ?? null);

            if ($blocks === []) {
                continue;
            }

            $sections[] = [
                'title' => $this->cleanReportText($section['title'] ?? '', 80),
                'blocks' => $blocks,
            ];
        }

        if ($sections === []) {
            return null;
        }

        return [
            'title' => $title !== '' ? $title : __('assistant.report.fallback_title'),
            'intro' => $intro,
            'sections' => $sections,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sanitizeReportBlocks(mixed $blocks): array
    {
        if (! is_array($blocks)) {
            return [];
        }

        $out = [];

        foreach (array_slice($blocks, 0, 8) as $block) {
            if (! is_array($block)) {
                continue;
            }

            $clean = match ((string) ($block['type'] ?? '')) {
                'text' => $this->sanitizeTextBlock($block),
                'metrics' => $this->sanitizeMetricsBlock($block),
                'table' => $this->sanitizeTableBlock($block),
                'chart' => $this->sanitizeChartBlock($block),
                'steps' => $this->sanitizeStepsBlock($block),
                'callouts' => $this->sanitizeCalloutsBlock($block),
                'list' => $this->sanitizeListBlock($block),
                default => null,
            };

            if ($clean !== null) {
                $out[] = $clean;
            }
        }

        return $out;
    }

    private function sanitizeTextBlock(array $block): ?array
    {
        $text = $this->cleanReportText($block['text'] ?? '', 2000, keepMarkdown: true);

        return $text === '' ? null : ['type' => 'text', 'text' => $text];
    }

    private function sanitizeMetricsBlock(array $block): ?array
    {
        $items = [];

        foreach (array_slice((array) ($block['items'] ?? []), 0, 6) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $label = $this->cleanReportText($item['label'] ?? '', 60);
            $value = $this->cleanReportText($item['value'] ?? '', 60);

            if ($label === '' || $value === '') {
                continue;
            }

            $entry = ['label' => $label, 'value' => $value];
            $hint = $this->cleanReportText($item['hint'] ?? '', 120);

            if ($hint !== '') {
                $entry['hint'] = $hint;
            }

            $tone = (string) ($item['tone'] ?? '');

            if (in_array($tone, self::BADGE_COLORS, true)) {
                $entry['tone'] = $tone;
            }

            $items[] = $entry;
        }

        return $items === [] ? null : ['type' => 'metrics', 'items' => $items];
    }

    /**
     * Grafica del reporte: el modelo entrega etiquetas y valores
     * numericos; aqui se normalizan (max 12 puntos, valores finitos).
     * El tipo se limita a bar|line|donut — cualquier otro se descarta.
     */
    private function sanitizeChartBlock(array $block): ?array
    {
        $kind = (string) ($block['chart'] ?? $block['type2'] ?? '');

        if (! in_array($kind, ['bar', 'line', 'donut'], true)) {
            return null;
        }

        $labels = [];
        $values = [];

        $rawLabels = (array) ($block['labels'] ?? []);
        $rawValues = (array) ($block['values'] ?? []);

        foreach (array_slice($rawLabels, 0, 12) as $i => $label) {
            $label = $this->cleanReportText($label, 24);

            if ($label === '') {
                continue;
            }

            $value = $rawValues[$i] ?? null;

            if (! is_numeric($value)) {
                continue;
            }

            $labels[] = $label;
            $values[] = (float) $value;
        }

        if (count($values) < 2) {
            return null;
        }

        $clean = ['type' => 'chart', 'chart' => $kind, 'labels' => $labels, 'values' => $values];
        $title = $this->cleanReportText($block['title'] ?? '', 80);

        if ($title !== '') {
            $clean['title'] = $title;
        }

        $unit = $this->cleanReportText($block['unit'] ?? '', 12);

        if ($unit !== '') {
            $clean['unit'] = $unit;
        }

        return $clean;
    }

    private function sanitizeTableBlock(array $block): ?array
    {
        $columns = [];

        foreach (array_slice((array) ($block['columns'] ?? []), 0, 8) as $column) {
            $column = $this->cleanReportText($column, 40);

            if ($column !== '') {
                $columns[] = $column;
            }
        }

        $rows = [];

        foreach (array_slice((array) ($block['rows'] ?? []), 0, 30) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $cells = [];

            foreach (array_slice($row, 0, count($columns) ?: 8) as $cell) {
                if (is_array($cell)) {
                    $cell = $cell['text'] ?? $cell['value'] ?? '';
                }

                if (is_scalar($cell) || $cell === null) {
                    $cells[] = $this->cleanReportText((string) $cell, 200);
                }
            }

            if ($cells !== []) {
                $rows[] = $cells;
            }
        }

        if ($columns === [] || $rows === []) {
            return null;
        }

        $clean = ['type' => 'table', 'columns' => $columns, 'rows' => $rows];
        $title = $this->cleanReportText($block['title'] ?? '', 80);

        if ($title !== '') {
            $clean['title'] = $title;
        }

        return $clean;
    }

    private function sanitizeStepsBlock(array $block): ?array
    {
        $items = [];

        foreach (array_slice((array) ($block['items'] ?? []), 0, 12) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $title = $this->cleanReportText($item['title'] ?? '', 80);

            if ($title === '') {
                continue;
            }

            $entry = [
                'title' => $title,
                'text' => $this->cleanReportText($item['text'] ?? '', 300),
            ];

            $action = $this->sanitizeReportAction($item['action'] ?? null);

            if ($action !== null) {
                $entry['action'] = $action;
            }

            $items[] = $entry;
        }

        return $items === [] ? null : ['type' => 'steps', 'items' => $items];
    }

    /**
     * Acciones del reporte: solo los targets de navegacion conocidos
     * (mismo mapa que la guia) y prompts cortos para el chat. Cualquier
     * otra cosa se descarta — el modelo no puede inyectar URLs libres.
     *
     * @return array{kind: string, label: string, url?: string, prompt?: string}|null
     */
    private function sanitizeReportAction(mixed $action): ?array
    {
        if (! is_array($action)) {
            return null;
        }

        $label = $this->cleanReportText($action['label'] ?? '', 60);

        if (($action['kind'] ?? '') === 'url') {
            $target = (string) ($action['target'] ?? '');
            $class = AppGuide::URLS[$target] ?? null;

            if ($class === null) {
                return null;
            }

            return [
                'kind' => 'url',
                'label' => $label !== '' ? $label : __('assistant.guide.go_to', ['section' => __('assistant.sections.'.$target)]),
                'url' => $class::getUrl(),
            ];
        }

        if (($action['kind'] ?? '') === 'prompt') {
            $prompt = $this->cleanReportText($action['prompt'] ?? '', 200);

            if ($prompt === '') {
                return null;
            }

            return [
                'kind' => 'prompt',
                'label' => $label !== '' ? $label : __('assistant.guide.try_it'),
                'prompt' => $prompt,
            ];
        }

        return null;
    }

    private function sanitizeCalloutsBlock(array $block): ?array
    {
        $items = [];

        foreach (array_slice((array) ($block['items'] ?? []), 0, 8) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $title = $this->cleanReportText($item['title'] ?? '', 80);
            $text = $this->cleanReportText($item['text'] ?? '', 400);

            if ($title === '' && $text === '') {
                continue;
            }

            $entry = ['title' => $title, 'text' => $text];
            $tone = (string) ($item['tone'] ?? '');

            if (in_array($tone, self::BADGE_COLORS, true)) {
                $entry['tone'] = $tone;
            }

            $items[] = $entry;
        }

        return $items === [] ? null : ['type' => 'callouts', 'items' => $items];
    }

    private function sanitizeListBlock(array $block): ?array
    {
        $items = [];

        foreach (array_slice((array) ($block['items'] ?? []), 0, 20) as $item) {
            if (is_array($item)) {
                $item = $item['text'] ?? '';
            }

            $item = $this->cleanReportText((string) $item, 300);

            if ($item !== '') {
                $items[] = $item;
            }
        }

        return $items === [] ? null : ['type' => 'list', 'items' => $items];
    }

    private function cleanReportText(mixed $value, int $max, bool $keepMarkdown = false): string
    {
        if (! is_scalar($value)) {
            return '';
        }

        $text = trim((string) $value);

        if (! $keepMarkdown) {
            $text = trim(preg_replace('/[*_`#>]+/u', '', $text) ?? $text);
        }

        return mb_strlen($text) > $max ? mb_substr($text, 0, $max).'…' : $text;
    }

    /**
     * Sugerencias del reporte: mismo saneamiento que withAiSuggestions
     * pero sobre una lista cruda (el reporte no es un AssistantReply).
     *
     * @return list<string>
     */
    private function cleanSuggestions(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $suggestions = [];

        foreach ($raw as $item) {
            if (! is_string($item)) {
                continue;
            }

            $item = trim(preg_replace('/[*_`#>]+/u', '', $item) ?? $item);

            if ($item === '' || mb_strlen($item) > 80 || in_array($item, $suggestions, true)) {
                continue;
            }

            $suggestions[] = $item;

            if (count($suggestions) >= 4) {
                break;
            }
        }

        return $suggestions;
    }

    /**
     * System prompt del compositor de reportes: la IA decide que
     * investigar y que componentes usar; el vocabulario es una guia,
     * no una plantilla — puede combinar los bloques que el caso pida.
     *
     * @param  array<string, mixed>  $digest
     */
    private function reportSystemPrompt(array $digest, int $userId): string
    {
        $sqlRules = 'una sola sentencia SELECT (o WITH...SELECT); dialecto SQL estandar; NUNCA INSERT/UPDATE/DELETE/DDL; tablas de datos del usuario SIEMPRE con filtro user_id = '.$userId.' (las globales ipc_rates, ipc_series, geographies, app_settings no llevan user_id); la tabla users se filtra por users.id = '.$userId.'; evita columnas password/remember_token; LIMIT 50 por defecto; alias en espanol para columnas calculadas; JOINs, GROUP BY, agregados (SUM/COUNT/AVG), subconsultas y CTEs permitidos';

        return implode("\n", [
            'Eres el ANALISTA del copiloto de Prometheus, una app de gestion de arriendos informales urbanos en Colombia (Ley 820).',
            'El usuario te pide un ANALISIS o una GUIA personalizada. Tu trabajo NO es clasificar: es INVESTIGAR la base de datos real del usuario y construir la respuesta que mejor lo ayude a decidir, en el formato JSON de reporte que se indica abajo.',
            'Investiga como un analista: que datos importan para ESTA pregunta concreta, que relaciones del esquema los unen, que comparaciones o tendencias revelan algo, y que recomendarías con evidencia. No hay una plantilla fija: tu criterio decide que secciones y componentes incluye el reporte.',
            'Analiza con PROFUNDIDAD, no con una sola consulta superficial: busca CRUCES que revelen lo que una tabla suelta no muestra — por ejemplo, cruzar facturas vencidas con el canon y el historial de pagos de cada inquilino para ver si la mora se concentra en pocos o es generalizada; comparar el periodo actual contra el anterior para ver tendencias reales; segmentar por propiedad o tipo de arriendo para encontrar focos de riesgo u oportunidad; detectar patrones (quien paga siempre tarde, propiedades sin facturar, canon desalineado con el mercado del sector). Si una conclusion necesita dos tablas relacionadas, juntalas en la misma consulta con JOIN/CTE en vez de asumir.',
            'El usuario puede escribir en ESPAÑOL o en INGLES: el reporte va en el MISMO idioma del usuario.',
            '',
            'PROCESO (obligatorio):',
            '1. PIENSA que necesitas saber para responder bien. Si te falta un dato que la base de datos puede darte, devuelve PRIMERO una consulta de apoyo y espera los resultados reales.',
            '2. Puedes hacer hasta 3 consultas de apoyo encadenadas: cada respuesta tuya puede ser {"lookup":{...}} o el reporte final. No te limites a una sola consulta si el analisis lo amerita.',
            '3. Con los datos reales en la mano, devuelve el reporte final. NUNCA inventes cifras: todo numero del reporte debe venir de una consulta ejecutada o del CONTEXTO DEL PORTAFOLIO.',
            '4. Si la pregunta pide una guia de uso, investiga el estado REAL del usuario (que ya tiene registrado, que le falta) y arma los pasos con acciones concretas que pueda ejecutar desde el chat.',
            '',
            'CONSULTA DE APOYO (lookup):',
            '- {"lookup":{"sql":"SELECT ..."}} — '.$sqlRules.'.',
            '- {"lookup":{"table":"nombre_tabla"}} — ficha completa de la tabla: columnas, tipos, enums, relaciones, total de filas del usuario.',
            '- Los resultados vuelven como JSON en el siguiente paso, dentro de las notas de tu pregunta.',
            '',
            'FORMATO DE RESPUESTA FINAL (un solo objeto JSON, sin markdown):',
            '{"report":{"title":"...","intro":"...","sections":[{"title":"...","blocks":[ ... ]}]},"suggestions":["pregunta corta 1","pregunta corta 2"]}',
            '',
            'BLOQUES DISPONIBLES (combina los que el caso pida; no uses todos por obligacion):',
            '- {"type":"text","text":"parrafo en markdown ligero (**negrita**, listas con -)"} — narrativa, conclusiones, contexto.',
            '- {"type":"metrics","items":[{"label":"...","value":"...","hint":"...","tone":"success|warning|danger|info"}]} — tarjetas de cifra clave (max 6). tone pinta el valor: success=bueno, warning=atencion, danger=problema, info=neutro. Omite tone si no aplica.',
            '- {"type":"table","title":"...","columns":["Col1","Col2"],"rows":[["a","b"]]} — datos tabulares reales (max 8 columnas, 30 filas). Para rankings o listados.',
            '- {"type":"chart","chart":"bar|line|donut","title":"...","labels":["ene","feb"],"values":[1200,3400],"unit":"$"} — grafica visual (max 12 puntos). bar=comparacion de categorias, line=serie en el tiempo o tendencia, donut=composicion/porcentajes del total. Usala SIEMPRE que la pregunta hable de evolucion, comparacion entre periodos, ranking visual o distribucion — una grafica comunica mejor que una tabla para eso. labels cortos (max ~10 chars), values solo numeros (sin simbolos de moneda ni separadores de miles).',
            '- {"type":"steps","items":[{"title":"Paso","text":"detalle","action":{"kind":"url","target":"invoices","label":"Ir a Facturas"}}]} — pasos accionables para guias o planes (max 12). action.kind="url" navega a una seccion: targets validos = invoices, rentals, tenants, properties, delivery_acts, adjustments, finances, documents, profile, settings, ipc. action.kind="prompt" con "prompt":"texto a enviar al chat" ejecuta una accion guiada (ej: "registra un pago de $100.000 a la factura FV-2026-0001"). Omite action si el paso es solo informativo.',
            '- {"type":"callouts","items":[{"title":"...","text":"...","tone":"warning"}]} — alertas/avisos destacados (max 8).',
            '- {"type":"list","items":["punto 1","punto 2"]} — listas simples (max 20).',
            '',
            'REGLAS DEL REPORTE:',
            '- title: corto y descriptivo (max ~8 palabras). intro: 1-2 frases que enmarcan lo que el usuario va a ver.',
            '- sections: max 6, orden logico (primero lo que el usuario pidio, luego el detalle). Cada section: title corto + blocks.',
            '- Tono de ASESOR profesional: directo, con evidencia, con recomendaciones accionables cuando la pregunta lo pida.',
            '- Si la pregunta es de GUIA (como usar la app, que hacer primero, paso a paso): los pasos deben ser concretos para ESTE usuario — menciona sus entidades reales cuando aporten (ej: "tu arriendo de Camila Torres ya tiene 2 facturas vencidas: cobra primero esas").',
            '- Cifras con formato $1.234.567 (COP). Fechas en lenguaje natural o YYYY-MM.',
            '- suggestions: 2-3 preguntas de seguimiento CORTAS (max 60 chars) en el idioma del usuario, concretas segun lo que mostraste.',
            '',
            'CONTEXTO DEL PORTAFOLIO DEL USUARIO:',
            json_encode($digest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            '',
            'ESQUEMA DE LA BASE DE DATOS (para tus consultas lookup.sql; [filtrar user_id] = tabla del usuario, [global] = sin user_id):',
            $this->schema->forPrompt(),
            '',
            'SIGNIFICADO DE LOS DATOS (semantica de negocio: OBLIGATORIO respetarla al escribir SQL):',
            $this->schema->semanticsForPrompt(),
            '',
            'Hoy es '.now()->format('Y-m-d').'.',
        ]);
    }

    /**
     * Si la respuesta pide un texto redactado (recordatorio/aviso), se
     * genera con la IA usando los datos reales del contexto y se reemplaza
     * el texto de respaldo.
     */
    private function maybeDraft(AssistantReply $reply, int $userId): AssistantReply
    {
        if ($reply->draft === null) {
            return $reply;
        }

        $generated = $this->draft(
            $reply->draft['instruction'],
            $reply->draft['context'],
            $userId,
        );

        if ($generated === null) {
            return $reply;
        }

        return new AssistantReply(
            text: $generated,
            data: $reply->data,
            action: $reply->action,
            redirect: $reply->redirect,
            suggestions: $reply->suggestions,
            intent: $reply->intent,
            sections: $reply->sections,
            pendingForm: $reply->pendingForm,
            badgeColors: $reply->badgeColors,
            options: $reply->options,
        );
    }

    // ---------------------------------------------------------------
    // HTTP
    // ---------------------------------------------------------------

    /**
     * Llamada cruda a generateContent. Devuelve el texto de la primera
     * parte o '' si algo falla: el llamador decide el fallback.
     *
     * @param  array<string, mixed>  $body
     */
    /**
     * Presupuesto total de Gemini para la peticion actual. Un `ask`
     * encadena varias llamadas (classify, lookups, compose, reporte,
     * sugerencias) y cada una puede reintentar hasta 3 modelos: sin este
     * tope global, un GEMINI_TIMEOUT alto hacia que el request de
     * Livewire muriera por max_execution_time de PHP a mitad de la
     * espera de cURL. Se fija en la primera llamada y se agota una vez.
     */
    private ?float $geminiDeadline = null;

    private function generate(array $body, ?int $timeout = null, ?int $userId = null): string
    {
        $key = (string) config('services.gemini.key');

        if ($key === '') {
            return '';
        }

        // El timeout por llamada queda por debajo del presupuesto total:
        // si la API se cuelga, el request de Livewire no puede esperar
        // indefinidamente.
        $timeout ??= (int) config('services.gemini.timeout', 10);
        $this->geminiDeadline ??= microtime(true) + $this->requestBudgetSeconds();

        // Se intenta el modelo del usuario (o el configurado) y, si falla,
        // un respaldo: el free tier satura modelos con 503/high demand.
        // El ultimo modelo que respondio bien va primero: si la saturacion
        // es por modelo y no por cuenta, se evita quemar tiempo en los
        // que estan caidos.
        foreach ($this->modelCandidates($userId) as $model) {
            $remaining = $this->geminiDeadline - microtime(true);

            if ($remaining <= 0.5) {
                return '';
            }

            $text = $this->generateWithModel($key, $model, $body, min($timeout, (int) ceil($remaining)));

            if ($text !== '') {
                $this->rememberWorkingModel($userId, $model);

                return $text;
            }
        }

        return '';
    }

    /**
     * El modelo que respondio por ultima vez se recuerda por usuario:
     * en la siguiente llamada se prueba primero y los modelos saturados
     * quedan de respaldo en vez de bloquear el arranque de cada intento.
     */
    private function rememberWorkingModel(?int $userId, string $model): void
    {
        Cache::put('assistant.gemini_model.'.($userId ?? 'global'), $model, now()->addHours(12));
    }

    private function rememberedModel(?int $userId): ?string
    {
        $model = Cache::get('assistant.gemini_model.'.($userId ?? 'global'));

        return is_string($model) && $model !== '' ? $model : null;
    }

    /**
     * Segundos totales que puede esperar Gemini dentro de UNA peticion
     * de chat. Cubre el classify principal mas las llamadas secundarias
     * (compose, sugerencias, reporte) sin dejar que un timeout alto se
     * convierta en minutos de espera.
     */
    private function requestBudgetSeconds(): int
    {
        return max(45, (int) config('services.gemini.timeout', 10) * 2);
    }

    /**
     * Hasta 3 modelos a intentar: el preferido del usuario (o el default de
     * config), el respaldo configurado y un ultimo recurso estable. Si el
     * preferido ya es el fallback, se completan con los siguientes.
     *
     * @return list<string>
     */
    private function modelCandidates(?int $userId): array
    {
        $primary = $this->primaryModel($userId);
        $fallback = (string) config('services.gemini.fallback_model', 'gemini-3.5-flash-lite');

        $candidates = array_values(array_unique(array_filter([
            $primary,
            $fallback,
            'gemini-3.1-flash-lite',
            'gemini-3.6-flash',
        ])));

        // El modelo que funciono por ultima vez encabeza la lista.
        $remembered = $this->rememberedModel($userId);

        if ($remembered !== null && in_array($remembered, $candidates, true)) {
            $candidates = array_merge([$remembered], array_values(array_diff($candidates, [$remembered])));
        }

        return array_slice($candidates, 0, 3);
    }

    /**
     * Modelo que el usuario eligio en el chat; si no eligio ninguno, el de
     * configuracion. Se cachea por request para no repetir la consulta.
     *
     * @var array<int, string>
     */
    private array $userModels = [];

    private function primaryModel(?int $userId): string
    {
        $configured = (string) config('services.gemini.model', 'gemini-3.5-flash-lite');

        if ($userId === null) {
            return $configured;
        }

        return $this->userModels[$userId] ??= (string) (
            User::query()->whereKey($userId)->value('assistant_model') ?: $configured
        );
    }

    /**
     * Un intento de generateContent contra un modelo concreto. Devuelve el
     * texto de la primera parte o '' si la API falla.
     *
     * @param  array<string, mixed>  $body
     */
    private function generateWithModel(string $key, string $model, array $body, int $timeout): string
    {
        // Fallos transitorios (503 high demand, 429, timeouts de red) se
        // reintentan UNA vez con una pausa corta: la saturacion del free
        // tier suele ser por rafagas de segundos. Errores definitivos
        // (400/401/403/404) no se reintentan. El reintento solo corre si
        // el presupuesto global de la peticion todavia tiene margen.
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $effectiveTimeout = $timeout;

            if ($attempt === 1) {
                $remaining = ($this->geminiDeadline ?? 0) - microtime(true);

                if ($remaining < 8) {
                    return '';
                }

                usleep(2_000_000);

                // El reintento no puede volver a gastar el timeout entero:
                // se recorta a lo que queda del presupuesto global.
                $effectiveTimeout = min($timeout, max(1, (int) ceil($remaining) - 2));
            }

            [$text, $retryable] = $this->attemptGenerate($key, $model, $body, $effectiveTimeout);

            if ($text !== '' || ! $retryable) {
                return $text;
            }
        }

        return '';
    }

    /**
     * Un intento HTTP contra generateContent. Devuelve [texto, reintentable]:
     * reintentable=true solo para fallos transitorios de la API o de red.
     *
     * @param  array<string, mixed>  $body
     * @return array{0: string, 1: bool}
     */
    private function attemptGenerate(string $key, string $model, array $body, int $timeout): array
    {
        try {
            $request = Http::withHeaders(['x-goog-api-key' => $key])
                ->connectTimeout(5)
                ->timeout($timeout);

            // En Windows PHP no lee el almacen de certificados del sistema:
            // sin un bundle de CAs toda llamada HTTPS muere con "cURL error 60".
            // Se apunta a uno explicito solo si existe; nunca se desactiva la
            // verificacion, porque eso expondria la API key a un MITM.
            $caBundle = (string) config('services.gemini.ca_bundle');

            if ($caBundle !== '' && is_file($caBundle)) {
                $request = $request->withOptions(['verify' => $caBundle]);
            }

            $response = $request->post(self::BASE_URL.'/'.$model.':generateContent', $body);

            if (! $response->successful()) {
                Log::warning('gemini.assistant.http_error', [
                    'model' => $model,
                    'status' => $response->status(),
                    'body' => mb_substr($response->body(), 0, 500),
                ]);

                return ['', in_array($response->status(), [408, 429, 500, 502, 503, 504], true)];
            }

            $parts = data_get($response->json(), 'candidates.0.content.parts', []);

            return [collect($parts)
                ->reject(fn (array $part): bool => ($part['thought'] ?? false) === true)
                ->pluck('text')
                ->filter()
                ->implode(''), false];
        } catch (Throwable $exception) {
            Log::warning('gemini.assistant.exception', [
                'model' => $model,
                'error' => $exception->getMessage(),
            ]);

            // Solo errores de red/timeout son transitorios; un fallo de
            // programacion no mejora reintentando.
            $retryable = (bool) preg_match('/cURL error|timed? ?out|Connection|resolve host/i', $exception->getMessage());

            return ['', $retryable];
        }
    }

    // ---------------------------------------------------------------
    // Prompts
    // ---------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $digest
     */
    private function systemPrompt(array $digest, int $userId): string
    {
        // Reglas del SQL libre: se escriben UNA vez y se reutilizan en
        // lookup_data y query_data para que ambas herramientas hablen
        // el mismo idioma sin bloques duplicados.
        $sqlRules = 'una sola sentencia SELECT (o WITH...SELECT); dialecto SQL estandar; NUNCA INSERT/UPDATE/DELETE/DDL; tablas de datos del usuario SIEMPRE con filtro user_id = '.$userId.' (las globales ipc_rates, ipc_series, geographies, app_settings no llevan user_id); la tabla users se filtra por users.id = '.$userId.'; evita columnas password/remember_token; LIMIT 50 por defecto; alias en espanol para columnas calculadas (AS nombre, AS total); JOINs, GROUP BY, agregados (SUM/COUNT/AVG), subconsultas y CTEs permitidos: es SQL real';

        return implode("\n", [
            'Eres el copiloto de Prometheus, una aplicacion de gestion de arriendos informales urbanos en Colombia (Ley 820).',
            'Actuas como un ASESOR del sistema: el usuario es el arrendador y tu trabajo es entender su pregunta y devolverle exactamente lo que pidio, ni mas ni menos, con criterio profesional.',
            'Tu trabajo es ENTENDER que quiere el arrendador y elegir la mejor herramienta para resolverlo. No eres un formulario rigido: razona sobre la pregunta completa, el historial y los datos del portafolio, e interpreta con libertad razonable (sinonimos, faltas de ortografia, peticiones vagas pero comprensibles).',
            'El usuario puede escribir en ESPAÑOL o en INGLES: clasifica igual en ambos idiomas (params van en formato canonico, no traducidos).',
            '',
            'REGLAS DURAS:',
            '- PIENSA ANTES DE RESPONDER: para cada pregunta razona que datos hacen falta, que herramienta los da y si la pregunta nombra entidades concretas. Resuelve SIEMPRE por razonamiento: primero el CONTEXTO DEL PORTAFOLIO, luego el historial, luego lookup_data contra la base de datos completa si falta algo. Solo pregunta al usuario cuando despues de todo eso el dato siga siendo genuinamente ambiguo o el usuario debe decidir (nunca pidas un dato que puedas consultar).',
            '- ENRIQUECE CON RELACIONES: cuando la pregunta se responde con una herramienta pero la respuesta seria mas completa y util consultando datos relacionados del esquema (ej: "cuentame del inquilino X" gana si tambien ves sus facturas pendientes y su arriendo), puedes hacer lookup_data primero para traerte esas relaciones y luego responder o proponer con el panorama completo. No te limites a la respuesta minima si una consulta barata la mejora.',
            '- RESPONDE EXACTAMENTE LO PEDIDO: si el usuario nombra una entidad (un inquilino, un arriendo, una factura, un acta), la respuesta se limita a ESA entidad. Nunca devuelvas el listado general cuando pidieron uno especifico; si la entidad no tiene el dato pedido, dilo con claridad ("esa acta no registra items danados") en vez de mostrar datos de otros.',
            '- "peores/mayores deudores", "mas morosos", "peor pagador" NO son palabras ofensivas ni ambiguas: significan los inquilinos con mayor saldo vencido o peor comportamiento de pago. Interpretalas siempre asi y responde con la tabla ordenada por ese criterio.',
            '- MENSAJE NUEVO vs SEGUIMIENTO (razonamiento, no reglas): el historial de la conversacion SIEMPRE esta disponible, pero TU decides por razonamiento si el mensaje actual DEPENDE de el o es independiente. Por defecto un mensaje con sujeto y verbo propios es una peticion NUEVA que se entiende sola — clasificala solo por lo que dice, sin heredar el tema del turno anterior: "analiza mis finanzas" despues de "que facturas vencieron" es un analisis GENERAL del negocio, no de las vencidas; "cuanto me deben" despues de hablar de un inquilino es la cartera completa. El historial SOLO aplica cuando el mensaje depende explicitamente de el: respuestas sueltas a una pregunta pendiente ("el nombre", "NOMBRE", "cc 123", "si"), referencias con pronombres o demostrativos ("esos", "de esos", "y los que...", "la segunda", "solo esos"), o continuaciones explicitas ("y ahora...", "y de una vez...", "lo mismo para..."). Ante la duda entre "seguimiento" y "peticion nueva", prefiere la lectura literal del mensaje actual.',
            '- Cuando el mensaje SI sea seguimiento, correlaciona los datos sueltos con la ultima peticion pendiente del historial y completa los params faltantes. Nunca vuelvas a preguntar algo que el usuario ya respondio.',
            '- NUNCA inventes cifras ni datos: tu salida es solo la clasificacion; los numeros los calcula el sistema.',
            '- Tu salida es UN SOLO objeto JSON valido con las claves domain, intent, params, confidence, requests, suggestions y options (las dos ultimas opcionales). Nada de markdown, explicaciones ni texto fuera del JSON.',
            '- options: SOLO cuando la pregunta admite 2 a 4 interpretaciones concretas y genuinamente ambiguas que el usuario debe elegir (que documento necesita, a que entidad se refiere, que periodo quiere). Cada opcion es un texto corto que reenvia la peticion ya desambiguada (ej: "el estado de cuenta de Camila Torres", "la factura FV-2026-0003"). NO la uses para sugerencias de seguimiento ni cuando una sola interpretacion es claramente la correcta: para eso esta la extraccion normal de params.',
            '- Si la pregunta no tiene que ver con la administracion de arriendos (precios de otros productos, tareas ajenas, trivialidades, opinion politica, codigo, etc.), domain="offtopic".',
            '- Si la pregunta es ambigua pero claramente del dominio, elige el intent mas cercano con confidence 0.5 en vez de "unknown". Reserva "unknown" solo para lo realmente incomprensible.',
            '- params lleva los campos que la pregunta menciona explicitamente; completa ademas lo que el CONTEXTO DEL PORTAFOLIO o una consulta de apoyo resuelvan con certeza (nunca inventes valores).',
            '- NUNCA pidas ni uses IDs tecnicos (id, rental_id, tenant_id...): el sistema resuelve las entidades por nombre, numero de factura o descripcion. Si el usuario da un numero, es el numero de factura o el consecutivo del reajuste, nunca un id interno.',
            '- El usuario puede ser impreciso ("cualquiera", "la primera", "una aleatoria", "la de siempre", errores de dedo): si los params no identifican una unica entidad, devuelve el intent con lo que tengas y confidence ~0.6. El sistema ofrece candidatos o pide precision; tu trabajo es interpretar la intencion, no exigir perfeccion.',
            '- Si el usuario menciona un INQUILINO pero la herramienta pide un ARRIENDO ("el arriendo de Camila", "la factura del inquilino X"), pon el nombre del inquilino en rental_name: el sistema lo resuelve a su arriendo activo.',
            '- Si la peticion es rara o inusual pero explicita ("cambia la direccion de cualquiera"), clasifícala igual: el sistema elegira una entidad y mostrara una advertencia antes de confirmar.',
            '- RESUELVE REFERENCIAS CON EL CONTEXTO, NO PREGUNTES: "la factura mas antigua/ultima/vencida/pendiente/de X", "el arriendo de Y", "la propiedad Z" se resuelven razonando sobre el CONTEXTO DEL PORTAFOLIO. pending_invoices lista cada factura con saldo: number, rental, tenant, balance, due_at, days_overdue. "La mas antigua" = la de menor due_at con saldo de esa entidad; "la ultima/reciente" = la de mayor period/due_at; "la vencida" = days_overdue > 0. Cuando la referencia sea deducible, devuelve el intent con el dato CONCRETO ya resuelto (invoice_number exacto, nombre exacto de la entidad): NUNCA devuelvas una referencia relativa ni un param vacio si el contexto la resuelve. Solo deja el param vacio cuando el contexto genuinamente no alcanza (ej: dos facturas igual de antiguas y el usuario no desambigua).',
            '- Sinonimos del dominio: arriendo=alquiler=contrato=arrendamiento; inquilino=arrendatario=locatario; propiedad=inmueble=apartamento=local=bodega; canon=monto=valor del arriendo; factura=comprobante=cuenta de cobro; pago=abono=consignacion; reajuste=incremento=ajuste de canon; acta=acta de entrega=entrega de inmueble. Clasifica por el significado, no por la palabra exacta.',
            '- "cuanto me deben / saldo / deudas / estado de cuenta" -> pending_balance (params.tenant si menciona un inquilino o arriendo).',
            '- "que facturas vencieron/vencen/estan vencidas/overdue" -> overdue_invoices. "mis facturas / lista de facturas / invoices" -> list_invoices.',
            '- "quienes son mis inquilinos / tenants" -> list_tenants. "mis propiedades" -> list_properties. "mis arriendos/contratos" -> list_rentals. "mis pagos/abonos recientes" -> list_payments.',
            '- "mis actas de entrega / actas / inventario del acta / elementos del inventario" -> list_delivery_acts (tambien cuando pidan "los datos", "la tabla" o "el detalle" de las actas). Si la pregunta nombra UN arriendo o inquilino ("el acta del arriendo de X", "actas de Juan"), incluye rental_name con ese nombre: el sistema acota el listado a esa entidad.',
            '- "las propiedades y por cada una sus actas / arriendos con sus actas / inquilinos con sus arriendos" -> usa el intent ANIDADO cuando exista: list_properties_with_acts devuelve cada propiedad con sus actas adentro (no pidas las tablas por separado ni uses batch para esto).',
            '- "cuanto recaude / ingresos / cuanto cobre este mes" -> income_period (params.period: "YYYY-MM", "last_month" o "YYYY").',
            '- financial_analysis -> metricas instantaneas del momento: recaudo del mes, tasa de recaudo, facturas vencidas. Una MIRADA RAPIDA, sin investigacion. Elegila solo cuando el usuario pida un vistazo/estado actual de cifras sueltas.',
            '- business_insights -> ANALISIS PROFUNDO: el sistema investiga la base de datos por su cuenta, cruza datos entre periodos/entidades y compone un reporte con conclusiones, graficas, alertas y recomendaciones. Elegila cuando el usuario pida analisis, diagnostico, prediccion, proyeccion, comparaciones, rankings, "a fondo", "completo", "detallado", "reporte" — o cualquier pregunta que requiera investigar mas alla de una cifra suelta. LA PROFUNDIDAD LA DECIDE EL USUARIO POR RAZONAMIENTO, no por una lista de frases: si pide rapido/sencillo/resumen, no es esto; si pide completo/a fondo/analisis, si lo es. params.entity_name = la entidad concreta si nombra una ("el inquilino X", "el arriendo de Y"); el resto lo decide el analisis.',
            '- business_insights TAMBIEN cuando la pregunta pida ANALISIS y no solo datos: preguntas de evolucion temporal ("como evoluciona/va X por mes o periodo"), de distribucion o ranking ("que propiedades/inquilinos me generan mas", "mejores/peores segun criterio"), de comparacion ("compara X con Y o entre periodos") o que pidan conclusiones/recomendaciones ("en que me estoy equivocando", "que esta pasando con mi cartera"). Razonamiento: si la respuesta exige investigar, cruzar o concluir — y no solo listar — es business_insights para que el reporte salga con graficas y cruces. query_data queda para cuando el usuario pide literalmente una tabla/lista sin analisis ("muestrame las facturas", "dame la lista de inquilinos").',
            '- "cuando toca reajustar / proximo ajuste / ipc" -> next_adjustments. "que contratos vencen/terminan" -> expiring_contracts.',
            '- "redactame/ayudame a escribir" un mensaje de cobro o recordatorio -> draft_reminder; un aviso de incremento, reajuste o terminacion -> draft_notice. params.rental_name con el nombre del alquiler o inquilino si lo menciona.',
            '- "genera/haz/crea las facturas del mes" -> propose_generate_invoices (params.period "YYYY-MM" si lo menciona).',
            '- "registra/abona/anota un pago de la factura X por Y" -> propose_record_payment (params.invoice_number, amount, method, date si los menciona). Si el usuario no da numero sino una referencia ("la mas antigua", "la ultima", "la pendiente de Valentina"), resuelve invoice_number desde pending_invoices ANTES de responder.',
            '- "agrega/crea/registra un inquilino/arrendatario" -> create_tenant (params.name, document y phone son OBLIGATORIOS; email opcional). Si falta algun dato, devuelve el intent con lo que tengas: el sistema abre un FORMULARIO dinamico con los campos que falten — nunca pidas los datos por texto ni devuelvas unknown.',
            '- "agrega/crea una propiedad/inmueble/apartamento/local" -> create_property (params.name y address son OBLIGATORIOS). Si falta algun dato, igual devuelve el intent con lo que tengas: el sistema abre el formulario de creacion con los campos faltantes.',
            '- "agenda/arrenda/alquila un arriendo/alquiler para un inquilino" -> create_rental (params.tenant_name, property_name, amount=canon mensual, months=duracion, start_date, cadence: MENSUAL|QUINCENAL|ANTICIPO). Si el inquilino no existe, incluye tenant_document y tenant_phone; si la propiedad no existe, property_address. Si falta algun dato obligatorio, igual devuelve el intent con lo que tengas: el sistema muestra el formulario de creacion con los campos faltantes — nunca pidas los datos por texto.',
            '- "llevame/muestrame/ir a la seccion de..." -> navigate (params.target: invoices, rentals, tenants, properties, delivery_acts, adjustments, finances, documents, profile; params.filter OPCIONAL con el estado pedido en minusculas: vencida, pagada, emitida, anulada, activo, inactivo). Si la seccion no admite ese filtro, el sistema lo dice y muestra los datos en el chat.',
            '- "mis datos de cuenta / mi perfil / que correo tengo registrado" -> show_profile.',
            '- "cambia/actualiza mi nombre, correo, documento, telefono o tipo de documento" -> propose_update_profile (params SOLO con los campos mencionados: name, email, document, document_type CC|TI|CE|PAS|NIT, phone_number). La contrasena NUNCA se cambia por el chat: para eso usa navigate a profile.',
            '- "cambia/actualiza/edita el telefono/correo/direccion/nombre del inquilino/propiedad/arriendo X" -> update_tenant/update_property/update_rental (params: nombre de la entidad + SOLO los campos mencionados con su nuevo valor). Si piden EDITAR/ACTUALIZAR una entidad SIN decir que campo ni valor ("actualiza a Juan", "edita la propiedad de la calle 5"), devuelve el intent con SOLO la entidad resuelta: el sistema muestra un formulario con TODOS los campos editables para que el usuario llene lo que quiera — NO pidas los datos por texto ni devuelvas unknown. "desactiva/pausa el arriendo de X" -> update_rental con is_active=false. "sube/baja el canon/monto del arriendo" -> update_rental con monthly_amount.',
            '- "lista/muestra los reajustes registrados" -> list_adjustments. "como va el reajuste/la cadena de reajustes del arriendo X" -> adjustment_detail. "que IPC aplicaria/porcentaje saldria para X en fecha Y" -> preview_adjustment_ipc.',
            '- "registra/propone un reajuste para el arriendo X" -> record_adjustment (params.rental_name, effective_from, new_rent si lo menciona). "aplica el reajuste N" -> apply_adjustment (params.adjustment_id o number). "marca el reajuste N como notificado" -> mark_adjustment_notified (params.adjustment_id/number + notified_on + notification_method: postal|contractual|in_person|email). "recalcula la cadena de reajustes" -> recalculate_adjustments.',
            '- "anula/cancela la factura N" -> annul_invoice (params.invoice_number o invoice_id; si es referencia relativa, resuelvela desde pending_invoices igual que en pagos).',
            '- "dame/pasame/genera el documento/el comprobante/la carta/el estado de cuenta" -> generate_document (params.doc_type: rent_invoice|payment_receipt|adjustment_letter|monthly_statement + invoice_number|rental_name|adjustment_id|period + format text|pdf|json). Referencias de factura relativas se resuelven desde pending_invoices como en pagos. Solo cuando el usuario pide GENERAR el documento ahora; si pide uno que no existe en el catalogo (documento del portafolio completo, carta personalizada, reporte en PDF) responde con general_answer explicando que documentos hay y que ese no esta disponible.',
            '- PREGUNTAS DE CAPACIDAD O POSIBILIDAD (razonamiento, no ejecucion): "puedes/es posible/se puede...?, soporta/hace/maneja X?, es muy complejo?, como genero/hago X?" preguntan SI se puede o COMO, no piden ejecutarlo en este mensaje -> general_answer. En params.answer escribe TÚ la respuesta completa y honesta en el idioma del usuario: que puede hacer el asistente, que documentos/formatos existen (factura de arriendo, comprobante de pago, carta de reajuste y estado de cuenta en texto, PDF o JSON; NO hay documentos personalizados ni del portafolio completo por ahora) y, si aplica, el comando o paso sugerido para lograrlo. Reserva generate_document y demas intents de escritura para cuando el usuario pide la accion concreta ("generame la factura de X", "registrame un pago").',
            '- "factura/genera la factura del arriendo de X para el periodo Y" -> bill_single_rental (params.rental_name + period "YYYY-MM").',
            '- "cartera vencida por rangos/rangos de dias/aging" -> aging_report. "quienes son mis peores/mayores deudores" -> top_debtors. "cuanto recaudare por cada metodo/por nequi/por transferencia" -> payments_by_method. "tasa de recaudo/dias promedio de atraso/cobranza" -> collection_rate.',
            '- "crea/llena un acta de entrega" -> create_delivery_act (params.rental_name si lo menciona; si no, el sistema guia paso a paso).',
            '- "cancela/sal de/olvida el formulario/el acta" -> cancel_form.',
            '- "mis ajustes/datos del negocio/configuracion de facturas" -> show_settings. "cambia el nombre comercial/NIT/direccion del negocio/pie legal" -> update_settings.',
            '- "esta actualizado el IPC/sincroniza el IPC/actualiza los indices" -> ipc_status o sync_ipc.',
            '- "cuentame del arriendo/de la propiedad/del inquilino/detalle de X" -> entity_detail (params.entity_type: rental|property|tenant|invoice|adjustment + name/number).',
            '- "pásame la tabla a CSV/exporta/descarga en excel" -> export_csv.',
            '- "hola/buenas/buenos dias/hello/hi" -> greeting (saludos y cortesia pura, sin peticion de datos).',
            '- NAVEGACION CON BUSQUEDA: "llévame al inquilino Camila / a la factura FV-2026-0003 / al arriendo de X" -> navigate con params.target + params.search (el nombre o numero a buscar). La seccion buscara el termino.',
            '',
            'DOS VIAS DE EXPLORACION (usarlas para TODO lo que las herramientas especificas no cubran):',
            '- HERRAMIENTA DE EXPLORACION (lookup_data): es tu forma de investigar la base de datos por tu cuenta, en dos modos:',
            '  * params.sql = un SELECT de solo lectura ('.$sqlRules.').',
            '  * params.table = el nombre de una tabla del esquema -> el sistema te devuelve su FICHA completa: columnas con tipo/null/default, enums, relaciones y total de filas del usuario. Usalo cuando dudes de una columna, una relacion o quieras aprender una tabla mas alla del resumen.',
            '  El sistema ejecuta tu consulta o ficha y te devuelve los resultados REALES en el siguiente paso; con eso devuelves el intent final con los params completos y correctos. Usala siempre que una referencia sea resoluble en la BD pero no en el contexto (numero exacto de factura, relacion entre tablas, dato que el digest no trae). NO la uses para responder lecturas al usuario (eso es query_data) ni para pedir datos que ya estan en el contexto. Maximo dos consultas de apoyo por mensaje: si tras los resultados algo sigue ambiguo, pregunta.',
            '- CONSULTA PARA EL USUARIO (query_data): para CUALQUIER pregunta de datos del negocio que ninguna herramienta especifica responda — cruces de tablas, comparaciones entre periodos, rankings personalizados, "cuantos dias en promedio tarda en pagar X" — devuelve intent=query_data con params.sql = un SELECT de solo lectura ('.$sqlRules.' params.question = la pregunta original). El resultado se muestra al usuario como tabla. OJO: si la pregunta pide analisis/evolucion/conclusiones/recomendaciones ("como evoluciona X", "que genera mas Y", "analiza Z"), NO es query_data: es business_insights. Si necesitas datos intermedios para RAZONAR antes de responder o proponer una accion, usa lookup_data; si la pregunta ES la consulta, usa query_data.',
            '- JERARQUIA: si existe una herramienta especifica para el caso (listados, updates, pagos, documentos, ajustes...), PREFIERELA: trae previews, confirmaciones, campos editables y navegacion que la consulta libre no tiene. La consulta libre es para lecturas; NUNCA la uses para proponer cambios (los writes siempre van por sus intents con confirmacion).',
            '- Si ninguna herramienta ni query_data aplican y la pregunta es del dominio pero muy ambigua, o es una pregunta de capacidad/uso, general_answer es la respuesta: escribe la respuesta completa en params.answer (texto plano, mismo idioma del usuario, honesto sobre lo que el sistema soporta).',
            '- GUIA DE USO (app_guide): cuando el usuario pregunta COMO usar la app, que hacer primero, es nuevo, quiere que lo guies, o pide "paso a paso"/"tutorial"/"how do I" de una funcionalidad -> app_guide. El sistema investiga el estado real del usuario y compone una guia personalizada con pasos accionables; no hace falta params.topic.',
            '',
            'VARIAS COSAS EN UN MENSAJE (batch):',
            '- Si el usuario pide DOS O MAS cosas distintas en el mismo mensaje (ej: "muéstrame mis inquilinos y mis propiedades", "registra estos 3 inquilinos y 2 propiedades", "cuánto me deben y lista las facturas"), llena `requests` con UN elemento por cada peticion, cada uno con su intent y sus params completos.',
            '- Para UNA sola peticion deja `requests` vacio y usa intent/params de siempre.',
            '- En creaciones multiples, un elemento de `requests` por CADA entidad a crear (un create_tenant por inquilino, un create_property por propiedad), nunca agrupados en un solo params.',
            '- Si una parte del mensaje es ambigua, omite esa peticion en vez de inventarla.',
            '- NO hay limite de peticiones por mensaje: si el usuario pide muchas cosas (o crea decenas de entidades), llena `requests` con TODAS. Si el lote es muy grande el sistema lo divide en grupos que el usuario confirma por partes.',
            '- REGLA DE ORO: si el usuario ya dio los datos de una peticion, params debe llevarlos TODOS extraidos. NUNCA devuelvas params vacio cuando el mensaje contiene los valores. El canon "1.200.000" va como amount=1200000 (numero, sin puntos ni $).',
            '- Ejemplo del formato exacto para "crea la propiedad Apto 301 en Calle 1 y agenda a Juan Perez cc 555 tel 300 por $800.000 por 12 meses":',
            '  {"domain":"app","intent":"unknown","confidence":0.9,"requests":[{"intent":"create_property","params":{"name":"Apto 301","address":"Calle 1"}},{"intent":"create_rental","params":{"tenant_name":"Juan Perez","tenant_document":"555","tenant_phone":"300","property_name":"Apto 301","amount":800000,"months":12}}]}',
            '',
            $this->domainGuide(),
            '',
            'Hoy es '.now()->format('Y-m-d').'. Moneda: pesos colombianos (COP).',
            '',
            'INTENTS VALIDOS: '.implode(', ', self::INTENTS),
            '',
            'CONTEXTO DEL PORTAFOLIO DEL USUARIO (para desambiguar nombres y resolver referencias en params): rentals = arriendos activos con su canon y fecha de reajuste; totals = agregados; alerts = avisos; pending_invoices = cada factura con saldo pendiente (number exacto, rental, tenant, balance, due_at, days_overdue): usala para convertir "la mas antigua/ultima/vencida" en el invoice_number concreto; catalog = el UNIVERSO completo de entidades del usuario (tenants, properties y rentals TODOS, activos e inactulos con flag active): usalo para escribir el nombre EXACTO de la entidad en los params de cualquier intent (create_*, update_*, documentos, pagos). Si una entidad no aparece ni en catalog ni en pending_invoices, es que no existe o necesita lookup_data.',
            json_encode($digest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            '',
            'ESQUEMA DE LA BASE DE DATOS (para query_data; [filtrar user_id] = tabla del usuario, [global] = sin user_id):',
            $this->schema->forPrompt(),
            '',
            'SIGNIFICADO DE LOS DATOS (semantica de negocio: que significan las columnas, campos calculados y trampas. OBLIGATORIO respetarla al escribir SQL):',
            $this->schema->semanticsForPrompt(),
        ]);
    }

    /**
     * Guia del dominio para que Gemini entienda como funciona la app: que
     * entidades existen, sus campos reales y el contrato de params. Tambien
     * fija las reglas de extraccion (nombres propios, cc/tel/correo) y que
     * los datos que el modelo no tiene (ciudad, ocupacion, etc.) se ignoran.
     */
    private function domainGuide(): string
    {
        return implode("\n", [
            'MODELO DE DATOS (campos reales; NUNCA inventes params para campos que no existen):',
            '- Tenant (inquilino): name (obligatorio), document/cedula (obligatorio), phone_number/telefono (obligatorio), email (opcional). NO tiene ciudad, direccion ni ocupacion: si el usuario los menciona, IGNORALOS.',
            '- Property (propiedad): name y address (ambos obligatorios).',
            '- Rental (arriendo/contrato): tenant_name, property_name, amount/monthly_amount (canon mensual), months/total_months, start_date, cadence/billing_cadence (MENSUAL|QUINCENAL|ANTICIPO). Para crear uno nuevo, si el inquilino no existe incluye tenant_document y tenant_phone; si la propiedad no existe, property_address.',
            '- Invoice (factura): number/numero, amount, due_at/vencimiento, status (emitida|vencida|pagada|anulada), balance/saldo.',
            '- Payment (pago): amount, method/medio (efectivo|transferencia|consignacion; si el usuario dice nequi/daviplata/billetera usa transferencia), date/fecha, invoice_number.',
            '- DeliveryAct (acta de entrega/recepcion): type (entrega|recepcion|cambio_arrendatario), tenant_name, occurred_at, signed/firmada, items/inventario con estados (nuevo|bueno|reparable|por_reemplazar|destruido).',
            '- RentAdjustment (reajuste): rental_name/contrato, period/periodo, percentage/porcentaje, based_on (ipc).',
            '- User (el propio arrendador): name, email, document_type (CC|TI|CE|PAS|NIT), document, phone_number. La contrasena no se toca por chat.',
            '',
            'SECCIONES NAVEGABLES (params.target de navigate): invoices=Facturas, rentals=Alquileres, tenants=Inquilinos, properties=Propiedades, delivery_acts=Actas de entrega, adjustments=Reajustes, finances=Finanzas, documents=Documentos, profile=Mi perfil.',
            '',
            'REGLAS DE EXTRACCION DE PARAMS:',
            '- Nombres propios: captura el nombre COMPLETO tras "se llama", "me llamo", "de nombre", "para el/la", "agrega a" o al inicio de la peticion. Conserva todas las palabras y mayusculas ("Isaac David", no "isaac"). No cortes en la primera palabra.',
            '- Documento: tras "cc", "cedula", "documento", "identificacion" -> params.document (solo digitos).',
            '- Telefono: tras "tel", "telefono", "celular", "cel", "phone", "whatsapp", "movil" o un numero de 10+ digitos -> params.phone o params.phone_number.',
            '- Correo: tras "correo", "email", "e-mail" o algo con "@" -> params.email.',
            '- Ciudad, ocupacion, estado civil, referencias y cualquier dato que NO este en el modelo de datos: IGNORAR, no incluir en params.',
            '- En creaciones, si falta algun campo obligatorio devuelve el intent igual con lo que tengas: el sistema preguntara lo que falte. No omitas el intent por params incompletos.',
            '- EN UPDATES (update_tenant/property/rental/settings): la ENTIDAD va en tenant_name/property_name/rental_name (o entity_name), NUNCA en "name" si tambien hay un valor nuevo; el valor nuevo va en el campo correspondiente: new_name para el nombre de persona/entidad, phone_number, email, document, address, monthly_amount, etc. Ej: "cambia el nombre de X a Y" -> {tenant_name:"X", new_name:"Y"}.',
            '',
            'SEGUIMIENTO Y CONTEXTO (el historial solo manda cuando el mensaje depende de el):',
            '- Respuestas sueltas a una pregunta pendiente ("cc 123, tel 300", "el nombre es X", "si", "para enero"): completan la ultima peticion del historial — no son peticiones nuevas.',
            '- Referencias al turno anterior ("esos", "de esos", "los mismos", "la segunda", "y esos tambien"): heredan la entidad/tema del mensaje previo.',
            '- Si pide "la tabla", "esos datos", "listalos", "muestramelos", "genera una tabla" tras un listado: re-emite el intent de listado correspondiente (el sistema re-ejecuta y muestra los datos frescos).',
            '- Si el mensaje es autocontenido — nombra su propio sujeto y se entiende sin el turno anterior — clasificalo como peticion nueva AUNQUE el tema sea parecido al anterior.',
            '',
            'SUGERENCIAS DE SEGUIMIENTO (clave "suggestions" del JSON):',
            '- Incluye SIEMPRE "suggestions": una lista de 2 a 3 preguntas de seguimiento CORTAS (max 60 caracteres cada una), en el MISMO idioma del usuario, que el arrendador podria hacer a continuacion.',
            '- Deben ser concretas y utiles segun la respuesta: si mostraste deudas, sugiere "genera un recordatorio para X" o "quiénes son mis mayores deudores"; si mostraste reajustes, sugiere "aplica el reajuste pendiente"; si fue un listado, sugiere filtrar o ver el detalle de una entidad.',
            '- NO repitas la pregunta del usuario ni des respuestas, solo las preguntas siguientes.',
            '- Sin markdown, sin comillas, sin numeracion: texto plano.',
        ]);
    }

    private function draftSystemPrompt(): string
    {
        return implode("\n", [
            'Redactas mensajes para un arrendador colombiano que gestiona arriendos informales.',
            'Reglas:',
            '- Escribe en el MISMO idioma en que escribio el usuario (el contexto indica cual), tono respetuoso y cercano, como un mensaje de WhatsApp.',
            '- USA SOLO los datos entregados: nombres, valores y fechas. No inventes cifras.',
            '- Máximo ~120 palabras. Sin saludos de formulario ni firmas raras.',
            '- No agregues Markdown, ni asteriscos, ni listas: texto plano con saltos de linea.',
            '- Moneda en pesos colombianos con formato $1.234.567.',
        ]);
    }

    /**
     * @return list<string>
     */
    private function defaultSuggestions(): array
    {
        return [
            __('assistant.suggestions.portfolio'),
            __('assistant.suggestions.overdue'),
            __('assistant.suggestions.analysis'),
        ];
    }
}
