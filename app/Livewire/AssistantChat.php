<?php

namespace App\Livewire;

use App\Contracts\CurrentUserContextInterface;
use App\Contracts\DataAssistantInterface;
use App\Dto\AssistantReply;
use App\Models\AssistantChat as AssistantChatModel;
use App\Models\AssistantMessage;
use App\Models\User;
use App\Services\Assistant\AssistantActionExecutor;
use Filament\Notifications\Notification;
use Illuminate\Support\Str;
use Livewire\Component;

/**
 * Copiloto del arrendador como drawer global del panel.
 *
 * Orquesta la conversacion: la IA interpreta via DataAssistantInterface y
 * las escrituras pasan por AssistantActionExecutor tras confirmacion humana.
 *
 * Multi-chat: cada conversacion es un AssistantChat con titulo (generado por
 * IA a partir del primer mensaje, con fallback truncado). Los mensajes se
 * cargan perezosamente: mount() solo trae la lista de chats, asi abrir el
 * drawer no paga el costo de renderizar el historial.
 */
class AssistantChat extends Component
{
    /**
     * Conversacion visible del chat activo. Cada entrada conserva lo que la
     * respuesta trajo (data, action, redirect) mas el id del registro para
     * actualizarlo al resolver una accion.
     *
     * @var list<array<string, mixed>>
     */
    public array $messages = [];

    /** @var list<array{id: int, title: string, updated_at: string|null}> */
    public array $chats = [];

    public ?int $activeChatId = null;

    /** 'chat' muestra la conversacion, 'chats' el historial de conversaciones. */
    public string $view = 'chat';

    public string $input = '';

    public bool $open = false;

    public bool $hasUnread = false;

    public bool $messagesLoaded = false;

    /** La lista de chats se pide bajo demanda: la vista muestra skeleton. */
    public bool $chatsLoaded = false;

    /**
     * Modal de preview dentro del drawer: acciones propuestas (con todos
     * sus datos antes de confirmar) o tablas de datos expandidas.
     *
     * @var array{kind: string, title: string, columns: list<string>, rows: list<array<string, mixed>>, action_index: int|null, action: array<string, mixed>|null}|null
     */
    public ?array $preview = null;

    /**
     * Modelo Gemini que el usuario eligio para el copiloto (null = el del
     * selector muestra el default de configuracion).
     */
    public ?string $assistantModel = null;

    /** Modelos gratuitos que el usuario puede elegir desde el drawer. */
    private const ASSISTANT_MODELS = [
        'gemini-3.8-flash',
        'gemini-3.6-flash',
        'gemini-3.5-flash-lite',
        'gemini-3.1-flash-lite',
        'gemma-4-31b-it',
    ];

    private const LOAD_LIMIT = 50;

    public function mount(): void
    {
        // El drawer siempre arranca cerrado: al navegar a otra pagina el
        // componente se remonta y el chat no debe reaparecer tapando la vista.
        // La lista de chats NO se carga aqui: cada pagina del panel montaria
        // este componente y consultaria la BD aunque nadie abra el drawer.
        $this->open = false;
        $this->activeChatId = $this->sessionChatId();
    }

    /**
     * La apertura la dispara Alpine en el cliente (drawerOpen) para que el
     * drawer se vea al instante; este metodo solo sincroniza el estado del
     * servidor y carga el historial la primera vez.
     */
    public function openDrawer(): void
    {
        $this->open = true;
        $this->hasUnread = false;
        $this->view = 'chat';

        // Los chats se cargan en segundo plano mientras el usuario mira la
        // conversacion; si abre "Conversaciones" antes de que lleguen, la
        // vista muestra su propio skeleton.
        if (! $this->chatsLoaded) {
            $this->fetchChats();
        }

        // Preferencia de modelo del usuario para el selector del header.
        $this->assistantModel = $this->userModel();

        if (! $this->messagesLoaded) {
            $this->loadMessages();
        }

        $this->dispatch('assistant-scroll');
    }

    public function closeDrawer(): void
    {
        $this->open = false;
        $this->preview = null;
    }

    /**
     * Se conserva para llamadas directas (tests, atajos); la vista usa
     * openDrawer/closeDrawer desde Alpine.
     */
    public function toggle(): void
    {
        $this->open ? $this->closeDrawer() : $this->openDrawer();
    }

