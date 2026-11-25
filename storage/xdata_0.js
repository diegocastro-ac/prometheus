{
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
        weeklyReportOpen: false,
        modelGroupsMeta: null => ['driver' => $group['driver'], 'models' => $group['models']], $assistantModelGroups)),
        // Vista interna del drawer ('chat' | 'chats'). La controla Alpine
        // para que cambiar de panel sea instantaneo; el servidor la refleja
        // en `view` y el watcher la resincroniza tras cada respuesta.
        chatView: null,
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
        thinkingSteps: null),
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
        // Resolucion de refs a prueba de scopes: los handlers del wizard
        // viven dentro de un x-data anidado ({open:true}) donde $refs no
        // siempre alcanza los refs del componente raiz. Primero intenta
        // $refs y si no, busca el atributo x-ref en el DOM — siempre hay
        // un solo elemento con cada nombre.
        domRef(name) {
            const viaRefs = this.$refs ? this.$refs[name] : null;
            return viaRefs ?? document.querySelector('[x-ref=' + name + ']');
        },
        startResize(event) {
            if (window.innerWidth < 1024) return;
            this.resizing = true;
            this.startClickGuard();
            this.resizeStartX = event.touches ? event.touches[0].clientX : event.clientX;
            this.resizeStartWidth = this.drawerWidth ?? (this.domRef('drawer')?.offsetWidth || 448);
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
                const pane = this.domRef('chatPane')?.offsetHeight ?? 0;
                const composer = this.domRef('composer')?.offsetHeight ?? 0;
                if (pane <= 0 || composer <= 0) return Math.round(window.innerHeight * 0.55);
                // chatPane + composer es constante durante el arrastre
                // (el chat cede via flex-1). Se descuentan ~300px: 200 de
                // chat minimo mas el cromo del compositor (input, chips).
                return Math.max(160, pane + composer - 300);
            }
            const chat = this.domRef('chatPane')?.offsetHeight ?? 0;
            const composer = this.domRef('composer')?.offsetHeight ?? 0;
            if (chat <= 0) return 110;
            return Math.max(110, chat + composer - 200);
        },
        // Alto real del objetivo para aria-valuenow: el explicito si
        // existe, si no lo que el elemento mide en el layout actual.
        vCurrentHeight(mode) {
            const explicit = this.vHeightFor(mode);
            if (explicit !== null) return explicit;
            const ref = mode === 'wizard' ? this.domRef('wizardCard') : this.domRef('composer');
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
            // Se fija tambien flex:none y se limpia el max-h de modo auto
            // para que el alto explícito mande sin esperar al proximo tick.
            const el = mode === 'wizard' ? this.domRef('wizardCard') : this.domRef('composer');
            if (el) {
                el.style.height = value + 'px';
                el.style.flex = 'none';
                el.style.maxHeight = 'none';
            }
            this.scheduleFitChatInput();
        },
        // Igual que startResize del drawer: mide al empezar y persiste al
        // soltar. Solo el compositor cambia de altura; el chat es flex-1.
        startVResize(event, mode) {
            const ref = mode === 'wizard' ? this.domRef('wizardCard') : this.domRef('composer');
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
            const ref = mode === 'wizard' ? this.domRef('wizardCard') : this.domRef('composer');
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
            const pane = this.domRef('chatPane');
            // Con el drawer cerrado o en la vista de chats el panel no tiene
            // altura util: no se tocan los valores guardados.
            if (! pane || pane.clientHeight <= 0) return;
            if (this.composerHeight !== null) {
                this.composerHeight = Math.min(this.composerHeight, this.vMaxFor('composer'));
                const el = this.domRef('composer');
                if (el) {
                    el.style.height = this.composerHeight + 'px';
                    el.style.flex = 'none';
                    el.style.maxHeight = 'none';
                }
            }
            if (this.wizardHeight !== null) {
                this.wizardHeight = Math.min(this.wizardHeight, this.vMaxFor('wizard'));
                const el = this.domRef('wizardCard');
                if (el) el.style.height = this.wizardHeight + 'px';
            }
            this.scheduleFitChatInput();
        },
        // Reparto de espacio del composer: los componentes (sugerencias,
        // panel de prompts, wizard, cola) tienen prioridad — se dimensionan
        // a su alto natural y el scroll interno absorbe la falta. El input
        // de chat se expande DESPUES: solo cuando queda espacio vacio real
        // (composer con altura fija) el textarea crece mas alla de su
        // contenido, hasta un tope sano. En modo auto no hay sobrante:
        // el composer abraza el contenido y el calculo da el mismo alto.
        fitChatInput() {
            const ta = this.domRef('chatInput');
            const inner = this.domRef('composerInner');
            const content = this.domRef('composerContent');
            if (! ta || ! inner || ! content) return;
            ta.style.height = 'auto';
            const contentH = Math.min(Math.max(ta.scrollHeight, 42), 160);
            if (inner.clientHeight <= 0 || this.composerHeight === null) {
                // Modo auto: el composer abraza el contenido — el textarea
                // toma exactamente su alto de contenido y el composer
                // crece solo. No hay sobrante que repartir.
                ta.style.height = contentH + 'px';
                return;
            }
            const padY = parseFloat(getComputedStyle(inner).paddingTop)
                + parseFloat(getComputedStyle(inner).paddingBottom);
            const form = ta.closest('form');
            const formChrome = form ? Math.max(0, form.offsetHeight - ta.offsetHeight) : 0;
            const othersH = content.offsetHeight
                + (this.domRef('queuePanel')?.offsetHeight ?? 0)
                + (this.domRef('composerHint')?.offsetHeight ?? 0)
                + (this.domRef('composerDisclaimer')?.offsetHeight ?? 0)
                + formChrome;
            // Los margenes de los componentes (form mt-2, cola mb-2,
            // hint mt-1.5, disclaimer mt-0.5) no cuentan en offsetHeight
            // pero si en el layout: sin descontarlos el textarea queda
            // unos px mas alto de lo que cabe y desborda el composer.
            const gaps = 16 + ((this.domRef('queuePanel')?.offsetHeight ?? 0) > 0 ? 8 : 0);
            // Espacio real disponible para el textarea: el alto del
            // composer menos TODO lo demas a su medida natural.
            const spaceForTa = inner.clientHeight - padY - othersH - gaps;
            // El input se queda con su alto de contenido y, si sobra
            // espacio, lo llena hasta el tope (nunca mas de 320px).
            const desired = Math.max(contentH, Math.min(Math.max(spaceForTa, 0), 320));
            const finalH = Math.min(desired, Math.max(spaceForTa, 42));
            ta.style.height = Math.max(42, finalH) + 'px';
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
            // Los estilos inline que applyVHeight escribe directo hay que
            // retirarlos a mano: el binding :style vuelve a '' pero no
            // siempre limpia cssText ya tocado por JS.
            const el = this.domRef('composer');
            if (el) {
                el.style.height = '';
                el.style.flex = '';
                el.style.maxHeight = '';
            }
            this.scheduleFitChatInput();
            try {
                localStorage.removeItem('assistant.composerSize');
            } catch (e) { /* sin storage: se queda el default */ }
        },
        resetWizardHeight() {
            this.wizardHeight = null;
            const el = this.domRef('wizardCard');
            if (el) {
                el.style.height = '';
                el.style.flex = '';
                el.style.maxHeight = '';
            }
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
                const el = this.domRef('messagesScroll');
                if (el) el.scrollTop = el.scrollHeight;
            });
        },
        updateAtBottom() {
            const el = this.domRef('messagesScroll');
            if (! el) return;
            this.atBottom = el.scrollHeight - el.scrollTop - el.clientHeight < 80;
        },
        scrollDown() {
            const el = this.domRef('messagesScroll');
            if (el) el.scrollTo({ top: el.scrollHeight, behavior: 'smooth' });
        },
        // Mientras el drawer este abierto la rueda nunca llega a la pagina:
        // si el cursor cae fuera del drawer se redirige al contenedor de
        // scroll activo y se consume el evento, asi el scroll no escapa a
        // la pagina ni siquiera cuando el chat llega a su tope.
        forwardWheel(event) {
            if (! this.drawerOpen || ! (event.target instanceof Element)) return;
            const drawer = this.domRef('drawer');
            if (! drawer) return;
            if (drawer === event.target || drawer.contains(event.target)) return;
            const el = this.previewVisible()
                ? this.domRef('previewScroll')
                : (this.chatView === 'chats' ? this.domRef('chatsScroll') : this.domRef('messagesScroll'));
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
            return this.modelSel ?? (this.$wire.assistantModel || null));
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
            const input = this.domRef('chatInput');
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
            const input = this.domRef('chatInput');
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
            const input = this.domRef('chatInput');
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
            const input = this.domRef('chatInput');
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
                if (value === '' && this.domRef('chatInput')) {
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
                        this.domRef('chatInput')?.focus();
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
                        const composer = this.domRef('composer');
                        const scroll = this.domRef('composerScroll');
                        if (! composer || ! scroll) return;
                        // composer = scroll + bloque del input; la diferencia
                        // de offsetHeight es el chrome fijo del input.
                        const chrome = composer.offsetHeight - scroll.offsetHeight;
                        // composerContent.offsetHeight es la altura NATURAL
                        // del contenido del scroll: scrollHeight mediria la
                        // caja flex-1 (que ya incluye el espacio sobrante).
                        const contentH = this.domRef('composerContent')?.offsetHeight ?? scroll.scrollHeight;
                        // chrome incluye el textarea ya agrandado por el
                        // espacio sobrante: se descuenta su parte flexible
                        // (todo lo que exceda el minimo de 42px) para que
                        // `needed` no dependa del alto actual del input.
                        const ta = this.domRef('chatInput');
                        const taFlex = ta ? Math.max(0, ta.offsetHeight - 42) : 0;
                        const needed = contentH + chrome - taFlex;
                        const target = Math.min(
                            this.vMaxFor('composer'),
                            Math.max(110, this.autoComposerHeight, needed),
                        );
                        // applyVHeight (no asignacion directa): escribe
                        // tambien los estilos inline del elemento para que
                        // el alto nuevo se vea en el mismo frame.
                        if (target !== this.composerHeight) this.applyVHeight('composer', target);
                    });
                } else if (this.autoComposerHeight !== null) {
                    const restore = this.autoComposerHeight;
                    this.autoComposerHeight = null;
                    this.applyVHeight('composer', restore);
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
    }