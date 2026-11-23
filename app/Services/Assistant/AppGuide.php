<?php

namespace App\Services\Assistant;

use App\Filament\Pages\AjustesPage;
use App\Filament\Pages\DocumentsPage;
use App\Filament\Pages\EditProfile;
use App\Filament\Pages\FinanzasPage;
use App\Filament\Resources\DeliveryActResource;
use App\Filament\Resources\InvoiceResource;
use App\Filament\Resources\IpcSeriesResource;
use App\Filament\Resources\PropertyResource;
use App\Filament\Resources\RentAdjustmentResource;
use App\Filament\Resources\RentalResource;
use App\Filament\Resources\TenantResource;

/**
 * Guia de uso de la app para el chat (intent app_guide).
 *
 * El contenido vive en lang/{locale}/assistant.php bajo `guide.topics`:
 * cada tema tiene titulo, introduccion y pasos {title, text, action}.
 * Este servicio solo resuelve las acciones:
 *
 *   'url:invoices'      -> boton que navega a la seccion del panel
 *   'prompt:crea ...'   -> boton que envia ese mensaje al chat (abre
 *                         wizards, propone acciones, etc.)
 *
 * Asi la guia no es texto muerto: cada paso puede LLEVAR al usuario a la
 * pantalla correcta o arrancar la accion guiada por el propio asistente.
 */
class AppGuide
{
    /** Temas disponibles; 'start' es la onboarding completa. */
    public const TOPICS = [
        'start',
        'setup',
        'billing',
        'collections',
        'adjustments',
        'delivery_acts',
        'documents',
        'settings',
    ];

    /**
     * Palabras clave -> tema, en orden de prioridad. 'setup' va ultimo
     * porque "inquilino"/"propiedad" aparecen en preguntas de otros
     * temas ("cobrar a mis inquilinos" es collections, no setup).
     *
     * @var array<string, string>
     */
    private const TOPIC_KEYWORDS = [
        'billing' => '/\b(factur\w+|billing|invoice)\b/iu',
        'collections' => '/\b(cobr\w+|pago\w*|recaud\w+|deud\w+|payment|collect|recordatorio)\b/iu',
        'adjustments' => '/\b(reajust\w+|ipc|incremento|canon)\b/iu',
        'delivery_acts' => '/\b(acta\w*|entrega|inventario|delivery)\b/iu',
        'documents' => '/\b(documento\w*|comprobante\w*|estado de cuenta|certificad\w+)\b/iu',
        'settings' => '/\b(ajustes?|configur\w+|perfil|business settings)\b/iu',
        'setup' => '/\b(propiedad\w*|inmueble\w*|inquilino\w*|arrendatario\w*|registrar|agregar|dar de alta|alta de|montar|primer arriendo|crear (una|mi|el|una nueva)? ?(propiedad|inquilino|arriendo)|new (property|tenant|rental)|register)\b/iu',
    ];

    /**
     * URLs internas por target, espejo del mapa de navigate() del
     * invoker mas 'settings' (AjustesPage no es un recurso navegable
     * por intents pero si una pantalla del panel).
     *
     * @var array<string, string>
     */
    public const URLS = [
        'invoices' => InvoiceResource::class,
        'rentals' => RentalResource::class,
        'tenants' => TenantResource::class,
        'properties' => PropertyResource::class,
        'delivery_acts' => DeliveryActResource::class,
        'adjustments' => RentAdjustmentResource::class,
        'finances' => FinanzasPage::class,
        'documents' => DocumentsPage::class,
        'profile' => EditProfile::class,
        'settings' => AjustesPage::class,
        'ipc' => IpcSeriesResource::class,
    ];

    /**
     * Tema que mejor describe la pregunta del usuario, o null si no
     * menciona ninguna funcionalidad concreta (ahi aplica 'start').
     */
    public function topicForQuestion(string $question): ?string
    {
        foreach (self::TOPIC_KEYWORDS as $topic => $pattern) {
            if (preg_match($pattern, $question) === 1) {
                return $topic;
            }
        }

        return null;
    }

    /**
     * @return array{title: string, intro: string, steps: list<array{title: string, text: string, action?: array{kind: string, label: string, url?: string, prompt?: string}}>}|null
     */
    public function for(string $topic): ?array
    {
        $topic = in_array($topic, self::TOPICS, true) ? $topic : 'start';

        $guide = __('assistant.guide.topics.'.$topic);

        if (! is_array($guide) || ! isset($guide['steps']) || ! is_array($guide['steps'])) {
            return null;
        }

        $steps = [];

        foreach ($guide['steps'] as $step) {
            if (! is_array($step) || empty($step['title'])) {
                continue;
            }

            $resolved = [
                'title' => (string) $step['title'],
                'text' => (string) ($step['text'] ?? ''),
            ];

            $action = $this->resolveAction($step['action'] ?? null);

            if ($action !== null) {
                $resolved['action'] = $action;
            }

            $steps[] = $resolved;
        }

        if ($steps === []) {
            return null;
        }

        return [
            'title' => (string) ($guide['title'] ?? __('assistant.guide.fallback_title')),
            'intro' => (string) ($guide['intro'] ?? ''),
            'steps' => $steps,
        ];
    }

    /**
     * 'url:target' -> ['kind'=>'url','url'=>...] | 'prompt:texto' ->
     * ['kind'=>'prompt','prompt'=>...]. Cualquier otra cosa se ignora.
     *
     * @return array{kind: string, label: string, url?: string, prompt?: string}|null
     */
    private function resolveAction(mixed $action): ?array
    {
        if (! is_string($action) || $action === '') {
            return null;
        }

        if (str_starts_with($action, 'url:')) {
            $target = substr($action, 4);
            $class = self::URLS[$target] ?? null;

            if ($class === null) {
                return null;
            }

            return [
                'kind' => 'url',
                'label' => __('assistant.guide.go_to', ['section' => __('assistant.sections.'.$target)]),
                'url' => $class::getUrl(),
            ];
        }

        if (str_starts_with($action, 'prompt:')) {
            $prompt = trim(substr($action, 7));

            if ($prompt === '') {
                return null;
            }

            return [
                'kind' => 'prompt',
                'label' => __('assistant.guide.try_it'),
                'prompt' => $prompt,
            ];
        }

        return null;
    }
}