    // ---------------------------------------------------------------
    // Chats
    // ---------------------------------------------------------------

    public function showChats(): void
    {
        // openDrawer ya dejo los chats cargados en segundo plano; si el
        // usuario llega antes, esta query indexada responde en milisegundos.
        // Sin skeleton extra ni dobles requests que puedan pisarse.
        if (! $this->chatsLoaded) {
            $this->fetchChats();
        }

        $this->view = 'chats';
    }

    /**
     * Carga la lista de conversaciones bajo demanda.
     */
    public function fetchChats(): void
    {
        $this->loadChats();
        $this->chatsLoaded = true;
    }

    public function backToChat(): void
    {
        $this->view = 'chat';
        $this->dispatch('assistant-scroll');
    }

    public function selectChat(int $id): void
    {
        // Solo chats propios: el id llega del cliente y podria ser ajeno.
        $chat = AssistantChatModel::query()
            ->where('id', $id)
            ->where('user_id', $this->userId())
            ->first();

        if ($chat === null) {
            return;
        }

        $this->activeChatId = $chat->id;
        $this->messagesLoaded = false;
        $this->messages = [];
        $this->view = 'chat';
        $this->hasUnread = false;

        session()->put('assistant.chat_id', $chat->id);
        $this->loadMessages();
        $this->dispatch('assistant-scroll');
    }

    /**
     * "Nuevo chat": no crea la fila hasta el primer mensaje, asi una sesion
     * que nunca escribe no ensucia el historial.
     */
    public function newChat(): void
    {
        $this->activeChatId = null;
        $this->messages = [];
        $this->messagesLoaded = true;
        $this->view = 'chat';
        $this->hasUnread = false;

        session()->put('assistant.chat_id', null);
        $this->dispatch('assistant-scroll');
    }

    public function deleteChat(int $id): void
    {
        AssistantChatModel::query()
            ->where('id', $id)
            ->where('user_id', $this->userId())
            ->delete();

        if ($this->activeChatId === $id) {
            $this->newChat();
        }

        $this->loadChats();
        $this->chatsLoaded = true;
    }

    // ---------------------------------------------------------------
    // Conversacion
    // ---------------------------------------------------------------

    public function send(): void
    {
        $question = trim($this->input);

        if ($question === '') {
            return;
        }

        // Por si el envio llega sin haber abierto el drawer (tests, atajos).
        if (! $this->messagesLoaded) {
            $this->loadMessages();
        }

        $this->input = '';
        $isFirstMessage = $this->messages === [];

        $this->ensureChat();
        $this->pushMessage('user', $question);

        try {
            // Todo pasa por la IA: el historial viaja en apiHistory() para
            // que resuelva follow-ups y referencias con contexto real.
            $reply = app(DataAssistantInterface::class)->ask(
                $question,
                $this->apiHistory(),
                $this->userId(),
            );
        } catch (\Throwable) {
            // La IA o la base de datos pueden fallar: el chat debe responder
            // algo, nunca quedarse esperando un request que murio.
            $reply = AssistantReply::say(__('assistant.error'));
        }

        $this->pushReply($reply);

        if ($isFirstMessage) {
            $this->titleChat($question);
        }

        $this->dispatch('assistant-scroll');
    }

    /**
     * Chip de pregunta sugerida: llena el input y envia de una vez.
     */
    public function askSuggestion(string $question): void
    {
        $this->input = $question;
        $this->send();
    }

    /**
     * Confirmacion humana de una accion propuesta. Es el unico camino por el
     * que una intencion de la IA escribe en el sistema.
     */
    public function confirmAction(int $index): void
    {
        $message = $this->messages[$index] ?? null;
        $action = $message['action'] ?? null;

        if ($action === null) {
            return;
        }

        $reply = app(AssistantActionExecutor::class)->execute($action, $this->userId());

        $this->resolveAction($index);
        $this->pushReply($reply);
        $this->dispatch('assistant-scroll');
    }

    public function cancelAction(int $index): void
    {
        $message = $this->messages[$index] ?? null;

        if (($message['action'] ?? null) === null) {
            return;
        }

        $this->resolveAction($index);
        $this->pushReply(AssistantReply::say(__('assistant.action_cancelled')));
        $this->dispatch('assistant-scroll');
    }

