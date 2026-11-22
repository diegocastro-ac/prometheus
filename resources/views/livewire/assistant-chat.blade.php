{{-- Copiloto del arrendador: FAB + drawer lateral derecho, visible en todo
     el panel. Multi-chat persistente, carga perezosa del historial, chips
     contextuales segun la pantalla, tablas de datos reales, tarjetas de
     accion con confirmacion y sugerencias solo en el ultimo mensaje. --}}
<div
    x-data="{
        drawerOpen: false,
        helpOpen: false,
        modelOpen: false,
        // Vista interna del drawer ('chat' | 'chats'). La controla Alpine
        // para que cambiar de panel sea instantaneo; el servidor la refleja
        // en `view` y el watcher la resincroniza tras cada respuesta.
        chatView: @js($view),
        // Mensajes del usuario en espera de respuesta: se pintan al instante
        // (echo optimista) y se reemplazan por el mensaje real cuando llega
        // la respuesta de Livewire, sin esperar el roundtrip para verlos.
        pendingMsgs: [],
        sending: false,
        // Ancho del drawer en px (null = default del CSS). Solo se usa en
        // escritorio (lg+); el maximo es la mitad del viewport.
        drawerWidth: null,
        resizing: false,
        resizeStartX: 0,
        resizeStartWidth: 0,
        startResize(event) {
            if (window.innerWidth < 1024) return;
            this.resizing = true;
            this.resizeStartX = event.clientX;
            this.resizeStartWidth = this.drawerWidth ?? (this.$refs.drawer?.offsetWidth || 448);
            document.body.style.userSelect = 'none';
            event.preventDefault();
        },
        resizeMove(event) {
            if (! this.resizing) return;
            const delta = this.resizeStartX - event.clientX;
            this.drawerWidth = Math.round(Math.min(
                window.innerWidth * 0.5,
                Math.max(340, this.resizeStartWidth + delta),
            ));
        },
        endResize() {
            if (! this.resizing) return;
            this.resizing = false;
            document.body.style.userSelect = '';
        },
        // true cuando el scroll esta pegado al fondo; la flecha de bajada
        // solo aparece si el usuario subio a leer historial.
        atBottom: true,
        scrollToBottom() {
            this.$nextTick(() => {
                const el = this.$refs.messagesScroll;
                if (el) el.scrollTop = el.scrollHeight;
            });
        },
        updateAtBottom() {
            const el = this.$refs.messagesScroll;
            if (! el) return;
            this.atBottom = el.scrollHeight - el.scrollTop - el.clientHeight < 80;
        },
        scrollDown() {
            const el = this.$refs.messagesScroll;
            if (el) el.scrollTo({ top: el.scrollHeight, behavior: 'smooth' });
        },
        async echoAndSend(text, call, restoreInput = null) {
            if (this.sending || ! text.trim()) return;
            this.sending = true;
            this.pendingMsgs.push(text.trim());
            this.atBottom = true;
            this.scrollToBottom();
            try {
                await call();
            } catch (e) {
                // Fallo de red o del request: se devuelve el texto al input
                // para que el usuario no lo pierda y pueda reintentar.
                if (restoreInput) restoreInput();
            } finally {
                this.pendingMsgs = [];
                this.sending = false;
                this.scrollToBottom();
            }
        },
        sendNow() {
            const text = (this.$wire.input ?? '').trim();
            if (! text) return Promise.resolve();
            const input = this.$refs.chatInput;
            // El textarea se vacia en el instante del envio, sin esperar la
            // respuesta; el servidor confirma el vaciado al responder y, si
            // el request falla, restoreInput devuelve el texto.
            if (input) {
                input.value = '';
                input.style.height = 'auto';
            }
            return this.echoAndSend(text, () => this.$wire.send(), () => {
                if (! input) return;
                input.value = text;
                input.dispatchEvent(new Event('input', { bubbles: true }));
                input.style.height = 'auto';
                input.style.height = Math.min(input.scrollHeight, 160) + 'px';
            });
        },
        sendSuggestion(text) {
            return this.echoAndSend(text, () => this.$wire.askSuggestion(text));
        },
        toggleDrawer() {
            this.drawerOpen = ! this.drawerOpen;
            // La llamada a Livewire va en segundo plano: la animacion del
            // drawer no espera respuesta del servidor.
            this.drawerOpen ? this.$wire.openDrawer() : this.$wire.closeDrawer();
        },
        closeDrawer() {
            this.drawerOpen = false;
            this.chatView = 'chat';
            this.$wire.closeDrawer();
        },
        init() {
            // Al enviar, Livewire limpia `input` por morph (sin evento input):
            // hay que devolver el textarea a una linea manualmente.
            this.$wire.$watch('input', (value) => {
                if (value === '' && this.$refs.chatInput) {
                    this.$refs.chatInput.style.height = 'auto';
                }
            });
            // Si el servidor cambio `view` (selectChat, newChat, etc.) el
            // panel visible se ajusta solo.
            this.$wire.$watch('view', (value) => {
                this.chatView = value;
            });
        },
    }"
    x-on:keydown.escape.window="drawerOpen && closeDrawer()"
    x-on:assistant-close.window="closeDrawer()"
    x-on:mousemove.window="resizeMove($event)"
    x-on:mouseup.window="endResize()"
    x-on:resize.window="window.innerWidth < 1024 && (drawerWidth = null)"
    class="pointer-events-none fixed inset-0 z-40"
