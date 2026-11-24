<?php

namespace App\Services\Assistant;

/**
 * Resuelve que proveedor de IA atiende al asistente y si esta
 * configurado. Soporta 'gemini' (API de Google, seccion services.gemini)
 * y 'openai_compatible' (cualquier endpoint /chat/completions: Groq,
 * OpenAI, OpenRouter — seccion services.ai).
 *
 * AI_DRIVER elige el preferido, pero si ese proveedor no esta
 * configurado y el otro si, se usa el otro: un .env a medio llenar no
 * deja el asistente muerto si hay una key valida del otro lado.
 *
 * GeminiDataAssistant lo usa para elegir transporte, timeouts y
 * candidatos de modelo; AssistantChat lo usa para el set_time_limit
 * y para saber si el selector de modelos aplica.
 */
class AssistantProvider
{
    public const GEMINI = 'gemini';

    public const OPENAI_COMPATIBLE = 'openai_compatible';

    /**
     * Proveedor efectivo: el preferido de AI_DRIVER si esta configurado;
     * si no, el otro cuando si lo este; si ninguno, el preferido (para
     * que isConfigured() diga false y el chat muestre "no configurado").
     */
    public static function driver(): string
    {
        $preferred = self::preferredDriver();

        if (self::driverConfigured($preferred)) {
            return $preferred;
        }

        $other = $preferred === self::GEMINI ? self::OPENAI_COMPATIBLE : self::GEMINI;

        return self::driverConfigured($other) ? $other : $preferred;
    }

    private static function preferredDriver(): string
    {
        $driver = (string) config('services.ai.driver', self::GEMINI);

        return in_array($driver, [self::GEMINI, self::OPENAI_COMPATIBLE], true)
            ? $driver
            : self::GEMINI;
    }

    private static function driverConfigured(string $driver): bool
    {
        return $driver === self::OPENAI_COMPATIBLE
            ? self::openAiKey() !== ''
                && self::openAiBaseUrl() !== ''
                && self::openAiModel() !== ''
            : (string) config('services.gemini.key') !== '';
    }

    /**
     * Catalogo fijo de modelos Gemini elegibles en el selector.
     *
     * @return list<string>
     */
    public static function geminiModelIds(): array
    {
        return [
            'gemini-3.8-flash',
            'gemini-3.6-flash',
            'gemini-3.5-flash-lite',
            'gemini-3.1-flash-lite',
            'gemma-4-31b-it',
        ];
    }

    /**
     * Modelos elegibles del endpoint OpenAI-compatible: AI_MODELS si
     * esta definida o, en su defecto, los modelos de chat conocidos
     * del host (Groq) o el modelo configurado.
     *
     * @return list<string>
     */
    public static function openAiModelIds(): array
    {
        $configured = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) config('services.ai.models', '')),
        )));

        if ($configured !== []) {
            return $configured;
        }

        if (str_contains(self::openAiBaseUrl(), 'groq.com')) {
            return [
                'openai/gpt-oss-120b',
                'openai/gpt-oss-20b',
                'qwen/qwen3.8-27b',
            ];
        }

        return array_values(array_unique(array_filter([
            self::openAiModel(),
            (string) config('services.ai.fallback_model'),
        ])));
    }

    /**
     * Grupos de modelos del selector, uno por proveedor CONFIGURADO:
     * asi el picker muestra Gemini y Groq (u otro endpoint) juntos y
     * cada eleccion sabe a que proveedor va dirigida. El proveedor
     * preferido de AI_DRIVER encabeza la lista.
     *
     * @return list<array{driver: string, label: string, models: list<string>}>
     */
    public static function modelGroups(): array
    {
        $groups = [];

        if (self::isConfiguredDriver(self::GEMINI)) {
            $groups[] = [
                'driver' => self::GEMINI,
                'label' => 'Google Gemini',
                'models' => self::geminiModelIds(),
            ];
        }

        if (self::isConfiguredDriver(self::OPENAI_COMPATIBLE)) {
            $groups[] = [
                'driver' => self::OPENAI_COMPATIBLE,
                'label' => self::openAiLabel(),
                'models' => self::openAiModelIds(),
            ];
        }

        $preferred = self::driver();

        usort($groups, fn (array $a, array $b) => ($a['driver'] === $preferred ? 0 : 1) <=> ($b['driver'] === $preferred ? 0 : 1));

        return $groups;
    }

    /**
     * Nombre legible del endpoint OpenAI-compatible segun su host:
     * el usuario reconoce "Groq" mejor que una URL.
     */
    public static function openAiLabel(): string
    {
        $host = strtolower((string) parse_url(self::openAiBaseUrl(), PHP_URL_HOST));

        return match (true) {
            str_contains($host, 'groq.com') => 'Groq',
            str_contains($host, 'openrouter.ai') => 'OpenRouter',
            str_contains($host, 'openai.com') => 'OpenAI',
            default => 'OpenAI-compatible',
        };
    }

    /**
     * Todos los ids elegibles de todos los proveedores configurados.
     *
     * @return list<string>
     */
    public static function selectableModelIds(): array
    {
        $ids = [];

        foreach (self::modelGroups() as $group) {
            $ids = array_merge($ids, $group['models']);
        }

        return $ids;
    }

    /**
     * Proveedor dueño de un modelo elegible. null si el id no
     * pertenece a ningun proveedor configurado (o no hay ninguno).
     */
    public static function driverForModel(?string $modelId): ?string
    {
        if ($modelId === null || $modelId === '') {
            return null;
        }

        foreach (self::modelGroups() as $group) {
            if (in_array($modelId, $group['models'], true)) {
                return $group['driver'];
            }
        }

        return null;
    }

    /**
     * El selector de modelos del header existe cuando hay mas de un
     * modelo elegible entre todos los proveedores configurados.
     */
    public static function hasModelPicker(): bool
    {
        return count(self::selectableModelIds()) > 1;
    }

    public static function isConfigured(): bool
    {
        return self::isConfiguredDriver(self::driver());
    }

    public static function isConfiguredDriver(string $driver): bool
    {
        return self::driverConfigured($driver);
    }

    /**
     * Timeout por llamada HTTP de un proveedor concreto.
     */
    public static function timeoutForDriver(string $driver): int
    {
        return $driver === self::OPENAI_COMPATIBLE
            ? max(10, (int) config('services.ai.timeout', 120))
            : (int) config('services.gemini.timeout', 10);
    }

    /**
     * Modelo por defecto de un proveedor concreto.
     */
    public static function modelForDriver(string $driver): string
    {
        return $driver === self::OPENAI_COMPATIBLE
            ? self::openAiModel()
            : (string) config('services.gemini.model', 'gemini-3.5-flash-lite');
    }

    /**
     * Modelo por defecto del proveedor activo (el que usa el servidor
     * cuando el usuario no eligio uno).
     */
    public static function model(): string
    {
        return self::modelForDriver(self::driver());
    }

    public static function openAiKey(): string
    {
        $key = trim((string) config('services.ai.key'));

        // Placeholders de .env.example ("gsk_...") no son keys validas:
        // contarlos como configuracion mandaria el chat a un 401.
        return str_contains($key, '...') ? '' : $key;
    }

    public static function openAiBaseUrl(): string
    {
        return rtrim((string) config('services.ai.base_url'), '/');
    }

    private static function openAiModel(): string
    {
        return trim((string) config('services.ai.model'));
    }
}