    // ---------------------------------------------------------------
    // Preview (modal dentro del drawer)
    // ---------------------------------------------------------------

    /**
     * Abre el modal con el detalle completo de una accion propuesta:
     * cada parametro que se va a escribir, para revisar antes de confirmar.
     */
    public function previewAction(int $index): void
    {
        $action = $this->messages[$index]['action'] ?? null;

        if ($action === null) {
            return;
        }

        $this->preview = [
            'kind' => 'action',
            'title' => (string) ($action['summary'] ?? __('assistant.action_proposed')),
            'columns' => [],
            'rows' => $this->actionPreviewRows($action),
            'action_index' => $index,
            'action' => $action,
        ];
    }

    /**
     * Abre el modal con la tabla de datos completa de un mensaje (sin el
     * recorte de 10 filas que muestra la burbuja).
     */
    public function previewData(int $messageIndex, string $key): void
    {
        $rows = $this->messages[$messageIndex]['data'][$key] ?? null;

        if (! is_array($rows) || $rows === [] || ! isset($rows[0]) || ! is_array($rows[0])) {
            return;
        }

        $this->preview = [
            'kind' => 'data',
            'title' => __('assistant.preview.data_title', ['name' => $this->dataTitle($key)]),
            'columns' => array_map(
                fn (string $column): string => __("assistant.columns.{$column}") !== "assistant.columns.{$column}"
                    ? __("assistant.columns.{$column}")
                    : ucfirst(str_replace('_', ' ', $column)),
                array_keys($rows[0]),
            ),
            'rows' => array_values(array_slice($rows, 0, 200)),
            'action_index' => null,
            'action' => null,
        ];
    }

    public function closePreview(): void
    {
        $this->preview = null;
    }

    /**
     * Abre el modal con TODAS las tablas del mensaje apiladas (cuando la
     * respuesta batch trajo datos de varias entidades).
     */
    public function previewAllData(int $messageIndex): void
    {
        $data = $this->messages[$messageIndex]['data'] ?? null;

        if (! is_array($data) || $data === []) {
            return;
        }

        $tables = [];

        foreach ($data as $key => $rows) {
            if (! is_array($rows) || $rows === [] || ! isset($rows[0]) || ! is_array($rows[0])) {
                continue;
            }

            $tables[] = [
                'title' => $this->dataTitle($key),
                'columns' => array_map(
                    fn (string $column): string => __("assistant.columns.{$column}") !== "assistant.columns.{$column}"
                        ? __("assistant.columns.{$column}")
                        : ucfirst(str_replace('_', ' ', $column)),
                    array_keys($rows[0]),
                ),
                'rows' => array_values(array_slice($rows, 0, 200)),
            ];
        }

        if ($tables === []) {
            return;
        }

        $this->preview = [
            'kind' => 'data_all',
            'title' => __('assistant.preview.all_tables'),
            'tables' => $tables,
            'action_index' => null,
            'action' => null,
        ];
    }

    /**
     * Confirma la accion desde el modal de preview.
     */
    public function confirmPreview(): void
    {
        $index = $this->preview['action_index'] ?? null;

        if ($index === null) {
            $this->closePreview();

            return;
        }

        $this->preview = null;
        $this->confirmAction($index);
    }

    /**
     * Detalle legible de lo que ejecutaria cada tipo de accion. Las etiquetas
     * salen de los params guardados en la propuesta, no de texto libre.
     *
     * @param  array<string, mixed>  $action
     * @return list<array{label: string, value: string}>
     */
    private function actionPreviewRows(array $action): array
    {
        $params = is_array($action['params'] ?? null) ? $action['params'] : [];
        $type = (string) ($action['type'] ?? '');

        $field = fn (string $key, string $param): array => [
            'label' => __('assistant.fields.'.$key),
            'value' => (string) ($params[$param] ?? '—'),
        ];

        return match ($type) {
            'generate_invoices' => [
                ['label' => __('assistant.fields.period'), 'value' => $this->formatPeriod((string) ($params['period'] ?? ''))],
                ['label' => __('assistant.fields.scope'), 'value' => __('assistant.preview.invoices_scope')],
            ],
            'record_payment' => $this->paymentPreviewRows($params),
            'create_tenant' => [
                $field('name', 'name'),
                $field('document', 'document'),
                $field('phone', 'phone_number'),
                ['label' => __('assistant.fields.email'), 'value' => (string) ($params['email'] ?? '—')],
            ],
            'create_property' => [
                $field('name', 'name'),
                $field('address', 'address'),
            ],
            'create_rental' => $this->rentalPreviewRows($params),
            'update_profile' => $this->profilePreviewRows($params),
            'batch' => $this->batchPreviewRows($action),
            default => [],
        };
    }

