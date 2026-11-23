<?php

namespace App\Livewire;

use App\Contracts\CurrentUserContextInterface;
use App\Contracts\DataAssistantInterface;
use App\Dto\AssistantReply;
use App\Models\AssistantChat as AssistantChatModel;
use App\Models\AssistantMessage;
use App\Models\Invoice;
use App\Models\RentAdjustment;
use App\Models\Rental;
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

    /**
     * Formulario guiado en curso (acta de entrega, etc.): {form, step,
     * total_steps, field, prompt, quick_replies?}. Se limpia al completar,
     * cancelar o cambiar de chat.
     */
    public ?array $pendingForm = null;

    /**
     * Valores editados en el modal de preview antes de confirmar: se
     * siembran desde action.editable y se fusionan sobre action.params.
     *
     * @var array<string, mixed>
     */
    public array $previewEdits = [];

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

    /**
     * Cancelacion best-effort de la respuesta en curso: el request de la IA
     * no se puede abortar en PHP, pero si `cancelPending` llega mientras
     * `send()` sigue procesando, la respuesta se descarta al terminar.
     * Si llega tarde (encolada), el flag queda en true y el siguiente
     * send() lo resetea al inicio, asi que nunca descarta una respuesta
     * que no corresponde.
     */
    public bool $cancelPendingReply = false;

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
        $this->pendingForm = null;
        $this->previewEdits = [];

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
        $this->pendingForm = null;
        $this->previewEdits = [];

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

        // Una respuesta puede encadenar varias llamadas a Gemini (clasificar,
        // lookups, redactar, reporte): el max_execution_time por defecto de
        // PHP (30s) mata el request a mitad de la espera de cURL. El
        // presupuesto total lo acota GeminiDataAssistant; aqui solo se
        // amplia la ventana del request por encima de el.
        set_time_limit(max(90, ((int) config('services.gemini.timeout', 10) * 2) + 60));

        // Por si el envio llega sin haber abierto el drawer (tests, atajos).
        if (! $this->messagesLoaded) {
            $this->loadMessages();
        }

        $this->input = '';
        $isFirstMessage = $this->messages === [];
        // Limpia cancelaciones encoladas de requests anteriores: solo una
        // cancelacion que llegue MIENTRAS esta respuesta se esta generando
        // debe descartarla.
        $this->cancelPendingReply = false;

        $this->ensureChat();
        $this->pushMessage('user', $question);

        // Comandos locales del panel (tema claro/oscuro): no pasan por la
        // IA, la respuesta es inmediata y el cambio lo hace el navegador.
        $theme = $this->detectThemeCommand($question);
        if ($theme !== null) {
            $this->dispatch('assistant-theme', theme: $theme);

            // Si habia un wizard en curso (acta de entrega), el comando de
            // tema no debe tumbarlo: la sesion sigue viva y la barra se
            // restaura desde el ultimo mensaje que la traia.
            $wizardAlive = app(\App\Services\Assistant\AssistantFormSession::class)
                ->form($this->userId()) !== null;

            $this->pushReply(AssistantReply::say(match ($theme) {
                'dark' => __('assistant.replies.theme_dark'),
                'light' => __('assistant.replies.theme_light'),
                default => __('assistant.replies.theme_system'),
            }));

            if ($wizardAlive) {
                $this->pendingForm = collect($this->messages)
                    ->reverse()
                    ->first(fn (array $message): bool => ($message['role'] ?? '') === 'assistant'
                        && ($message['pending_form'] ?? null) !== null)['pending_form'] ?? null;
            }

            $this->dispatch('assistant-scroll');

            return;
        }

        // Cola de un batch grande: "continuar" propone el siguiente grupo
        // de acciones; "cancela" la descarta. Otro tema no la toca — la
        // cola sigue guardada para un continuar posterior.
        $batchSession = app(\App\Services\Assistant\AssistantFormSession::class)->get($this->userId());

        if (($batchSession['form'] ?? null) === 'batch_continue') {
            if (preg_match('/\b(cancela\w*|cancelar|olvida\w*|stop|cancel)\b/iu', $question)) {
                app(\App\Services\Assistant\AssistantFormSession::class)->clear($this->userId());
                $this->pushReply(AssistantReply::say(__('assistant.replies.form_cancelled')));
                $this->dispatch('assistant-scroll');

                return;
            }

            if (preg_match('/\b(continuar|continue|sigue|seguir|next|go on|que sigue|resto|rest\w*)\b/iu', $question)) {
                try {
                    $reply = app(\App\Services\Assistant\AssistantToolInvoker::class)
                        ->invoke('continue_batch', [], $this->userId());
                } catch (\Throwable) {
                    $reply = AssistantReply::say(__('assistant.error'));
                }

                $this->pushReply($reply);
                $this->dispatch('assistant-scroll');

                return;
            }
        }

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

        // Si el usuario cancelo mientras la IA pensaba, su mensaje queda
        // pero la respuesta se descarta con un aviso corto.
        if ($this->cancelPendingReply) {
            $this->cancelPendingReply = false;
            $this->pushReply(AssistantReply::say(__('assistant.replies.request_cancelled')));
            $this->dispatch('assistant-scroll');

            return;
        }

        $this->pushReply($reply);

        if ($isFirstMessage) {
            $this->titleChat($question);
        }

        $this->dispatch('assistant-scroll');
    }

    /**
     * Respuesta del input tipado del wizard (fecha, numero, switch, select):
     * entra al mismo pipeline que un mensaje escrito, asi que el handler
     * del formulario la parsea igual que texto libre.
     */
    public function answerFormField(string $value): void
    {
        $value = trim($value);

        if ($value === '') {
            return;
        }

        $this->input = $value;
        $this->send();
    }

    /**
     * Formulario dinamico del chat (update o creacion): el usuario lleno
     * los campos que queria (cada uno con su tipo real) y el sistema
     * arma la propuesta — sin pasar por el clasificador porque los
     * valores ya vienen tipados y el wizard sabe que entidad es.
     *
     * @param  array<string, mixed>  $values
     */
    public function submitForm(array $values): void
    {
        try {
            $reply = app(\App\Services\Assistant\AssistantToolInvoker::class)
                ->submitForm($values, $this->userId());
        } catch (\Throwable) {
            $reply = AssistantReply::say(__('assistant.error'));
        }

        $this->pushReply($reply);
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
     * Boton de accion dentro del modal de preview (guia, insights):
     * cierra el modal y envia el prompt como mensaje normal del chat,
     * que ademas hace scroll al final.
     */
    public function askFromPreview(string $question): void
    {
        $this->preview = null;
        $this->askSuggestion($question);
    }

    /**
     * Boton "cancelar" del indicador de pensamiento: marca el request para
     * que su respuesta se descarte cuando termine (ver send()).
     */
    public function cancelPending(): void
    {
        $this->cancelPendingReply = true;
    }

    /**
     * Abre el modal con el analisis predictivo completo del mensaje.
     */
    public function previewInsights(int $messageIndex): void
    {
        $insights = $this->messages[$messageIndex]['data']['insights'] ?? null;

        if (! is_array($insights)) {
            return;
        }

        $this->preview = [
            'kind' => 'insights',
            'title' => __('assistant.insights.title'),
            'payload' => $insights,
            'action_index' => null,
            'action' => null,
        ];
    }

    /**
     * Abre el modal con la guia de uso completa del mensaje.
     */
    public function previewGuide(int $messageIndex): void
    {
        $guide = $this->messages[$messageIndex]['data']['guide'] ?? null;

        if (! is_array($guide) || empty($guide['steps'])) {
            return;
        }

        $this->preview = [
            'kind' => 'guide',
            'title' => (string) ($guide['title'] ?? __('assistant.guide.fallback_title')),
            'payload' => $guide,
            'action_index' => null,
            'action' => null,
        ];
    }

    /**
     * Abre el modal con el reporte completo del mensaje (analisis o
     * guia compuesto por la IA a partir de la base de datos real).
     */
    public function previewReport(int $messageIndex): void
    {
        $report = $this->messages[$messageIndex]['data']['report'] ?? null;

        if (! is_array($report) || empty($report['sections'])) {
            return;
        }

        $this->preview = [
            'kind' => 'report',
            'title' => (string) ($report['title'] ?? __('assistant.report.fallback_title')),
            'payload' => $report,
            'action_index' => null,
            'action' => null,
        ];
    }

    /**
     * Chip de candidato en una respuesta ambigua ("¿cuál Camila?").
     * Re-ejecuta la intencion original con la entidad elegida via
     * _entity_ref: el nombre puede seguir siendo identico para otro
     * registro, la ref es lo que desambigua de verdad.
     */
    public function pickCandidate(int $index, string $token): void
    {
        $data = $this->messages[$index]['data'] ?? null;
        $resolution = is_array($data) ? ($data['pending_resolution'] ?? null) : null;
        $candidates = is_array($data) ? ($data['candidates'] ?? null) : null;

        if (! is_array($resolution) || ! is_array($candidates)) {
            return;
        }

        $picked = collect($candidates)->first(
            fn (array $candidate): bool => ($candidate['token'] ?? null) === $token,
        );

        if ($picked === null) {
            return;
        }

        $params = is_array($resolution['params'] ?? null) ? $resolution['params'] : [];
        $params['_entity_ref'] = $token;
        $params[(string) ($resolution['name_key'] ?? 'name')] = (string) ($picked['name'] ?? '');

        try {
            $reply = app(\App\Services\Assistant\AssistantToolInvoker::class)->invoke(
                (string) ($resolution['intent'] ?? 'entity_detail'),
                $params,
                $this->userId(),
            );
        } catch (\Throwable) {
            $reply = AssistantReply::say(__('assistant.error'));
        }

        $this->pushReply($reply);
        $this->dispatch('assistant-scroll');
    }

    /**
     * Descarga el archivo que una accion del chat dejo en cache (documento
     * generado, CSV exportado). El token viaja en data.file del mensaje;
     * sin el es imposible adivinar que archivo pedir.
     */
    public function downloadFile(string $token)
    {
        $file = app(AssistantActionExecutor::class)->fileForDownload($token, $this->userId());

        if ($file === null) {
            return null;
        }

        return response()->streamDownload(
            fn () => print($file['content']),
            $file['filename'],
            ['Content-Type' => $file['mime']],
        );
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

        $this->pendingForm = null;
        $this->previewEdits = [];
        $reply = app(AssistantActionExecutor::class)->execute($action, $this->userId());

        // La accion se resuelve EN el mismo mensaje que la propuso:
        // el documento/resultado queda en esa burbuja y la tarjeta pasa
        // a "Accion completada". No se abre un mensaje nuevo.
        $this->resolveAction($index);
        $this->applyReplyToMessage($index, $reply);
        $this->dispatch('assistant-scroll');
    }

    public function cancelAction(int $index): void
    {
        $message = $this->messages[$index] ?? null;

        if (($message['action'] ?? null) === null) {
            return;
        }

        $this->pendingForm = null;
        $this->previewEdits = [];
        $this->resolveAction($index, cancelled: true);
        // Al cancelar se conserva el texto original de la propuesta: el
        // detalle de lo que se iba a hacer no debe desaparecer; la tarjeta
        // neutra de "Accion cancelada" ya comunica el resultado.
        $this->applyReplyToMessage($index, AssistantReply::say(''), keepText: true);
        $this->dispatch('assistant-scroll');
    }

    /**
     * Cancela el formulario guiado en curso (acta de entrega, etc.):
     * limpia la sesion del borrador y avisa en el chat.
     */
    public function cancelForm(): void
    {
        if ($this->pendingForm === null) {
            return;
        }

        app(\App\Services\Assistant\AssistantFormSession::class)->clear($this->userId());
        $this->pendingForm = null;
        $this->previewEdits = [];
        $this->pushReply(AssistantReply::say(__('assistant.replies.form_cancelled')));
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

        // Campos editables: los valores por defecto salen del payload de
        // la accion y el usuario puede corregirlos antes de confirmar.
        $this->previewEdits = collect(is_array($action['editable'] ?? null) ? $action['editable'] : [])
            ->mapWithKeys(fn (array $field): array => [
                (string) ($field['key'] ?? '') => $field['value'] ?? null,
            ])
            ->filter(fn ($value, string $key): bool => $key !== '')
            ->all();

        // Batch: cada item puede tener sus propios campos editables y un
        // checkbox para excluirlo del paquete antes de confirmar.
        if (($action['type'] ?? '') === 'batch' && is_array($action['items'] ?? null)) {
            foreach ($action['items'] as $i => $item) {
                $this->previewEdits["drop_{$i}"] = '0';

                foreach (is_array($item['editable'] ?? null) ? $item['editable'] : [] as $field) {
                    if (! is_array($field) || ! isset($field['key'])) {
                        continue;
                    }

                    $this->previewEdits["b{$i}_".(string) $field['key']] = $field['value'] ?? null;
                }
            }
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
     * Abre el modal con el documento generado embebido (iframe): el
     * archivo se sirve por la ruta publica con el mismo control de
     * acceso por usuario que la descarga.
     */
    public function previewFile(int $index): void
    {
        $file = $this->messages[$index]['data']['file'] ?? null;

        if (! is_array($file) || empty($file['token'])) {
            return;
        }

        $this->preview = [
            'kind' => 'file',
            'title' => (string) ($file['filename'] ?? __('assistant.preview.file_title')),
            'file_url' => route('assistant.file', ['token' => $file['token']]),
            'file_name' => (string) ($file['filename'] ?? ''),
            'file_token' => (string) $file['token'],
            'columns' => [],
            'rows' => [],
        ];
    }

    /**
     * Abre el modal con la tabla de datos completa de un mensaje (sin el
     * recorte de 10 filas que muestra la burbuja).
     */
    public function previewData(int $messageIndex, string $key): void
    {
        // La tabla puede vivir en `data` (respuesta simple) o dentro de una
        // seccion de una respuesta batch.
        $rows = $this->messages[$messageIndex]['data'][$key] ?? null;
        $sourceData = $this->messages[$messageIndex]['data'] ?? null;

        if ($rows === null) {
            foreach ($this->messages[$messageIndex]['sections'] ?? [] as $section) {
                $rows = $section['data'][$key] ?? null;
                if ($rows !== null) {
                    $sourceData = $section['data'] ?? null;
                    break;
                }
            }
        }

        // 'candidates' no es una tabla de datos: se pinta como chips.
        if ($key === 'candidates') {
            return;
        }

        if (! is_array($rows) || $rows === [] || ! isset($rows[0]) || ! is_array($rows[0])) {
            return;
        }

        $this->preview = [
            'kind' => 'data',
            'title' => __('assistant.preview.data_title', ['name' => $this->dataTitle($key, is_array($sourceData) ? $sourceData : null)]),
            'columns' => array_map(
                fn (string $column): string => __("assistant.columns.{$column}") !== "assistant.columns.{$column}"
                    ? __("assistant.columns.{$column}")
                    : ucfirst(str_replace('_', ' ', $column)),
                array_keys($rows[0]),
            ),
            'rows' => array_values(array_slice($rows, 0, 200)),
            'action_index' => null,
            'action' => null,
            // Colores de badge propuestos por la IA para esta tabla.
            'badge_colors' => $this->messages[$messageIndex]['badge_colors'][$key] ?? [],
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
        // Con batch cada seccion trae su propia data (las claves del mapa
        // `data` fusionado pueden ir renombradas con sufijo); sin batch se
        // usa el mapa plano. Iterar ambos duplicaria las tablas.
        $sections = $this->messages[$messageIndex]['sections'] ?? [];
        $sources = $sections !== []
            ? array_map(fn (array $section): array => $section['data'] ?? [], $sections)
            : [$this->messages[$messageIndex]['data'] ?? []];

        $tables = [];

        foreach ($sources as $data) {
            foreach ($data as $key => $rows) {
                // 'candidates' se pinta como chips, no como tabla.
                if ($key === 'candidates'
                    || ! is_array($rows) || $rows === [] || ! isset($rows[0]) || ! is_array($rows[0])) {
                    continue;
                }

                $tables[] = [
                    'title' => $this->dataTitle($key, is_array($data) ? $data : null),
                    'columns' => array_map(
                        fn (string $column): string => __("assistant.columns.{$column}") !== "assistant.columns.{$column}"
                            ? __("assistant.columns.{$column}")
                            : ucfirst(str_replace('_', ' ', $column)),
                        array_keys($rows[0]),
                    ),
                    'rows' => array_values(array_slice($rows, 0, 200)),
                    'badge_colors' => $this->messages[$messageIndex]['badge_colors'][$key] ?? [],
                ];
            }
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

        // Aplica las ediciones del modal sobre los params de la accion y
        // las persiste en el mensaje: lo que se confirma es exactamente lo
        // que el usuario reviso en pantalla.
        $message = $this->messages[$index] ?? null;

        if (is_array($message) && is_array($message['action'] ?? null) && $this->previewEdits !== []) {
            $edited = $this->applyPreviewEdits($message['action']);
            $this->messages[$index]['action'] = $edited;
            $this->persistActionEdits($index, $edited);
        }

        $this->preview = null;
        $this->previewEdits = [];
        $this->confirmAction($index);
    }

    /**
     * Fusiona previewEdits sobre action.params segun la lista
     * `editable` (key -> param name). Campos editables que el usuario
     * dejo vacios se omiten del params.
     *
     * @param  array<string, mixed>  $action
     * @return array<string, mixed>
     */
    private function applyPreviewEdits(array $action): array
    {
        // Batch: cada elemento se excluye con su checkbox o recibe las
        // ediciones de SUS campos editables (keys "b{indice}_{campo}").
        if (($action['type'] ?? '') === 'batch' && is_array($action['items'] ?? null)) {
            $items = [];

            foreach ($action['items'] as $i => $item) {
                if (! is_array($item)) {
                    continue;
                }

                if (in_array($this->previewEdits["drop_{$i}"] ?? '0', ['1', 'true', 'on', 'yes'], true)) {
                    continue;
                }

                foreach (is_array($item['editable'] ?? null) ? $item['editable'] : [] as $field) {
                    if (! is_array($field) || ! isset($field['key'])) {
                        continue;
                    }

                    $editKey = "b{$i}_".(string) $field['key'];

                    if (! array_key_exists($editKey, $this->previewEdits)) {
                        continue;
                    }

                    $value = $this->previewEdits[$editKey];
                    $param = (string) ($field['param'] ?? $field['key']);

                    // En batch un campo vacio conserva el valor original:
                    // es mas seguro que mandar null y perder el dato.
                    if (is_string($value) && trim($value) === '') {
                        continue;
                    }

                    $item['params'][$param] = $value;
                }

                $items[] = $item;
            }

            $action['items'] = $items;

            return $action;
        }

        $editable = is_array($action['editable'] ?? null) ? $action['editable'] : [];

        foreach ($editable as $field) {
            if (! is_array($field) || ! isset($field['key'])) {
                continue;
            }

            $key = (string) $field['key'];
            $param = (string) ($field['param'] ?? $key);

            if (! array_key_exists($key, $this->previewEdits)) {
                continue;
            }

            $value = $this->previewEdits[$key];

            // Vacios se guardan como null para que el executor decida si
            // el campo es opcional o falta (valida de nuevo al ejecutar).
            $action['params'][$param] = is_string($value) && trim($value) === '' ? null : $value;
        }

        return $action;
    }

    /**
     * Persiste la accion ya editada en el mensaje guardado, para que un
     * reload del historial muestre lo que realmente se ejecuto.
     *
     * @param  array<string, mixed>  $action
     */
    private function persistActionEdits(int $index, array $action): void
    {
        $message = $this->messages[$index] ?? null;

        if ($message === null || ! isset($message['id'])) {
            return;
        }

        $stored = AssistantMessage::query()
            ->whereKey($message['id'])
            ->where('user_id', $this->userId())
            ->first();

        if ($stored === null) {
            return;
        }

        $stored->payload = array_merge($stored->payload ?? [], ['action' => $action]);
        $stored->save();
    }

    /**
     * Detalle legible de lo que ejecutaria cada tipo de accion. Las etiquetas
     * salen de los params guardados en la propuesta, no de texto libre.
     *
     * @param  array<string, mixed>  $action
     * @return list<array<string, mixed>>
     */
    private function actionPreviewRows(array $action): array
    {
        // Acciones con diff before/after (updates, records con valores
        // actuales): el payload ya trae las filas formateadas.
        if (isset($action['changes']) && is_array($action['changes'])) {
            return $action['changes'];
        }

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
            'generate_document' => $this->documentPreviewRows($params),
            'batch' => $this->batchPreviewRows($action),
            default => $this->genericPreviewRows($params),
        };
    }

    /**
     * Filas de preview para acciones sin diff propio: lista los params
     * escalares con su etiqueta (sin ids internos ni claves "_...").
     *
     * @param  array<string, mixed>  $params
     * @return list<array{label: string, value: string}>
     */
    private function genericPreviewRows(array $params): array
    {
        $rows = [];

        foreach ($params as $key => $value) {
            $key = (string) $key;

            if (str_starts_with($key, '_') || str_ends_with($key, '_id') || is_array($value) || is_object($value)) {
                continue;
            }

            if (! is_scalar($value)) {
                continue;
            }

            $value = trim((string) $value);

            if ($value === '') {
                continue;
            }

            $labelKey = 'assistant.fields.'.$key;
            $label = __($labelKey);

            $rows[] = [
                'label' => $label === $labelKey ? ucfirst(str_replace('_', ' ', $key)) : $label,
                'value' => $value,
            ];
        }

        return $rows;
    }

    /**
     * Preview de generacion de documento: el tipo va como badge
     * traducido, el formato con su etiqueta de dominio y el objetivo
     * resuelto a algo legible (numero de factura, arriendo) en vez de
     * ids crudos.
     *
     * @param  array<string, mixed>  $params
     * @return list<array{label: string, value: string, badge?: string}>
     */
    private function documentPreviewRows(array $params): array
    {
        $kind = (string) ($params['kind'] ?? '');
        $format = (string) ($params['format'] ?? 'text');

        $kindLabel = $kind !== '' ? __('assistant.doc_types.'.$kind) : '—';
        // doc_types trae articulo ("la factura"): el badge muestra el
        // nombre solo, igual que fieldTitle hace con los labels.
        $kindBadge = trim((string) preg_replace('/^(?:el|la|los|las|the)\s+/iu', '', $kindLabel));
        $kindBadge = $kindBadge === '' ? $kindLabel : mb_strtoupper(mb_substr($kindBadge, 0, 1)).mb_substr($kindBadge, 1);

        $formatKey = 'invoice.documents.format.'.$format;
        $formatLabel = __($formatKey);

        $rows = [
            [
                'label' => __('assistant.fields.document_type'),
                'value' => $kindBadge,
                'badge' => 'document',
            ],
            [
                'label' => __('assistant.fields.format'),
                'value' => $formatLabel === $formatKey ? ucfirst($format) : $formatLabel,
            ],
        ];

        $target = $this->documentTargetLabel($params);

        if ($target !== null) {
            $rows[] = ['label' => __('assistant.fields.reference'), 'value' => $target];
        }

        if (! empty($params['period'])) {
            $rows[] = ['label' => __('assistant.fields.period'), 'value' => $this->formatPeriod((string) $params['period'])];
        }

        return $rows;
    }

    /**
     * Etiqueta legible del objetivo del documento: numero de factura,
     * nombre del arriendo o del ajuste. Null si no hay nada que mostrar.
     *
     * @param  array<string, mixed>  $params
     */
    private function documentTargetLabel(array $params): ?string
    {
        $userId = $this->userId();

        if (! empty($params['invoice_id'])) {
            return Invoice::query()
                ->where('user_id', $userId)
                ->whereKey((int) $params['invoice_id'])
                ->value('number');
        }

        if (! empty($params['adjustment_id'])) {
            return RentAdjustment::query()
                ->where('user_id', $userId)
                ->whereKey((int) $params['adjustment_id'])
                ->with('rental:id,name')
                ->first()
                ?->rental?->name;
        }

        if (! empty($params['rental_id'])) {
            return Rental::query()
                ->where('user_id', $userId)
                ->whereKey((int) $params['rental_id'])
                ->value('name');
        }

        return null;
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
                'changes' => $item['changes'] ?? null,
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
                ->with('rental:id,name,tenant_id', 'rental.tenant:id,name')
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
    public function dataTitle(string $key, ?array $data = null): string
    {
        // Titulo contextual: respuestas de entidad unica pueden traer
        // `_titles` => {clave: "Arriendo de X"} para que la tabla diga
        // exactamente de quien es el detalle pedido.
        $custom = $data['_titles'][$key] ?? null;

        if (is_string($custom) && $custom !== '') {
            return $custom;
        }

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

    /**
     * Detecta pedidos de cambio de tema ("modo oscuro", "dark mode"...).
     * Devuelve 'dark' | 'light' | null. La lista de frases es explicita
     * para no confundir preguntas que solo mencionan la palabra.
     */
    private function detectThemeCommand(string $question): ?string
    {
        $text = \Illuminate\Support\Str::ascii(\Illuminate\Support\Str::lower($question));

        $dark = ['modo oscuro', 'tema oscuro', 'dark mode', 'modo dark', 'tema dark',
            'cambiar a modo oscuro', 'cambia a modo oscuro', 'pon modo oscuro',
            'activa modo oscuro', 'modo noche', 'dark theme'];
        $light = ['modo claro', 'tema claro', 'light mode', 'modo light', 'tema light',
            'cambiar a modo claro', 'cambia a modo claro', 'pon modo claro',
            'activa modo claro', 'light theme'];
        $system = ['modo sistema', 'modo de sistema', 'tema sistema', 'system mode',
            'system theme', 'modo automatico', 'tema automatico', 'auto theme',
            'modo auto'];

        foreach ($dark as $phrase) {
            if (str_contains($text, $phrase)) {
                return 'dark';
            }
        }

        foreach ($light as $phrase) {
            if (str_contains($text, $phrase)) {
                return 'light';
            }
        }

        foreach ($system as $phrase) {
            if (str_contains($text, $phrase)) {
                return 'system';
            }
        }

        return null;
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
                'action_cancelled' => $message->payload['action_cancelled'] ?? false,
                'resolved_action' => $message->payload['resolved_action'] ?? null,
                'redirect' => $message->payload['redirect'] ?? null,
                'suggestions' => $message->payload['suggestions'] ?? [],
                'intent' => $message->payload['intent'] ?? null,
                'sections' => $message->payload['sections'] ?? null,
                'pending_form' => $message->payload['pending_form'] ?? null,
                'badge_colors' => $message->payload['badge_colors'] ?? null,
                'options' => $message->payload['options'] ?? [],
                'table_count' => $message->payload['table_count'] ?? $this->countTables($message->payload ?? []),
                'at' => $message->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        // El wizard guiado se restaura desde la ultima respuesta del
        // asistente: si el usuario recarga a mitad de un acta, la barra
        // de pasos vuelve a aparecer.
        $this->pendingForm = collect($this->messages)
            ->reverse()
            ->first(fn (array $message): bool => ($message['role'] ?? '') === 'assistant')['pending_form'] ?? null;

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
     * request de envio y una llamada HTTP extra atrasaria TODA respuesta.
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
        $action = $reply->action;

        // export_csv necesita las filas de la ultima tabla mostrada: se
        // capturan aqui, en el momento de la propuesta, para que la
        // confirmacion exporte exactamente lo que el usuario vio.
        if (is_array($action) && ($action['type'] ?? '') === 'export_csv'
            && ! isset($action['params']['tables'])) {
            $action['params']['tables'] = $this->lastTablesForExport();
        }

        $this->messages[] = $this->persist('assistant', $reply->text, [
            'data' => $reply->data,
            'action' => $action,
            'redirect' => $reply->redirect,
            'suggestions' => $reply->suggestions,
            'intent' => $reply->intent,
            'sections' => $reply->sections,
            'pending_form' => $reply->pendingForm,
            'badge_colors' => $reply->badgeColors,
            'options' => $reply->options,
        ]);

        $this->pendingForm = $reply->pendingForm;

        if (! $this->open) {
            $this->hasUnread = true;
        }
    }

    /**
     * Tablas del ultimo mensaje del asistente con datos, en el formato que
     * espera el exportador ({headers, rows}). Recorre secciones batch.
     *
     * @return list<array{headers: list<string>, rows: list<array<string, mixed>>}>
     */
    private function lastTablesForExport(): array
    {
        $tables = [];

        for ($i = count($this->messages) - 1; $i >= 0; $i--) {
            $message = $this->messages[$i] ?? null;

            if (($message['role'] ?? '') !== 'assistant') {
                continue;
            }

            $sources = [];

            foreach ($message['sections'] ?? [] as $section) {
                if (is_array($section['data'] ?? null)) {
                    $sources[] = $section['data'];
                }
            }

            if (is_array($message['data'] ?? null)) {
                $sources[] = $message['data'];
            }

            foreach ($sources as $data) {
                foreach ($data as $key => $rows) {
                    if (! is_array($rows) || $rows === [] || ! isset($rows[0]) || ! is_array($rows[0])) {
                        continue;
                    }

                    $tables[] = [
                        'key' => (string) $key,
                        'headers' => array_keys($rows[0]),
                        'rows' => array_values(array_slice($rows, 0, 500)),
                    ];
                }
            }

            // Solo la respuesta mas reciente: exportar tablas de hace tres
            // mensajes seria sorprender al usuario.
            break;
        }

        return $tables;
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
        // Las tablas nunca muestran ids internos: el usuario se refiere a
        // las entidades por nombre o numero de factura, no por PK.
        $payload['data'] = $this->stripIdColumns($payload['data'] ?? null);

        foreach ($payload['sections'] ?? [] as $i => $section) {
            $payload['sections'][$i]['data'] = $this->stripIdColumns($section['data'] ?? null);
        }

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
            'action_cancelled' => false,
            'resolved_action' => null,
            'redirect' => $payload['redirect'],
            'suggestions' => $payload['suggestions'],
            'intent' => $payload['intent'] ?? null,
            'sections' => $payload['sections'] ?? null,
            'pending_form' => $payload['pending_form'] ?? null,
            'badge_colors' => $payload['badge_colors'] ?? null,
            'options' => $payload['options'] ?? [],
            'table_count' => $this->countTables($payload),
            'at' => $stored->created_at?->toIso8601String(),
        ];
    }

    /**
     * Quita columnas `id` y `*_id` de todas las tablas del payload. Los
     * ids siguen existiendo en el action.params para ejecutar la accion;
     * solo se ocultan de lo que el usuario ve.
     *
     * @param  array<string, mixed>|null  $data
     * @return array<string, mixed>|null
     */
    private function stripIdColumns(?array $data): ?array
    {
        if ($data === null) {
            return null;
        }

        foreach ($data as $key => $rows) {
            if (! is_array($rows) || $rows === [] || ! isset($rows[0]) || ! is_array($rows[0])) {
                continue;
            }

            // array_filter conserva las claves originales: hay que
            // reindexar con array_values o $visible quedaria [0,1,2..]
            // y array_intersect_key vaciaria todas las filas.
            $visible = array_values(array_filter(
                array_keys($rows[0]),
                fn (string $column): bool => $column !== 'id' && ! str_ends_with($column, '_id'),
            ));

            $data[$key] = array_map(
                fn (array $row): array => array_intersect_key($row, array_flip($visible)),
                $rows,
            );
        }

        return $data;
    }

    /**
     * Marca la accion de un mensaje como resuelta, en memoria y en BD.
     */
    private function resolveAction(int $index, bool $cancelled = false): void
    {
        $message = $this->messages[$index] ?? null;

        if ($message === null) {
            return;
        }

        // La accion se conserva como `resolved_action` para que la tarjeta
        // "Accion completada" pueda mostrar que tipo de operacion fue y
        // que cambios aplico, en vez de un simple check. `action_cancelled`
        // distingue la cancelada: se muestra como tarjeta neutra, no como
        // si el cambio se hubiera aplicado.
        $resolvedAction = $message['action'] ?? null;

        $this->messages[$index]['action'] = null;
        $this->messages[$index]['resolved_action'] = $resolvedAction;
        $this->messages[$index]['action_resolved'] = true;
        $this->messages[$index]['action_cancelled'] = $cancelled;

        $stored = isset($message['id'])
            ? AssistantMessage::query()->whereKey($message['id'])->where('user_id', $this->userId())->first()
            : null;

        if ($stored !== null) {
            $stored->payload = array_merge($stored->payload ?? [], [
                'action' => null,
                'resolved_action' => $resolvedAction,
                'action_resolved' => true,
                'action_cancelled' => $cancelled,
            ]);
            $stored->save();
        }
    }

    /**
     * Vuelca el resultado de una accion confirmada/cancelada sobre el
     * MISMO mensaje que la propuso: texto, datos (p.ej. el archivo
     * generado), sugerencias y badges se fusionan con lo que ya tenia
     * la burbuja y se persisten en el AssistantMessage original.
     * Si el resultado propone otra accion, queda como `action` nueva.
     */
    private function applyReplyToMessage(int $index, AssistantReply $reply, bool $keepText = false): void
    {
        $message = $this->messages[$index] ?? null;

        if ($message === null) {
            return;
        }

        $data = $this->stripIdColumns(array_merge(
            is_array($message['data'] ?? null) ? $message['data'] : [],
            is_array($reply->data) ? $reply->data : [],
        ));

        $suggestions = $reply->suggestions !== []
            ? $reply->suggestions
            : ($message['suggestions'] ?? []);

        $badgeColors = array_merge(
            is_array($message['badge_colors'] ?? null) ? $message['badge_colors'] : [],
            is_array($reply->badgeColors) ? $reply->badgeColors : [],
        );

        if (! $keepText) {
            $this->messages[$index]['text'] = $reply->text;
        }
        $this->messages[$index]['data'] = $data === [] ? null : $data;
        $this->messages[$index]['suggestions'] = $suggestions;
        $this->messages[$index]['badge_colors'] = $badgeColors === [] ? null : $badgeColors;
        $this->messages[$index]['redirect'] = $reply->redirect ?? ($message['redirect'] ?? null);

        if ($reply->action !== null) {
            $this->messages[$index]['action'] = $reply->action;
        }

        if ($reply->sections !== null) {
            $this->messages[$index]['sections'] = $reply->sections;
        }

        $this->pendingForm = $reply->pendingForm;

        $stored = isset($message['id'])
            ? AssistantMessage::query()->whereKey($message['id'])->where('user_id', $this->userId())->first()
            : null;

        if ($stored !== null) {
            if (! $keepText) {
                $stored->text = $reply->text;
            }
            $stored->payload = array_merge($stored->payload ?? [], [
                'data' => $this->messages[$index]['data'],
                'suggestions' => $suggestions,
                'badge_colors' => $this->messages[$index]['badge_colors'],
                'redirect' => $this->messages[$index]['redirect'],
                'action' => $reply->action,
                'sections' => $reply->sections ?? ($stored->payload['sections'] ?? null),
                'pending_form' => $reply->pendingForm,
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