>
    {{-- FAB --}}
    <button
        type="button"
        x-on:click="toggleDrawer()"
        wire:key="assistant-fab"
        aria-label="{{ __('assistant.open_label') }}"
        class="pointer-events-auto group fixed bottom-6 right-6 z-40 flex items-center gap-2 rounded-full bg-primary-600 py-3 pl-4 pr-5 text-white shadow-lg shadow-primary-600/30 transition hover:bg-primary-500 hover:shadow-xl focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 dark:focus:ring-offset-gray-900"
    >
        <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H8.25m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H12m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.76 9.76 0 0 1-2.555-.337A5.97 5.97 0 0 1 5.41 20.97a5.97 5.97 0 0 1-.474-.065 4.48 4.48 0 0 0 .978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25Z" />
        </svg>
        <span class="hidden text-sm font-semibold sm:inline">{{ __('assistant.fab_label') }}</span>
        @if ($hasUnread)
            <span class="absolute -right-0.5 -top-0.5 flex h-4 w-4">
                <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-red-400 opacity-75"></span>
                <span class="relative inline-flex h-4 w-4 items-center justify-center rounded-full bg-red-500 text-[10px] font-bold text-white ring-2 ring-white dark:ring-gray-900"></span>
            </span>
        @endif
    </button>

    {{-- Backdrop: solo existe cuando el drawer esta abierto para no robar
         clicks a la pagina. La clase la controla Alpine al instante. --}}
    <div
        x-show="drawerOpen"
        x-transition:enter="transition-opacity duration-200"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition-opacity duration-150"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        x-on:click="closeDrawer()"
        class="pointer-events-auto fixed inset-0 z-40 bg-gray-950/40 backdrop-blur-[2px]"
        aria-hidden="true"
    ></div>

    {{-- Drawer --}}
    <aside
        x-show="drawerOpen"
        x-ref="drawer"
        :style="drawerWidth ? 'width: ' + drawerWidth + 'px; max-width: ' + drawerWidth + 'px' : ''"
        x-transition:enter="transition-transform duration-300 ease-out"
        x-transition:enter-start="translate-x-full"
        x-transition:enter-end="translate-x-0"
        x-transition:leave="transition-transform duration-200 ease-in"
        x-transition:leave-start="translate-x-0"
        x-transition:leave-end="translate-x-full"
        class="pointer-events-auto fixed inset-y-0 right-0 z-50 flex w-full flex-col bg-white shadow-2xl sm:max-w-md dark:bg-gray-900"
        role="dialog"
        aria-modal="true"
        aria-label="{{ __('assistant.title') }}"
    >
        {{-- Handle de redimensionado: arrastrar el borde izquierdo del
             drawer. Solo escritorio (lg+); el ancho maximo es 50vw. --}}
        <div
            x-on:mousedown="startResize($event)"
            title="{{ __('assistant.resize') }}"
            class="absolute inset-y-0 left-0 z-30 hidden w-1.5 cursor-col-resize transition-colors hover:bg-primary-500/40 lg:block"
            :class="resizing && 'bg-primary-500/60'"
        ></div>

        {{-- Header --}}
        <header class="flex items-center justify-between gap-3 border-b border-gray-200 px-4 py-3 dark:border-white/10">
            <div class="flex min-w-0 items-center gap-3">
                <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-primary-600 text-white">
                    <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 0 0-2.456 2.456Z" />
                    </svg>
                </div>
                <div class="min-w-0">
                    <h2 class="truncate text-sm font-bold text-gray-900 dark:text-white">
                        <span wire:ignore.self x-show="chatView === 'chat'">{{ __('assistant.title') }}</span>
                        <span wire:ignore.self x-show="chatView === 'chats'">{{ __('assistant.chats_title') }}</span>
                    </h2>
                    <p class="truncate text-xs text-gray-500 dark:text-gray-400">{{ __('assistant.subtitle') }}</p>
                </div>
            </div>
            <div class="flex shrink-0 items-center gap-1">
                {{-- Ambos grupos siempre en el DOM: x-show los alterna al
                     instante y wire:ignore.self evita que el morph de
                     Livewire pise el display que pone Alpine. --}}
                <div wire:ignore.self x-show="chatView === 'chats'" class="flex items-center gap-1">
                    <button
                        type="button"
                        x-on:click="chatView = 'chat'; $wire.backToChat()"
                        title="{{ __('assistant.back_to_chat') }}"
                        class="rounded-lg p-2 text-gray-400 transition hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-white/10 dark:hover:text-gray-200"
                    >
                        <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" />
                        </svg>
                    </button>
                </div>
                <div wire:ignore.self x-show="chatView === 'chat'" class="flex items-center gap-1">
                    {{-- Selector de modelo del copiloto: los 3 modelos
                         gratuitos de Gemini con su uso recomendado. El
                         cambio aplica en tiempo real al proximo mensaje. --}}
                    <div class="relative">
                        <button
                            type="button"
                            x-on:click="modelOpen = ! modelOpen"
                            title="{{ __('assistant.model.title') }}"
                            class="rounded-lg p-2 transition"
                            :class="modelOpen ? 'bg-primary-100 text-primary-600 dark:bg-primary-500/20 dark:text-primary-400' : 'text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-white/10 dark:hover:text-gray-200'"
                        >
                            <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 3v1.5M4.5 8.25H3m18 0h-1.5M4.5 12H3m18 0h-1.5m-15 3.75H3m18 0h-1.5M8.25 19.5V21M12 3v1.5m0 15V21m3.75-18v1.5m0 15V21m-9-1.5h10.5a2.25 2.25 0 0 0 2.25-2.25V6.75a2.25 2.25 0 0 0-2.25-2.25H6.75A2.25 2.25 0 0 0 4.5 6.75v10.5a2.25 2.25 0 0 0 2.25 2.25Zm.75-12h9v9h-9v-9Z" />
                            </svg>
                        </button>
                        <div
                            x-show="modelOpen"
                            x-transition:enter="transition ease-out duration-150"
                            x-transition:enter-start="opacity-0 -translate-y-1"
                            x-transition:enter-end="opacity-100 translate-y-0"
                            x-on:click.outside="modelOpen = false"
                            class="absolute right-0 top-full z-30 mt-1 w-72 overflow-hidden rounded-xl bg-white shadow-xl ring-1 ring-gray-950/10 dark:bg-gray-800 dark:ring-white/10"
                        >
                            <p class="border-b border-gray-100 px-3 py-2 text-[11px] font-bold uppercase tracking-wider text-gray-400 dark:border-white/10 dark:text-gray-500">
                                {{ __('assistant.model.title') }}
                            </p>
                            <div class="max-h-80 overflow-y-auto p-1.5">
                                @php $activeModel = $assistantModel ?: config('services.gemini.model'); @endphp
                                @foreach ($assistantModels as $modelIndex => $modelId)
                                    @php $isActive = $activeModel === $modelId; @endphp
                                    <button
                                        type="button"
                                        x-on:click="modelOpen = false; $wire.setAssistantModel('{{ $modelId }}')"
                                        @class([
                                            'flex w-full items-start gap-2.5 rounded-lg px-2.5 py-2 text-left transition',
                                            'bg-primary-50 dark:bg-primary-500/10' => $isActive,
                                            'hover:bg-gray-50 dark:hover:bg-white/5' => ! $isActive,
                                        ])
                                    >
                                        <span @class([
                                            'mt-0.5 flex h-4 w-4 shrink-0 items-center justify-center rounded-full border-2 transition',
                                            'border-primary-600 bg-primary-600' => $isActive,
                                            'border-gray-300 dark:border-gray-600' => ! $isActive,
                                        ])>
                                            @if ($isActive)
                                                <svg class="h-2.5 w-2.5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="3" stroke="currentColor" aria-hidden="true">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                                                </svg>
                                            @endif
                                        </span>
                                        <span class="min-w-0 flex-1">
                                            <span class="flex items-center gap-1.5">
                                                <span class="truncate text-xs font-semibold text-gray-800 dark:text-gray-100">{{ __("assistant.models.{$modelIndex}.name") }}</span>
                                                <span class="shrink-0 rounded-full bg-gray-100 px-1.5 py-0.5 text-[9px] font-bold uppercase tracking-wide text-gray-500 dark:bg-white/10 dark:text-gray-400">{{ __("assistant.models.{$modelIndex}.tag") }}</span>
                                            </span>
                                            <span class="mt-0.5 block text-[10px] leading-snug text-gray-500 dark:text-gray-400">{{ __("assistant.models.{$modelIndex}.use") }}</span>
                                        </span>
                                    </button>
                                @endforeach
                            </div>
                            <p class="border-t border-gray-100 px-3 py-2 text-[10px] leading-snug text-gray-400 dark:border-white/10 dark:text-gray-500">
                                {{ __('assistant.model.fallback_note') }}
                            </p>
                        </div>
                    </div>
                    <button
                        type="button"
                        x-on:click="chatView = 'chats'; $wire.showChats()"
                        title="{{ __('assistant.chats_title') }}"
                        class="rounded-lg p-2 text-gray-400 transition hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-white/10 dark:hover:text-gray-200"
                    >
                        <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H8.25m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H12m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.76 9.76 0 0 1-2.555-.337A5.97 5.97 0 0 1 5.41 20.97a5.97 5.97 0 0 1-.474-.065 4.48 4.48 0 0 0 .978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25Z" />
                        </svg>
                    </button>
                    <button
                        type="button"
                        x-on:click="chatView = 'chat'"
                        wire:click="newChat"
                        title="{{ __('assistant.new_chat') }}"
                        class="rounded-lg p-2 text-gray-400 transition hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-white/10 dark:hover:text-gray-200"
                    >
                        <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                        </svg>
                    </button>
                </div>
                <button
                    type="button"
                    x-on:click="closeDrawer()"
                    title="{{ __('assistant.close') }}"
                    class="rounded-lg p-2 text-gray-400 transition hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-white/10 dark:hover:text-gray-200"
                >
                    <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        </header>

        {{-- Ambos paneles viven siempre en el DOM y Alpine decide cual se
             ve (chatView): el cambio de vista es instantaneo, sin esperar
             el roundtrip de Livewire. wire:ignore.self evita que el morph
             pise el display que pone x-show; el contenido interno si se
             actualiza con cada respuesta. --}}
        <div
            wire:ignore.self
            x-show="chatView === 'chats'"
            class="flex-1 overflow-y-auto bg-gray-50 px-3 py-3 dark:bg-gray-950/60"
        >
                {{-- Historial de conversaciones --}}
                <button
                    type="button"
                    x-on:click="chatView = 'chat'"
                    wire:click="newChat"
                    class="mb-3 flex w-full items-center justify-center gap-2 rounded-xl border-2 border-dashed border-gray-300 px-4 py-3 text-sm font-semibold text-gray-500 transition hover:border-primary-400 hover:text-primary-600 dark:border-white/15 dark:text-gray-400 dark:hover:border-primary-500 dark:hover:text-primary-400"
                >
                    <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                    </svg>
                    {{ __('assistant.new_chat') }}
                </button>

                @if (! $chatsLoaded)
                    {{-- Skeleton: la seccion se muestra de una vez y los
                         datos llegan con el request fetchChats. --}}
                    <div class="space-y-1.5">
                        @foreach ([1, 2, 3, 4] as $i)
                            <div class="flex items-center gap-3 rounded-xl px-2 py-2.5">
                                <div class="h-8 w-8 animate-pulse rounded-lg bg-gray-200 dark:bg-white/10"></div>
                                <div class="flex-1 space-y-1.5">
                                    <div class="h-3 w-2/3 animate-pulse rounded bg-gray-200 dark:bg-white/10"></div>
                                    <div class="h-2 w-1/3 animate-pulse rounded bg-gray-200 dark:bg-white/5"></div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                <div class="space-y-1.5">
                    @forelse ($chats as $chat)
                        <div
                            wire:key="chat-{{ $chat['id'] }}"
                            @class([
                                'group flex items-center gap-1 rounded-xl px-2 py-2 transition',
                                'bg-primary-50 ring-1 ring-primary-600/20 dark:bg-primary-500/10 dark:ring-primary-400/30' => $chat['id'] === $activeChatId,
                                'hover:bg-gray-100 dark:hover:bg-white/5' => $chat['id'] !== $activeChatId,
                            ])
                        >
                            <button
                                type="button"
                                x-on:click="chatView = 'chat'"
                                wire:click="selectChat({{ $chat['id'] }})"
                                class="flex min-w-0 flex-1 items-center gap-3 text-left"
                            >
                                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-gray-200 text-gray-500 dark:bg-white/10 dark:text-gray-400">
                                    <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M7.5 8.25h9m-9 3H12m-9.75 1.51c0 1.6 1.123 2.994 2.707 3.227 1.129.166 2.27.293 3.423.379.35.026.67.21.865.501L12 21l2.755-4.133a1.14 1.14 0 0 1 .865-.501 48.172 48.172 0 0 0 3.423-.379c1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0 0 12 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018Z" />
                                    </svg>
                                </span>
                                <span class="min-w-0">
                                    <span class="block truncate text-sm font-medium text-gray-800 dark:text-gray-100">{{ $chat['title'] }}</span>
                                    @if ($chat['updated_at'])
                                        <span class="block text-[11px] text-gray-400 dark:text-gray-500">{{ $chat['updated_at'] }}</span>
                                    @endif
                                </span>
                            </button>
                            <button
                                type="button"
                                x-data
                                x-on:click="if (confirm(@js(__('assistant.delete_chat_confirm')))) { $wire.deleteChat({{ $chat['id'] }}) }"
                                title="{{ __('assistant.delete_chat') }}"
                                class="rounded-lg p-1.5 text-gray-300 opacity-0 transition hover:bg-red-50 hover:text-red-500 group-hover:opacity-100 dark:text-gray-600 dark:hover:bg-red-500/10 dark:hover:text-red-400"
                            >
                                <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                                </svg>
                            </button>
                        </div>
                    @empty
                        <p class="px-2 py-6 text-center text-sm text-gray-400 dark:text-gray-500">{{ __('assistant.no_chats') }}</p>
                    @endforelse
                </div>
                @endif
            </div>

            {{-- Conversacion --}}
            <div
                wire:ignore.self
                x-show="chatView === 'chat'"
                class="relative flex min-h-0 flex-1 flex-col"
            >
            <div
                x-ref="messages"
                x-ref="messagesScroll"
                x-init="$nextTick(() => { $el.scrollTop = $el.scrollHeight })"
                x-on:assistant-scroll.window="$nextTick(() => { $el.scrollTop = $el.scrollHeight })"
                x-on:scroll.passive="updateAtBottom()"
                class="flex-1 space-y-4 overflow-y-auto bg-gray-50 px-4 py-4 dark:bg-gray-950/60"
            >
                @php
                    $lastAssistantIndex = -1;
                    foreach ($messages as $i => $m) {
                        if ($m['role'] === 'assistant') {
                            $lastAssistantIndex = $i;
                        }
                    }
                @endphp

                @if (! $messagesLoaded)
                    {{-- Skeleton mientras carga el historial (drawer recien
                         abierto): la apertura se siente instantanea. --}}
                    <div class="space-y-4">
                        @foreach ([1, 2, 3] as $i)
                            <div @class(['flex', 'justify-end' => $i % 2 === 0, 'justify-start' => $i % 2 === 1])>
                                <div class="h-12 w-2/3 animate-pulse rounded-2xl bg-gray-200 dark:bg-white/10"></div>
                            </div>
                        @endforeach
                    </div>
                @else
                    @forelse ($messages as $index => $message)
                        <div
                            wire:key="msg-{{ $message['id'] ?? $index }}"
                            class="assistant-msg flex {{ $message['role'] === 'user' ? 'justify-end' : 'justify-start' }}"
                        >
                            <div @class([
                                'relative max-w-[85%] rounded-2xl px-4 py-3 text-sm shadow-sm',
                                'rounded-br-md bg-primary-600 text-white' => $message['role'] === 'user',
                                'rounded-bl-md bg-white text-gray-800 ring-1 ring-gray-950/5 dark:bg-gray-800 dark:text-gray-100 dark:ring-white/10' => $message['role'] === 'assistant',
                            ])>
                                {{-- Respuestas batch: cada seccion lleva su texto
                                     seguido de sus tablas, en el orden en que el
                                     usuario pidio las cosas. --}}
                                @if (! empty($message['sections']))
                                    @foreach ($message['sections'] as $section)
                                        @if (! empty($section['text']))
                                            <p class="whitespace-pre-line leading-relaxed {{ $loop->first ? '' : 'mt-3' }}">{{ $section['text'] }}</p>
                                        @endif
                                        @include('livewire.partials.assistant-tables', ['data' => $section['data'] ?? null, 'messageIndex' => $index])
                                    @endforeach
                                @else
                                    <p class="whitespace-pre-line leading-relaxed">{{ $message['text'] }}</p>

                                    {{-- Datos estructurados: tabla compacta cuando el
                                         intent devolvio filas. --}}
                                    @include('livewire.partials.assistant-tables', ['data' => $message['data'] ?? null, 'messageIndex' => $index])
                                @endif

                                {{-- Accion propuesta: la IA propone, el humano confirma. --}}
                                @if (! empty($message['action']))
                                    <div class="mt-3 rounded-xl border border-amber-300 bg-amber-50 p-3 text-gray-900 dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-100">
                                        <p class="flex items-center gap-1.5 text-xs font-bold uppercase tracking-wide">
                                            <svg class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.008v.008H12v-.008Z" />
                                            </svg>
                                            {{ __('assistant.action_proposed') }}
                                        </p>
                                        <p class="mt-1.5 text-sm font-medium">{{ $message['action']['summary'] ?? '' }}</p>
                                        <div class="mt-2.5">
                                            <button
                                                type="button"
                                                wire:click="previewAction({{ $index }})"
                                                class="inline-flex items-center gap-1.5 rounded-lg bg-white px-3 py-1.5 text-xs font-semibold text-gray-700 ring-1 ring-gray-300 transition hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:ring-white/15 dark:hover:bg-gray-700"
                                            >
                                                <svg class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                                </svg>
                                                {{ __('assistant.preview.action_title') }}
                                            </button>
                                        </div>
                                    </div>
                                @elseif (($message['action_resolved'] ?? false) === true)
                                    <p class="mt-2 flex items-center gap-1 text-xs italic text-gray-400 dark:text-gray-500">
                                        <svg class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                                        </svg>
                                        {{ __('assistant.action_resolved') }}
                                    </p>
                                @endif

                                {{-- Acciones del mensaje: "ver todas las tablas"
                                     y "ir a la seccion" conviven en la misma
                                     fila y se apilan solos si no hay espacio. --}}
                                @if (($message['table_count'] ?? 0) > 1 || ! empty($message['redirect']))
                                    <div class="mt-3 flex flex-wrap items-center gap-2">
                                        @if (($message['table_count'] ?? 0) > 1)
                                            <button
                                                type="button"
                                                wire:click="previewAllData({{ $index }})"
                                                class="inline-flex items-center gap-1.5 rounded-lg bg-gray-100 px-2.5 py-1.5 text-[11px] font-semibold text-gray-600 transition hover:bg-primary-100 hover:text-primary-700 dark:bg-white/10 dark:text-gray-300 dark:hover:bg-primary-500/20 dark:hover:text-primary-300"
                                            >
                                                <svg class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 4.5v15m6-15v15m-10.875 0h15.75c.621 0 1.125-.504 1.125-1.125V5.625c0-.621-.504-1.125-1.125-1.125H4.125C3.504 4.5 3 5.004 3 5.625v12.75c0 .621.504 1.125 1.125 1.125Z" />
                                                </svg>
                                                {{ __('assistant.preview.view_all_tables', ['count' => $message['table_count']]) }}
                                            </button>
                                        @endif
                                        @if (! empty($message['redirect']))
                                            <button
                                                type="button"
                                                wire:click="goTo('{{ $message['redirect'] }}')"
                                                class="inline-flex items-center gap-1.5 rounded-lg bg-primary-50 px-3 py-1.5 text-xs font-semibold text-primary-700 ring-1 ring-primary-600/20 transition hover:bg-primary-100 dark:bg-primary-500/10 dark:text-primary-300 dark:ring-primary-400/30 dark:hover:bg-primary-500/20"
                                            >
                                                {{ __('assistant.go_to') }}
                                                <svg class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" />
                                                </svg>
                                            </button>
                                        @endif
                                    </div>
                                @endif

                                {{-- Sugerencias: solo en el ultimo mensaje del
                                     asistente para no saturar el historial. --}}
                                @if (! empty($message['suggestions']) && $index === $lastAssistantIndex)
                                    <div class="mt-3 flex flex-wrap gap-1.5">
                                        @foreach ($message['suggestions'] as $suggestion)
                                            <button
                                                type="button"
                                                x-on:click="sendSuggestion('{{ addslashes($suggestion) }}')"
                                                class="rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-600 transition hover:bg-primary-100 hover:text-primary-700 dark:bg-white/10 dark:text-gray-300 dark:hover:bg-primary-500/20 dark:hover:text-primary-300"
                                            >
                                                {{ $suggestion }}
                                            </button>
                                        @endforeach
                                    </div>
                                @endif

                                @if ($message['role'] === 'assistant')
                                    <button
                                        type="button"
                                        x-data="{ copied: false }"
                                        x-on:click="navigator.clipboard.writeText(@js($message['text'])).then(() => { copied = true; setTimeout(() => copied = false, 1500) })"
                                        title="{{ __('assistant.copy') }}"
                                        class="absolute -bottom-2 left-3 flex h-6 w-6 items-center justify-center rounded-full bg-white text-gray-400 shadow-sm ring-1 ring-gray-950/10 transition hover:text-primary-600 dark:bg-gray-700 dark:text-gray-400 dark:ring-white/10 dark:hover:text-primary-400"
                                    >
                                        <svg x-show="!copied" class="h-3 w-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.666 3.888A2.25 2.25 0 0 0 13.5 2.25h-3c-1.03 0-1.9.693-2.166 1.638m7.332 0c.055.194.084.4.084.612v0a.75.75 0 0 1-.75.75H9.75a.75.75 0 0 1-.75-.75v0c0-.212.03-.418.084-.612m7.332 0c.646.049 1.288.11 1.927.184 1.1.128 1.907 1.077 1.907 2.185V19.5a2.25 2.25 0 0 1-2.25 2.25H6.75A2.25 2.25 0 0 1 4.5 19.5V6.257c0-1.108.806-2.057 1.907-2.185a48.208 48.208 0 0 1 1.927-.184" />
                                        </svg>
                                        <svg x-show="copied" x-cloak class="h-3 w-3 text-success-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                                        </svg>
                                    </button>
                                @endif

                                @if ($message['at'] ?? null)
                                    <p class="mt-1.5 {{ $message['role'] === 'assistant' ? 'pr-8' : '' }} text-right text-[10px] text-gray-400 dark:text-gray-500">
                                        {{ \Illuminate\Support\Carbon::parse($message['at'])->format('H:i') }}
                                    </p>
                                @endif
                            </div>
                        </div>
                    @empty
                        {{-- Estado vacio con sugerencias clicables. --}}
                        <div class="flex h-full flex-col items-center justify-center gap-4 py-8 text-center">
                            <div class="flex h-14 w-14 items-center justify-center rounded-2xl bg-primary-100 text-primary-600 dark:bg-primary-500/15 dark:text-primary-400">
                                <svg class="h-7 w-7" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H8.25m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H12m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.76 9.76 0 0 1-2.555-.337A5.97 5.97 0 0 1 5.41 20.97a5.97 5.97 0 0 1-.474-.065 4.48 4.48 0 0 0 .978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25Z" />
                                </svg>
                            </div>
                            <div>
                                <p class="text-sm font-semibold text-gray-700 dark:text-gray-200">{{ __('assistant.empty_title') }}</p>
                                <p class="mt-1 max-w-xs text-xs leading-relaxed text-gray-500 dark:text-gray-400">{{ __('assistant.empty_state') }}</p>
                            </div>
                            <div class="flex max-w-xs flex-wrap justify-center gap-1.5">
                                @foreach (['portfolio', 'overdue', 'analysis', 'tenants'] as $key)
                                    <button
                                        type="button"
                                        x-on:click="sendSuggestion('{{ addslashes(__("assistant.suggestions.{$key}")) }}')"
                                        class="rounded-full bg-white px-3 py-1.5 text-xs font-medium text-gray-600 shadow-sm ring-1 ring-gray-950/10 transition hover:bg-primary-50 hover:text-primary-700 hover:ring-primary-600/30 dark:bg-white/5 dark:text-gray-300 dark:ring-white/10 dark:hover:bg-primary-500/10 dark:hover:text-primary-300"
                                    >
                                        {{ __("assistant.suggestions.{$key}") }}
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    @endforelse

                    {{-- Eco optimista: el mensaje del usuario aparece al
                         instante mientras Gemini piensa; el mensaje real lo
                         reemplaza cuando llega la respuesta. --}}
                    <template x-for="(pendingText, pendingIndex) in pendingMsgs" :key="'pending-' + pendingIndex">
                        <div class="assistant-msg flex justify-end">
                            <div class="relative max-w-[85%] rounded-2xl rounded-br-md bg-primary-600 px-4 py-3 text-sm text-white shadow-sm">
                                <p class="whitespace-pre-line leading-relaxed" x-text="pendingText"></p>
                            </div>
                        </div>
                    </template>

                    {{-- Indicador de escritura: wire:loading lo muestra al
                         instante del clic, sin esperar la respuesta. --}}
                    <div
                        wire:loading
                        wire:target="send,askSuggestion"
                        class="flex justify-start"
                    >
                        <div class="flex items-center gap-1.5 rounded-2xl rounded-bl-md bg-white px-4 py-3.5 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-800 dark:ring-white/10">
                            <span class="assistant-dot h-1.5 w-1.5 rounded-full bg-primary-500"></span>
                            <span class="assistant-dot h-1.5 w-1.5 rounded-full bg-primary-500" style="animation-delay: 0.15s"></span>
                            <span class="assistant-dot h-1.5 w-1.5 rounded-full bg-primary-500" style="animation-delay: 0.3s"></span>
                        </div>
                    </div>
                @endif
            </div>

            {{-- Flecha "bajar al final": solo visible si el usuario subio
                 a leer mensajes anteriores. --}}
            <button
                type="button"
                x-show="! atBottom"
                x-transition:enter="transition ease-out duration-200"
                x-transition:enter-start="opacity-0 translate-y-2"
                x-transition:enter-end="opacity-100 translate-y-0"
                x-transition:leave="transition ease-in duration-150"
                x-transition:leave-start="opacity-100 translate-y-0"
                x-transition:leave-end="opacity-0 translate-y-2"
                x-on:click="scrollDown()"
                aria-label="{{ __('assistant.scroll_down') }}"
                class="absolute bottom-3 left-1/2 z-10 flex h-9 w-9 -translate-x-1/2 items-center justify-center rounded-full bg-white text-gray-500 shadow-lg ring-1 ring-gray-950/10 transition hover:bg-primary-50 hover:text-primary-600 dark:bg-gray-800 dark:text-gray-300 dark:ring-white/10 dark:hover:bg-primary-500/10 dark:hover:text-primary-300"
            >
                <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 13.5 12 21m0 0-7.5-7.5M12 21V3" />
                </svg>
            </button>
            </div>

        {{-- Input (solo visible en vista de chat) --}}
        <div
            wire:ignore.self
            x-show="chatView === 'chat'"
            class="border-t border-gray-200 bg-white px-3 pb-3 pt-2 dark:border-white/10 dark:bg-gray-900"
            style="padding-bottom: calc(0.75rem + env(safe-area-inset-bottom, 0px))"
        >
                {{-- Sugerencias contextuales + centro de ayuda: los chips
                     cambian por ruta del panel; el panel expandible tiene
                     categorias con todos los prompts que la IA entiende. --}}
                @php
                    $chipIcons = [
                            'portfolio' => 'M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z',
                            'overdue' => 'M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.008v.008H12v-.008Z',
                            'pending' => 'M21 12a2.25 2.25 0 0 0-2.25-2.25H15a3 3 0 1 1-6 0H5.25A2.25 2.25 0 0 0 3 12m18 0v6a2.25 2.25 0 0 1-2.25 2.25H5.25A2.25 2.25 0 0 1 3 18v-6m18 0V9M3 12V9m18 0a2.25 2.25 0 0 0-2.25-2.25H5.25A2.25 2.25 0 0 0 3 9m18 0V6a2.25 2.25 0 0 0-2.25-2.25H5.25A2.25 2.25 0 0 0 3 6v3',
                            'analysis' => 'M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 0 0-2.456 2.456Z',
                            'adjustments' => 'M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5',
                            'reminder' => 'M8.625 12a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H8.25m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H12m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.76 9.76 0 0 1-2.555-.337A5.97 5.97 0 0 1 5.41 20.97a5.97 5.97 0 0 1-.474-.065 4.48 4.48 0 0 0 .978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25Z',
                            'tenants' => 'M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z',
                            'rentals' => 'm2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25',
                            'properties' => 'M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 3h12m-.75 4.5H21m-3.75 3.75h.008v.008h-.008v-.008Zm0 3h.008v.008h-.008v-.008Zm0 3h.008v.008h-.008v-.008Z',
                            'invoices' => 'M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z',
                            'payments' => 'M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 0 0-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 0 1-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 0 0 3 15h-.75M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm3 0h.008v.008H18V10.5Zm-12 0h.008v.008H6V10.5Z',
                            'income' => 'M2.25 18 9 11.25l4.306 4.306a11.95 11.95 0 0 1 5.814-5.518l2.74-1.22m0 0-5.94-2.281m5.94 2.28-2.28 5.941',
                            'contracts' => 'M11.35 3.836c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m8.9-4.414c.376.023.75.05 1.124.08 1.131.094 1.976 1.057 1.976 2.192V16.5A2.25 2.25 0 0 1 18 18.75h-2.25m-7.5-10.5H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V18.75m-7.5-10.5h6.375c.621 0 1.125.504 1.125 1.125v9.375m-8.25-3 1.5 1.5 3-3.75',
                            'record_payment' => 'M12 9v6m3-3H9m12 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
                            'generate_invoices' => 'M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z',
                        ];
                    @endphp
                    <div class="mb-2">
                        <div class="mb-1.5 flex items-center justify-between px-0.5">
                            <p class="text-[10px] font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500">
                                {{ __('assistant.quick_prompts') }}
                            </p>
                            <button
                                type="button"
                                x-on:click="helpOpen = ! helpOpen"
                                :aria-expanded="helpOpen"
                                class="flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-semibold text-primary-600 transition hover:bg-primary-50 dark:text-primary-400 dark:hover:bg-primary-500/10"
                            >
                                <svg class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 5.25h.008v.008H12v-.008Z" />
                                </svg>
                                {{ __('assistant.help.toggle') }}
                                <svg class="h-3 w-3 transition-transform duration-200" :class="helpOpen && 'rotate-180'" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                                </svg>
                            </button>
                        </div>

                        @if (! empty($contextSuggestions))
                            <div class="flex gap-1.5 overflow-x-auto pb-0.5 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                                @foreach ($contextSuggestions as $key)
                                    <button
                                        type="button"
                                        x-on:click="sendSuggestion('{{ addslashes(__("assistant.suggestions.{$key}")) }}')"
                                        class="flex shrink-0 items-center gap-1.5 rounded-full bg-white py-1.5 pl-2.5 pr-3 text-xs font-medium text-gray-700 shadow-sm ring-1 ring-gray-950/10 transition hover:bg-primary-50 hover:text-primary-700 hover:ring-primary-600/30 dark:bg-white/5 dark:text-gray-300 dark:ring-white/10 dark:hover:bg-primary-500/10 dark:hover:text-primary-300"
                                    >
                                        <svg class="h-3.5 w-3.5 shrink-0 text-primary-500 dark:text-primary-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="{{ $chipIcons[$key] ?? $chipIcons['analysis'] }}" />
                                        </svg>
                                        {{ __("assistant.suggestions.{$key}") }}
                                    </button>
                                @endforeach
                            </div>
                        @endif

                        {{-- Centro de ayuda: categorias desplegables con todos
                             los prompts que la IA entiende. Cada opcion envia
                             el texto traducido como mensaje normal. --}}
                        <div
                            x-show="helpOpen"
                            x-transition:enter="transition ease-out duration-200"
                            x-transition:enter-start="opacity-0 -translate-y-1"
                            x-transition:enter-end="opacity-100 translate-y-0"
                            x-transition:leave="transition ease-in duration-150"
                            x-transition:leave-start="opacity-100"
                            x-transition:leave-end="opacity-0"
                            class="mt-2 max-h-72 overflow-y-auto rounded-xl bg-gray-50 p-2.5 ring-1 ring-gray-950/5 [scrollbar-width:thin] dark:bg-white/5 dark:ring-white/10"
                        >
                            <p class="px-1 pb-2 text-xs font-semibold text-gray-700 dark:text-gray-200">{{ __('assistant.help.title') }}</p>
                            <div class="grid gap-3 sm:grid-cols-2">
                                @foreach (__('assistant.help.categories') as $category)
                                    <div>
                                        <p class="mb-1.5 flex items-center gap-1.5 px-1 text-[10px] font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500">
                                            <svg class="h-3.5 w-3.5 shrink-0 text-primary-500 dark:text-primary-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="{{ $category['icon'] }}" />
                                            </svg>
                                            {{ $category['title'] }}
                                        </p>
                                        <div class="flex flex-col gap-0.5">
                                            @foreach ($category['items'] as $prompt)
                                                <button
                                                    type="button"
                                                    x-on:click="helpOpen = false; sendSuggestion('{{ addslashes($prompt) }}')"
                                                    class="rounded-lg px-2 py-1.5 text-left text-xs text-gray-600 transition hover:bg-white hover:text-primary-700 hover:shadow-sm dark:text-gray-300 dark:hover:bg-white/10 dark:hover:text-primary-300"
                                                >
                                                    {{ $prompt }}
                                                </button>
                                            @endforeach
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>

                <form x-on:submit.prevent="sendNow()" class="flex items-end gap-2">
                    <textarea
                        x-ref="chatInput"
                        wire:model="input"
                        rows="1"
                        placeholder="{{ __('assistant.placeholder') }}"
                        autocomplete="off"
                        x-on:input="$el.style.height = 'auto'; $el.style.height = Math.min($el.scrollHeight, 160) + 'px'"
                        x-on:keydown="if ($event.key === 'Enter' && ! $event.shiftKey) { $event.preventDefault(); $el.form.requestSubmit(); }"
                        class="max-h-40 min-h-[42px] flex-1 resize-none overflow-y-auto rounded-2xl border-0 bg-gray-100 px-4 py-2.5 text-sm text-gray-900 placeholder:text-gray-400 focus:bg-white focus:ring-2 focus:ring-primary-500 dark:bg-white/10 dark:text-white dark:placeholder:text-gray-500 dark:focus:bg-white/15"
                        wire:loading.attr="disabled"
                        wire:target="send,askSuggestion,confirmAction,cancelAction"
                    ></textarea>
                    <button
                        type="submit"
                        aria-label="{{ __('assistant.send') }}"
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-primary-600 text-white shadow-md transition hover:bg-primary-500 disabled:opacity-50"
                        wire:loading.attr="disabled"
                        wire:target="send,askSuggestion,confirmAction,cancelAction"
                    >
                        <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 12 3.269 3.125A59.769 59.769 0 0 1 21.485 12 59.768 59.768 0 0 1 3.27 20.875L5.999 12Zm0 0h7.5" />
                        </svg>
                    </button>
                </form>
                <p class="mt-1.5 px-1 text-center text-[10px] leading-tight text-gray-400 dark:text-gray-500">
                    {{ __('assistant.input_hint') }}
                </p>
                <p class="mt-0.5 px-1 text-center text-[10px] leading-tight text-gray-400 dark:text-gray-500">
                    {{ __('assistant.disclaimer') }}
                </p>
        </div>

        {{-- Modal de preview: acciones por confirmar o tablas expandidas.
             Vive DENTRO del drawer (absolute), asi no pelea con el z-index
             de modales de Filament ni se sale del contexto del chat. --}}
        @if ($preview !== null)
            <div class="absolute inset-0 z-20 flex flex-col bg-white dark:bg-gray-900">
                <header class="flex items-center justify-between gap-3 border-b border-gray-200 px-4 py-3 dark:border-white/10">
                    <h3 class="truncate text-sm font-bold text-gray-900 dark:text-white">
                        {{ $preview['kind'] === 'action' ? __('assistant.preview.action_title') : $preview['title'] }}
                    </h3>
                    <button
                        type="button"
                        wire:click="closePreview"
                        title="{{ __('assistant.close') }}"
                        class="rounded-lg p-2 text-gray-400 transition hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-white/10 dark:hover:text-gray-200"
                    >
                        <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                        </svg>
                    </button>
                </header>

                <div class="flex-1 overflow-y-auto px-4 py-4">
                    @if ($preview['kind'] === 'action')
                        <p class="mb-3 text-sm font-medium text-gray-700 dark:text-gray-300">{{ $preview['title'] }}</p>

                        <dl class="space-y-2.5 rounded-xl bg-gray-50 p-3 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                            @foreach ($preview['rows'] as $row)
                                @if ($row['section'] ?? false)
                                    <div class="pt-2 text-[11px] font-bold uppercase tracking-wider text-primary-600 first:pt-0 dark:text-primary-400">{{ $row['label'] }}</div>
                                @else
                                    <div class="flex items-start justify-between gap-3 text-sm {{ ($row['item'] ?? false) ? 'pl-3' : '' }}">
                                        <dt class="shrink-0 {{ ($row['item'] ?? false) ? 'text-gray-400 dark:text-gray-500' : 'text-gray-500 dark:text-gray-400' }}">{{ $row['label'] }}</dt>
                                        <dd class="text-right font-medium text-gray-900 dark:text-white">{{ $row['value'] }}</dd>
                                    </div>
                                @endif
                            @endforeach
                        </dl>

                        <p class="mt-3 flex items-start gap-1.5 text-xs leading-relaxed text-amber-700 dark:text-amber-400">
                            <svg class="mt-0.5 h-3.5 w-3.5 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                            </svg>
                            {{ __('assistant.preview.confirm_note') }}
                        </p>
                    @elseif ($preview['kind'] === 'data_all')
                        {{-- Todas las tablas del mensaje apiladas. --}}
                        <div class="space-y-5">
                            @foreach ($preview['tables'] as $table)
                                <div>
                                    <p class="mb-1.5 px-0.5 text-[11px] font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500">{{ $table['title'] }}</p>
                                    <div class="overflow-x-auto rounded-xl ring-1 ring-gray-950/10 dark:ring-white/10">
                                        <table class="w-full text-xs">
                                            <thead class="bg-gray-50 dark:bg-gray-800">
                                                <tr>
                                                    @foreach ($table['columns'] as $column)
                                                        <th class="px-2.5 py-2 text-left font-semibold text-gray-500 dark:text-gray-400">{{ $column }}</th>
                                                    @endforeach
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @foreach ($table['rows'] as $row)
                                                    <tr class="border-t border-gray-950/5 dark:border-white/5">
                                                        @foreach ($row as $cell)
                                                            <td class="px-2.5 py-1.5">{{ is_array($cell) && isset($cell[0]) && is_array($cell[0]) ? __('assistant.nested.count', ['count' => count($cell)]) : (is_array($cell) ? json_encode($cell, JSON_UNESCAPED_UNICODE) : $cell) }}</td>
                                                        @endforeach
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <div class="overflow-x-auto rounded-xl ring-1 ring-gray-950/10 dark:ring-white/10">
                            <table class="w-full text-xs">
                                <thead class="bg-gray-50 dark:bg-gray-800">
                                    <tr>
                                        @foreach ($preview['columns'] as $column)
                                            <th class="px-2.5 py-2 text-left font-semibold text-gray-500 dark:text-gray-400">{{ $column }}</th>
                                        @endforeach
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($preview['rows'] as $row)
                                        <tr class="border-t border-gray-950/5 dark:border-white/5">
                                            @foreach ($row as $cell)
                                                <td class="px-2.5 py-1.5">{{ is_array($cell) && isset($cell[0]) && is_array($cell[0]) ? __('assistant.nested.count', ['count' => count($cell)]) : (is_array($cell) ? json_encode($cell, JSON_UNESCAPED_UNICODE) : $cell) }}</td>
                                            @endforeach
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        @if (count($preview['rows']) >= 200)
                            <p class="mt-2 text-center text-[11px] text-gray-400">{{ __('assistant.preview.truncated') }}</p>
                        @endif
                    @endif
                </div>

                @if ($preview['kind'] === 'action')
                    <footer class="flex gap-2 border-t border-gray-200 px-4 py-3 dark:border-white/10">
                        <button
                            type="button"
                            wire:click="confirmPreview"
                            wire:loading.attr="disabled"
                            wire:target="confirmPreview,confirmAction"
                            class="flex-1 rounded-xl bg-primary-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-primary-500 disabled:opacity-50"
                        >
                            {{ __('assistant.confirm') }}
                        </button>
                        <button
                            type="button"
                            wire:click="closePreview"
                            class="flex-1 rounded-xl bg-white px-4 py-2.5 text-sm font-semibold text-gray-600 ring-1 ring-gray-300 transition hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-300 dark:ring-white/10 dark:hover:bg-gray-700"
                        >
                            {{ __('assistant.cancel') }}
                        </button>
                    </footer>
                @endif
            </div>
        @endif
    </aside>

    <style>
        [x-cloak] { display: none !important; }
        .assistant-msg {
            animation: assistant-msg-in 0.25s ease-out both;
        }
        @keyframes assistant-msg-in {
            from { opacity: 0; transform: translateY(6px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .assistant-dot {
            animation: assistant-dot-bounce 1.2s ease-in-out infinite;
        }
        @keyframes assistant-dot-bounce {
            0%, 60%, 100% { transform: translateY(0); opacity: 0.4; }
            30% { transform: translateY(-3px); opacity: 1; }
        }
    </style>
</div>