    /**
     * Preview del paquete por secciones: cada grupo de acciones del mismo
     * tipo abre una seccion y debajo va cada elemento con sus campos.
     *
     * @param  array<string, mixed>  $action
     * @return list<array{label: string, value: string, section?: bool, item?: bool}>
     */
    private function batchPreviewRows(array $action): array
    {
        $items = is_array($action['items'] ?? null) ? $action['items'] : [];
        $rows = [];

        foreach ($items as $item) {
            if (! is_array($item) || ! isset($item['type'])) {
                continue;
            }

            $type = (string) $item['type'];
            $typeLabel = __('assistant.batch_types.'.$type, ['count' => 1]);

            // El tipo puede repetirse: la seccion solo se repite si cambia.
            $lastType = $rows === [] ? null : ($rows[count($rows) - 1]['_type'] ?? null);

            if ($lastType !== $type) {
                $rows[] = [
                    'label' => __('assistant.batch_sections.'.$type),
                    'value' => '',
                    'section' => true,
                    '_type' => $type,
                ];
            }

            $rows[] = [
                'label' => '· '.$this->batchItemLabel($item),
                'value' => '',
                'item' => true,
                '_type' => $type,
            ];

            foreach ($this->actionPreviewRows([
                'type' => $type,
                'params' => is_array($item['params'] ?? null) ? $item['params'] : [],
            ]) as $fieldRow) {
                $fieldRow['item'] = true;
                $fieldRow['_type'] = $type;
                $rows[] = $fieldRow;
            }
        }

        return array_map(
            fn (array $row): array => array_diff_key($row, ['_type' => true]),
            $rows,
        );
    }

    /**
     * Nombre legible del elemento dentro del paquete ("Isaac David",
     * "María — Apto 301"); cae al resumen de la accion si no hay nombre.
     *
     * @param  array<string, mixed>  $item
     */
    private function batchItemLabel(array $item): string
    {
        $params = is_array($item['params'] ?? null) ? $item['params'] : [];

        $label = isset($params['name']) && trim((string) $params['name']) !== ''
            ? trim((string) $params['name'])
            : null;

        if ($label === null) {
            $combo = trim(implode(' — ', array_filter([
                $params['tenant_name'] ?? null,
                $params['property_name'] ?? null,
            ])), ' —');

            $label = $combo !== '' ? $combo : null;
        }

        if ($label !== null) {
            return $label;
        }

        return (string) ($item['summary'] ?? $item['type'] ?? '—');
    }

