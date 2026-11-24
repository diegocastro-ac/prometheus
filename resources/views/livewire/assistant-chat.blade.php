{{-- Copiloto del arrendador: FAB + drawer lateral derecho, visible en todo
     el panel. Multi-chat persistente, carga perezosa del historial, chips
     contextuales segun la pantalla, tablas de datos reales, tarjetas de
     accion con confirmacion y sugerencias solo en el ultimo mensaje. --}}
<div
    x-data="{
        drawerOpen: false,
        promptsOpen: false,
        // Repertorio de prompts: mensajes en cola mientras la IA responde
        // (max 11, persistida por conversacion en localStorage), estado
        // del drag para reordenar y flash cuando la cola esta llena.
        msgQueue: [],
        queueOpen: false,
        // Badges del historial: chatId -> {d: borrador, q: encolados},
        // leido de las mismas claves assistant.chatState.* del storage.
        chatBadges: {},
        queueDragFrom: null,
        queueDragOver: null,
        queueFullFlash: false,
        QUEUE_MAX: 11,
        // Clave del estado local (borrador + cola) del chat activo; se
        // actualiza al cambiar de conversacion para guardar/restaurar.
        _stateKey: null,
        // Chips de sugerencias colapsables: el usuario que ya conoce los
        // prompts libera espacio; la preferencia persiste entre sesiones.
        suggestOpen: localStorage.getItem('assistant.suggestOpen') !== '0',
        modelOpen: false,
        modelTab: null,
        modelGroupsMeta: @js(array_map(fn ($group) => ['driver' => $group['driver'], 'models' => $group['models']], $assistantModelGroups)),
        // Vista interna del drawer ('chat' | 'chats'). La controla Alpine
        // para que cambiar de panel sea instantaneo; el servidor la refleja
        // en `view` y el watcher la resincroniza tras cada respuesta.
        chatView: @js($view),
        // Mensajes del usuario en espera de respuesta: se pintan al instante
        // (echo optimista) y se reemplazan por el mensaje real cuando llega
        // la respuesta de Livewire, sin esperar el roundtrip para verlos.
        pendingMsgs: [],
        sending: false,
        // Requests de envio en vuelo: con force-send pueden coexistir el
        // cancelado y el nuevo; `sending` solo baja cuando todos cierran.
        sendDepth: 0,
        // Texto del input al momento (Alpine): wire:model es diferido,
        // asi que el boton enviar se deshabilita con esto, no con $wire.
        inputText: '',
        // Cambio de chat o de modelo: la UI responde al instante (optimista)
        // y muestra skeleton/spinner hasta que Livewire confirma.
        chatBusy: false,
        // Preview del modal (tablas/accion): el modal se abre al instante
        // con skeleton y Livewire lo rellena cuando llega el contenido.
        previewBusy: false,
        // Secuencia de aperturas: si el usuario cierra el modal mientras
        // la peticion sigue en vuelo, el resultado que llega despues se
        // descarta en vez de reabrir el modal solo.
        previewSeq: 0,
        previewDismissed: false,
        // Cierre local e instantaneo del modal: los botones dentro del
        // preview que lanzan una accion del chat (Hazlo conmigo, pasos
        // de guia) lo ocultan de una vez en vez de esperar la respuesta
        // de la IA. OJO: PROHIBIDO usar comillas dobles en este bloque —
        // el x-data es un atributo HTML y una sola lo truncaria.
        // Se resetea en cada openPreview.
        previewHidden: false,
        modelSel: null,
        modelBusy: false,
        // Ancho del drawer en px (null = default del CSS). Solo se usa en
        // escritorio (lg+); el maximo es el 75% del viewport.
        drawerWidth: null,
        resizing: false,
        resizeStartX: 0,
        resizeStartWidth: 0,
        // Resize vertical, mismo patron que el drawer: una sola medida
        // explicita. El compositor tiene altura fija; el chat es flex-1 y
        // absorbe todo el resto, asi el reparto siempre suma la altura del
        // panel y nunca aparece un hueco entre ambos.
        composerHeight: null,
        wizardHeight: null,
        // Altura manual del composer que el usuario tenia antes de que
        // una UI grande (wizard, biblioteca de prompts) pidiera modo
        // auto: se restaura cuando TODAS esas UI se cierran.
        autoComposerHeight: null,
        vMode: null,
        vStartY: 0,
        vStartHeight: 0,
        // Indicador de pensamiento: rota frases mientras `sending` este
        // activo y muestra el boton cancelar solo tras 10 segundos.
        thinkingSteps: @js(__('assistant.thinking_steps')),
        thinkingStep: 0,
        thinkingSince: 0,
        thinkingNow: 0,
        thinkingTimer: null,
        thinkingText() {
            return this.thinkingSteps[this.thinkingStep % this.thinkingSteps.length];
        },
        thinkingElapsed() {
            return this.thinkingNow - this.thinkingSince;
        },
        canCancelThinking() {
            return this.sending && this.thinkingElapsed() > 10000;
        },
        cancelThinking() {
            this.$wire.cancelPending();
            this.sending = false;
            // pendingMsgs NO se limpia aqui: el finally de echoAndSend lo
            // hace cuando llega la respuesta real (mensaje del usuario +
            // burbuja de cancelado), asi el texto nunca desaparece de la
            // conversacion. La cola sigue su curso cuando esa respuesta
            // termine.
            this.scrollToBottom();
        },
        // Guard de clicks residual del gesto de resize. Dos capas:
        // __assistantResizing queda activo durante TODO el arrastre —
        // aunque el cursor se quede quieto a mitad y el timestamp de
        // 400ms expire, el gesto sigue vivo — y al soltar queda una cola
        // de 400ms para el click sintetico del mouseup. Antes solo
        // existia la cola y una pausa larga dejaba pasar el click.
        startClickGuard() {
            window.__assistantResizing = true;
        },
        endClickGuard() {
            window.__assistantResizing = false;
            window.__assistantClickGuardUntil = Date.now() + 400;
        },
        guardClick() {
            window.__assistantClickGuardUntil = Date.now() + 400;
        },
        clickGuardActive() {
            return window.__assistantResizing === true
                || Date.now() < (window.__assistantClickGuardUntil || 0);
        },
        startResize(event) {
            if (window.innerWidth < 1024) return;
            this.resizing = true;
            this.startClickGuard();
            this.resizeStartX = event.touches ? event.touches[0].clientX : event.clientX;
            this.resizeStartWidth = this.drawerWidth ?? (this.$refs.drawer?.offsetWidth || 448);
            document.body.style.userSelect = 'none';
            event.preventDefault();
        },
        resizeMove(event) {
            // Si el mouseup cayo fuera de la ventana endResize no corrio
            // y el gesto quedo colgado: un mousemove con el boton ya
            // solado (buttons === 0) delata que termino de verdad.
            if (this.resizing && event.buttons === 0) {
                this.resizing = false;
                document.body.style.userSelect = '';
                this.endClickGuard();
                return;
            }
            if (! this.resizing) return;
            this.guardClick();
            const x = event.touches ? event.touches[0].clientX : event.clientX;
            const delta = this.resizeStartX - x;
            // Minimo 360px: por debajo el boton copiar (que flota fuera
            // de la card) ya no cabe en el margen y generaria scroll
            // horizontal en el chat.
            this.drawerWidth = Math.round(Math.min(
                window.innerWidth * 0.75,
                Math.max(360, this.resizeStartWidth + delta),
            ));
        },
        endResize() {
            if (! this.resizing) return;
            this.resizing = false;
            document.body.style.userSelect = '';
            // El mouseup sintetiza un click sobre el elemento bajo el
            // cursor: se ignora cualquier click los 400ms siguientes.
            this.endClickGuard();
            // El ancho elegido persiste por dispositivo: cada pantalla
            // recuerda su preferencia y no hay que reajustarlo.
            try {
                localStorage.setItem('assistant.drawerWidth', String(this.drawerWidth));
            } catch (e) { /* storage lleno/bloqueado: el resize sigue funcionando en sesion */ }
        },
        // Altura actual del objetivo ('composer' | 'wizard').
        vHeightFor(mode) {
            return mode === 'wizard' ? this.wizardHeight : this.composerHeight;
        },
        // Limites: el compositor deja siempre 200px para el chat; el wizard
        // vive dentro del scroll del compositor y su maximo usa el
        // presupuesto real chatPane + composer, constante durante el
        // arrastre porque chatPane (flex-1) cede el espacio.
        vMaxFor(mode) {
            if (mode === 'wizard') {
                const pane = this.$refs.chatPane?.offsetHeight ?? 0;
                const composer = this.$refs.composer?.offsetHeight ?? 0;
                if (pane <= 0 || composer <= 0) return Math.round(window.innerHeight * 0.55);
                // chatPane + composer es constante durante el arrastre
                // (el chat cede via flex-1). Se descuentan ~300px: 200 de
                // chat minimo mas el cromo del compositor (input, chips).
                return Math.max(160, pane + composer - 300);
            }
            const chat = this.$refs.chatPane?.offsetHeight ?? 0;
            const composer = this.$refs.composer?.offsetHeight ?? 0;
            if (chat <= 0) return 110;
            return Math.max(110, chat + composer - 200);
        },
        // Alto real del objetivo para aria-valuenow: el explicito si
        // existe, si no lo que el elemento mide en el layout actual.
        vCurrentHeight(mode) {
            const explicit = this.vHeightFor(mode);
            if (explicit !== null) return explicit;
            const ref = mode === 'wizard' ? this.$refs.wizardCard : this.$refs.composer;
            return ref ? ref.offsetHeight : 0;
        },
        applyVHeight(mode, height) {
            const min = mode === 'wizard' ? 160 : 110;
            const value = Math.round(Math.min(this.vMaxFor(mode), Math.max(min, height)));
            // Ambos modos cambian el alto natural del scroll: el
            // textarea absorbe (o cede) el espacio resultante.
            if (mode === 'wizard') this.wizardHeight = value;
            else this.composerHeight = value;
            // Escritura directa ademas del binding reactivo: durante el
            // arrastre el morph de Livewire o un Alpine tardio no pueden
            // dejar el alto a medias — el elemento obedece al cursor ya.
            const el = mode === 'wizard' ? this.$refs.wizardCard : this.$refs.composer;
            if (el) el.style.height = value + 'px';
            this.scheduleFitChatInput();
        },
        // Igual que startResize del drawer: mide al empezar y persiste al
        // soltar. Solo el compositor cambia de altura; el chat es flex-1.
        startVResize(event, mode) {
            const ref = mode === 'wizard' ? this.$refs.wizardCard : this.$refs.composer;
            if (! ref) return;
            this.vMode = mode;
            this.startClickGuard();
            this.vStartY = event.touches ? event.touches[0].clientY : event.clientY;
            this.vStartHeight = this.vHeightFor(mode) ?? ref.offsetHeight;
            document.body.style.userSelect = 'none';
            event.preventDefault();
        },
        vResizeMove(event) {
            // Misma senal que en resizeMove: botones sueltos = gesto
            // terminado fuera de la ventana.
            if (this.vMode && event.buttons === 0) {
                const mode = this.vMode;
                this.vMode = null;
                document.body.style.userSelect = '';
                this.endClickGuard();
                this.persistVHeight(mode);
                return;
            }
            if (! this.vMode) return;
            this.guardClick();
            const y = event.touches ? event.touches[0].clientY : event.clientY;
            const moved = y - this.vStartY;
            // El handle sigue al cursor: subir agranda el compositor y el
            // chat (flex-1) se encoge; bajar hace lo contrario. En el wizard
            // el handle esta abajo, asi que bajar agranda la tarjeta.
            const delta = this.vMode === 'wizard' ? moved : -moved;
            this.applyVHeight(this.vMode, this.vStartHeight + delta);
        },
        endVResize() {
            if (! this.vMode) return;
            const mode = this.vMode;
            this.vMode = null;
            document.body.style.userSelect = '';
            this.endClickGuard();
            this.persistVHeight(mode);
        },
        vResizeKey(mode, delta) {
            const ref = mode === 'wizard' ? this.$refs.wizardCard : this.$refs.composer;
            if (! ref) return;
            this.applyVHeight(mode, (this.vHeightFor(mode) ?? ref.offsetHeight) + delta);
            this.persistVHeight(mode);
        },
        persistVHeight(mode) {
            const value = this.vHeightFor(mode);
            if (value === null) return;
            // Si el usuario re-ajusto el composer con un panel abierto,
            // su nueva preferencia es la que se restaura al cerrar.
            if (mode === 'composer' && this.autoComposerHeight !== null) {
                this.autoComposerHeight = value;
            }
            try {
                localStorage.setItem(
                    mode === 'wizard' ? 'assistant.wizardHeight' : 'assistant.composerSize',
                    String(value),
                );
            } catch (e) { /* storage lleno/bloqueado: la altura sigue aplicando en sesion */ }
        },
        clampVHeights() {
            const pane = this.$refs.chatPane;
            // Con el drawer cerrado o en la vista de chats el panel no tiene
            // altura util: no se tocan los valores guardados.
            if (! pane || pane.clientHeight <= 0) return;
            if (this.composerHeight !== null) {
                this.composerHeight = Math.min(this.composerHeight, this.vMaxFor('composer'));
            }
            if (this.wizardHeight !== null) {
                this.wizardHeight = Math.min(this.wizardHeight, this.vMaxFor('wizard'));
            }
            this.scheduleFitChatInput();
        },
        // Altura del panel de prompts: ocupa el espacio real que queda
        // bajo las chips en el scroll del composer. '' = sin tope
        // inline y manda la clase max-h-96.
        promptsMaxHeight() {
            if (this.composerHeight === null) return '';
            const scroll = this.$refs.composerScroll;
            const panel = this.$refs.promptsPanel;
            if (! scroll || ! panel || panel.offsetHeight === 0) return '';
            const above = panel.getBoundingClientRect().top - scroll.getBoundingClientRect().top;
            return Math.max(140, Math.floor(scroll.clientHeight - above - 8)) + 'px';
        },
        fitChatInput() {
            const ta = this.$refs.chatInput;
            if (! ta) return;
            ta.style.height = 'auto';
            ta.style.height = Math.min(Math.max(ta.scrollHeight, 42), 160) + 'px';
        },
        // Diferido a rAF: el alto del composer o el contenido de los
        // paneles pueden cambiar por Alpine/morph en el mismo tick y la
        // medicion debe correr con el layout ya aplicado.
        scheduleFitChatInput() {
            requestAnimationFrame(() => this.fitChatInput());
            // x-transition deja el panel ~150-200ms en el layout al
            // cerrar: una segunda medicion cubre el contenido que se
            // esconde despues del primer frame.
            setTimeout(() => this.fitChatInput(), 240);
        },
        resetComposerHeight() {
            this.composerHeight = null;
            // Reset manual: ya no hay altura previa que restaurar cuando
            // el panel abierto se cierre.
            this.autoComposerHeight = null;
            this.scheduleFitChatInput();
            try {
                localStorage.removeItem('assistant.composerSize');
            } catch (e) { /* sin storage: se queda el default */ }
        },
        resetWizardHeight() {
            this.wizardHeight = null;
            try {
                localStorage.removeItem('assistant.wizardHeight');
            } catch (e) { /* sin storage: se queda el default */ }
        },
        onWindowResize() {
            if (window.innerWidth < 1024) this.drawerWidth = null;
            this.clampVHeights();
        },
        toggleSuggest() {
            if (this.clickGuardActive()) return;
            this.suggestOpen = ! this.suggestOpen;
            localStorage.setItem('assistant.suggestOpen', this.suggestOpen ? '1' : '0');
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
        // Mientras el drawer este abierto la rueda nunca llega a la pagina:
        // si el cursor cae fuera del drawer se redirige al contenedor de
        // scroll activo y se consume el evento, asi el scroll no escapa a
        // la pagina ni siquiera cuando el chat llega a su tope.
        forwardWheel(event) {
            if (! this.drawerOpen || ! (event.target instanceof Element)) return;
            const drawer = this.$refs.drawer;
            if (! drawer) return;
            if (drawer === event.target || drawer.contains(event.target)) return;
            const el = this.previewVisible()
                ? this.$refs.previewScroll
                : (this.chatView === 'chats' ? this.$refs.chatsScroll : this.$refs.messagesScroll);
            if (! el || el.scrollHeight <= el.clientHeight) return;
            event.preventDefault();
            // Scroll suavizado por inercia: se acumula un objetivo y un
            // rAF lo persigue con amortiguacion, asi el scroll redirigido
            // se siente igual que el nativo (dentro del drawer).
            const unit = event.deltaMode === 1 ? 16 : 1;
            const max = el.scrollHeight - el.clientHeight;
            if (! el._smoothScrolling) el._scrollTarget = el.scrollTop;
            el._scrollTarget = Math.min(Math.max(el._scrollTarget + event.deltaY * unit, 0), max);
            if (el._smoothScrolling) return;
            el._smoothScrolling = true;
            el._smoothLast = el.scrollTop;
            let stillFrames = 0;
            const step = () => {
                // Si el scrollTop cambio sin que lo hayamos movido nosotros,
                // el usuario scrolleo dentro del drawer: se cancela la
                // inercia para no pelear con el scroll nativo.
                if (Math.abs(el.scrollTop - el._smoothLast) > 0.6) {
                    el._scrollTarget = el.scrollTop;
                    el._smoothScrolling = false;
                    return;
                }
                const diff = el._scrollTarget - el.scrollTop;
                if (Math.abs(diff) < 0.5) {
                    el.scrollTop = el._scrollTarget;
                    el._smoothScrolling = false;
                    return;
                }
                el.scrollTop += diff * 0.22;
                // Red de seguridad: si el navegador no puede mover el
                // scrollTop (redondeo sub-pixel) se aborta la animacion.
                if (el.scrollTop === el._smoothLast) {
                    if (++stillFrames > 20) {
                        el._smoothScrolling = false;
                        return;
                    }
                } else {
                    stillFrames = 0;
                }
                el._smoothLast = el.scrollTop;
                requestAnimationFrame(step);
            };
            requestAnimationFrame(step);
        },
        // Modelo activo: el elegido localmente (optimista) o el del servidor.
        activeModelId() {
            return this.modelSel ?? (this.$wire.assistantModel || @js(\App\Services\Assistant\AssistantProvider::model()));
        },
        activeModelTab() {
            if (this.modelGroupsMeta.some((group) => group.driver === this.modelTab)) {
                return this.modelTab;
            }
            const active = this.modelGroupsMeta.find((group) => group.models.includes(this.activeModelId()));
            return active ? active.driver : (this.modelGroupsMeta[0]?.driver ?? '');
        },
        toggleModelPicker() {
            this.modelOpen = ! this.modelOpen;
            if (this.modelOpen) this.modelTab = null;
        },
        // Eleccion optimista: el radio se marca al instante y muestra spinner
        // hasta que Livewire confirma la preferencia en el servidor.
        selectModel(id) {
            if (this.modelBusy) return;
            const previous = this.modelSel;
            this.modelSel = id;
            this.modelBusy = true;
            this.modelOpen = false;
            this.$wire.setAssistantModel(id)
                .catch(() => { this.modelSel = previous; })
                .finally(() => { this.modelBusy = false; });
        },
        async echoAndSend(text, call, restoreInput = null, force = false) {
            // force=true solo para interruptSend: se salta el guard de
            // `sending` porque el request en vuelo ya fue cancelado en el
            // servidor via nonce y este mensaje debe salir ya.
            if ((! force && this.sending) || ! text.trim()) return;
            this.sendDepth++;
            this.sending = true;
            this.thinkingSince = Date.now();
            this.thinkingStep = 0;
            const pendingText = text.trim();
            this.pendingMsgs.push(pendingText);
            this.atBottom = true;
            this.scrollToBottom();
            try {
                await call();
            } catch (e) {
                // Fallo de red o del request: se devuelve el texto al input
                // para que el usuario no lo pierda y pueda reintentar.
                if (restoreInput) restoreInput();
            } finally {
                // Se quita solo el eco de ESTE envio: un force-send pudo
                // poner el suyo mientras este request seguia en vuelo y
                // limpiarlo todo le borraria el mensaje optimista.
                const pendingIndex = this.pendingMsgs.indexOf(pendingText);
                if (pendingIndex !== -1) this.pendingMsgs.splice(pendingIndex, 1);
                this.sendDepth = Math.max(0, this.sendDepth - 1);
                this.sending = this.sendDepth > 0;
                // Si la respuesta trajo un preview nuevo no debe quedar
                // oculto por un dismiss anterior.
                this.previewHidden = false;
                this.scrollToBottom();
                // Siguiente mensaje en cola: se envia solo cuando la
                // respuesta anterior ya llego (un request a la vez).
                this.pumpQueue();
            }
        },
        sendNow() {
            // Un click residual del resize no debe disparar un envio.
            if (this.clickGuardActive()) return Promise.resolve();
            const input = this.$refs.chatInput;
            const text = ((input ? input.value : (this.$wire.input ?? '')) ?? '').trim();
            if (! text) return Promise.resolve();
            // El textarea se vacia en el instante del envio, sin esperar la
            // respuesta; el servidor confirma el vaciado al responder y, si
            // el request falla, restoreInput devuelve el texto.
            if (input) {
                input.value = '';
            }
            this.inputText = '';
            this.clearDraft();
            this.scheduleFitChatInput();
            // Con una respuesta en curso el mensaje entra a la cola en vez
            // de perderse: se procesa en orden al terminar la actual.
            if (this.sending) {
                this.enqueueMessage(text);
                return Promise.resolve();
            }
            return this.echoAndSend(text, () => this.$wire.send(), () => {
                if (! input) return;
                input.value = text;
                input.dispatchEvent(new Event('input', { bubbles: true }));
            });
        },
        sendSuggestion(text) {
            if (this.sending || this.msgQueue.length > 0) return this.enqueueMessage(text);
            return this.echoAndSend(text, () => this.$wire.askSuggestion(text));
        },
        // Botones de prompt dentro del modal de preview (Hazlo
        // conmigo, pasos de guia): cierran el modal al instante y el
        // envio pasa por echoAndSend — el mensaje del usuario aparece
        // de una vez y el indicador de pensamiento cubre la espera de
        // la IA, sin congelar la vista.
        sendFromPreview(text) {
            this.dismissPreviewForChat();
            if (this.sending || this.msgQueue.length > 0) return this.enqueueMessage(text);
            return this.echoAndSend(text, () => this.$wire.askFromPreview(text));
        },
        // ---------------------------------------------------------------
        // Cola de mensajes (max QUEUE_MAX): se persiste por conversacion
        // en localStorage junto al borrador del input.
        // ---------------------------------------------------------------
        enqueueMessage(text) {
            const clean = (text ?? '').trim();
            if (clean === '') return false;
            if (this.msgQueue.length >= this.QUEUE_MAX) {
                // Cola llena: el texto vuelve al input para no perderlo y
                // la barra de cola avisa del limite unos segundos.
                this.restoreInputText(clean);
                this.queueFullFlash = true;
                setTimeout(() => { this.queueFullFlash = false; }, 2200);
                return false;
            }
            this.msgQueue.push(clean);
            this.persistQueue();
            this.queueOpen = true;
            return true;
        },
        pumpQueue() {
            if (this.sending || this.msgQueue.length === 0) return;
            const next = this.msgQueue.shift();
            this.persistQueue();
            if (! next) return;
            this.echoAndSend(next, () => this.$wire.askSuggestion(next));
        },
        removeQueued(index) {
            this.msgQueue.splice(index, 1);
            this.persistQueue();
        },
        // Force-send de un item encolado: si hay respuesta en curso la
        // cancela en el servidor (nonce) y envia este mensaje ya.
        sendQueuedNow(index) {
            const text = this.msgQueue.splice(index, 1)[0];
            this.persistQueue();
            if (! text) return;
            if (this.sending) {
                this.interruptSendNow(text);
            } else {
                this.echoAndSend(text, () => this.$wire.askSuggestion(text));
            }
        },
        // Ctrl+Enter / Cmd+Enter: interrumpe la respuesta en curso y manda
        // el texto del input de una vez, sin pasar por la cola. Sin
        // respuesta en curso equivale a un envio normal (sendNow).
        forceSendInput() {
            if (! this.sending) {
                this.sendNow();
                return;
            }
            const input = this.$refs.chatInput;
            const text = ((input ? input.value : (this.$wire.input ?? '')) ?? '').trim();
            if (! text) return;
            if (input) {
                input.value = '';
            }
            this.inputText = '';
            this.clearDraft();
            this.scheduleFitChatInput();
            this.interruptSendNow(text);
        },
        interruptSendNow(text) {
            return this.echoAndSend(text, () => this.$wire.interruptSend(text), null, true);
        },
        // Drag & drop del item encolado: se guarda el indice origen en
        // dragstart y el destino se calcula en drop.
        queueDragStart(index, event) {
            this.queueDragFrom = index;
            if (event.dataTransfer) event.dataTransfer.effectAllowed = 'move';
        },
        queueDragOver(index, event) {
            event.preventDefault();
            if (event.dataTransfer) event.dataTransfer.dropEffect = 'move';
            this.queueDragOver = index;
        },
        queueDrop(index, event) {
            event.preventDefault();
            const from = this.queueDragFrom;
            this.queueDragFrom = null;
            this.queueDragOver = null;
            if (from === null || from === index || from >= this.msgQueue.length) return;
            const [item] = this.msgQueue.splice(from, 1);
            this.msgQueue.splice(index, 0, item);
            this.persistQueue();
        },
        queueDragEnd() {
            this.queueDragFrom = null;
            this.queueDragOver = null;
        },
        // ---------------------------------------------------------------
        // Estado local por conversacion: borrador del input + cola.
        // Una sola clave JSON por chat en localStorage.
        // ---------------------------------------------------------------
        chatStateKey() {
            return 'assistant.chatState.' + (this.$wire.activeChatId ?? 'new');
        },
        saveChatState(key) {
            try {
                localStorage.setItem(key ?? this.chatStateKey(), JSON.stringify({
                    d: this.inputText,
                    q: this.msgQueue,
                }));
            } catch (e) { /* storage lleno/bloqueado: el estado sigue en sesion */ }
            this.refreshChatBadges();
        },
        // Relee todas las claves assistant.chatState.* y reconstruye el
        // mapa de badges: se llama tras cada guardado, al entrar a la
        // vista de chats y al restaurar estado de una conversacion.
        refreshChatBadges() {
            const map = {};
            try {
                for (let i = 0; i < localStorage.length; i++) {
                    const key = localStorage.key(i);
                    if (! key || ! key.startsWith('assistant.chatState.')) continue;
                    const id = key.slice('assistant.chatState.'.length);
                    if (id === 'new' || id === '') continue;
                    const state = JSON.parse(localStorage.getItem(key) || 'null');
                    const draft = typeof state?.d === 'string' ? state.d.trim() : '';
                    const queued = Array.isArray(state?.q) ? state.q.length : 0;
                    if (draft !== '' || queued > 0) {
                        map[id] = { d: draft, q: queued };
                    }
                }
            } catch (e) { /* storage bloqueado: sin badges */ }
            this.chatBadges = map;
        },
        // Los x-text del historial se evaluan aunque el badge este
        // oculto: sin este fallback revienta con undefined.d antes de
        // que refreshChatBadges() termine de leer localStorage.
        badgeFor(id) {
            const badge = this.chatBadges[id];
            return badge && typeof badge === 'object' ? badge : { d: '', q: 0 };
        },
        restoreChatState(key) {
            let state = null;
            try {
                state = JSON.parse(localStorage.getItem(key) || 'null');
            } catch (e) { state = null; }
            this.msgQueue = Array.isArray(state?.q) ? state.q.slice(0, this.QUEUE_MAX) : [];
            this.applyDraft(typeof state?.d === 'string' ? state.d : '');
        },
        // Al cambiar de conversacion: guarda el estado del chat saliente
        // bajo su clave y restaura el del entrante. No hay migracion
        // '.new' -> id: cuando el primer mensaje crea el chat el borrador
        // ya se consumio y la cola se vacia al procesarse.
        onChatStateKeyChange() {
            const prev = this._stateKey ?? this.chatStateKey();
            const next = this.chatStateKey();
            if (prev === next) return;
            this.saveChatState(prev);
            this._stateKey = next;
            this.restoreChatState(next);
        },
        applyDraft(text) {
            const input = this.$refs.chatInput;
            if (input) {
                input.value = text;
                this.fitChatInput();
            }
            this.inputText = text;
        },
        saveDraft() {
            this.saveChatState();
        },
        clearDraft() {
            try {
                const key = this.chatStateKey();
                const raw = localStorage.getItem(key);
                if (raw !== null) {
                    const state = JSON.parse(raw);
                    state.d = '';
                    localStorage.setItem(key, JSON.stringify(state));
                }
            } catch (e) { /* sin storage: nada que limpiar */ }
        },
        persistQueue() {
            this.saveChatState();
            // La cola visible cambia el reparto del composer: el
            // textarea cede o recupera ese espacio.
            this.scheduleFitChatInput();
        },
        restoreInputText(text) {
            const input = this.$refs.chatInput;
            if (input) {
                input.value = text;
                this.fitChatInput();
                input.focus();
            }
            this.inputText = text;
            this.saveDraft();
        },
        // Descarga del chat exportado: el servidor manda el Markdown en
        // el detail del evento y aqui se materializa el blob + <a>.
        downloadExport(detail) {
            try {
                const blob = new Blob([detail.content || ''], { type: 'text/markdown;charset=utf-8' });
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = detail.name || 'chat.md';
                document.body.appendChild(a);
                a.click();
                a.remove();
                setTimeout(() => URL.revokeObjectURL(url), 5000);
            } catch (e) { /* sin download: el contenido sigue en el chat */ }
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
        // El modal es visible mientras carga (previewBusy) o cuando el
        // servidor ya entrego el contenido ($wire.preview).
        previewVisible() {
            return ! this.previewHidden && (this.previewBusy || this.$wire.preview !== null);
        },
        // Abre el modal al instante y lanza la carga en segundo plano:
        // el skeleton se ve dentro del modal, no tapando el chat.
        async openPreview(call) {
            this.previewSeq++;
            this.previewDismissed = false;
            this.previewHidden = false;
            this.previewBusy = true;
            try {
                await call();
            } finally {
                this.previewBusy = false;
                if (this.previewDismissed) this.$wire.closePreview();
            }
        },
        // Cierra el modal desde Alpine: si hay una carga en vuelo se marca
        // como descartada para que su respuesta no lo reabra.
        closePreviewModal() {
            if (this.previewBusy) this.previewDismissed = true;
            this.previewBusy = false;
            this.previewHidden = true;
            this.$wire.closePreview();
        },
        // Botones dentro del modal que envian un prompt al chat: cierran
        // la vista al instante (sin esperar la respuesta de la IA) y el
        // servidor ademas limpia $preview via askFromPreview.
        dismissPreviewForChat() {
            this.previewHidden = true;
            this.previewBusy = false;
        },
        // Esc cierra en orden de prioridad: primero el modal de preview,
        // luego el selector de modelo y al final el drawer completo.
        escapeKey() {
            if (! this.drawerOpen) return;
            if (this.previewVisible()) {
                this.closePreviewModal();
                return;
            }
            if (this.modelOpen) {
                this.modelOpen = false;
                return;
            }
            this.closeDrawer();
        },
        init() {
            // Al enviar, Livewire limpia `input` por morph (sin evento input):
            // hay que devolver el textarea a una linea manualmente.
            this.$wire.$watch('input', (value) => {
                if (value === '' && this.$refs.chatInput) {
                    this.scheduleFitChatInput();
                    this.inputText = '';
                }
            });
            // Si el servidor cambio `view` (selectChat, newChat, etc.) el
            // panel visible se ajusta solo.
            this.$wire.$watch('view', (value) => {
                this.chatView = value;
            });
            // Cambio de conversacion: guarda borrador+cola del chat
            // saliente y restaura los del entrante (localStorage). El
            // restore va en nextTick porque chatInput aun no existe
            // durante init().
            this._stateKey = this.chatStateKey();
            this.$nextTick(() => this.restoreChatState(this._stateKey));
            this.$wire.$watch('activeChatId', () => {
                this.onChatStateKeyChange();
            });
            // Al abrir el drawer el foco va al input: el usuario puede
            // escribir de una vez sin hacer click extra. Con el drawer ya
            // visible se ajustan las alturas guardadas al espacio real.
            this.$watch('drawerOpen', (open) => {
                if (open) {
                    this.$nextTick(() => {
                        this.clampVHeights();
                        this.$refs.chatInput?.focus();
                    });
                }
            });
            // La rueda fuera del drawer sigue moviendo el scroll del chat.
            window.addEventListener('wheel', (event) => this.forwardWheel(event), { passive: false });
            // Ancho guardado del dispositivo (solo escritorio: en movil el
            // drawer ocupa todo el ancho y el valor se ignora).
            try {
                const savedWidth = parseInt(localStorage.getItem('assistant.drawerWidth') || '', 10);
                if (savedWidth >= 360 && savedWidth <= window.innerWidth * 0.75) {
                    this.drawerWidth = savedWidth;
                }
                const savedComposer = parseInt(localStorage.getItem('assistant.composerSize') || '', 10);
                // Se descarta un valor desproporcionado (p. ej. de una version
                // anterior): el compositor nunca debe comerse media pantalla.
                if (savedComposer >= 110 && savedComposer <= window.innerHeight * 0.6) {
                    this.composerHeight = savedComposer;
                }
                const savedWizard = parseInt(localStorage.getItem('assistant.wizardHeight') || '', 10);
                if (savedWizard >= 160 && savedWizard <= window.innerHeight * 0.6) {
                    this.wizardHeight = savedWizard;
                }
            } catch (e) { /* sin storage: alturas por defecto */ }
            // UI grande dentro del composer (wizard guiado, biblioteca
            // de prompts): el panel manda sobre el espacio — si su
            // contenido necesita MAS alto del que el usuario reservo con
            // el resize, el composer crece hasta cubrirlo; si la altura
            // del usuario ya alcanza o sobra, NO se toca su resize.
            // Al cerrarse todo se restaura la altura previa (si el
            // usuario no la modifico mientras tanto).
            this.updateComposerAuto = () => {
                // El textarea se reajusta en cuanto cambie la altura del
                // composer o el contenido de los paneles (rAF: corre
                // despues de que Alpine aplique el estilo y el morph).
                this.scheduleFitChatInput();
                // El panel de prompts solo ocupa espacio cuando es
                // visible de verdad: promptsOpen dentro de la seccion
                // suggestOpen (colapsada = display:none = alto 0).
                const wantsAuto = this.$wire.pendingForm !== null
                    || (this.promptsOpen && this.suggestOpen);
                if (wantsAuto) {
                    if (this.composerHeight !== null && this.autoComposerHeight === null) {
                        this.autoComposerHeight = this.composerHeight;
                    }
                    // Solo aplica cuando el usuario tenia altura manual:
                    // en modo auto el contenido ya dimensiona el composer.
                    if (this.autoComposerHeight === null) return;
                    // $nextTick: el watcher puede disparar antes de que
                    // x-show pinte el panel y el contenido lo mediria
                    // como si no existiera.
                    this.$nextTick(() => {
                        if (! this.$wire.pendingForm && ! (this.promptsOpen && this.suggestOpen)) return;
                        if (this.autoComposerHeight === null) return;
                        const composer = this.$refs.composer;
                        const scroll = this.$refs.composerScroll;
                        if (! composer || ! scroll) return;
                        // composer = scroll + bloque del input; la diferencia
                        // de offsetHeight es el chrome fijo del input.
                        const chrome = composer.offsetHeight - scroll.offsetHeight;
                        // composerContent.offsetHeight es la altura NATURAL
                        // del contenido del scroll: scrollHeight mediria la
                        // caja flex-1 (que ya incluye el espacio sobrante).
                        const contentH = this.$refs.composerContent?.offsetHeight ?? scroll.scrollHeight;
                        // chrome incluye el textarea ya agrandado por el
                        // espacio sobrante: se descuenta su parte flexible
                        // (todo lo que exceda el minimo de 42px) para que
                        // `needed` no dependa del alto actual del input.
                        const ta = this.$refs.chatInput;
                        const taFlex = ta ? Math.max(0, ta.offsetHeight - 42) : 0;
                        const needed = contentH + chrome - taFlex;
                        const target = Math.min(
                            this.vMaxFor('composer'),
                            Math.max(110, this.autoComposerHeight, needed),
                        );
                        if (target !== this.composerHeight) this.composerHeight = target;
                    });
                } else if (this.autoComposerHeight !== null) {
                    this.composerHeight = this.autoComposerHeight;
                    this.autoComposerHeight = null;
                }
            };
            this.$wire.$watch('pendingForm', this.updateComposerAuto);
            this.$watch('promptsOpen', this.updateComposerAuto);
            // Colapsar/expandir la seccion de prompts tambien cambia el
            // alto natural del scroll: el composer y el textarea se
            // reajustan en el acto, sin esperar al resize.
            this.$watch('suggestOpen', this.updateComposerAuto);
            this.updateComposerAuto();
            // Otros contenidos del composer que cambian el reparto del
            // espacio: la cola de mensajes y la propia vista (al volver
            // del historial el composer reaparece).
            this.$watch('queueOpen', () => this.scheduleFitChatInput());
            this.$watch('msgQueue', () => this.scheduleFitChatInput());
            this.scheduleFitChatInput();
            // El click sintetico tras soltar un resize no debe activar lo
            // que quede bajo el cursor (toggle de prompts, chips, wizard).
            // Listener global UNA sola vez: el flag vive en window para
            // sobrevivir re-inits del componente y el capture+stopImmediate
            // mata el evento antes de que llegue a cualquier handler.
            if (! window.__assistantClickGuardBound) {
                window.__assistantClickGuardBound = true;
                window.addEventListener('click', (e) => {
                    if (window.__assistantResizing === true
                        || Date.now() < (window.__assistantClickGuardUntil || 0)) {
                        e.stopPropagation();
                        e.stopImmediatePropagation();
                        e.preventDefault();
                    }
                }, true);
                // Si la ventana pierde el foco a mitad del gesto (alt+tab,
                // otra app) el mouseup puede no llegar nunca: el flag se
                // liberaria y todos los clicks quedarian muertos.
                window.addEventListener('blur', () => {
                    window.__assistantResizing = false;
                });
                // Y si el mouseup cayo FUERA de la ventana, endResize/
                // endVResize no corrieron: el flag quedaria atascado y
                // todo click moriria. Se libera solo tras despachar el
                // evento — durante el gesto no hay mouseup, asi que un
                // resize real nunca lo limpia antes de tiempo.
                ['mouseup', 'touchend'].forEach((evt) => {
                    window.addEventListener(evt, () => {
                        setTimeout(() => { window.__assistantResizing = false; }, 0);
                    });
                });
            }
            // Badges del historial: se refrescan al abrir la vista de
            // chats y una vez al iniciar por si ya habia estado guardado.
            this.$watch('chatView', (view) => {
                if (view === 'chats') this.refreshChatBadges();
                // Al volver a la vista de chat el composer se re-muestra:
                // hay que re-medir el reparto de alturas.
                this.scheduleFitChatInput();
            });
            this.refreshChatBadges();
            // Rota el texto del indicador de pensamiento mientras haya
            // una respuesta en curso; un solo intervalo para toda la vida
            // del componente.
            this.thinkingTimer = setInterval(() => {
                this.thinkingNow = Date.now();
                if (this.sending) {
                    this.thinkingStep = (this.thinkingStep + 1) % this.thinkingSteps.length;
                }
            }, 2200);
        },
    }"
    x-on:keydown.escape.window="escapeKey()"
    x-on:assistant-close.window="closeDrawer()"
    x-on:assistant-export.window="downloadExport($event.detail)"
    x-on:assistant-theme.window="
        window.dispatchEvent(new CustomEvent('theme-changed', { detail: $event.detail.theme }));
        const switcher = document.querySelector('.fi-theme-switcher');
        if (switcher && switcher._x_dataStack && switcher._x_dataStack[0]) {
            switcher._x_dataStack[0].theme = $event.detail.theme;
        }
    "
    x-on:mousemove.window="resizeMove($event); vResizeMove($event)"
    x-on:mouseup.window="endResize(); endVResize()"
    x-on:touchmove.window="resizeMove($event); vResizeMove($event)"
    x-on:touchend.window="endResize(); endVResize()"
    x-on:resize.window="onWindowResize()"
    {{-- La supresion del click post-resize vive en un listener de
         window capture dentro de init(): ver suppressClickUntil. --}}
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
        {{-- El ancho personalizado solo aplica en escritorio (lg+): en
             pantallas menores el drawer siempre ocupa el ancho completo
             aunque haya un ancho guardado de una sesion anterior. --}}
        :style="drawerWidth && window.innerWidth >= 1024 ? 'width: ' + drawerWidth + 'px; max-width: ' + drawerWidth + 'px' : ''"
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
             drawer. Solo escritorio (lg+); el ancho maximo es 75vw. --}}
        <div
            x-on:mousedown="startResize($event)"
            x-on:touchstart="startResize($event)"
            title="{{ __('assistant.resize') }}"
            class="absolute inset-y-0 left-0 z-30 hidden w-1.5 cursor-col-resize touch-none transition-colors hover:bg-primary-500/40 lg:block"
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
                    <p class="truncate text-xs text-gray-500 dark:text-gray-400">
                        <span wire:ignore.self x-show="chatView === 'chat'">{{ __('assistant.subtitle') }}</span>
                        <span wire:ignore.self x-show="chatView === 'chats'">{{ $chatsLoaded ? __('assistant.chats_subtitle', ['count' => count($chats)]) : __('assistant.chats_loading') }}</span>
                    </p>
                    @if ($pendingForm !== null)
                        <p class="mt-0.5 flex items-center gap-1 truncate text-[10px] font-semibold text-primary-600 dark:text-primary-400">
                            <svg class="h-3 w-3 shrink-0 animate-pulse" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09Z" />
                            </svg>
                            {{ $pendingForm['form_label'] ?? ($pendingForm['form'] === 'delivery_act' ? __('act.navigation_label') : $pendingForm['form']) }}
                            · {{ __('assistant.wizard.step_of', ['step' => $pendingForm['step'], 'total' => $pendingForm['total_steps']]) }}
                        </p>
                    @endif
                </div>
            </div>
            <div class="flex shrink-0 items-center gap-1">
                {{-- Ambos grupos siempre en el DOM: x-show los alterna al
                     instante y wire:ignore.self evita que el morph de
                     Livewire pise el display que pone Alpine. --}}
                <div wire:ignore.self x-show="chatView === 'chats'" class="flex items-center gap-1">
                    <button
                        type="button"
                        wire:click="exportWeeklyReport"
                        wire:loading.attr="disabled"
                        wire:target="exportWeeklyReport"
                        title="{{ __('assistant.weekly_report.button') }}"
                        class="rounded-lg p-2 text-gray-400 transition hover:bg-gray-100 hover:text-gray-600 disabled:opacity-50 dark:hover:bg-white/10 dark:hover:text-gray-200"
                    >
                        <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
                        </svg>
                    </button>
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
                    {{-- Selector de modelo del copiloto: los modelos
                         gratuitos de Gemini con su uso recomendado. El
                         cambio aplica en tiempo real al proximo mensaje.
                         Las opciones las define el proveedor activo:
                         catalogo fijo de Gemini o AI_MODELS del endpoint
                         OpenAI-compatible (Groq, OpenAI, OpenRouter...). --}}
                    @if ($showModelPicker)
                    <div class="relative">
                        <button
                            type="button"
                            x-on:click="toggleModelPicker()"
                            title="{{ __('assistant.model.title') }}"
                            class="rounded-lg p-2 transition"
                            :class="modelOpen || modelBusy ? 'bg-primary-100 text-primary-600 dark:bg-primary-500/20 dark:text-primary-400' : 'text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-white/10 dark:hover:text-gray-200'"
                        >
                            {{-- Mientras Livewire confirma el cambio el icono
                                 de chip se reemplaza por un spinner: es la
                                 señal visible de que el modelo esta cargando. --}}
                            <svg x-show="modelBusy" x-cloak class="h-5 w-5 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4Z"></path>
                            </svg>
                            <svg x-show="! modelBusy" class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
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
                            {{-- Un tab por proveedor configurado: con un
                                 solo proveedor la barra no aporta nada y
                                 se oculta. --}}
                            @if (count($assistantModelGroups) > 1)
                                <div class="flex gap-1 border-b border-gray-100 px-2 py-1.5 dark:border-white/10">
                                    @foreach ($assistantModelGroups as $modelGroup)
                                        <button
                                            type="button"
                                            x-on:click="modelTab = @js($modelGroup['driver'])"
                                            title="{{ $modelGroup['label'] }}"
                                            class="flex-1 truncate rounded-lg px-2 py-1 text-[11px] font-semibold transition"
                                            :class="activeModelTab() === @js($modelGroup['driver']) ? 'bg-primary-100 text-primary-700 dark:bg-primary-500/20 dark:text-primary-300' : 'text-gray-500 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-white/10'"
                                        >
                                            {{ $modelGroup['label'] }}
                                        </button>
                                    @endforeach
                                </div>
                            @endif
                            <div class="max-h-80 overflow-y-auto p-1.5">
                                @foreach ($assistantModelGroups as $modelGroup)
                                    <div x-show="activeModelTab() === @js($modelGroup['driver'])">
                                    @foreach ($modelGroup['models'] as $modelIndex => $modelId)
                                        @php $modelMeta = $assistantModelMeta($modelId, $modelIndex); @endphp
                                        <button
                                            type="button"
                                            x-on:click="selectModel(@js($modelId))"
                                            class="flex w-full items-start gap-2.5 rounded-lg px-2.5 py-2 text-left transition"
                                            :class="activeModelId() === @js($modelId) ? 'bg-primary-50 dark:bg-primary-500/10' : 'hover:bg-gray-50 dark:hover:bg-white/5'"
                                        >
                                            <span
                                                class="mt-0.5 flex h-4 w-4 shrink-0 items-center justify-center rounded-full border-2 transition"
                                                :class="activeModelId() === @js($modelId) ? 'border-primary-600 bg-primary-600' : 'border-gray-300 dark:border-gray-600'"
                                            >
                                                <svg
                                                    x-show="modelBusy && modelSel === @js($modelId)"
                                                    x-cloak
                                                    class="h-2.5 w-2.5 animate-spin text-white"
                                                    xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                                >
                                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4Z"></path>
                                                </svg>
                                                <svg
                                                    x-show="!(modelBusy && modelSel === @js($modelId)) && activeModelId() === @js($modelId)"
                                                    x-cloak
                                                    class="h-2.5 w-2.5 text-white"
                                                    xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="3" stroke="currentColor" aria-hidden="true"
                                                >
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                                                </svg>
                                            </span>
                                            <span class="min-w-0 flex-1">
                                                <span class="flex items-center gap-1.5">
                                                    <span class="truncate text-xs font-semibold text-gray-800 dark:text-gray-100">{{ $modelMeta['name'] }}</span>
                                                    <span class="shrink-0 rounded-full bg-gray-100 px-1.5 py-0.5 text-[9px] font-bold uppercase tracking-wide text-gray-500 dark:bg-white/10 dark:text-gray-400">{{ $modelMeta['tag'] }}</span>
                                                </span>
                                                @if ($modelMeta['use'] !== '')
                                                    <span class="mt-0.5 block text-[10px] leading-snug text-gray-500 dark:text-gray-400">{{ $modelMeta['use'] }}</span>
                                                @endif
                                            </span>
                                        </button>
                                    @endforeach
                                    </div>
                                @endforeach
                            </div>
                            <p class="border-t border-gray-100 px-3 py-2 text-[10px] leading-snug text-gray-400 dark:border-white/10 dark:text-gray-500">
                                {{ __('assistant.model.fallback_note') }}
                            </p>
                        </div>
                    </div>
                    @endif
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
                    @if ($briefChat)
                    <button
                        type="button"
                        wire:click="toggleBriefing"
                        wire:loading.attr="disabled"
                        wire:target="toggleBriefing"
                        title="{{ $briefEnabled ? __('assistant.briefing.toggle_off') : __('assistant.briefing.toggle_on') }}"
                        class="rounded-lg p-2 transition {{ $briefEnabled ? 'text-amber-500 hover:bg-amber-50 dark:hover:bg-amber-500/10' : 'text-gray-300 hover:bg-gray-100 hover:text-gray-500 dark:text-gray-600 dark:hover:bg-white/10 dark:hover:text-gray-400' }}"
                    >
                        <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0" />
                            @unless ($briefEnabled)
                                <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 4.5 15 15" />
                            @endunless
                        </svg>
                    </button>
                    @endif
                    @if ($activeChatId !== null)
                        <button
                            type="button"
                            wire:click="exportChat"
                            wire:loading.attr="disabled"
                            wire:target="exportChat"
                            title="{{ __('assistant.export_chat') }}"
                            class="rounded-lg p-2 text-gray-400 transition hover:bg-gray-100 hover:text-gray-600 disabled:opacity-50 dark:hover:bg-white/10 dark:hover:text-gray-200"
                        >
                            <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" />
                            </svg>
                        </button>
                    @endif
                    <button
                        type="button"
                        x-bind:disabled="chatBusy"
                        x-on:click="chatView = 'chat'; chatBusy = true; $wire.newChat().finally(() => chatBusy = false)"
                        title="{{ __('assistant.new_chat') }}"
                        class="rounded-lg p-2 text-gray-400 transition hover:bg-gray-100 hover:text-gray-600 disabled:opacity-50 dark:hover:bg-white/10 dark:hover:text-gray-200"
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
            x-ref="chatsScroll"
            class="assistant-scroll flex-1 overflow-y-auto bg-gray-50 px-3 py-3 [overscroll-behavior:contain] dark:bg-gray-950/60"
        >
                {{-- Historial de conversaciones --}}
                <button
                    type="button"
                    x-bind:disabled="chatBusy"
                    x-on:click="chatView = 'chat'; chatBusy = true; $wire.newChat().finally(() => chatBusy = false)"
                    class="mb-3 flex w-full items-center justify-center gap-2 rounded-xl border-2 border-dashed border-gray-300 px-4 py-3 text-sm font-semibold text-gray-500 transition hover:border-primary-400 hover:text-primary-600 disabled:opacity-60 dark:border-white/15 dark:text-gray-400 dark:hover:border-primary-500 dark:hover:text-primary-400"
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
                                x-bind:disabled="chatBusy"
                                x-on:click="chatView = 'chat'; chatBusy = true; $wire.selectChat({{ $chat['id'] }}).finally(() => chatBusy = false)"
                                class="flex min-w-0 flex-1 items-center gap-3 text-left disabled:opacity-60"
                            >
                                <span @class([
                                    'flex h-8 w-8 shrink-0 items-center justify-center rounded-lg',
                                    'bg-amber-100 text-amber-600 dark:bg-amber-500/15 dark:text-amber-300' => ($chat['kind'] ?? 'user') === 'briefing' && ($chat['brief_enabled'] ?? true),
                                    'bg-gray-200 text-gray-400 dark:bg-white/10 dark:text-gray-500' => ($chat['kind'] ?? 'user') !== 'briefing' || ! ($chat['brief_enabled'] ?? true),
                                ])>
                                    @if (($chat['kind'] ?? 'user') === 'briefing')
                                        <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                                        </svg>
                                    @else
                                        <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M7.5 8.25h9m-9 3H12m-9.75 1.51c0 1.6 1.123 2.994 2.707 3.227 1.129.166 2.27.293 3.423.379.35.026.67.21.865.501L12 21l2.755-4.133a1.14 1.14 0 0 1 .865-.501 48.172 48.172 0 0 0 3.423-.379c1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0 0 12 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018Z" />
                                        </svg>
                                    @endif
                                </span>
                                <span class="min-w-0">
                                    <span class="flex items-center gap-1.5">
                                        <span class="truncate text-sm font-medium text-gray-800 dark:text-gray-100">{{ ($chat['kind'] ?? 'user') === 'briefing' ? __('assistant.briefing.title') : $chat['title'] }}</span>
                                        @if (($chat['kind'] ?? 'user') === 'briefing')
                                            @if ($chat['brief_enabled'] ?? true)
                                                <span class="shrink-0 rounded-md bg-amber-50 px-1.5 py-0.5 text-[9px] font-bold uppercase tracking-wide text-amber-700 ring-1 ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-400/25">{{ __('assistant.briefing.badge') }}</span>
                                            @else
                                                <span class="shrink-0 rounded-md bg-gray-100 px-1.5 py-0.5 text-[9px] font-bold uppercase tracking-wide text-gray-500 ring-1 ring-gray-500/20 dark:bg-white/10 dark:text-gray-400 dark:ring-white/15">{{ __('assistant.briefing.disabled_badge') }}</span>
                                            @endif
                                        @endif
                                    </span>
                                    <span
                                        x-show="chatBadges[{{ $chat['id'] }}]"
                                        class="mt-0.5 flex flex-wrap items-center gap-1"
                                    >
                                        <span
                                            x-show="badgeFor({{ $chat['id'] }}).d !== ''"
                                            class="inline-flex max-w-full items-center gap-1 rounded-md bg-amber-50 px-1.5 py-0.5 text-[10px] font-medium text-amber-700 ring-1 ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-400/25"
                                        >
                                            <svg class="h-3 w-3 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10" />
                                            </svg>
                                            <span
                                                class="truncate"
                                                x-text="@js(__('assistant.badge_draft') . ': ') + badgeFor({{ $chat['id'] }}).d.slice(0, 42) + (badgeFor({{ $chat['id'] }}).d.length > 42 ? '…' : '')"
                                            ></span>
                                        </span>
                                        <span
                                            x-show="badgeFor({{ $chat['id'] }}).q > 0"
                                            class="inline-flex items-center gap-1 rounded-md bg-sky-50 px-1.5 py-0.5 text-[10px] font-medium text-sky-700 ring-1 ring-sky-600/20 dark:bg-sky-500/10 dark:text-sky-300 dark:ring-sky-400/25"
                                        >
                                            <svg class="h-3 w-3 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 12h16.5m-16.5 3.75h16.5M3.75 19.5h16.5M5.625 4.5h12.75a1.875 1.875 0 0 1 0 3.75H5.625a1.875 1.875 0 0 1 0-3.75Z" />
                                            </svg>
                                            <span x-text="badgeFor({{ $chat['id'] }}).q + ' ' + @js(__('assistant.badge_queue'))"></span>
                                        </span>
                                    </span>
                                    @if ($chat['updated_at'])
                                        <span class="block text-[11px] text-gray-400 dark:text-gray-500">{{ $chat['updated_at'] }}</span>
                                    @endif
                                </span>
                            </button>
                            @if (($chat['kind'] ?? 'user') !== 'briefing')
                            <button
                                type="button"
                                x-data
                                x-on:click="if (confirm(@js(__('assistant.delete_chat_confirm')))) { $wire.deleteChat({{ $chat['id'] }}) }"
                                title="{{ __('assistant.delete_chat') }}"
                                class="rounded-lg p-1.5 text-gray-300 opacity-100 transition hover:bg-red-50 hover:text-red-500 focus-within:opacity-100 group-hover:opacity-100 sm:opacity-0 dark:text-gray-600 dark:hover:bg-red-500/10 dark:hover:text-red-400"
                            >
                                <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                                </svg>
                            </button>
                            @endif
                        </div>
                    @empty
                        <p class="px-2 py-6 text-center text-sm text-gray-400 dark:text-gray-500">{{ __('assistant.no_chats') }}</p>
                    @endforelse
                </div>
                @endif
            </div>

            @if ($briefMuted)
                {{-- Briefing desactivado: el chat no existe como conversacion.
                     Ni mensajes, ni composer, ni sugerencias — solo el aviso
                     y el boton para reactivar el job diario. --}}
                <div
                    x-show="chatView === 'chat'"
                    class="flex min-h-0 flex-1 flex-col items-center justify-center gap-4 px-6 text-center"
                >
                    <div class="flex h-14 w-14 items-center justify-center rounded-2xl bg-gray-100 text-gray-400 ring-1 ring-gray-950/5 dark:bg-white/5 dark:text-gray-500 dark:ring-white/10">
                        <svg class="h-7 w-7" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" />
                        </svg>
                    </div>
                    <div class="space-y-1">
                        <p class="text-sm font-bold text-gray-800 dark:text-gray-100">{{ __('assistant.briefing.disabled_title') }}</p>
                        <p class="max-w-xs text-xs leading-relaxed text-gray-500 dark:text-gray-400">{{ __('assistant.briefing.disabled_text') }}</p>
                    </div>
                    <button
                        type="button"
                        wire:click="toggleBriefing"
                        wire:loading.attr="disabled"
                        wire:target="toggleBriefing"
                        class="rounded-xl bg-primary-600 px-4 py-2 text-xs font-bold text-white transition hover:bg-primary-500 disabled:opacity-50"
                    >
                        {{ __('assistant.briefing.toggle_on') }}
                    </button>
                </div>
            @else

            {{-- Conversacion --}}
            <div
                wire:ignore.self
                x-ref="chatPane"
                x-show="chatView === 'chat'"
                class="relative flex min-h-0 flex-1 flex-col"
            >
            <div
                wire:ignore.self
                x-ref="messagesScroll"
                x-init="$nextTick(() => { $el.scrollTop = $el.scrollHeight })"
                x-on:assistant-scroll.window="$nextTick(() => { if (atBottom) { $el.scrollTop = $el.scrollHeight } })"
                x-on:scroll.passive="updateAtBottom()"
                aria-live="polite"
                class="assistant-scroll min-h-0 flex-1 space-y-4 overflow-y-auto bg-gray-50 px-4 py-4 [overscroll-behavior:contain] dark:bg-gray-950/60"
            >
                @php
                    $lastAssistantIndex = -1;
                    $lastMessageDay = null;
                    foreach ($messages as $i => $m) {
                        if ($m['role'] === 'assistant') {
                            $lastAssistantIndex = $i;
                        }
                    }
                    // Iniciales del usuario para su avatar: nombre completo
                    // si existe, si no el correo, si no un icono generico.
                    $userDisplay = trim((string) (auth()->user()?->name ?? ''));
                    if ($userDisplay === '') {
                        $userDisplay = (string) (auth()->user()?->email ?? '');
                    }
                    $userInitials = collect(preg_split('/\s+/', $userDisplay) ?: [])
                        ->filter()
                        ->take(2)
                        ->map(fn (string $w): string => mb_strtoupper(mb_substr($w, 0, 1)))
                        ->implode('');
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
                        @php
                            // Separador de dia estilo WhatsApp: badge centrado
                            // cuando cambia la fecha entre mensajes.
                            $messageDay = ! empty($message['at']) ? \Illuminate\Support\Carbon::parse($message['at']) : null;
                            $showDaySeparator = $messageDay !== null && $messageDay->toDateString() !== $lastMessageDay;
                            $lastMessageDay = $messageDay?->toDateString() ?? $lastMessageDay;
                            $dayLabel = $messageDay?->isToday() ? __('assistant.today')
                                : ($messageDay?->isYesterday() ? __('assistant.yesterday') : $messageDay?->format('d/m/Y'));
                        @endphp
                        @if ($showDaySeparator)
                            <div class="flex justify-center">
                                <span class="rounded-full bg-gray-200/70 px-3 py-1 text-[10px] font-semibold text-gray-500 dark:bg-white/10 dark:text-gray-400">
                                    {{ $dayLabel }}
                                </span>
                            </div>
                        @endif
                        @php
                            $isUserMessage = $message['role'] === 'user';
                            // Mensajes consecutivos del mismo rol se agrupan
                            // (menos aire) y el avatar se oculta salvo en el
                            // primero del bloque, estilo chat moderno. El
                            // agrupado se rompe en los separadores de dia.
                            $sameRoleAsPrev = $index > 0
                                && ! $showDaySeparator
                                && ($messages[$index - 1]['role'] ?? null) === $message['role'];
                            // La hora solo se muestra en el ultimo mensaje
                            // del grupo: una sola marca de tiempo por bloque,
                            // no una por burbuja.
                            $nextMessage = $messages[$index + 1] ?? null;
                            $nextMessageDay = $nextMessage !== null && ! empty($nextMessage['at'])
                                ? \Illuminate\Support\Carbon::parse($nextMessage['at'])->toDateString()
                                : null;
                            $sameRoleAsNext = $nextMessage !== null
                                && $nextMessage['role'] === $message['role']
                                && ($messageDay === null || $nextMessageDay === $messageDay->toDateString());
                            // Respuesta descartada por cancelacion/force-send:
                            // se pinta como aviso neutro, no como burbuja normal.
                            $isCancelledNotice = ($message['notice'] ?? null) === 'cancelled';
                        @endphp
                        <div
                            wire:key="msg-{{ $message['id'] ?? $index }}"
                            {{-- 10px en vez de los 16px de space-y-4: agrupa
                                 el bloque sin que las burbujas se solapen
                                 (un margin negativo aqui las encimaba). --}}
                            @if ($sameRoleAsPrev) style="margin-top:10px" @endif
                            class="assistant-msg group flex items-end gap-2 {{ $isUserMessage ? 'justify-end' : 'justify-start' }}"
                        >
                            @if (! $isUserMessage)
                                <span
                                    @class([
                                        'flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-primary-50 text-primary-600 ring-1 ring-primary-600/10 transition dark:bg-primary-500/15 dark:text-primary-300 dark:ring-primary-400/25',
                                        'invisible' => $sameRoleAsPrev,
                                    ])
                                    aria-hidden="true"
                                >
                                    <svg class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 0 0-2.456 2.456Z" />
                                    </svg>
                                </span>
                            @endif
                            <div @class([
                                'relative min-w-0 w-fit max-w-[75%] rounded-2xl px-4 py-3 text-sm shadow-sm lg:max-w-xl',
                                'rounded-br-md bg-primary-600 text-white' => $isUserMessage,
                                'rounded-bl-md bg-white text-gray-800 ring-1 ring-gray-950/5 dark:bg-gray-800 dark:text-gray-100 dark:ring-white/10' => ! $isUserMessage && ! $isCancelledNotice,
                                'rounded-bl-md bg-gray-100 text-gray-600 ring-1 ring-gray-950/5 dark:bg-white/5 dark:text-gray-300 dark:ring-white/10' => $isCancelledNotice,
                            ])>
                                @if ($isCancelledNotice)
                                    {{-- Cancelado: cabecera de aviso neutra
                                         estilo Laravel; el texto de abajo
                                         explica que se puede reformular. --}}
                                    <p class="mb-1 flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                                        <svg class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                            <rect x="7" y="7" width="10" height="10" rx="2" />
                                        </svg>
                                        {{ __('assistant.cancelled_title') }}
                                    </p>
                                @endif
                                {{-- Respuestas batch: cada seccion lleva su texto
                                     seguido de sus tablas, en el orden en que el
                                     usuario pidio las cosas. --}}
                                @if (! empty($message['sections']))
                                    @foreach ($message['sections'] as $section)
                                        @if (! empty($section['text']))
                                            <div class="assistant-md leading-relaxed {{ $loop->first ? '' : 'mt-3' }}">{!! \Illuminate\Support\Str::markdown($section['text'], ['html_input' => 'escape', 'allow_unsafe_links' => false]) !!}</div>
                                        @endif
                                        @include('livewire.partials.assistant-tables', ['data' => $section['data'] ?? null, 'messageIndex' => $index, 'badgeColors' => $message['badge_colors'] ?? []])
                                    @endforeach
                                @else
                                    {{-- Markdown solo para el asistente: el texto
                                         del usuario se muestra literal — un * o _ en
                                         un nombre/cantidad no debe alterar el formato. --}}
                                    @if ($isUserMessage)
                                        <p class="whitespace-pre-line leading-relaxed">{{ $message['text'] }}</p>
                                    @else
                                        <div class="assistant-md leading-relaxed">{!! \Illuminate\Support\Str::markdown($message['text'], ['html_input' => 'escape', 'allow_unsafe_links' => false]) !!}</div>
                                    @endif

                                    {{-- Datos estructurados: tabla compacta cuando el
                                         intent devolvio filas. --}}
                                    @include('livewire.partials.assistant-tables', ['data' => $message['data'] ?? null, 'messageIndex' => $index, 'badgeColors' => $message['badge_colors'] ?? []])
                                @endif

                                {{-- Guia de uso (app_guide): la tarjeta
                                     compacta es clickeable y abre la guia
                                     completa en el modal de preview. --}}
                                @include('livewire.partials.assistant-guide', ['data' => $message['data'] ?? null, 'compact' => true, 'messageIndex' => $index, 'previewMethod' => 'previewGuide', 'previewIndex' => $index])

                                {{-- Respuesta ambigua: chips clicables con
                                     los candidatos. Elegir uno re-ejecuta
                                     la peticion original sobre esa entidad. --}}
                                {{-- Analisis predictivo (business_insights):
                                     la tarjeta compacta es clickeable y abre
                                     el analisis completo en el modal. --}}
                                @include('livewire.partials.assistant-insights', ['data' => $message['data'] ?? null, 'compact' => true, 'previewMethod' => 'previewInsights', 'previewIndex' => $index])

                                {{-- Reporte compuesto por la IA (analisis o
                                     guia): la tarjeta compacta es clickeable
                                     y abre el reporte completo en el modal. --}}
                                @include('livewire.partials.assistant-report', ['data' => $message['data'] ?? null, 'compact' => true, 'previewMethod' => 'previewReport', 'previewIndex' => $index])

                                @php $candidates = $message['data']['candidates'] ?? null; @endphp
                                @if (is_array($candidates) && $candidates !== [])
                                    <div class="mt-3">
                                        <p class="mb-1.5 text-[10px] font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500">{{ __('assistant.candidates.pick_one') }}</p>
                                        <div class="flex flex-wrap gap-1.5">
                                            @foreach ($candidates as $candidate)
                                                <button
                                                    type="button"
                                                    wire:click="pickCandidate({{ $index }}, '{{ $candidate['token'] ?? '' }}')"
                                                    wire:loading.attr="disabled"
                                                    class="group inline-flex max-w-full items-center gap-1.5 rounded-lg bg-white px-2.5 py-1.5 text-left text-xs font-medium text-gray-700 shadow-sm ring-1 ring-gray-950/10 transition hover:bg-primary-50 hover:ring-primary-300 dark:bg-gray-800 dark:text-gray-200 dark:ring-white/10 dark:hover:bg-primary-500/10"
                                                >
                                                    <svg class="h-3.5 w-3.5 shrink-0 text-gray-400 transition group-hover:text-primary-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" />
                                                    </svg>
                                                    <span class="min-w-0">
                                                        <span class="block truncate">{{ $candidate['name'] ?? '—' }}</span>
                                                        @php $candidateDetail = trim(implode(' · ', array_filter([$candidate['tenant'] ?? null, $candidate['detail'] ?? null]))); @endphp
                                                        @if ($candidateDetail !== '')
                                                            <span class="block truncate text-[10px] font-normal text-gray-400 dark:text-gray-500">{{ $candidateDetail }}</span>
                                                        @endif
                                                    </span>
                                                </button>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif

                                {{-- Archivo generado por una accion (documento
                                     o CSV): tarjeta con descarga directa. --}}
                                @if (! empty($message['data']['file']['token']))
                                    @php $file = $message['data']['file']; @endphp
                                    <div class="mt-3 overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/10 dark:bg-gray-800 dark:ring-white/10">
                                        <div class="flex items-stretch">
                                            <button
                                                type="button"
                                                x-on:click="openPreview(() => $wire.previewFile({{ $index }}))"
                                                title="{{ __('assistant.preview.view_file') }}"
                                                class="flex items-center justify-center px-3 text-gray-400 transition hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-white/10 dark:hover:text-gray-200"
                                            >
                                                <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                                </svg>
                                            </button>
                                            <button
                                                type="button"
                                                wire:click="downloadFile('{{ $file['token'] }}')"
                                                wire:loading.attr="disabled"
                                                class="flex w-full items-center gap-3 px-3.5 py-3 text-left transition hover:bg-gray-50 dark:hover:bg-white/5"
                                            >
                                                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-gray-100 text-gray-600 dark:bg-white/10 dark:text-gray-300">
                                                    <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" />
                                                    </svg>
                                                </span>
                                                <span class="min-w-0 flex-1">
                                                    <span class="block truncate text-xs font-semibold text-gray-800 dark:text-gray-100">{{ $file['filename'] }}</span>
                                                    <span class="block text-[10px] text-gray-500 dark:text-gray-400">{{ __('assistant.download_file') }}</span>
                                                </span>
                                                <svg class="h-4 w-4 shrink-0 text-gray-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" />
                                                </svg>
                                            </button>
                                        </div>
                                        @if (! empty($file['preview']))
                                            <pre class="max-h-48 overflow-auto border-t border-gray-950/5 bg-gray-50 p-3 text-[11px] leading-relaxed text-gray-600 [overscroll-behavior:contain] dark:border-white/5 dark:bg-black/20 dark:text-gray-300">{{ $file['preview'] }}</pre>
                                        @endif
                                    </div>
                                @endif

                                {{-- Accion propuesta: la IA propone, el humano
                                     confirma. El color/icono identifica el
                                     tipo de operacion de un vistazo. --}}
                                @if (! empty($message['action']))
                                    @php
                                        $actionType = (string) ($message['action']['type'] ?? '');
                                        $actionMeta = match (true) {
                                            str_starts_with($actionType, 'create_') => ['create', 'M12 9v6m3-3H9m12 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z'],
                                            str_starts_with($actionType, 'update_') || str_starts_with($actionType, 'record_adjustment') || str_starts_with($actionType, 'apply_adjustment') || str_starts_with($actionType, 'mark_adjustment') || str_starts_with($actionType, 'recalculate') => ['update', 'M16.862 4.487l1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10'],
                                            str_contains($actionType, 'payment') => ['payment', 'M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 0 0-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 0 1-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 0 0 3 15h-.75M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm3 0h.008v.008H18V10.5Zm-12 0h.008v.008H6V10.5Z'],
                                            str_contains($actionType, 'annul') || str_contains($actionType, 'deactivate') => ['danger', 'M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.008v.008H12v-.008Z'],
                                            str_contains($actionType, 'document') || str_contains($actionType, 'export') || str_contains($actionType, 'bill') || str_contains($actionType, 'invoice') => ['document', 'M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z'],
                                            str_contains($actionType, 'ipc') || str_contains($actionType, 'settings') => ['settings', 'M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.24-.438.613-.431.992a6.759 6.759 0 0 1 0 .255c-.007.378.138.75.43.99l1.005.828c.424.35.534.954.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.57 6.57 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.28c-.09.543-.56.941-1.11.941h-2.594c-.55 0-1.02-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.992a6.932 6.932 0 0 1 0-.255c.007-.378-.138-.75-.43-.99l-1.004-.828a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.087.22-.128.332-.183.582-.495.644-.869l.214-1.281Z'],
                                            default => ['default', 'M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.008v.008H12v-.008Z'],
                                        };
                                        [$actionTone, $actionIcon] = $actionMeta;
                                        $actionToneClasses = match ($actionTone) {
                                            'create' => 'border-emerald-300 bg-emerald-50 dark:border-emerald-500/40 dark:bg-emerald-500/10',
                                            'update' => 'border-sky-300 bg-sky-50 dark:border-sky-500/40 dark:bg-sky-500/10',
                                            'payment' => 'border-amber-300 bg-amber-50 dark:border-amber-500/40 dark:bg-amber-500/10',
                                            'danger' => 'border-red-300 bg-red-50 dark:border-red-500/40 dark:bg-red-500/10',
                                            'document' => 'border-cyan-300 bg-cyan-50 dark:border-cyan-500/40 dark:bg-cyan-500/10',
                                            'settings' => 'border-violet-300 bg-violet-50 dark:border-violet-500/40 dark:bg-violet-500/10',
                                            default => 'border-gray-300 bg-gray-50 dark:border-white/15 dark:bg-white/5',
                                        };
                                        $actionTextClasses = match ($actionTone) {
                                            'create' => 'text-emerald-700 dark:text-emerald-300',
                                            'update' => 'text-sky-700 dark:text-sky-300',
                                            'payment' => 'text-amber-700 dark:text-amber-300',
                                            'danger' => 'text-red-700 dark:text-red-300',
                                            'document' => 'text-cyan-700 dark:text-cyan-300',
                                            'settings' => 'text-violet-700 dark:text-violet-300',
                                            default => 'text-gray-700 dark:text-gray-300',
                                        };
                                    @endphp
                                    <div class="mt-3 rounded-xl border p-3 {{ $actionToneClasses }}">
                                        <p class="flex items-center gap-1.5 text-xs font-bold uppercase tracking-wide {{ $actionTextClasses }}">
                                            <svg class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="{{ $actionIcon }}" />
                                            </svg>
                                            {{ __('assistant.action_proposed') }}
                                        </p>
                                        <p class="mt-1.5 text-sm font-medium text-gray-900 dark:text-white">{{ $message['action']['summary'] ?? '' }}</p>
                                        @if (! empty($message['action']['warnings']))
                                            <ul class="mt-2 space-y-1">
                                                @foreach ($message['action']['warnings'] as $warning)
                                                    <li class="flex items-start gap-1.5 text-xs text-amber-700 dark:text-amber-400">
                                                        <svg class="mt-0.5 h-3 w-3 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                                                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.008v.008H12v-.008Z" />
                                                        </svg>
                                                        {{ $warning }}
                                                    </li>
                                                @endforeach
                                            </ul>
                                        @endif
                                        <div class="mt-2.5 flex flex-wrap items-center gap-2">
                                            {{-- Estandar para TODA accion: preview en el
                                                 modal (con datos completos y campos
                                                 editables si los hay) y, si el usuario
                                                 prefiere, confirmacion directa aqui. --}}
                                            <button
                                                type="button"
                                                x-on:click="openPreview(() => $wire.previewAction({{ $index }}))"
                                                class="inline-flex items-center gap-1.5 rounded-lg bg-white px-3 py-1.5 text-xs font-semibold text-gray-700 ring-1 ring-gray-300 transition hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:ring-white/15 dark:hover:bg-gray-700"
                                            >
                                                <svg class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                                </svg>
                                                {{ __('assistant.preview.action_title') }}
                                            </button>
                                            <button
                                                type="button"
                                                wire:click="confirmAction({{ $index }})"
                                                wire:loading.attr="disabled"
                                                wire:target="confirmAction,confirmPreview"
                                                class="inline-flex items-center gap-1.5 rounded-lg bg-primary-600 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-primary-500 disabled:opacity-50"
                                            >
                                                <svg class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                                                </svg>
                                                {{ __('assistant.confirm') }}
                                            </button>
                                            <button
                                                type="button"
                                                wire:click="cancelAction({{ $index }})"
                                                class="inline-flex items-center gap-1.5 rounded-lg bg-white px-3 py-1.5 text-xs font-semibold text-gray-600 ring-1 ring-gray-300 transition hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-300 dark:ring-white/10 dark:hover:bg-gray-700"
                                            >
                                                {{ __('assistant.cancel') }}
                                            </button>
                                        </div>
                                    </div>
                                @elseif (($message['action_resolved'] ?? false) === true || ($message['action_cancelled'] ?? false))
                                    @php
                                        $resolvedAction = $message['resolved_action'] ?? null;
                                        $resolvedType = is_array($resolvedAction) ? (string) ($resolvedAction['type'] ?? '') : '';
                                        $resolvedSummaryKey = 'assistant.actions.'.$resolvedType.'_summary';
                                        $resolvedSummary = __($resolvedSummaryKey);
                                        // batch_summary lleva :items y no se puede
                                        // resolver aqui: etiqueta propia sin params.
                                        if ($resolvedType === 'batch') {
                                            $resolvedLabel = __('assistant.actions.batch_label');
                                        } else {
                                            $resolvedLabel = $resolvedSummary === $resolvedSummaryKey || str_contains($resolvedSummary, ':')
                                                ? ucfirst(str_replace('_', ' ', $resolvedType))
                                                : $resolvedSummary;
                                        }
                                        $resolvedChanges = is_array($resolvedAction['changes'] ?? null) ? $resolvedAction['changes'] : [];
                                    @endphp
                                    @if ($message['action_cancelled'] ?? false)
                                        {{-- Accion descartada: tarjeta neutra — NO
                                             debe verse como si el cambio se
                                             hubiera aplicado. --}}
                                        <div class="mt-3 overflow-hidden rounded-xl border border-gray-300 bg-gray-50 dark:border-white/15 dark:bg-white/5">
                                            <div class="flex items-center gap-2 px-3 py-2">
                                                <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-gray-200 text-gray-500 dark:bg-white/10 dark:text-gray-400">
                                                    <svg class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor" aria-hidden="true">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                                                    </svg>
                                                </span>
                                                <div class="min-w-0 flex-1">
                                                    <p class="text-[10px] font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ __('assistant.action_cancelled_title') }}</p>
                                                    <p class="truncate text-xs font-semibold text-gray-700 dark:text-gray-200">{{ $resolvedLabel !== '' ? $resolvedLabel : __('assistant.action_resolved') }}</p>
                                                </div>
                                            </div>
                                        </div>
                                    @else
                                    <div class="mt-3 overflow-hidden rounded-xl border border-emerald-300 bg-emerald-50/70 dark:border-emerald-500/40 dark:bg-emerald-500/10">
                                        <div class="flex items-center gap-2 px-3 py-2">
                                            <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-emerald-500/15 text-emerald-600 dark:text-emerald-400">
                                                <svg class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor" aria-hidden="true">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                                                </svg>
                                            </span>
                                            <div class="min-w-0 flex-1">
                                                <p class="text-[10px] font-bold uppercase tracking-wider text-emerald-700/80 dark:text-emerald-400/80">{{ __('assistant.action_done') }}</p>
                                                <p class="truncate text-xs font-semibold text-emerald-900 dark:text-emerald-200">{{ $resolvedLabel !== '' ? $resolvedLabel : __('assistant.action_resolved') }}</p>
                                            </div>
                                        </div>
                                        @if ($resolvedChanges !== [])
                                            <dl class="divide-y divide-emerald-900/5 border-t border-emerald-900/10 dark:divide-white/5 dark:border-white/10">
                                                @foreach (array_slice($resolvedChanges, 0, 6) as $change)
                                                    @php
                                                        // Labels de campo: sin articulo y con
                                                        // mayuscula inicial, igual que en el preview.
                                                        $cl = trim((string) preg_replace('/^(?:el|la|los|las|the)\s+/iu', '', (string) ($change['label'] ?? '')));
                                                        $cl = $cl === '' ? '' : mb_strtoupper(mb_substr($cl, 0, 1)).mb_substr($cl, 1);
                                                    @endphp
                                                    <div class="flex items-center justify-between gap-3 px-3 py-1.5 text-[11px]">
                                                        <dt class="shrink-0 text-emerald-900/60 dark:text-emerald-200/60">{{ $cl }}</dt>
                                                        <dd class="flex min-w-0 items-center gap-1.5 text-right">
                                                            @if (($change['before'] ?? null) !== null && ($change['before'] ?? '') !== '')
                                                                <span class="truncate text-emerald-900/40 line-through dark:text-emerald-200/40">{{ $change['before'] }}</span>
                                                            @endif
                                                            <span class="font-semibold text-emerald-900 dark:text-emerald-100">{{ ($change['after'] ?? null) ?: '—' }}</span>
                                                        </dd>
                                                    </div>
                                                @endforeach
                                            </dl>
                                        @endif
                                    </div>
                                    @endif
                                @endif

                                {{-- Acciones del mensaje: "ver todas las tablas"
                                     y "ir a la seccion" conviven en la misma
                                     fila y se apilan solos si no hay espacio. --}}
                                @if (($message['table_count'] ?? 0) > 1 || ! empty($message['redirect']))
                                    <div class="mt-3 flex flex-wrap items-center gap-2">
                                        @if (($message['table_count'] ?? 0) > 1)
                                            <button
                                                type="button"
                                                x-on:click="openPreview(() => $wire.previewAllData({{ $index }}))"
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

                                {{-- Opciones A-D: cuando la IA pregunta algo
                                     con interpretaciones concretas, cada
                                     opcion es un texto que se re-envia por
                                     el mismo input; el texto libre sigue
                                     disponible. Solo en el ultimo mensaje. --}}
                                @if (! empty($message['options']) && is_array($message['options']) && $index === $lastAssistantIndex)
                                    <div class="mt-3">
                                        <p class="mb-1.5 text-[10px] font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500">{{ __('assistant.options.pick_one') }}</p>
                                        <div class="space-y-1.5">
                                            @foreach (array_slice($message['options'], 0, 4) as $optionIndex => $option)
                                                <button
                                                    type="button"
                                                    x-on:click="sendSuggestion(@js($option))"
                                                    x-bind:class="sending ? 'pointer-events-none opacity-60' : ''"
                                                    title="{{ $option }}"
                                                    class="group flex w-full items-center gap-2.5 rounded-lg bg-white px-2.5 py-2 text-left text-xs font-medium text-gray-700 shadow-sm ring-1 ring-gray-950/10 transition hover:bg-primary-50 hover:ring-primary-300 dark:bg-gray-800 dark:text-gray-200 dark:ring-white/10 dark:hover:bg-primary-500/10"
                                                >
                                                    <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-md bg-gray-100 text-[10px] font-bold text-gray-500 ring-1 ring-gray-950/5 transition group-hover:bg-primary-100 group-hover:text-primary-700 dark:bg-white/10 dark:text-gray-400 dark:ring-white/10 dark:group-hover:bg-primary-500/20 dark:group-hover:text-primary-300">{{ chr(65 + $optionIndex) }}</span>
                                                    <span class="line-clamp-2 min-w-0 flex-1 break-words">{{ $option }}</span>
                                                </button>
                                            @endforeach
                                        </div>
                                        <p class="mt-1.5 text-[10px] text-gray-400 dark:text-gray-500">{{ __('assistant.options.or_type') }}</p>
                                    </div>
                                @endif

                                {{-- Sugerencias: solo en el ultimo mensaje del
                                     asistente para no saturar el historial. --}}
                                @if (! empty($message['suggestions']) && $index === $lastAssistantIndex)
                                    <div class="mt-3 flex flex-wrap gap-1.5">
                                        @foreach ($message['suggestions'] as $suggestion)
                                            <button
                                                type="button"
                                                x-on:click="sendSuggestion(@js($suggestion))"
                                                x-bind:class="sending ? 'pointer-events-none opacity-60' : ''"
                                                class="rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-600 transition hover:bg-primary-100 hover:text-primary-700 dark:bg-white/10 dark:text-gray-300 dark:hover:bg-primary-500/20 dark:hover:text-primary-300"
                                            >
                                                {{ $suggestion }}
                                            </button>
                                        @endforeach
                                    </div>
                                @endif

                                @if ($message['role'] === 'assistant' && ! $isCancelledNotice)
                                    @php $isLastMessage = $index === count($messages) - 1; @endphp
                                    <div class="absolute -right-7 top-1/2 z-10 flex -translate-y-1/2 flex-col items-center gap-1">
                                        {{-- Regenerar: solo en el ultimo mensaje
                                             del chat — rehace la respuesta sobre
                                             la misma pregunta sin duplicarla. --}}
                                        @if ($isLastMessage)
                                            <button
                                                type="button"
                                                wire:click="regenerateLast"
                                                wire:loading.attr="disabled"
                                                wire:target="regenerateLast"
                                                x-bind:class="sending ? 'pointer-events-none opacity-50' : ''"
                                                title="{{ __('assistant.regenerate') }}"
                                                class="flex h-6 w-6 items-center justify-center rounded-full bg-white text-gray-400 opacity-0 shadow-md ring-1 ring-gray-950/10 transition hover:text-primary-600 focus-visible:opacity-100 group-hover:opacity-100 hover:opacity-100 disabled:opacity-50 [@media(hover:none)]:opacity-100 dark:bg-gray-700 dark:text-gray-400 dark:ring-white/10 dark:hover:text-primary-400"
                                            >
                                                <svg class="h-3 w-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" />
                                                </svg>
                                            </button>
                                        @endif
                                        <button
                                            type="button"
                                            x-data="{ copied: false }"
                                            x-on:click="navigator.clipboard.writeText(@js($message['text'])).then(() => { copied = true; setTimeout(() => copied = false, 1500) })"
                                            title="{{ __('assistant.copy') }}"
                                            // Flota fuera del borde derecho de la
                                            // card (4px de aire): aparece al
                                            // hacer hover en el mensaje o al
                                            // acercar el cursor al propio boton.
                                            // En pantallas touch no hay hover:
                                            // se muestra siempre.
                                            class="flex h-6 w-6 items-center justify-center rounded-full bg-white text-gray-400 opacity-0 shadow-md ring-1 ring-gray-950/10 transition hover:text-primary-600 focus-visible:opacity-100 group-hover:opacity-100 hover:opacity-100 [@media(hover:none)]:opacity-100 dark:bg-gray-700 dark:text-gray-400 dark:ring-white/10 dark:hover:text-primary-400"
                                        >
                                            <svg x-show="!copied" class="h-3 w-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M15.666 3.888A2.25 2.25 0 0 0 13.5 2.25h-3c-1.03 0-1.9.693-2.166 1.638m7.332 0c.055.194.084.4.084.612v0a.75.75 0 0 1-.75.75H9.75a.75.75 0 0 1-.75-.75v0c0-.212.03-.418.084-.612m7.332 0c.646.049 1.288.11 1.927.184 1.1.128 1.907 1.077 1.907 2.185V19.5a2.25 2.25 0 0 1-2.25 2.25H6.75A2.25 2.25 0 0 1 4.5 19.5V6.257c0-1.108.806-2.057 1.907-2.185a48.208 48.208 0 0 1 1.927-.184" />
                                            </svg>
                                            <svg x-show="copied" x-cloak class="h-3 w-3 text-success-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                                            </svg>
                                        </button>
                                    </div>
                                @endif

                                {{-- Chip "profundizar": solo en respuestas
                                     con reporte — relanza el analisis en
                                     modo exhaustivo sobre el mismo tema. --}}
                                @if (($message['role'] ?? '') === 'assistant' && ! empty($message['data']['report'] ?? null))
                                    <div class="mt-2">
                                        <button
                                            type="button"
                                            x-on:click="sendSuggestion(@js(__('assistant.deep_prompt')))"
                                            x-bind:class="sending ? 'pointer-events-none opacity-60' : ''"
                                            class="inline-flex items-center gap-1.5 rounded-full bg-violet-50 px-2.5 py-1 text-xs font-medium text-violet-700 ring-1 ring-violet-600/20 transition hover:bg-violet-100 dark:bg-violet-500/10 dark:text-violet-300 dark:ring-violet-400/25 dark:hover:bg-violet-500/20"
                                        >
                                            <svg class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3v11.25A2.25 2.25 0 0 0 6 16.5h2.25M3.75 3h-1.5m1.5 0h16.5m0 0h1.5m-1.5 0v11.25A2.25 2.25 0 0 1 18 16.5h-2.25m-7.5 0h7.5m-7.5 0-1 3m8.5-3 1 3m0 0 .5 1.5m-.5-1.5h-9.5m0 0-.5 1.5M9 11.25v1.5M12 9v3.75m3-6v6" />
                                            </svg>
                                            {{ __('assistant.deep_chip') }}
                                        </button>
                                    </div>
                                @endif

                                {{-- Feedback sutil: 👍/👎 sobre la calidad
                                     de la respuesta; el estado activo queda
                                     guardado en el payload del mensaje. --}}
                                @if ($message['role'] === 'assistant' && ! $isCancelledNotice && ! empty($message['id']))
                                    <div class="mt-2 flex items-center justify-end gap-0.5">
                                        <button
                                            type="button"
                                            wire:click="setFeedback({{ $message['id'] }}, 'up')"
                                            wire:loading.attr="disabled"
                                            wire:target="setFeedback"
                                            title="{{ __('assistant.feedback_up') }}"
                                            @class([
                                                'rounded-md p-1 transition',
                                                'text-success-500' => ($message['feedback'] ?? null) === 'up',
                                                'text-gray-300 hover:text-success-500 dark:text-gray-600 dark:hover:text-success-400' => ($message['feedback'] ?? null) !== 'up',
                                            ])
                                        >
                                            <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                                <path d="M1 21h4V9H1v12zm22-11c0-1.1-.9-2-2-2h-6.31l.95-4.57.03-.32c0-.41-.17-.79-.44-1.06L14.17 1 7.59 7.59C7.22 7.95 7 8.45 7 9v10c0 1.1.9 2 2 2h9c.83 0 1.54-.5 1.84-1.22l3.02-7.05c.09-.23.14-.47.14-.73v-2z" />
                                            </svg>
                                        </button>
                                        <button
                                            type="button"
                                            wire:click="setFeedback({{ $message['id'] }}, 'down')"
                                            wire:loading.attr="disabled"
                                            wire:target="setFeedback"
                                            title="{{ __('assistant.feedback_down') }}"
                                            @class([
                                                'rounded-md p-1 transition',
                                                'text-danger-500' => ($message['feedback'] ?? null) === 'down',
                                                'text-gray-300 hover:text-danger-500 dark:text-gray-600 dark:hover:text-danger-400' => ($message['feedback'] ?? null) !== 'down',
                                            ])
                                        >
                                            <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                                <path d="M15 3H6c-.83 0-1.54.5-1.84 1.22l-3.02 7.05c-.09.23-.14.47-.14.73v2c0 1.1.9 2 2 2h6.31l-.95 4.57-.03.32c0 .41.17.79.44 1.06L9.83 23l6.59-6.59c.36-.36.58-.86.58-1.41V5c0-1.1-.9-2-2-2zm4 0v12h4V3h-4z" />
                                            </svg>
                                        </button>
                                    </div>
                                @endif

                                @if (($message['at'] ?? null) && ! $sameRoleAsNext)
                                    <p
                                        class="mt-1.5 {{ $isUserMessage ? '' : '' }} text-right text-[10px] {{ $isUserMessage ? 'text-white/70' : 'text-gray-500 dark:text-gray-400' }}"
                                        title="{{ \Illuminate\Support\Carbon::parse($message['at'])->translatedFormat('d/m/Y h:i A') }}"
                                    >
                                        {{ \Illuminate\Support\Carbon::parse($message['at'])->translatedFormat('h:i A') }}
                                    </p>
                                @endif
                            </div>
                            @if ($isUserMessage)
                                <span
                                    @class([
                                        'flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-primary-100 text-[10px] font-bold text-primary-700 ring-1 ring-primary-600/15 transition dark:bg-primary-500/15 dark:text-primary-300 dark:ring-primary-400/25',
                                        'invisible' => $sameRoleAsPrev,
                                    ])
                                    aria-hidden="true"
                                >
                                    {{ $userInitials !== '' ? $userInitials : '·' }}
                                </span>
                            @endif
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
                                        x-bind:class="sending ? 'pointer-events-none opacity-60' : ''"
                                        x-on:click="sendSuggestion(@js(__("assistant.suggestions.{$key}")))"
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
                        <div class="assistant-msg flex items-end justify-end gap-2">
                            <div class="relative w-fit max-w-[75%] rounded-2xl rounded-br-md bg-primary-600 px-4 py-3 text-sm text-white shadow-sm lg:max-w-xl">
                                <p class="whitespace-pre-line leading-relaxed" x-text="pendingText"></p>
                            </div>
                            <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-primary-100 text-[10px] font-bold text-primary-700 ring-1 ring-primary-600/15 dark:bg-primary-500/15 dark:text-primary-300 dark:ring-primary-400/25" aria-hidden="true">
                                {{ $userInitials !== '' ? $userInitials : '·' }}
                            </span>
                        </div>
                    </template>

                    {{-- Indicador de escritura: `sending` lo muestra en el
                         mismo tick de Alpine que el mensaje pendiente, asi el
                         scrollToBottom del envio ya lo deja visible. --}}
                    <div
                        x-show="sending"
                        x-cloak
                        class="flex items-end justify-start gap-2"
                    >
                        <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-primary-50 text-primary-600 ring-1 ring-primary-600/10 dark:bg-primary-500/15 dark:text-primary-300 dark:ring-primary-400/25" aria-hidden="true">
                            <svg class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 0 0-2.456 2.456Z" />
                            </svg>
                        </span>
                        <div class="flex items-center gap-2 rounded-2xl rounded-bl-md bg-white px-4 py-3 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-800 dark:ring-white/10">
                            <span class="flex gap-1">
                                <span class="assistant-dot h-1.5 w-1.5 rounded-full bg-primary-500"></span>
                                <span class="assistant-dot h-1.5 w-1.5 rounded-full bg-primary-500" style="animation-delay: 0.15s"></span>
                                <span class="assistant-dot h-1.5 w-1.5 rounded-full bg-primary-500" style="animation-delay: 0.3s"></span>
                            </span>
                            <span
                                x-text="thinkingText()"
                                class="text-xs font-medium text-gray-500 dark:text-gray-400"
                            ></span>
                            <button
                                type="button"
                                x-show="canCancelThinking()"
                                x-cloak
                                x-on:click="cancelThinking()"
                                class="ml-1 rounded-md px-2 py-0.5 text-xs font-semibold text-danger-600 ring-1 ring-danger-600/30 transition hover:bg-danger-50 dark:text-danger-400 dark:hover:bg-danger-500/10"
                                x-text="@js(__('assistant.cancel'))"
                            ></button>
                        </div>
                    </div>
                @endif
            </div>

            {{-- Skeleton al cambiar de chat: cubre la conversacion hasta que
                 Livewire responde, asi no se ve el historial anterior. --}}
            <div
                x-show="chatBusy"
                x-cloak
                class="absolute inset-0 z-20 flex flex-col gap-4 bg-gray-50 px-4 py-4 dark:bg-gray-950"
            >
                @foreach ([1, 2, 3] as $i)
                    <div @class(['h-12 w-2/3 animate-pulse rounded-2xl bg-gray-200 dark:bg-white/10', 'self-end' => $i % 2 === 0])></div>
                @endforeach
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

        {{-- Splitter vertical: el chat es flex-1 y el compositor tiene
             altura propia; arrastrar arriba agranda el chat, abajo el
             compositor. Doble clic restablece el reparto. --}}
        <div
            x-show="chatView === 'chat'"
            x-ref="vHandleMessages"
            x-on:mousedown="startVResize($event, 'composer')"
            x-on:touchstart="startVResize($event, 'composer')"
            x-on:keydown.up.prevent="vResizeKey('composer', 24)"
            x-on:keydown.down.prevent="vResizeKey('composer', -24)"
            x-on:dblclick="resetComposerHeight()"
            role="separator"
            aria-orientation="horizontal"
            aria-label="{{ __('assistant.resize_messages') }}"
            aria-valuemin="110"
            :aria-valuemax="vMaxFor('composer')"
            :aria-valuenow="vCurrentHeight('composer')"
            tabindex="0"
            title="{{ __('assistant.resize_messages') }}"
            class="group flex h-2 w-full shrink-0 cursor-row-resize touch-none items-center justify-center bg-gray-100 transition hover:bg-primary-500/30 focus:outline-none focus:ring-2 focus:ring-primary-500 dark:bg-white/5 dark:hover:bg-primary-500/20"
            :class="vMode === 'composer' && 'bg-primary-500/40'"
        >
            <span class="h-1 w-12 rounded-full bg-gray-300 transition group-hover:bg-primary-500 group-focus:bg-primary-500 dark:bg-white/20"></span>
        </div>

        {{-- Input (solo visible en vista de chat) --}}
        <div
            wire:ignore.self
            x-show="chatView === 'chat'"
            x-ref="composer"
            :style="composerHeight !== null ? 'height: ' + composerHeight + 'px; flex: none' : ''"
            :class="composerHeight === null && 'max-h-[72dvh]'"
            class="flex min-h-0 shrink-0 flex-col overflow-hidden bg-white dark:bg-gray-900"
        >
        <div
            x-ref="composerInner"
            class="flex min-h-0 flex-1 flex-col border-t border-gray-200 bg-white px-3 pb-3 pt-2 dark:border-white/10 dark:bg-gray-900"
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
                    {{-- Zona desplazable: sugerencias, ayuda y formulario
                         guiado. El campo de texto queda anclado abajo, asi
                         al reducir el compositor se oculta lo de arriba y
                         nunca desaparece el input. --}}
                    <div
                        x-ref="composerScroll"
                        class="assistant-scroll min-h-0 flex-1 overflow-y-auto"
                    >
                    {{-- Wrapper flow-root: contiene los margenes de los
                         paneles para que offsetHeight sea la altura
                         natural del contenido (fitChatInput la usa para
                         saber cuanto espacio queda para el textarea). --}}
                    <div x-ref="composerContent" class="flow-root">
                    {{-- Los quick prompts ceden el sitio cuando hay otra
                         UI dentro del composer: wizard guiado (pendingForm)
                         o mensajes en cola. Sin ellos el composer abraza
                         solo lo que importa y el input queda pegado. --}}
                    @if ($pendingForm === null)
                    <div class="mb-2">
                        <div class="mb-1.5 flex items-center justify-between px-0.5">
                            <button
                                type="button"
                                x-on:click="toggleSuggest()"
                                :aria-expanded="suggestOpen"
                                class="flex items-center gap-1 text-[10px] font-bold uppercase tracking-wider text-gray-400 transition hover:text-gray-600 dark:text-gray-500 dark:hover:text-gray-300"
                            >
                                <svg class="h-3 w-3 transition-transform duration-200" :class="suggestOpen && 'rotate-90'" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
                                </svg>
                                {{ __('assistant.quick_prompts') }}
                            </button>
                            <button
                                type="button"
                                x-show="suggestOpen"
                                x-on:click="if (! clickGuardActive()) promptsOpen = ! promptsOpen"
                                :aria-expanded="promptsOpen"
                                class="flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-semibold text-primary-600 transition hover:bg-primary-50 dark:text-primary-400 dark:hover:bg-primary-500/10"
                            >
                                <svg class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25" />
                                </svg>
                                {{ __('assistant.help.toggle') }}
                                <svg class="h-3 w-3 transition-transform duration-200" :class="promptsOpen && 'rotate-180'" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                                </svg>
                            </button>
                        </div>

                        <div x-show="suggestOpen" x-transition.opacity.duration.150ms>
                        @if (! empty($contextSuggestions))
                            <div class="flex gap-1.5 overflow-x-auto px-0.5 pb-1 pt-2 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                                @foreach ($contextSuggestions as $key)
                                    <button
                                        type="button"
                                        x-bind:class="sending ? 'pointer-events-none opacity-60' : ''"
                                        x-on:click="sendSuggestion(@js(__("assistant.suggestions.{$key}")))"
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

                        {{-- Repertorio de prompts: categorias desplegables con
                             todos los prompts que la IA entiende. Cada opcion
                             envia el texto traducido como mensaje normal. --}}
                        <div
                            x-show="promptsOpen"
                            x-ref="promptsPanel"
                            x-transition:enter="transition ease-out duration-200"
                            x-transition:enter-start="opacity-0 -translate-y-1"
                            x-transition:enter-end="opacity-100 translate-y-0"
                            x-transition:leave="transition ease-in duration-150"
                            x-transition:leave-start="opacity-100"
                            x-transition:leave-end="opacity-0"
                            :style="{ 'max-height': promptsMaxHeight() }"
                            class="mt-2 max-h-96 overflow-y-auto rounded-xl bg-gray-50 p-2.5 ring-1 ring-gray-950/5 [overscroll-behavior:contain] [scrollbar-width:thin] dark:bg-white/5 dark:ring-white/10"
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
                                                    x-bind:class="sending ? 'pointer-events-none opacity-60' : ''"
                                                    x-on:click="if (! clickGuardActive()) { promptsOpen = false; sendSuggestion(@js($prompt)) }"
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
                    </div>
                    @endif

                {{-- Wizard guiado en curso: progreso, campo actual,
                     respuestas rapidas y cancelacion. --}}
                @if ($pendingForm !== null)
                    @php
                        $wizardStep = max(1, min((int) ($pendingForm['step'] ?? 1), (int) ($pendingForm['total_steps'] ?? 1)));
                        $wizardTotal = max(1, (int) ($pendingForm['total_steps'] ?? 1));
                        $wizardLast = $wizardStep >= $wizardTotal;
                    @endphp
                    <div
                        wire:ignore.self
                        x-data="{ open: true }"
                        x-ref="wizardCard"
                        :style="wizardHeight !== null ? 'height: ' + wizardHeight + 'px' : ''"
                        :class="wizardHeight === null && 'max-h-[52dvh]'"
                        class="mx-1 mb-2 mt-1 flex min-h-0 min-w-0 flex-col overflow-x-clip rounded-xl bg-primary-50 p-3 ring-1 ring-primary-600/15 dark:bg-primary-500/10 dark:ring-primary-400/25"
                    >
                        <div class="flex items-center justify-between gap-2">
                            <p class="flex min-w-0 items-center gap-1.5 text-xs font-bold text-primary-700 dark:text-primary-300">
                                <svg class="h-3.5 w-3.5 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09Z" />
                                </svg>
                                <span class="truncate">
                                    {{ $pendingForm['form_label'] ?? ($pendingForm['form'] === 'delivery_act' ? __('act.navigation_label') : $pendingForm['form']) }}
                                    · {{ __('assistant.wizard.step_of', ['step' => $wizardStep, 'total' => $wizardTotal]) }}
                                </span>
                            </p>
                            <span class="flex shrink-0 items-center gap-1">
                                @if ($wizardStep > 1 && empty($pendingForm['fields']) && empty($pendingForm['input']))
                                    <button
                                        type="button"
                                        wire:click="prevFormStep"
                                        class="rounded-lg bg-white px-2.5 py-1 text-[11px] font-bold text-gray-600 ring-1 ring-gray-950/10 transition hover:bg-gray-50 dark:bg-white/10 dark:text-gray-300 dark:ring-white/15 dark:hover:bg-white/15"
                                    >
                                        {{ __('assistant.wizard.previous') }}
                                    </button>
                                @endif
                                <button
                                    type="button"
                                    x-on:click="open = ! open; scheduleFitChatInput()"
                                    x-bind:title="open ? @js(__('assistant.wizard.collapse')) : @js(__('assistant.wizard.expand'))"
                                    class="rounded-full p-1 text-primary-500 transition hover:bg-primary-100 dark:hover:bg-white/10"
                                >
                                    <svg class="h-3.5 w-3.5 transition-transform" x-bind:class="open ? '' : 'rotate-180'" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 15.75 7.5-7.5 7.5 7.5" />
                                    </svg>
                                </button>
                                <button
                                    type="button"
                                    wire:click="cancelForm"
                                    class="rounded-full px-2 py-0.5 text-[10px] font-semibold text-red-600 transition hover:bg-red-100 dark:text-red-400 dark:hover:bg-red-500/15"
                                >
                                    {{ __('assistant.wizard.cancel') }}
                                </button>
                            </span>
                        </div>
                        <div x-show="open" x-transition.opacity.duration.150ms class="flex min-h-0 flex-1 flex-col">
                        <div class="mt-2 flex items-center gap-1">
                            @for ($stepIndex = 1; $stepIndex <= $wizardTotal; $stepIndex++)
                                <span
                                    class="h-1.5 flex-1 rounded-full transition-colors {{ $stepIndex <= $wizardStep ? 'bg-primary-600 dark:bg-primary-400' : 'bg-primary-600/15 dark:bg-white/10' }}"
                                    title="{{ __('assistant.wizard.step_of', ['step' => $stepIndex, 'total' => $wizardTotal]) }}"
                                ></span>
                            @endfor
                        </div>
                        <p class="mt-2 text-xs font-medium text-gray-700 dark:text-gray-200">{{ $pendingForm['prompt'] ?? '' }}</p>

                        {{-- Input tipado del campo actual: el tipo sale del
                             kind real del campo (fecha, numero, switch,
                             select de enum, textarea), no un input generico. --}}
                        @php $wfInput = $pendingForm['input'] ?? null; @endphp
                        {{-- Formulario vacio del update guiado: TODOS los
                             campos editables de la entidad, cada uno con su
                             tipo real (fecha, numero, switch, select, textarea).
                             Se envian por FormData — los vacios no se tocan. --}}
                        @php $wfFields = $pendingForm['fields'] ?? null; @endphp
                        @if (is_array($wfFields) && $wfFields !== [])
                            {{-- wire:key por paso+contenido: al avanzar a la
                                 siguiente entidad del batch el form se
                                 reemplaza entero. Sin esto Livewire morph
                                 conserva los valores que el usuario tecleo
                                 en la entidad anterior. --}}
                            <form
                                wire:key="wf-{{ $pendingForm['form'] ?? 'x' }}-{{ $pendingForm['step'] ?? 1 }}-{{ $pendingForm['total_steps'] ?? 1 }}-{{ md5(json_encode([$pendingForm['prompt'] ?? '', $wfFields])) }}"
                                x-on:submit.prevent="$wire.submitForm(Object.fromEntries(new FormData($el).entries()))"
                                class="mt-2 grid min-h-0 flex-1 content-start gap-2 overflow-y-auto pr-1 sm:grid-cols-2"
                            >
                                {{-- Discriminador de sesion: en un batch mixto
                                     pueden coexistir cola de creacion y de
                                     update; el envio cae en la correcta. --}}
                                <input type="hidden" name="__form" value="{{ $pendingForm['form'] ?? '' }}">
                                @foreach ($wfFields as $wfField)
                                    @php
                                        $fk = (string) ($wfField['key'] ?? '');
                                        $fkind = (string) ($wfField['kind'] ?? 'text');
                                        $fval = (string) ($wfField['value'] ?? '');
                                        // Los labels vienen pensados para frases ("el
                                        // telefono"); en el formulario van limpios:
                                        // sin articulo y con mayuscula inicial.
                                        $flabel = trim((string) preg_replace('/^(?:el|la|los|las|the)\s+/iu', '', (string) ($wfField['label'] ?? $fk)));
                                        $flabel = $flabel === '' || $flabel === $fk ? $fk : mb_strtoupper(mb_substr($flabel, 0, 1)).mb_substr($flabel, 1);
                                        $freq = ! empty($wfField['required']);
                                        $fClass = 'w-full rounded-lg bg-white px-2.5 py-1.5 text-xs text-gray-900 ring-1 ring-gray-950/10 focus:outline-none focus:ring-2 focus:ring-primary-500 dark:bg-gray-900 dark:text-gray-100 dark:ring-white/10';
                                    @endphp
                                    <label class="block text-[11px] {{ $fkind === 'textarea' ? 'sm:col-span-2' : '' }}">
                                        <span class="mb-0.5 block font-medium text-gray-500 dark:text-gray-400">{{ $flabel }}@if ($freq)<span class="ml-0.5 text-red-500" aria-hidden="true">*</span>@endif</span>
                                        @if ($fkind === 'textarea')
                                            <textarea name="{{ $fk }}" rows="2" @if ($freq) required @endif class="{{ $fClass }}">{{ $fval }}</textarea>
                                        @elseif ($fkind === 'date')
                                            <input type="date" name="{{ $fk }}" value="{{ $fval }}" @if ($freq) required @endif class="{{ $fClass }}">
                                        @elseif ($fkind === 'datetime')
                                            <input type="datetime-local" name="{{ $fk }}" value="{{ $fval }}" @if ($freq) required @endif class="{{ $fClass }}">
                                        @elseif ($fkind === 'number')
                                            <input type="number" name="{{ $fk }}" step="{{ $wfField['step'] ?? 'any' }}" inputmode="decimal" value="{{ $fval }}" @if (isset($wfField['min'])) min="{{ $wfField['min'] }}" @endif @if (isset($wfField['max'])) max="{{ $wfField['max'] }}" @endif @if ($freq) required @endif class="{{ $fClass }}">
                                        @elseif ($fkind === 'range')
                                            <input type="range" name="{{ $fk }}" value="{{ $fval !== '' ? $fval : ($wfField['min'] ?? 0) }}" min="{{ $wfField['min'] ?? 0 }}" max="{{ $wfField['max'] ?? 100 }}" step="{{ $wfField['step'] ?? 1 }}" class="{{ $fClass }} py-1">
                                        @elseif ($fkind === 'email')
                                            <input type="email" name="{{ $fk }}" value="{{ $fval }}" @if ($freq) required @endif class="{{ $fClass }}">
                                        @elseif ($fkind === 'tel')
                                            <input type="tel" name="{{ $fk }}" value="{{ $fval }}" @if ($freq) required @endif class="{{ $fClass }}">
                                        @elseif ($fkind === 'password')
                                            <input type="password" name="{{ $fk }}" value="" autocomplete="new-password" class="{{ $fClass }}">
                                        @elseif ($fkind === 'bool')
                                            <select name="{{ $fk }}" class="{{ $fClass }}">
                                                <option value="1" @selected($fval === '1')>{{ __('assistant.wizard.yes') }}</option>
                                                <option value="0" @selected($fval === '0')>{{ __('assistant.wizard.no') }}</option>
                                            </select>
                                        @elseif ($fkind === 'select' && is_array($wfField['options'] ?? null) && $wfField['options'] !== [])
                                            <select name="{{ $fk }}" class="{{ $fClass }}">
                                                <option value="">{{ str_starts_with($pendingForm['form'] ?? '', 'create_') ? __('assistant.fields.optional') : __('assistant.update_form.keep') }}</option>
                                                @foreach ($wfField['options'] as $optValue => $optLabel)
                                                    @if (is_array($optLabel))
                                                        <option value="{{ $optLabel['value'] ?? '' }}" @selected($fval === (string) ($optLabel['value'] ?? ''))>{{ $optLabel['label'] ?? '' }}</option>
                                                    @else
                                                        <option value="{{ is_int($optValue) ? $optLabel : $optValue }}" @selected($fval === (string) $optValue)>{{ $optLabel }}</option>
                                                    @endif
                                                @endforeach
                                            </select>
                                        @else
                                            <input type="text" name="{{ $fk }}" value="{{ $fval }}" autocomplete="off" @if ($freq) required @endif class="{{ $fClass }}">
                                        @endif
                                    </label>
                                @endforeach
                                <div class="flex items-center justify-between gap-2 self-start sm:col-span-2">
                                    @if ($wizardStep > 1)
                                        <button
                                            type="button"
                                            wire:click="prevFormStep"
                                            class="h-fit shrink-0 rounded-lg bg-white px-3.5 py-1.5 text-[11px] font-bold leading-tight text-gray-600 ring-1 ring-gray-950/10 transition hover:bg-gray-50 dark:bg-white/10 dark:text-gray-300 dark:ring-white/15 dark:hover:bg-white/15"
                                        >
                                            {{ __('assistant.wizard.previous') }}
                                        </button>
                                    @else
                                        <span></span>
                                    @endif
                                    <button
                                        type="submit"
                                        class="h-fit shrink-0 rounded-lg bg-primary-600 px-3.5 py-1.5 text-[11px] font-bold leading-tight text-white transition hover:bg-primary-500"
                                    >
                                        {{ $wizardLast
                                            ? (str_starts_with($pendingForm['form'] ?? '', 'create_') ? __('assistant.create_form.submit') : __('assistant.update_form.submit'))
                                            : __('assistant.wizard.next') }}
                                    </button>
                                </div>
                            </form>
                        @elseif (is_array($wfInput))
                            {{-- Mismo motivo que el form de campos: key por
                                 paso para que el input del wizard no arrastre
                                 lo tecleado en el paso anterior. --}}
                            <form
                                wire:key="wi-{{ $pendingForm['form'] ?? 'x' }}-{{ $pendingForm['step'] ?? 1 }}-{{ md5(json_encode([$pendingForm['prompt'] ?? '', $wfInput])) }}"
                                x-on:submit.prevent="const v = new FormData($el).get('fv'); if (v !== null && String(v).trim() !== '') { $wire.answerFormField(String(v).trim()); }"
                                class="mt-2 flex flex-wrap items-center gap-2"
                            >
                                @php
                                    $wfKind = (string) ($wfInput['kind'] ?? 'text');
                                    $wfClass = 'w-full rounded-lg bg-white px-2.5 py-1.5 text-xs text-gray-900 ring-1 ring-gray-950/10 focus:outline-none focus:ring-2 focus:ring-primary-500 dark:bg-gray-900 dark:text-gray-100 dark:ring-white/10';
                                @endphp
                                @if ($wfKind === 'date')
                                    <input type="date" name="fv" class="{{ $wfClass }} sm:w-44">
                                @elseif ($wfKind === 'datetime')
                                    <input type="datetime-local" name="fv" class="{{ $wfClass }} sm:w-52">
                                @elseif ($wfKind === 'number')
                                    <input type="number" name="fv" step="any" inputmode="decimal" class="{{ $wfClass }} sm:w-40">
                                @elseif ($wfKind === 'range')
                                    <input type="range" name="fv" min="{{ $wfInput['min'] ?? 0 }}" max="{{ $wfInput['max'] ?? 100 }}" step="{{ $wfInput['step'] ?? 1 }}" class="{{ $wfClass }} sm:w-40">
                                @elseif ($wfKind === 'email')
                                    <input type="email" name="fv" autocomplete="email" class="{{ $wfClass }} sm:w-48">
                                @elseif ($wfKind === 'tel')
                                    <input type="tel" name="fv" autocomplete="tel" class="{{ $wfClass }} sm:w-40">
                                @elseif ($wfKind === 'password')
                                    <input type="password" name="fv" autocomplete="new-password" class="{{ $wfClass }} sm:w-44">
                                @elseif ($wfKind === 'bool')
                                    <select name="fv" class="{{ $wfClass }} sm:w-32">
                                        <option value="sí">{{ __('assistant.wizard.yes') }}</option>
                                        <option value="no">{{ __('assistant.wizard.no') }}</option>
                                    </select>
                                @elseif ($wfKind === 'select' && ! empty($wfInput['options']))
                                    <select name="fv" class="{{ $wfClass }} sm:w-48">
                                        @foreach ($wfInput['options'] as $opt)
                                            <option value="{{ $opt }}">{{ $opt }}</option>
                                        @endforeach
                                    </select>
                                @elseif ($wfKind === 'textarea')
                                    <textarea name="fv" rows="2" class="{{ $wfClass }}"></textarea>
                                @elseif ($wfKind === 'entity_select' && ! empty($wfInput['options']))
                                    {{-- Select buscable de entidades reales: el
                                         usuario filtra por nombre y elige; tambien
                                         puede escribir un nombre a mano. --}}
                                    <div
                                        x-data="{ q: '', picked: '', options: {{ \Illuminate\Support\Js::from($wfInput['options']) }} }"
                                        class="relative w-full"
                                    >
                                        <input
                                            type="text"
                                            x-model="q"
                                            x-on:input="picked = ''"
                                            x-on:focus="picked = ''"
                                            placeholder="{{ $wfInput['label'] ?? '' }}"
                                            autocomplete="off"
                                            class="{{ $wfClass }}"
                                        >
                                        <input type="hidden" name="fv" x-bind:value="picked !== '' ? picked : q">
                                        <div
                                            x-show="picked === '' && q.trim() !== '' && options.filter(o => (o.label + ' ' + o.value).toLowerCase().includes(q.toLowerCase())).length > 0"
                                            x-on:click.outside="picked = q"
                                            class="absolute z-30 mt-1 max-h-52 w-full overflow-y-auto rounded-lg bg-white py-1 shadow-lg ring-1 ring-gray-950/10 dark:bg-gray-800 dark:ring-white/15"
                                        >
                                            <template x-for="opt in options.filter(o => (o.label + ' ' + o.value).toLowerCase().includes(q.toLowerCase())).slice(0, 12)" :key="opt.value">
                                                <button
                                                    type="button"
                                                    x-on:click="picked = opt.value; q = opt.label"
                                                    class="block w-full px-3 py-1.5 text-left text-xs text-gray-700 transition hover:bg-primary-50 dark:text-gray-200 dark:hover:bg-primary-500/10"
                                                    x-text="opt.label"
                                                ></button>
                                            </template>
                                        </div>
                                    </div>
                                @else
                                    <input type="text" name="fv" autocomplete="off" class="{{ $wfClass }}">
                                @endif
                                <span class="flex items-center gap-2 sm:ml-auto">
                                    @if ($wizardStep > 1)
                                        <button
                                            type="button"
                                            wire:click="prevFormStep"
                                            class="shrink-0 rounded-lg bg-white px-3 py-1.5 text-[11px] font-bold text-gray-600 ring-1 ring-gray-950/10 transition hover:bg-gray-50 dark:bg-white/10 dark:text-gray-300 dark:ring-white/15 dark:hover:bg-white/15"
                                        >
                                            {{ __('assistant.wizard.previous') }}
                                        </button>
                                    @endif
                                    <button
                                        type="submit"
                                        class="shrink-0 rounded-lg bg-primary-600 px-3 py-1.5 text-[11px] font-bold text-white transition hover:bg-primary-500"
                                    >
                                        {{ $wizardLast ? __('assistant.wizard.send') : __('assistant.wizard.next') }}
                                    </button>
                                </span>
                            </form>
                        @endif

                        @if (! empty($pendingForm['quick_replies']))
                            <div class="mt-2 flex flex-wrap gap-1.5">
                                @foreach ($pendingForm['quick_replies'] as $reply)
                                    <button
                                        type="button"
                                        x-bind:class="sending ? 'pointer-events-none opacity-60' : ''"
                                        x-on:click="sendSuggestion(@js($reply))"
                                        class="rounded-full bg-white px-2.5 py-1 text-[11px] font-semibold text-primary-700 ring-1 ring-primary-600/20 transition hover:bg-primary-600 hover:text-white dark:bg-white/10 dark:text-primary-300 dark:ring-primary-400/30 dark:hover:bg-primary-500/30"
                                    >
                                        {{ $reply }}
                                    </button>
                                @endforeach
                            </div>
                        @endif

                        {{-- Splitter de la tarjeta: barra completa como la
                             del composer para que el resize sea evidente.
                             Arrastrar abajo agranda el wizard; el historial
                             se desplaza dentro del scroll del composer. --}}
                        <div
                            x-on:mousedown="startVResize($event, 'wizard')"
                            x-on:touchstart="startVResize($event, 'wizard')"
                            x-on:keydown.up.prevent="vResizeKey('wizard', -24)"
                            x-on:keydown.down.prevent="vResizeKey('wizard', 24)"
                            x-on:dblclick="resetWizardHeight()"
                            role="separator"
                            aria-orientation="horizontal"
                            aria-label="{{ __('assistant.resize_wizard') }}"
                            aria-valuemin="160"
                            :aria-valuemax="vMaxFor('wizard')"
                            :aria-valuenow="vCurrentHeight('wizard')"
                            tabindex="0"
                            title="{{ __('assistant.resize_wizard') }}"
                            class="group -mx-3 mt-1 flex h-2.5 w-[calc(100%+1.5rem)] shrink-0 cursor-row-resize touch-none items-center justify-center rounded-b-xl transition hover:bg-primary-600/15 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-primary-500"
                            :class="vMode === 'wizard' && 'bg-primary-600/25'"
                        >
                            <span class="h-1 w-12 rounded-full bg-primary-600/30 transition group-hover:bg-primary-600/70 group-focus:bg-primary-600/70 dark:bg-primary-400/30 dark:group-hover:bg-primary-400/70" :class="vMode === 'wizard' && 'bg-primary-600/80'"></span>
                        </div>
                        </div>
                    </div>
                @endif
                    </div>
                    </div>

                {{-- Cola de mensajes: aparece cuando la IA esta respondiendo
                     y el usuario sigue escribiendo. Cada item se puede
                     reordenar (arrastrar), quitar o enviar ya — eso cancela
                     la respuesta en curso y la manda de inmediato. --}}
                <div
                    x-show="msgQueue.length > 0"
                    x-cloak
                    class="mb-2 shrink-0 overflow-hidden rounded-xl bg-gray-50 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10"
                >
                    <button
                        type="button"
                        x-on:click="queueOpen = ! queueOpen"
                        class="flex w-full items-center gap-2 px-2.5 py-1.5 text-left"
                    >
                        <svg class="h-3.5 w-3.5 shrink-0 text-primary-500 dark:text-primary-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                        </svg>
                        <span class="min-w-0 flex-1 truncate text-[11px] font-bold text-gray-600 dark:text-gray-300">{{ __('assistant.queue.title') }}</span>
                        <span
                            x-show="queueFullFlash"
                            x-cloak
                            x-text="@js(__('assistant.queue.full'))"
                            class="shrink-0 rounded-full bg-red-100 px-2 py-0.5 text-[10px] font-semibold text-red-600 dark:bg-red-500/15 dark:text-red-400"
                        ></span>
                        <span class="flex h-5 min-w-5 shrink-0 items-center justify-center rounded-full bg-primary-600 px-1.5 text-[10px] font-bold text-white" x-text="msgQueue.length"></span>
                        <svg class="h-3.5 w-3.5 shrink-0 text-gray-400 transition-transform duration-200" :class="queueOpen && 'rotate-180'" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                        </svg>
                    </button>
                    <div
                        x-show="queueOpen"
                        x-transition:enter="transition ease-out duration-150"
                        x-transition:enter-start="opacity-0 -translate-y-1"
                        x-transition:enter-end="opacity-100 translate-y-0"
                        class="max-h-36 overflow-y-auto border-t border-gray-950/5 px-1.5 py-1 [overscroll-behavior:contain] dark:border-white/10"
                    >
                        <template x-for="(item, qi) in msgQueue" :key="'queued-' + qi">
                            <div
                                draggable="true"
                                x-on:dragstart="queueDragStart(qi, $event)"
                                x-on:dragover="queueDragOver(qi, $event)"
                                x-on:drop="queueDrop(qi, $event)"
                                x-on:dragend="queueDragEnd()"
                                x-bind:class="queueDragOver === qi && queueDragFrom !== qi ? 'bg-primary-50 ring-1 ring-primary-500/40 dark:bg-primary-500/10 dark:ring-primary-400/30' : ''"
                                class="group/q flex cursor-grab items-center gap-1.5 rounded-lg px-1 py-1.5 transition active:cursor-grabbing hover:bg-white dark:hover:bg-white/5"
                            >
                                <svg class="h-3.5 w-3.5 shrink-0 text-gray-300 transition group-hover/q:text-gray-400 dark:text-white/20 dark:group-hover/q:text-white/40" xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <circle cx="8" cy="6" r="1.6" /><circle cx="16" cy="6" r="1.6" /><circle cx="8" cy="12" r="1.6" /><circle cx="16" cy="12" r="1.6" /><circle cx="8" cy="18" r="1.6" /><circle cx="16" cy="18" r="1.6" />
                                </svg>
                                <span class="min-w-0 flex-1 truncate text-xs text-gray-600 dark:text-gray-300" x-text="item"></span>
                                <button
                                    type="button"
                                    x-on:click="sendQueuedNow(qi)"
                                    x-bind:title="@js(__('assistant.queue.send_now'))"
                                    aria-label="{{ __('assistant.queue.send_now') }}"
                                    class="flex h-6 w-6 shrink-0 items-center justify-center rounded-md text-primary-500 opacity-0 transition hover:bg-primary-100 group-hover/q:opacity-100 focus-visible:opacity-100 [@media(hover:none)]:opacity-100 dark:hover:bg-primary-500/20"
                                >
                                    <svg class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 12 3.269 3.125A59.769 59.769 0 0 1 21.485 12 59.768 59.768 0 0 1 3.27 20.875L5.999 12Zm0 0h7.5" />
                                    </svg>
                                </button>
                                <button
                                    type="button"
                                    x-on:click="removeQueued(qi)"
                                    x-bind:title="@js(__('assistant.queue.remove'))"
                                    aria-label="{{ __('assistant.queue.remove') }}"
                                    class="flex h-6 w-6 shrink-0 items-center justify-center rounded-md text-gray-400 opacity-0 transition hover:bg-red-100 hover:text-red-500 group-hover/q:opacity-100 focus-visible:opacity-100 [@media(hover:none)]:opacity-100 dark:hover:bg-red-500/15 dark:hover:text-red-400"
                                >
                                    <svg class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                                    </svg>
                                </button>
                            </div>
                        </template>
                    </div>
                </div>

                <form
                    x-on:submit.prevent="sendNow()"
                    class="flex shrink-0 items-end gap-2"
                >
                    <textarea
                        x-ref="chatInput"
                        wire:model="input"
                        rows="1"
                        enterkeyhint="send"
                        aria-label="{{ __('assistant.placeholder') }}"
                        placeholder="{{ __('assistant.placeholder') }}"
                        autocomplete="off"
                        x-on:input="inputText = $el.value; fitChatInput(); saveDraft()"
                        x-on:keydown="if ($event.key === 'Enter' && ($event.ctrlKey || $event.metaKey)) { $event.preventDefault(); forceSendInput(); } else if ($event.key === 'Enter' && ! $event.shiftKey) { $event.preventDefault(); $el.form.requestSubmit(); }"
                        class="min-h-[42px] flex-1 resize-none overflow-y-auto rounded-2xl border-0 bg-gray-100 px-4 py-2.5 text-sm text-gray-900 placeholder:text-gray-400 focus:bg-white focus:ring-2 focus:ring-primary-500 dark:bg-white/10 dark:text-white dark:placeholder:text-gray-500 dark:focus:bg-white/15"
                    ></textarea>
                    <button
                        x-bind:type="sending ? 'button' : 'submit'"
                        x-on:click="sending && cancelThinking()"
                        x-bind:aria-label="sending ? @js(__('assistant.cancel')) : @js(__('assistant.send'))"
                        x-bind:disabled="! sending && inputText.trim() === ''"
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-primary-600 text-white shadow-md transition hover:bg-primary-500 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        {{-- Mientras la IA responde el boton enviar se
                             convierte en stop: cancela la respuesta en
                             curso sin borrar el mensaje del usuario. --}}
                        <svg x-show="! sending" class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 12 3.269 3.125A59.769 59.769 0 0 1 21.485 12 59.768 59.768 0 0 1 3.27 20.875L5.999 12Zm0 0h7.5" />
                        </svg>
                        <svg x-show="sending" x-cloak class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                            <rect x="6" y="6" width="12" height="12" rx="2.5" />
                        </svg>
                    </button>
                </form>
                <div class="relative mt-1.5 px-1" x-data="{ helpOpen: false }">
                    <button
                        type="button"
                        x-on:click="helpOpen = ! helpOpen"
                        x-on:click.outside="helpOpen = false"
                        x-bind:aria-expanded="helpOpen"
                        title="{{ __('assistant.shortcuts.title') }}"
                        class="absolute right-1 top-0 rounded-full p-1 text-gray-400 transition hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-white/10 dark:hover:text-gray-300"
                    >
                        <svg class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M12 17.25h.008v.008H12v-.008Z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                        </svg>
                    </button>
                    <p class="text-center text-[10px] leading-tight text-gray-400 dark:text-gray-500">
                        {{ __('assistant.input_hint') }}
                    </p>
                    <div
                        x-show="helpOpen"
                        x-cloak
                        x-transition.opacity.duration.120ms
                        class="absolute bottom-full right-0 z-30 mb-1 w-56 rounded-xl bg-white p-3 text-left shadow-lg ring-1 ring-gray-950/10 dark:bg-gray-800 dark:ring-white/15"
                    >
                        <p class="mb-1.5 text-[10px] font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500">{{ __('assistant.shortcuts.title') }}</p>
                        <ul class="space-y-1 text-[11px] text-gray-600 dark:text-gray-300">
                            <li class="flex items-center justify-between gap-2">
                                <span>{{ __('assistant.shortcuts.enter') }}</span>
                                <kbd class="rounded bg-gray-100 px-1.5 py-0.5 font-mono text-[10px] text-gray-500 dark:bg-white/10 dark:text-gray-400">Enter</kbd>
                            </li>
                            <li class="flex items-center justify-between gap-2">
                                <span>{{ __('assistant.shortcuts.shift_enter') }}</span>
                                <kbd class="rounded bg-gray-100 px-1.5 py-0.5 font-mono text-[10px] text-gray-500 dark:bg-white/10 dark:text-gray-400">Shift + Enter</kbd>
                            </li>
                            <li class="flex items-center justify-between gap-2">
                                <span>{{ __('assistant.shortcuts.ctrl_enter') }}</span>
                                <kbd class="rounded bg-gray-100 px-1.5 py-0.5 font-mono text-[10px] text-gray-500 dark:bg-white/10 dark:text-gray-400">Ctrl + Enter</kbd>
                            </li>
                            <li class="flex items-center justify-between gap-2">
                                <span>{{ __('assistant.shortcuts.queued') }}</span>
                                <span class="text-[10px] text-gray-400 dark:text-gray-500">{{ __('assistant.shortcuts.queued_hint') }}</span>
                            </li>
                        </ul>
                    </div>
                </div>
                <p class="mt-0.5 px-1 text-center text-[10px] leading-tight text-gray-400 dark:text-gray-500">
                    {{ __('assistant.disclaimer') }}
                </p>
        </div>
        </div>
        @endif

        {{-- Modal de preview: acciones por confirmar o tablas expandidas.
             Vive DENTRO del drawer (absolute), asi no pelea con el z-index
             de modales de Filament ni se sale del contexto del chat.
             El shell esta siempre en el DOM y Alpine lo muestra al
             instante (previewBusy) con skeleton dentro; Livewire rellena
             el contenido cuando la respuesta llega. --}}
        <div
            x-show="previewVisible()"
            x-cloak
            class="absolute inset-0 z-20 flex flex-col bg-white dark:bg-gray-900"
        >
                <header class="flex items-center justify-between gap-3 border-b border-gray-200 px-4 py-3 dark:border-white/10">
                    <div class="flex min-w-0 items-center gap-2.5">
                        <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-gray-100 text-gray-500 ring-1 ring-gray-950/5 dark:bg-white/5 dark:text-gray-400 dark:ring-white/10">
                            @php $previewKind = $preview['kind'] ?? ''; @endphp
                            @if ($previewKind === 'action')
                                <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                                </svg>
                            @elseif ($previewKind === 'file')
                                <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
                                </svg>
                            @else
                                <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                </svg>
                            @endif
                        </span>
                        <h3 class="truncate text-sm font-bold text-gray-900 dark:text-white">
                            {{ $preview === null
                                ? __('assistant.preview.loading_title')
                                : ($previewKind === 'action' ? __('assistant.preview.action_title') : $preview['title']) }}
                        </h3>
                    </div>
                    <button
                        type="button"
                        x-on:click="closePreviewModal()"
                        title="{{ __('assistant.close') }}"
                        class="rounded-lg p-2 text-gray-400 transition hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-white/10 dark:hover:text-gray-200"
                    >
                        <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                        </svg>
                    </button>
                </header>

                <div x-ref="previewScroll" class="assistant-scroll min-h-0 flex-1 overflow-y-auto px-4 py-4 [overscroll-behavior:contain]">
                    @if ($preview === null)
                        {{-- Skeleton dentro del modal: la vista abre al
                             instante del click y se llena cuando Livewire
                             devuelve el contenido. --}}
                        <div class="space-y-3">
                            <div class="h-4 w-1/3 animate-pulse rounded bg-gray-200 dark:bg-white/10"></div>
                            <div class="h-32 animate-pulse rounded-xl bg-gray-200 dark:bg-white/5"></div>
                            <div class="h-24 animate-pulse rounded-xl bg-gray-200 dark:bg-white/5"></div>
                            <div class="h-24 animate-pulse rounded-xl bg-gray-200 dark:bg-white/5"></div>
                        </div>
                    @else
                    @if ($previewKind === 'action')
                        @php
                            $previewAction = $preview['action'] ?? [];
                            $previewWarnings = is_array($previewAction['warnings'] ?? null) ? $previewAction['warnings'] : [];
                            $previewEditable = is_array($previewAction['editable'] ?? null) ? $previewAction['editable'] : [];
                            // Las filas con before/after son diffs; el resto
                            // son resumen campo:valor.
                            $previewDiffs = array_values(array_filter(
                                $preview['rows'],
                                fn (array $row): bool => array_key_exists('before', $row) || array_key_exists('after', $row),
                            ));
                            $previewPlainRows = array_values(array_filter(
                                $preview['rows'],
                                fn (array $row): bool => ! array_key_exists('before', $row) && ! array_key_exists('after', $row),
                            ));
                            // Las etiquetas de campo vienen con articulo
                            // ("el teléfono") porque tambien se usan en
                            // frases; en tablas se ven mejor capitalizadas
                            // y sin articulo.
                            $fieldTitle = function (string $label): string {
                                $label = trim((string) preg_replace('/^(?:el|la|los|las|the)\s+/iu', '', $label));

                                return $label === '' ? $label : mb_strtoupper(mb_substr($label, 0, 1)).mb_substr($label, 1);
                            };
                        @endphp
                        <p class="mb-3 text-sm font-medium text-gray-700 dark:text-gray-300">{{ $preview['title'] }}</p>

                        {{-- 1. Warnings: nunca enterrados, van primero. --}}
                        @if ($previewWarnings !== [])
                            <div class="mb-3 space-y-1.5 rounded-xl border border-amber-300 bg-amber-50 p-3 dark:border-amber-500/40 dark:bg-amber-500/10">
                                @foreach ($previewWarnings as $warning)
                                    <p class="flex items-start gap-1.5 text-xs leading-relaxed text-amber-800 dark:text-amber-300">
                                        <svg class="mt-0.5 h-3.5 w-3.5 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.008v.008H12v-.008Z" />
                                        </svg>
                                        {{ $warning }}
                                    </p>
                                @endforeach
                            </div>
                        @endif

                        {{-- 2. Diff before/after: lo viejo tachado, lo nuevo
                             resaltado. Valores null se muestran como "—". --}}
                        @if ($previewDiffs !== [])
                            <div class="mb-3 overflow-hidden rounded-xl ring-1 ring-gray-950/10 dark:ring-white/10">
                                <div class="border-b border-gray-950/5 bg-gray-50 px-3 py-1.5 text-[10px] font-bold uppercase tracking-wider text-gray-400 dark:border-white/5 dark:bg-white/5 dark:text-gray-500">
                                    {{ __('assistant.preview.changes_title') }}
                                </div>
                                <dl class="divide-y divide-gray-950/5 dark:divide-white/5">
                                    @foreach ($previewDiffs as $row)
                                        <div class="flex items-center justify-between gap-3 px-3 py-2 text-sm">
                                            <dt class="shrink-0 text-gray-500 dark:text-gray-400">{{ $fieldTitle((string) $row['label']) }}</dt>
                                            <dd class="flex min-w-0 items-center gap-1.5 text-right">
                                                @if (($row['before'] ?? null) !== null)
                                                    <span class="truncate text-gray-400 line-through dark:text-gray-500">{{ $row['before'] }}</span>
                                                @endif
                                                <svg class="h-3 w-3 shrink-0 text-gray-300 dark:text-gray-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" />
                                                </svg>
                                                <span class="truncate font-semibold {{ ($row['after'] ?? null) === null ? 'text-red-500 line-through' : 'text-emerald-600 dark:text-emerald-400' }}">
                                                    {{ ($row['after'] ?? null) ?? '—' }}
                                                </span>
                                            </dd>
                                        </div>
                                    @endforeach
                                </dl>
                            </div>
                        @endif

                        {{-- 3. Resumen plano (creaciones, acciones sin diff). --}}
                        @if ($previewPlainRows !== [])
                            <dl class="space-y-2.5 rounded-xl bg-gray-50 p-3 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                                @foreach ($previewPlainRows as $row)
                                    @if ($row['section'] ?? false)
                                        <div class="pt-2 text-[11px] font-bold uppercase tracking-wider text-primary-600 first:pt-0 dark:text-primary-400">{{ $row['label'] }}</div>
                                    @else
                                        <div class="flex items-start justify-between gap-3 text-sm {{ ($row['item'] ?? false) ? 'pl-3' : '' }}">
                                            <dt class="shrink-0 {{ ($row['item'] ?? false) ? 'text-gray-400 dark:text-gray-500' : 'text-gray-500 dark:text-gray-400' }}">{{ $fieldTitle((string) $row['label']) }}</dt>
                                            <dd class="text-right font-medium text-gray-900 dark:text-white">
                                                @if (! empty($row['badge']))
                                                    @php
                                                        $badgeTone = match ((string) $row['badge']) {
                                                            'document' => 'bg-sky-50 text-sky-700 ring-sky-600/20 dark:bg-sky-500/10 dark:text-sky-300 dark:ring-sky-400/30',
                                                            'payment' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-400/30',
                                                            'danger' => 'bg-red-50 text-red-700 ring-red-600/20 dark:bg-red-500/10 dark:text-red-300 dark:ring-red-400/30',
                                                            'settings' => 'bg-violet-50 text-violet-700 ring-violet-600/20 dark:bg-violet-500/10 dark:text-violet-300 dark:ring-violet-400/30',
                                                            default => 'bg-gray-100 text-gray-700 ring-gray-950/10 dark:bg-white/10 dark:text-gray-200 dark:ring-white/15',
                                                        };
                                                    @endphp
                                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide ring-1 {{ $badgeTone }}">{{ $row['value'] }}</span>
                                                @else
                                                    {{ $row['value'] }}
                                                @endif
                                            </dd>
                                        </div>
                                    @endif
                                @endforeach
                            </dl>
                        @endif

                        {{-- 3b. Batch: cada elemento del paquete se puede
                             excluir y tiene sus propios campos editables
                             (keys "b{indice}_{campo}" en previewEdits). --}}
                        @if (($previewAction['type'] ?? '') === 'batch' && is_array($previewAction['items'] ?? null))
                            <div class="mt-3 space-y-2">
                                <p class="px-0.5 text-[11px] font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500">
                                    {{ __('assistant.preview.batch_items_title') }}
                                </p>
                                @foreach ($previewAction['items'] as $itemIndex => $batchItem)
                                    @continue(! is_array($batchItem) || ! isset($batchItem['type']))
                                    @php
                                        $itemParams = is_array($batchItem['params'] ?? null) ? $batchItem['params'] : [];
                                        $itemName = trim((string) ($itemParams['name'] ?? $itemParams['tenant_name'] ?? $itemParams['rental_name'] ?? ''));
                                        $itemEditable = is_array($batchItem['editable'] ?? null) ? $batchItem['editable'] : [];
                                    @endphp
                                    <div class="rounded-xl bg-gray-50 p-3 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                                        <label class="flex items-center gap-2 text-xs font-semibold text-gray-700 dark:text-gray-200">
                                            <input
                                                type="checkbox"
                                                wire:model.blur="previewEdits.drop_{{ $itemIndex }}"
                                                true-value="1"
                                                false-value="0"
                                                class="h-3.5 w-3.5 rounded border-gray-300 text-red-600 focus:ring-red-500"
                                            >
                                            <span class="min-w-0 flex-1 truncate">
                                                {{ $itemName !== '' ? $itemName : ($batchItem['summary'] ?? __('assistant.batch_types.'.$batchItem['type'], ['count' => 1])) }}
                                            </span>
                                            <span class="shrink-0 text-[10px] font-normal text-gray-400">{{ __('assistant.batch_types.'.$batchItem['type'], ['count' => 1]) }}</span>
                                        </label>
                                        @if ($itemEditable !== [])
                                            <div class="mt-2 grid gap-2 sm:grid-cols-2">
                                                @foreach ($itemEditable as $itemField)
                                                    @php
                                                        $ifKey = 'b'.$itemIndex.'_'.(string) ($itemField['key'] ?? '');
                                                        $ifKind = (string) ($itemField['kind'] ?? 'text');
                                                    @endphp
                                                    <label class="block text-[11px]">
                                                        <span class="mb-0.5 block font-medium text-gray-500 dark:text-gray-400">{{ $fieldTitle((string) ($itemField['label'] ?? $ifKey)) }}@if (! empty($itemField['required']))<span class="ml-0.5 text-red-500">*</span>@endif</span>
                                                        @if ($ifKind === 'textarea')
                                                            <textarea wire:model.blur="previewEdits.{{ $ifKey }}" rows="2" class="w-full rounded-lg border-0 bg-white px-2 py-1 text-xs text-gray-900 ring-1 ring-gray-950/10 focus:ring-2 focus:ring-primary-500 dark:bg-gray-900 dark:text-white dark:ring-white/10"></textarea>
                                                        @elseif ($ifKind === 'bool')
                                                            <select wire:model.blur="previewEdits.{{ $ifKey }}" class="w-full rounded-lg border-0 bg-white px-2 py-1 text-xs text-gray-900 ring-1 ring-gray-950/10 focus:ring-2 focus:ring-primary-500 dark:bg-gray-900 dark:text-white dark:ring-white/10">
                                                                <option value="1">{{ __('assistant.yes') }}</option>
                                                                <option value="0">{{ __('assistant.no') }}</option>
                                                            </select>
                                                        @elseif ($ifKind === 'select' && is_array($itemField['options'] ?? null) && $itemField['options'] !== [])
                                                            <select wire:model.blur="previewEdits.{{ $ifKey }}" class="w-full rounded-lg border-0 bg-white px-2 py-1 text-xs text-gray-900 ring-1 ring-gray-950/10 focus:ring-2 focus:ring-primary-500 dark:bg-gray-900 dark:text-white dark:ring-white/10">
                                                                @foreach ($itemField['options'] as $optValue => $optLabel)
                                                                    @if (is_array($optLabel))
                                                                        <option value="{{ $optLabel['value'] ?? '' }}">{{ $optLabel['label'] ?? '' }}</option>
                                                                    @else
                                                                        <option value="{{ is_int($optValue) ? $optLabel : $optValue }}">{{ $optLabel }}</option>
                                                                    @endif
                                                                @endforeach
                                                            </select>
                                                        @else
                                                            <input
                                                                type="{{ in_array($ifKind, ['number', 'date', 'email', 'tel', 'datetime', 'range'], true) ? $ifKind : 'text' }}"
                                                                @if ($ifKind === 'number') inputmode="decimal" @endif
                                                                @if (isset($itemField['min'])) min="{{ $itemField['min'] }}" @endif
                                                                @if (isset($itemField['max'])) max="{{ $itemField['max'] }}" @endif
                                                                @if (isset($itemField['step'])) step="{{ $itemField['step'] }}" @endif
                                                                wire:model.blur="previewEdits.{{ $ifKey }}"
                                                                class="w-full rounded-lg border-0 bg-white px-2 py-1 text-xs text-gray-900 ring-1 ring-gray-950/10 focus:ring-2 focus:ring-primary-500 dark:bg-gray-900 dark:text-white dark:ring-white/10"
                                                            >
                                                        @endif
                                                    </label>
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        {{-- 4. Campos editables: lo que el usuario puede
                             corregir antes de confirmar. --}}
                        @if ($previewEditable !== [] && ($previewAction['type'] ?? '') !== 'batch')
                            <div class="mt-3">
                                <p class="mb-1.5 px-0.5 text-[11px] font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500">
                                    {{ __('assistant.preview.editable_title') }}
                                </p>
                                <div class="grid gap-2 rounded-xl bg-gray-50 p-3 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10 sm:grid-cols-2">
                                    @foreach ($previewEditable as $field)
                                        @php $editKey = (string) ($field['key'] ?? ''); @endphp
                                        <label class="block text-xs">
                                            <span class="mb-0.5 block font-medium text-gray-500 dark:text-gray-400">{{ $fieldTitle((string) ($field['label'] ?? $editKey)) }}@if (! empty($field['required']))<span class="ml-0.5 text-red-500" aria-hidden="true">*</span>@endif</span>
                                            @if (($field['kind'] ?? 'text') === 'textarea')
                                                <textarea
                                                    wire:model.blur="previewEdits.{{ $editKey }}"
                                                    rows="2"
                                                    class="w-full rounded-lg border-0 bg-white px-2.5 py-1.5 text-xs text-gray-900 ring-1 ring-gray-950/10 focus:ring-2 focus:ring-primary-500 dark:bg-gray-900 dark:text-white dark:ring-white/10"
                                                ></textarea>
                                            @elseif (($field['kind'] ?? 'text') === 'bool')
                                                <select
                                                    wire:model.blur="previewEdits.{{ $editKey }}"
                                                    class="w-full rounded-lg border-0 bg-white px-2.5 py-1.5 text-xs text-gray-900 ring-1 ring-gray-950/10 focus:ring-2 focus:ring-primary-500 dark:bg-gray-900 dark:text-white dark:ring-white/10"
                                                >
                                                    <option value="1">{{ __('assistant.yes') }}</option>
                                                    <option value="0">{{ __('assistant.no') }}</option>
                                                </select>
                                            @elseif (($field['kind'] ?? 'text') === 'select' && is_array($field['options'] ?? null) && $field['options'] !== [])
                                                <select
                                                    wire:model.blur="previewEdits.{{ $editKey }}"
                                                    class="w-full rounded-lg border-0 bg-white px-2.5 py-1.5 text-xs text-gray-900 ring-1 ring-gray-950/10 focus:ring-2 focus:ring-primary-500 dark:bg-gray-900 dark:text-white dark:ring-white/10"
                                                >
                                                    @foreach ($field['options'] as $optionValue => $optionLabel)
                                                        @if (is_array($optionLabel))
                                                            <option value="{{ $optionLabel['value'] ?? '' }}">{{ $optionLabel['label'] ?? '' }}</option>
                                                        @else
                                                            <option value="{{ is_int($optionValue) ? $optionLabel : $optionValue }}">{{ $optionLabel }}</option>
                                                        @endif
                                                    @endforeach
                                                </select>
                                            @else
                                                <input
                                                    type="{{ in_array($field['kind'] ?? 'text', ['number', 'date', 'email', 'tel', 'datetime', 'range'], true) ? $field['kind'] : 'text' }}"
                                                    @if (($field['kind'] ?? 'text') === 'number') inputmode="decimal" @endif
                                                    @if (isset($field['min'])) min="{{ $field['min'] }}" @endif
                                                    @if (isset($field['max'])) max="{{ $field['max'] }}" @endif
                                                    @if (isset($field['step'])) step="{{ $field['step'] }}" @endif
                                                    wire:model.blur="previewEdits.{{ $editKey }}"
                                                    class="w-full rounded-lg border-0 bg-white px-2.5 py-1.5 text-xs text-gray-900 ring-1 ring-gray-950/10 focus:ring-2 focus:ring-primary-500 dark:bg-gray-900 dark:text-white dark:ring-white/10"
                                                >
                                            @endif
                                        </label>
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        <p class="mt-3 flex items-start gap-1.5 text-xs leading-relaxed text-amber-700 dark:text-amber-400">
                            <svg class="mt-0.5 h-3.5 w-3.5 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                            </svg>
                            {{ __('assistant.preview.confirm_note') }}
                        </p>
                    @elseif ($preview['kind'] === 'insights')
                        {{-- Analisis predictivo completo. Las acciones prompt
                             usan askFromPreview: cierran el modal y envian el
                             mensaje al chat con scroll al final. --}}
                        @include('livewire.partials.assistant-insights', ['data' => ['insights' => $preview['payload'] ?? null], 'compact' => false, 'fromPreview' => true])
                    @elseif ($preview['kind'] === 'guide')
                        {{-- Guia de uso completa: mismos botones, mismo cierre
                             de modal antes de preguntar al chat. --}}
                        @include('livewire.partials.assistant-guide', ['data' => ['guide' => $preview['payload'] ?? null], 'compact' => false, 'fromPreview' => true])
                    @elseif ($preview['kind'] === 'report')
                        {{-- Reporte compuesto por la IA: mismas acciones
                             prompt/url que en la burbuja, cerrando el modal
                             antes de enviar al chat. --}}
                        @include('livewire.partials.assistant-report', ['data' => ['report' => $preview['payload'] ?? null], 'compact' => false, 'fromPreview' => true, 'pdfIndex' => $preview['message_index'] ?? null])
                    @elseif ($preview['kind'] === 'file')
                        {{-- Documento generado: ocupa TODA la altura del
                             drawer; el visor del iframe ya trae descarga. --}}
                        <iframe
                            src="{{ $preview['file_url'] }}"
                            title="{{ $preview['title'] }}"
                            class="h-full w-full flex-1 rounded-xl bg-white ring-1 ring-gray-950/10 dark:bg-gray-900 dark:ring-white/10"
                        ></iframe>
                    @else
                        {{-- data / data_all: una o varias tablas. Las celdas con
                             listas de filas se despliegan igual que en las
                             tablas inline del chat. --}}
                        @php
                            $previewTables = $preview['kind'] === 'data_all'
                                ? $preview['tables']
                                : [['title' => null, 'columns' => $preview['columns'], 'rows' => $preview['rows']]];
                        @endphp
                        <div class="space-y-5">
                            @foreach ($previewTables as $table)
                                <div>
                                    @if (! empty($table['title']))
                                        <p class="mb-1.5 px-0.5 text-[11px] font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500">{{ $table['title'] }}</p>
                                    @endif
                                    <div class="overflow-x-auto rounded-xl ring-1 ring-gray-950/10 dark:ring-white/10">
                                        <table class="w-full text-xs">
                                            <thead class="bg-gray-50 dark:bg-gray-800">
                                                <tr>
                                                    @foreach ($table['columns'] as $column)
                                                        <th class="whitespace-nowrap px-2.5 py-2 text-left font-semibold text-gray-500 dark:text-gray-400">{{ $column }}</th>
                                                    @endforeach
                                                </tr>
                                            </thead>
                                            @foreach ($table['rows'] as $row)
                                                @php
                                                    $nestedCells = collect($row)->filter(
                                                        fn ($cell): bool => is_array($cell) && isset($cell[0]) && is_array($cell[0]),
                                                    );
                                                @endphp
                                                <tbody
                                                    x-data="{ open: {} }"
                                                    class="border-t border-gray-950/5 dark:border-white/5"
                                                >
                                                    <tr>
                                                        @foreach ($row as $cellColumn => $cell)
                                                            @php
                                                                $badgeColumn = is_string($cellColumn) ? $cellColumn : ($table['columns'][$loop->index] ?? null);
                                                                $cellColors = $preview['kind'] === 'data_all'
                                                                    ? ($table['badge_colors'] ?? [])
                                                                    : ($preview['badge_colors'] ?? []);
                                                            @endphp
                                                            <td class="px-2.5 py-1.5">
                                                                @include('livewire.partials.assistant-cell', ['cell' => $cell, 'column' => $badgeColumn, 'aiColor' => $cellColors[$badgeColumn] ?? null, 'expandKey' => is_string($cellColumn) ? $cellColumn : 'default'])
                                                            </td>
                                                        @endforeach
                                                    </tr>
                                                    @foreach ($nestedCells as $nestedKey => $nestedRows)
                                                        <tr x-show="open[{{ Js::from($nestedKey) }}]">
                                                            <td colspan="{{ count($table['columns']) }}" class="bg-gray-50/60 px-3 py-2.5 dark:bg-white/5">
                                                                <table class="w-full text-[11px]">
                                                                    <thead>
                                                                        <tr>
                                                                            @foreach (array_keys($nestedRows[0]) as $nestedColumn)
                                                                                <th class="whitespace-nowrap px-2 py-1 text-left font-semibold text-gray-400 dark:text-gray-500">
                                                                                    {{ __("assistant.columns.{$nestedColumn}") !== "assistant.columns.{$nestedColumn}" ? __("assistant.columns.{$nestedColumn}") : str_replace('_', ' ', ucfirst($nestedColumn)) }}
                                                                                </th>
                                                                            @endforeach
                                                                        </tr>
                                                                    </thead>
                                                                    <tbody>
                                                                        @foreach ($nestedRows as $nestedRow)
                                                                            <tr class="border-t border-gray-950/5 dark:border-white/5">
                                                                                @foreach ($nestedRow as $nestedCellColumn => $nestedCell)
                                                                                    <td class="px-2 py-1">@include('livewire.partials.assistant-cell', ['cell' => $nestedCell, 'column' => is_string($nestedCellColumn) ? $nestedCellColumn : null, 'aiColor' => $cellColors[is_string($nestedCellColumn) ? $nestedCellColumn : ''] ?? null, 'allowExpand' => false])</td>
                                                                                @endforeach
                                                                            </tr>
                                                                        @endforeach
                                                                    </tbody>
                                                                </table>
                                                            </td>
                                                        </tr>
                                                    @endforeach
                                                </tbody>
                                            @endforeach
                                        </table>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        @if ($preview['kind'] !== 'data_all' && count($preview['rows'] ?? []) >= 200)
                            <p class="mt-2 text-center text-[11px] text-gray-400">{{ __('assistant.preview.truncated') }}</p>
                        @endif
                    @endif
                    @endif
                </div>

                @if (($preview['kind'] ?? '') === 'action')
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
                            x-on:click="closePreviewModal()"
                            class="flex-1 rounded-xl bg-white px-4 py-2.5 text-sm font-semibold text-gray-600 ring-1 ring-gray-300 transition hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-300 dark:ring-white/10 dark:hover:bg-gray-700"
                        >
                            {{ __('assistant.cancel') }}
                        </button>
                    </footer>
                @endif
        </div>
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
        /* Scrollbars finos y discretos en los contenedores del chat. */
        .assistant-scroll {
            scrollbar-width: thin;
            scrollbar-color: rgba(100, 116, 139, 0.35) transparent;
        }
        .assistant-scroll::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }
        .assistant-scroll::-webkit-scrollbar-track {
            background: transparent;
        }
        .assistant-scroll::-webkit-scrollbar-thumb {
            background-color: rgba(100, 116, 139, 0.3);
            border-radius: 999px;
        }
        .dark .assistant-scroll {
            scrollbar-color: rgba(148, 163, 184, 0.3) transparent;
        }
        .dark .assistant-scroll::-webkit-scrollbar-thumb {
            background-color: rgba(148, 163, 184, 0.25);
        }
        /* Accesibilidad: sin animaciones si el SO pide movimiento reducido. */
        @media (prefers-reduced-motion: reduce) {
            .assistant-msg,
            .assistant-dot {
                animation: none !important;
            }
        }
    </style>
</div>
