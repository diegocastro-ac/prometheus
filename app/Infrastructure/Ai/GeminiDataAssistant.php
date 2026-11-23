<?php

namespace App\Infrastructure\Ai;

use App\Contracts\DataAssistantInterface;
use App\Dto\AssistantReply;
use App\Models\User;
use App\Services\Assistant\AssistantFormSession;
use App\Services\Assistant\AssistantKeywordMatcher;
use App\Services\Assistant\AssistantToolInvoker;
use App\Services\Assistant\PortfolioContextBuilder;
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
        'unknown',
    ];

    public function __construct(
        private readonly AssistantToolInvoker $invoker,
        private readonly PortfolioContextBuilder $context,
        private readonly AssistantKeywordMatcher $matcher,
        private readonly AssistantFormSession $forms,
    ) {}

    public function ask(string $question, array $history, int $userId): AssistantReply
    {
        // Wizard en curso (acta de entrega, etc.): TODA respuesta va al
        // handler del formulario. Si pasara por el matcher/Gemini, una
        // respuesta como "entrega" o "sí" seria reclasificada como un
        // listado nuevo y el wizard nunca avanzaria. El handler detecta
        // "cancela" por su cuenta; para salir hay que decirlo explicitamente.
        if ($this->forms->form($userId) !== null) {
            $reply = $this->invoker->handleFormField($question, $userId);

            return $this->maybeDraft($this->withBadgeColors($reply, $question, $userId), $userId);
        }

        // Sin API key el chat degrada al matcher local para no quedar mudo.
        // Con key, TODO pasa por Gemini: el historial y la guia de dominio le
        // dan el contexto para interpretar matices, follow-ups y extracciones.
        if (! $this->isConfigured()) {
            $local = $this->matcher->match($question);

            if ($local !== null) {
                return $this->resolveLocal($local, $userId, $question);
            }

            return AssistantReply::say(__('assistant.unavailable'));
        }

        $digest = $this->context->digestFor($userId);

        $parsed = $this->classify($question, $history, $digest->toArray(), $userId);

        // Si Gemini no responde (red, TLS, cuota, 5xx) el chat no debe quedar
        // mudo: el matcher local resuelve las consultas mas comunes sin red.
        // Solo si tampoco reconoce la pregunta se muestra el error generico.
        if ($parsed === null) {
            $local = $this->matcher->match($question);

            if ($local !== null) {
                return $this->resolveLocal($local, $userId, $question);
            }

            return AssistantReply::say(__('assistant.error'));
        }

        if (($parsed['domain'] ?? 'app') === 'offtopic') {
            return AssistantReply::offTopic();
        }

        // Peticiones multiples en un solo mensaje ("muéstrame inquilinos y
        // propiedades", "registra estos 3 inquilinos y 2 propiedades"): el
        // modelo devuelve `requests` con una entrada por cada peticion.
        $confidence = (float) ($parsed['confidence'] ?? 0);
        $requests = $this->extractRequests($parsed);

        if (count($requests) > 1) {
            $batch = $this->invoker->invokeBatch($requests, $userId);
            $batch = $this->withAiSuggestions($batch, $parsed);
            $batch = $this->withBadgeColors($batch, $question, $userId);

            return $this->maybeDraft($batch, $userId);
        }

        $intent = $requests[0]['intent'];
        $params = $requests[0]['params'];

        if ($confidence < 0.4 || $intent === 'unknown') {
            return AssistantReply::say(__('assistant.unknown'), $this->defaultSuggestions());
        }

        $reply = $this->invoker->invoke($intent, $params, $userId);
        $reply = $this->withAiSuggestions($reply, $parsed);
        $reply = $this->withBadgeColors($reply, $question, $userId);

        return $this->maybeDraft($reply, $userId);
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
        $raw = $parsed['suggestions'] ?? null;

        if (! is_array($raw)) {
            return $reply;
        }

        $suggestions = [];

        foreach ($raw as $item) {
            if (! is_string($item)) {
                continue;
            }

            // Sin markdown ni comillas: son chips que se envian tal cual.
            $item = trim(preg_replace('/[*_`#>]+/u', '', $item) ?? $item);

            if ($item === '' || mb_strlen($item) > 80 || in_array($item, $suggestions, true)) {
                continue;
            }

            $suggestions[] = $item;

            if (count($suggestions) >= 4) {
                break;
            }
        }

        return $suggestions === [] ? $reply : $reply->withSuggestions($suggestions);
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
     * Resuelve una intencion detectada por el matcher local. Los intents de
     * lectura/analisis/listado responden al instante; los drafts igual pasan
     * por maybeDraft para que Gemini los personalize si hay key.
     *
     * @param  array{intent: string, params: array<string, mixed>}  $local
     */
    private function resolveLocal(array $local, int $userId, string $question): AssistantReply
    {
        if ($local['intent'] === 'greeting') {
            return AssistantReply::say(
                __('assistant.greeting'),
                $this->defaultSuggestions(),
            );
        }

        $reply = $this->invoker->invoke($local['intent'], $local['params'], $userId);
        $reply = $this->withBadgeColors($reply, $question, $userId);

        return $this->maybeDraft($reply, $userId);
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
                'parts' => [['text' => $this->systemPrompt($digest)]],
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
    private function generate(array $body, ?int $timeout = null, ?int $userId = null): string
    {
        $key = (string) config('services.gemini.key');

        if ($key === '') {
            return '';
        }

        // El timeout debe quedar MUY por debajo del max_execution_time de PHP
        // (30s): si la API se cuelga, el request de Livewire moriria a mitad
        // de camino y el chat quedaria congelado sin respuesta.
        $timeout ??= (int) config('services.gemini.timeout', 10);

        // Se intenta el modelo del usuario (o el configurado) y, si falla,
        // un respaldo: el free tier satura modelos con 503/high demand.
        foreach ($this->modelCandidates($userId) as $model) {
            $text = $this->generateWithModel($key, $model, $body, $timeout);

            if ($text !== '') {
                return $text;
            }
        }

        return '';
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

                return '';
            }

            $parts = data_get($response->json(), 'candidates.0.content.parts', []);

            return collect($parts)
                ->reject(fn (array $part): bool => ($part['thought'] ?? false) === true)
                ->pluck('text')
                ->filter()
                ->implode('');
        } catch (Throwable $exception) {
            Log::warning('gemini.assistant.exception', [
                'model' => $model,
                'error' => $exception->getMessage(),
            ]);

            return '';
        }
    }

    // ---------------------------------------------------------------
    // Prompts
    // ---------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $digest
     */
    private function systemPrompt(array $digest): string
    {
        return implode("\n", [
            'Eres el copiloto de Prometheus, una aplicacion de gestion de arriendos informales urbanos en Colombia (Ley 820).',
            'Tu UNICO trabajo es clasificar la pregunta del arrendador: devuelves domain, intent, params y confidence.',
            'El usuario puede escribir en ESPAÑOL o en INGLES: clasifica igual en ambos idiomas (los params van en formato canonico, no traducidos).',
            '',
            'REGLAS DURAS:',
            '- Tu salida es UN SOLO objeto JSON valido con las claves domain, intent, params, confidence y requests. Nada de markdown, explicaciones ni texto fuera del JSON.',
            '- Si la pregunta no tiene que ver con la administracion de arriendos (precios de otros productos, tareas ajenas, trivialidades, opinion politica, codigo, etc.), domain="offtopic".',
            '- NUNCA inventes cifras ni datos: tu salida es solo la clasificacion; los numeros los calcula el sistema.',
            '- Si la pregunta es ambigua pero claramente del dominio, elige el intent mas cercano con confidence 0.5 en vez de "unknown". Reserva "unknown" solo para lo realmente incomprensible.',
            '- params solo lleva los campos que la pregunta menciona explicitamente.',
            '- "cuanto me deben / saldo / deudas / estado de cuenta" -> pending_balance (params.tenant si menciona un inquilino o arriendo).',
            '- "que facturas vencieron/vencen/estan vencidas/overdue" -> overdue_invoices. "mis facturas / lista de facturas / invoices" -> list_invoices.',
            '- "quienes son mis inquilinos / tenants" -> list_tenants. "mis propiedades" -> list_properties. "mis arriendos/contratos" -> list_rentals. "mis pagos/abonos recientes" -> list_payments.',
            '- "mis actas de entrega / actas / inventario del acta / elementos del inventario" -> list_delivery_acts (tambien cuando pidan "los datos", "la tabla" o "el detalle" de las actas).',
            '- "las propiedades y por cada una sus actas / arriendos con sus actas / inquilinos con sus arriendos" -> usa el intent ANIDADO cuando exista: list_properties_with_acts devuelve cada propiedad con sus actas adentro (no pidas las tablas por separado ni uses batch para esto).',
            '- "cuanto recaude / ingresos / cuanto cobre este mes" -> income_period (params.period: "YYYY-MM", "last_month" o "YYYY").',
            '- "analiza mis finanzas / metricas / tendencias / como va el negocio" -> financial_analysis.',
            '- "cuando toca reajustar / proximo ajuste / ipc" -> next_adjustments. "que contratos vencen/terminan" -> expiring_contracts.',
            '- "redactame/ayudame a escribir" un mensaje de cobro o recordatorio -> draft_reminder; un aviso de incremento, reajuste o terminacion -> draft_notice. params.rental_name con el nombre del alquiler o inquilino si lo menciona.',
            '- "genera/haz/crea las facturas del mes" -> propose_generate_invoices (params.period "YYYY-MM" si lo menciona).',
            '- "registra/abona/anota un pago de la factura X por Y" -> propose_record_payment (params.invoice_number, amount, method, date si los menciona).',
            '- "agrega/crea/registra un inquilino/arrendatario" -> create_tenant (params.name, document y phone son OBLIGATORIOS: pidelos si faltan; email opcional).',
            '- "agrega/crea una propiedad/inmueble/apartamento/local" -> create_property (params.name y address son OBLIGATORIOS: pidelos si faltan).',
            '- "agenda/arrenda/alquila un arriendo/alquiler para un inquilino" -> create_rental (params.tenant_name, property_name, amount=canon mensual, months=duracion, start_date, cadence: MENSUAL|QUINCENAL|ANTICIPO). Si el inquilino no existe, incluye tenant_document y tenant_phone; si la propiedad no existe, property_address. Si falta algun dato obligatorio, igual devuelve el intent con lo que tengas: el sistema preguntara lo que falte.',
            '- "llevame/muestrame/ir a la seccion de..." -> navigate (params.target: invoices, rentals, tenants, properties, delivery_acts, adjustments, finances, documents, profile; params.filter OPCIONAL con el estado pedido en minusculas: vencida, pagada, emitida, anulada, activo, inactivo). Si la seccion no admite ese filtro, el sistema lo dice y muestra los datos en el chat.',
            '- "mis datos de cuenta / mi perfil / que correo tengo registrado" -> show_profile.',
            '- "cambia/actualiza mi nombre, correo, documento, telefono o tipo de documento" -> propose_update_profile (params SOLO con los campos mencionados: name, email, document, document_type CC|TI|CE|PAS|NIT, phone_number). La contrasena NUNCA se cambia por el chat: para eso usa navigate a profile.',
            '- "cambia/actualiza/edita el telefono/correo/direccion/nombre del inquilino/propiedad/arriendo X" -> update_tenant/update_property/update_rental (params: nombre de la entidad + SOLO los campos mencionados con su nuevo valor). "desactiva/pausa el arriendo de X" -> update_rental con is_active=false. "sube/baja el canon/monto del arriendo" -> update_rental con monthly_amount.',
            '- "lista/muestra los reajustes registrados" -> list_adjustments. "como va el reajuste/la cadena de reajustes del arriendo X" -> adjustment_detail. "que IPC aplicaria/porcentaje saldria para X en fecha Y" -> preview_adjustment_ipc.',
            '- "registra/propone un reajuste para el arriendo X" -> record_adjustment (params.rental_name, effective_from, new_rent si lo menciona). "aplica el reajuste N" -> apply_adjustment (params.adjustment_id o number). "marca el reajuste N como notificado" -> mark_adjustment_notified (params.adjustment_id/number + notified_on + notification_method: postal|contractual|in_person|email). "recalcula la cadena de reajustes" -> recalculate_adjustments.',
            '- "anula/cancela la factura N" -> annul_invoice (params.invoice_number o invoice_id).',
            '- "dame/pasame/genera el documento/el comprobante/la carta/el estado de cuenta" -> generate_document (params.doc_type: rent_invoice|payment_receipt|adjustment_letter|monthly_statement + invoice_number|rental_name|adjustment_id|period + format text|pdf|json).',
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
            'VARIAS COSAS EN UN MENSAJE (batch):',
            '- Si el usuario pide DOS O MAS cosas distintas en el mismo mensaje (ej: "muéstrame mis inquilinos y mis propiedades", "registra estos 3 inquilinos y 2 propiedades", "cuánto me deben y lista las facturas"), llena `requests` con UN elemento por cada peticion, cada uno con su intent y sus params completos.',
            '- Para UNA sola peticion deja `requests` vacio y usa intent/params de siempre.',
            '- En creaciones multiples, un elemento de `requests` por CADA entidad a crear (un create_tenant por inquilino, un create_property por propiedad), nunca agrupados en un solo params.',
            '- Si una parte del mensaje es ambigua, omite esa peticion en vez de inventarla.',
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
            'CONTEXTO DEL PORTAFOLIO DEL USUARIO (para desambiguar nombres en params):',
            json_encode($digest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
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
            '- Payment (pago): amount, method/medio (efectivo|transferencia|consignacion|nequi|daviplata), date/fecha, invoice_number.',
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
            '',
            'SEGUIMIENTO Y CONTEXTO (el historial de la conversacion es tu memoria):',
            '- Si el usuario responde con datos sueltos ("cc 123, tel 300"), correlacionalos con la ultima peticion pendiente del historial y completa los params faltantes.',
            '- Si pide "la tabla", "esos datos", "listalos", "muestramelos", "genera una tabla" tras un listado: re-emite el intent de listado correspondiente (el sistema re-ejecuta y muestra los datos frescos).',
            '- Si cambia de tema (menciona otra entidad), trata la pregunta como nueva.',
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
            '- Escribe en espanol, tono respetuoso y cercano, como un mensaje de WhatsApp.',
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