    /**
     * @param  array<string, mixed>  $params
     * @return list<array{label: string, value: string}>
     */
    private function profilePreviewRows(array $params): array
    {
        $rows = [];

        foreach (['name', 'email', 'document_type', 'document', 'phone_number'] as $field) {
            if (isset($params[$field])) {
                $rows[] = [
                    'label' => __('assistant.fields.profile_'.$field),
                    'value' => (string) $params[$field],
                ];
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return list<array{label: string, value: string}>
     */
    private function paymentPreviewRows(array $params): array
    {
        $invoice = isset($params['invoice_id'])
            ? \App\Models\Invoice::query()
                ->where('user_id', $this->userId())
                ->with('rental:id,name', 'rental.tenant:id,name')
                ->find((int) $params['invoice_id'])
            : null;

        return [
            ['label' => __('assistant.fields.invoice'), 'value' => $invoice?->number ?? (string) ($params['invoice_id'] ?? '—')],
            ['label' => __('assistant.fields.tenant'), 'value' => $invoice?->rental?->tenant?->name ?? '—'],
            ['label' => __('assistant.fields.value'), 'value' => isset($params['amount']) ? '$'.number_format((float) $params['amount'], 0, ',', '.') : '—'],
            ['label' => __('assistant.fields.method'), 'value' => (string) ($params['method'] ?? '—')],
            ['label' => __('assistant.fields.date'), 'value' => (string) ($params['date'] ?? '—')],
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return list<array{label: string, value: string}>
     */
    private function rentalPreviewRows(array $params): array
    {
        $cadence = (string) ($params['billing_cadence'] ?? 'MENSUAL');
        $cadenceLabel = __("billing_cadence.{$cadence}");

        return [
            ['label' => __('assistant.fields.tenant'), 'value' => (string) ($params['tenant_name'] ?? '—')],
            ['label' => __('assistant.fields.property'), 'value' => (string) ($params['property_name'] ?? '—')],
            ['label' => __('assistant.fields.amount'), 'value' => isset($params['monthly_amount']) ? '$'.number_format((float) $params['monthly_amount'], 0, ',', '.') : '—'],
            ['label' => __('assistant.fields.months'), 'value' => (string) ($params['total_months'] ?? '—')],
            ['label' => __('assistant.fields.start_date'), 'value' => (string) ($params['start_date'] ?? '—')],
            ['label' => __('assistant.fields.cadence'), 'value' => $cadenceLabel !== "billing_cadence.{$cadence}" ? $cadenceLabel : $cadence],
        ];
    }

    /**
     * Titulo legible de una tabla de datos: primero el mapa dedicado
     * (data_titles), luego columns y al final el nombre capitalizado.
     */
    public function dataTitle(string $key): string
    {
        $title = __("assistant.data_titles.{$key}");

        if ($title !== "assistant.data_titles.{$key}") {
            return $title;
        }

        $column = __("assistant.columns.{$key}");

        return $column !== "assistant.columns.{$key}"
            ? $column
            : ucfirst(str_replace('_', ' ', $key));
    }

    /**
     * Cuenta las tablas de datos de un payload: las del mapa `data` mas
     * las anidadas en cada seccion batch.
     *
     * @param  array<string, mixed>  $payload
     */
    private function countTables(array $payload): int
    {
        // `data` ya es la fusion de todas las tablas del mensaje (incluidas
        // las de cada seccion batch): contar las secciones duplicaria.
        return $this->countDataTables($payload['data'] ?? null);
    }

    /**
     * @param  array<string, mixed>|null  $data
     */
    private function countDataTables(?array $data): int
    {
        if (! is_array($data)) {
            return 0;
        }

        return collect($data)->filter(
            fn ($rows): bool => is_array($rows) && $rows !== [] && isset($rows[0]) && is_array($rows[0]),
        )->count();
    }

    private function formatPeriod(string $period): string
    {
        try {
            return \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $period.'-01')->format('m/Y');
        } catch (\Throwable) {
            return $period;
        }
    }

    /**
     * El intent "navigate" devuelve una URL del panel; el boton "Ir a…" la
     * usa para llevar al usuario a la seccion correspondiente.
     */
    public function goTo(string $url): void
    {
        // Solo URLs internas del panel: el copiloto nunca debe redirigir a
        // un dominio externo aunque el modelo lo propusiera.
        if (! str_starts_with($url, url('/'))) {
            Notification::make()->danger()->title(__('assistant.error'))->send();

            return;
        }

        // Cierra el drawer en el cliente antes de navegar: si el panel usa
        // navegacion SPA el estado de Alpine sobreviviria al redirect.
        $this->js("window.dispatchEvent(new CustomEvent('assistant-close'))");
        $this->redirect($url);
    }

    /**
     * Render con datos extra: las sugerencias contextuales se calculan en
     * cada render, asi reflejan la pantalla actual del panel.
     */
    public function render()
    {
        return view('livewire.assistant-chat', [
            'contextSuggestions' => $this->contextSuggestions(),
            'assistantModels' => $this->assistantModelOptions(),
        ]);
    }

    // ---------------------------------------------------------------
    // Sugerencias contextuales
    // ---------------------------------------------------------------

    /**
     * Chips persistentes arriba del input, elegidos segun la pantalla en la
     * que esta el usuario. No limitan al chat: solo recomiendan puntos de
     * partida relevantes para esa seccion.
     *
     * @return list<string>
     */
    public function contextSuggestions(): array
    {
        $path = request()->path();

        $keys = match (true) {
            str_contains($path, 'invoices') => ['overdue', 'pending', 'record_payment', 'payments'],
            str_contains($path, 'rentals') => ['adjustments', 'contracts', 'portfolio'],
            str_contains($path, 'tenants') => ['pending', 'reminder', 'tenants'],
            str_contains($path, 'properties') => ['portfolio', 'rentals', 'invoices'],
            str_contains($path, 'delivery-acts') => ['portfolio', 'overdue', 'pending'],
            str_contains($path, 'rent-adjustments') => ['adjustments', 'contracts', 'analysis'],
            str_contains($path, 'finances') || str_contains($path, 'finanzas') => ['analysis', 'income', 'pending'],
            str_contains($path, 'dashboard') => ['portfolio', 'analysis', 'overdue'],
            default => ['portfolio', 'overdue', 'analysis'],
        };

        // Se devuelven las CLAVES, no el texto: la vista las traduce y les
        // pone icono, asi cada chip se ve como una accion rapida.
        return $keys;
    }

    // ---------------------------------------------------------------
    // Modelo del copiloto
    // ---------------------------------------------------------------

    /**
     * Ids de los modelos del selector; la vista los decora con las
     * traducciones assistant.models.{indice}.
     *
     * @return list<string>
     */
    public function assistantModelOptions(): array
    {
        return self::ASSISTANT_MODELS;
    }

    /**
     * Cambia el modelo del usuario en tiempo real: se guarda en su perfil
     * y el proximo mensaje ya lo usa (la clasificacion lee la preferencia
     * en cada llamada).
     */
    public function setAssistantModel(string $model): void
    {
        if (! in_array($model, self::ASSISTANT_MODELS, true)) {
            return;
        }

        User::query()->whereKey($this->userId())->update(['assistant_model' => $model]);
        $this->assistantModel = $model;
    }

    /**
     * Modelo efectivo del usuario: el que eligio o el default de config.
     */
    private function userModel(): string
    {
        return (string) (
            User::query()->whereKey($this->userId())->value('assistant_model')
            ?: config('services.gemini.model', 'gemini-3.5-flash-lite')
        );
    }

    // ---------------------------------------------------------------
    // Internos
    // ---------------------------------------------------------------

    private function userId(): int
    {
        return (int) app(CurrentUserContextInterface::class)->id();
    }

    private function sessionChatId(): ?int
    {
        $id = session()->get('assistant.chat_id');

        return $id === null ? null : (int) $id;
    }

    /**
     * @return list<array{id: int, title: string, updated_at: string|null}>
     */
    private function loadChats(): void
    {
        $this->chats = AssistantChatModel::query()
            ->where('user_id', $this->userId())
            ->latest('updated_at')
            ->limit(30)
            ->get()
            ->map(fn (AssistantChatModel $chat): array => [
                'id' => $chat->id,
                'title' => $chat->title ?: __('assistant.untitled_chat'),
                'updated_at' => $chat->updated_at?->diffForHumans(),
            ])
            ->all();
    }

    private function loadMessages(): void
    {
        if ($this->activeChatId === null) {
            $this->messages = [];
            $this->messagesLoaded = true;

            return;
        }

        $this->messages = AssistantMessage::query()
            ->where('chat_id', $this->activeChatId)
            ->where('user_id', $this->userId())
            ->latest()
            ->limit(self::LOAD_LIMIT)
            ->get()
            ->reverse()
            ->map(fn (AssistantMessage $message): array => [
                'id' => $message->id,
                'role' => $message->role,
                'text' => $message->text,
                'data' => $message->payload['data'] ?? null,
                'action' => $message->payload['action'] ?? null,
                'action_resolved' => $message->payload['action_resolved'] ?? false,
                'redirect' => $message->payload['redirect'] ?? null,
                'suggestions' => $message->payload['suggestions'] ?? [],
                'intent' => $message->payload['intent'] ?? null,
                'sections' => $message->payload['sections'] ?? null,
                'table_count' => $message->payload['table_count'] ?? $this->countTables($message->payload ?? []),
                'at' => $message->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        $this->messagesLoaded = true;
    }

    /**
     * Crea el chat activo si no existe (primer mensaje de la conversacion).
     */
    private function ensureChat(): void
    {
        if ($this->activeChatId !== null) {
            return;
        }

        $chat = AssistantChatModel::query()->create([
            'user_id' => $this->userId(),
            'title' => null,
        ]);

        $this->activeChatId = $chat->id;
        session()->put('assistant.chat_id', $chat->id);
    }

    /**
     * Titulo del chat: truncado local del primer mensaje. Se evito la
     * llamada a Gemini a proposito: el titulo se genera dentro del mismo
     * request de envio y una llamada HTTP extra atrasaria TODA respuesta
     * (incluso las que el matcher resuelve al instante).
     */
    private function titleChat(string $firstMessage): void
    {
        $chat = AssistantChatModel::query()
            ->where('id', $this->activeChatId)
            ->where('user_id', $this->userId())
            ->first();

        if ($chat === null || $chat->title !== null) {
            return;
        }

        $chat->title = Str::limit(trim($firstMessage), 50);
        $chat->touch();
        $chat->save();

        $this->loadChats();
        $this->chatsLoaded = true;
    }

    private function pushMessage(string $role, string $text): void
    {
        $this->messages[] = $this->persist($role, $text, [
            'data' => null,
            'action' => null,
            'redirect' => null,
            'suggestions' => [],
            'intent' => null,
            'sections' => null,
        ]);
    }

    private function pushReply(AssistantReply $reply): void
    {
        $this->messages[] = $this->persist('assistant', $reply->text, [
            'data' => $reply->data,
            'action' => $reply->action,
            'redirect' => $reply->redirect,
            'suggestions' => $reply->suggestions,
            'intent' => $reply->intent,
            'sections' => $reply->sections,
        ]);

        if (! $this->open) {
            $this->hasUnread = true;
        }
    }

    /**
     * Guarda el turno en base de datos y devuelve la entrada lista para la
     * lista en memoria.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function persist(string $role, string $text, array $payload): array
    {
        $stored = AssistantMessage::query()->create([
            'user_id' => $this->userId(),
            'chat_id' => $this->activeChatId,
            'role' => $role,
            'text' => $text,
            'payload' => $payload,
        ]);

        AssistantChatModel::query()->whereKey($this->activeChatId)->update(['updated_at' => now()]);

        return [
            'id' => $stored->id,
            'role' => $role,
            'text' => $text,
            'data' => $payload['data'],
            'action' => $payload['action'],
            'action_resolved' => false,
            'redirect' => $payload['redirect'],
            'suggestions' => $payload['suggestions'],
            'intent' => $payload['intent'] ?? null,
            'sections' => $payload['sections'] ?? null,
            'table_count' => $this->countTables($payload),
            'at' => $stored->created_at?->toIso8601String(),
        ];
    }

    /**
     * Marca la accion de un mensaje como resuelta, en memoria y en BD.
     */
    private function resolveAction(int $index): void
    {
        $message = $this->messages[$index] ?? null;

        if ($message === null) {
            return;
        }

        $this->messages[$index]['action'] = null;
        $this->messages[$index]['action_resolved'] = true;

        $stored = isset($message['id'])
            ? AssistantMessage::query()->whereKey($message['id'])->where('user_id', $this->userId())->first()
            : null;

        if ($stored !== null) {
            $stored->payload = array_merge($stored->payload ?? [], [
                'action' => null,
                'action_resolved' => true,
            ]);
            $stored->save();
        }
    }

    /**
     * Historial en el formato que espera Gemini: role user/model y solo el
     * texto. Los datos estructurados no viajan: el digest se reconstruye en
     * cada llamada y es siempre el estado actual, no el del turno viejo.
     *
     * @return list<array{role: string, text: string}>
     */
    private function apiHistory(): array
    {
        // El ultimo mensaje es la pregunta recien enviada: classify() la
        // agrega otra vez al final de contents, asi que aqui se excluye.
        return collect($this->messages)
            ->slice(0, -1)
            ->map(fn (array $message): array => [
                'role' => $message['role'] === 'assistant' ? 'assistant' : 'user',
                'text' => (string) $message['text'],
            ])
            ->values()
            ->all();
    }
}
