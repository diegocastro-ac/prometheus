<?php

namespace App\Console\Commands;

use App\Contracts\DataAssistantInterface;
use App\Enums\InvoiceStatus;
use App\Models\AssistantChat;
use App\Models\AssistantMessage;
use App\Models\DeliveryAct;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Rental;
use App\Models\User;
use App\Services\Assistant\AssistantFormSession;
use App\Settings\AppSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;

/**
 * Publica el briefing diario del copiloto: un mensaje del asistente en el
 * chat protegido (kind=briefing) de cada usuario con arriendos.
 *
 * Es determinista a proposito: corre en cron, no puede depender de que la
 * API de Gemini este disponible ni de que responda rapido. Las "alertas"
 * del dia (facturas por vencer, actas sin firmar, ajustes vencidos,
 * contratos por terminar) son la parte que mas valor da y salen de las
 * mismas reglas que ya usa el resto del sistema.
 */
class AssistantBrief extends Command
{
    protected $signature = 'assistant:brief {--user= : Procesar solo el usuario con ese id} {--force : Regenera el briefing de hoy aunque ya exista}';

    protected $description = 'Publica el briefing diario del copiloto en el chat protegido de cada usuario.';

    public function handle(): int
    {
        $users = User::query()
            ->whereHas('rentals')
            // Opt-out por usuario: el toggle vive en el chat de briefing.
            ->where('assistant_brief_enabled', true)
            ->when($this->option('user'), fn ($query, $id) => $query->whereKey($id))
            ->orderBy('id')
            ->get();

        $published = 0;

        foreach ($users as $user) {
            try {
                $this->briefFor($user) && $published++;
            } catch (\Throwable $e) {
                // Un usuario con datos corruptos no debe tumbar el lote.
                $this->warn("Usuario {$user->id}: {$e->getMessage()}");
                report($e);
            }
        }

        $this->info("Briefing publicado para {$published} de {$users->count()} usuarios.");

        return self::SUCCESS;
    }

    private function briefFor(User $user): bool
    {
        // El mensaje queda guardado en la BD: hay que redactarlo en el
        // idioma del usuario, no en el locale por defecto del proceso
        // (los comandos corren con APP_LOCALE, no con el del panel).
        App::setLocale($user->preferred_locale ?: config('app.locale'));

        $chat = AssistantChat::query()->firstOrCreate(
            ['user_id' => $user->id, 'kind' => AssistantChat::KIND_BRIEFING],
            ['title' => __('assistant.briefing.title'), 'kind' => AssistantChat::KIND_BRIEFING],
        );

        // Idempotente dentro del dia: una segunda corrida no duplica el
        // mensaje. El scheduler corre una vez, pero un reintento manual
        // tampoco debe ensuciar el chat.
        $alreadyToday = AssistantMessage::query()
            ->where('chat_id', $chat->id)
            ->where('role', 'assistant')
            ->whereDate('created_at', today())
            ->exists();

        if ($alreadyToday) {
            if (! $this->option('force')) {
                return false;
            }

            // --force: reemplaza el briefing del dia (p. ej. tras probar la
            // via de IA o corregir el resumen) en vez de saltarlo.
            AssistantMessage::query()
                ->where('chat_id', $chat->id)
                ->where('role', 'assistant')
                ->whereDate('created_at', today())
                ->delete();
        }

        $digest = $this->digest($user);

        // Primero intenta el análisis redactado por la IA (el mismo
        // reporte con secciones y gráficas del módulo de análisis). Si
        // la IA no está configurada, no responde o devuelve algo
        // interactivo, cae al resumen determinista de siempre.
        $ai = $this->aiAnalysis($user);

        AssistantMessage::query()->create([
            'user_id' => $user->id,
            'chat_id' => $chat->id,
            'role' => 'assistant',
            'text' => $ai['text'] ?? $this->summaryText($digest),
            'payload' => $ai !== null
                ? ['data' => $ai['data'], 'suggestions' => []]
                : [
                    'data' => $digest['tables'],
                    'suggestions' => [],
                ],
        ]);

        $chat->touch();

        return true;
    }

    /**
     * Pide a la IA el análisis del día con el mismo pipeline del chat
     * (clasifica -> lookup_data -> composeReport): el resultado es un
     * reporte estructurado con secciones, métricas, gráficas y tablas
     * que el chat ya sabe pintar.
     *
     * Solo se acepta un reporte limpio: si el modelo devolvió preguntas
     * aclaratorias, opciones, una acción propuesta o una respuesta por
     * secciones, el mensaje interactivo no tiene sentido en un chat de
     * solo lectura y se usa el resumen determinista.
     *
     * @return array{text: string, data: array<string, mixed>, suggestions: list<string>}|null
     */
    private function aiAnalysis(User $user): ?array
    {
        // Con un wizard de formulario en curso, la pregunta del briefing
        // se clasificaría como respuesta del formulario (form_field) y
        // ensuciaría la sesión del usuario: mejor el resumen fijo.
        if (app(AssistantFormSession::class)->wizard($user->id) !== null) {
            return null;
        }

        $sections = [];
        $title = '';
        $intro = '';
        $text = '';

        // Un sub-reporte por tema en vez de uno solo: pedir las ~15
        // secciones del informe en una única respuesta hacía que el
        // modelo las condensara en 3. Tres llamadas enfocadas (cada una
        // pide sus 5-7 secciones) sí llenan el informe completo.
        foreach ((array) __('assistant.briefing.ai_theme_prompts') as $themePrompt) {
            try {
                $reply = app(DataAssistantInterface::class)->ask(
                    $themePrompt,
                    [],
                    $user->id,
                );
            } catch (\Throwable $e) {
                // La IA caída o lenta en un tema no tumba el briefing:
                // los demás sub-reportes igual arman el informe.
                report($e);

                continue;
            }

            $report = $reply->data['report'] ?? null;
            $interactive = $reply->questions !== []
                || $reply->options !== []
                || $reply->action !== null
                || $reply->redirect !== null
                || $reply->pendingForm !== null
                || $reply->sections !== null;

            if (! is_array($report) || $interactive || trim($reply->text) === '') {
                continue;
            }

            if ($title === '') {
                $title = (string) ($report['title'] ?? '');
            }
            if ($intro === '') {
                $intro = (string) ($report['intro'] ?? '');
            }
            if ($text === '') {
                $text = $reply->text;
            }

            foreach (array_slice((array) ($report['sections'] ?? []), 0, 7) as $section) {
                $sections[] = $section;
            }
        }

        if ($sections === []) {
            return null;
        }

        return [
            'text' => $text !== '' ? $text : ($intro !== '' ? $intro : $title),
            'data' => [
                'report' => [
                    'title' => $title !== '' ? $title : __('assistant.briefing.title'),
                    'intro' => $intro,
                    'sections' => $sections,
                ],
            ],
            // El chat de briefing es de solo lectura: no propone
            // respuestas rápidas como el chat normal.
            'suggestions' => [],
        ];
    }

    /**
     * Recolecta las cifras del dia. Todo lo que se muestra sale de aqui:
     * el texto, las tablas y las alertas comparten el mismo digest.
     *
     * @return array{tables: array<string, mixed>, suggestions: list<string>, alerts: list<array{severity: string, detail: string}>, overdue_count: int, overdue_amount: float, due_soon_count: int, payments_today: float, expiring_count: int, adjustments_due: int, unsigned_acts: int}
     */
    private function digest(User $user): array
    {
        $invoices = Invoice::query()
            ->where('user_id', $user->id)
            ->whereIn('status', [InvoiceStatus::EMITIDA, InvoiceStatus::VENCIDA])
            ->with(['rental:id,name,tenant_id', 'rental.tenant:id,name', 'payments:id,invoice_id,amount'])
            ->orderBy('due_at')
            ->get();

        $overdue = $invoices->filter(fn (Invoice $invoice): bool => $invoice->balance() > 0 && $invoice->due_at->isBefore(today()));
        $dueSoon = $invoices->filter(fn (Invoice $invoice): bool => $invoice->balance() > 0
            && ! $invoice->due_at->isBefore(today())
            && $invoice->due_at->lte(today()->addDays(7)));

        $paymentsToday = Payment::query()
            ->where('user_id', $user->id)
            ->whereDate('date', today())
            ->sum('amount');

        $expiring = Rental::query()
            ->where('user_id', $user->id)
            ->whereNotNull('end_date')
            ->whereBetween('end_date', [today(), today()->addDays(60)])
            ->with(['tenant:id,name', 'property:id,name'])
            ->orderBy('end_date')
            ->get();

        // El proximo reajuste es una fecha calculada (no una columna), asi
        // que hay que materializar los arriendos y preguntarles. Solo se
        // revisan los que tienen cadena de ajustes: el resto nunca vence.
        $adjustmentsDue = Rental::query()
            ->where('user_id', $user->id)
            ->whereHas('rentAdjustments')
            ->with('rentAdjustments')
            ->get()
            ->filter(fn (Rental $rental): bool => $rental->nextAdjustmentDueDate()->lte(today()->addDays(30)))
            ->sortBy(fn (Rental $rental) => $rental->nextAdjustmentDueDate())
            ->take(15)
            ->values();

        $unsignedActs = DeliveryAct::query()
            ->where('user_id', $user->id)
            // Firmada = fecha de firma O ambas firmas subidas (isSigned).
            ->whereNull('signed_at')
            ->where(fn ($query) => $query
                ->whereNull('landlord_signature_path')
                ->orWhereNull('tenant_signature_path'))
            ->where(fn ($query) => $query
                ->whereDate('occurred_at', '<=', today())
                ->orWhere('scheduled_at', '<=', now()))
            ->with('rental:id,name')
            ->orderByRaw('COALESCE(occurred_at, scheduled_at) desc')
            ->limit(15)
            ->get();

        $alerts = $this->alerts($overdue, $dueSoon, $adjustmentsDue, $unsignedActs);

        return [
            'tables' => $this->tables($invoices, $overdue, $dueSoon, $expiring, $adjustmentsDue, $unsignedActs, (float) $paymentsToday, $alerts),
            'suggestions' => [
                __('assistant.briefing.suggest_overdue'),
                __('assistant.briefing.suggest_expiring'),
                __('assistant.briefing.suggest_analysis'),
                __('assistant.briefing.suggest_today'),
            ],
            'alerts' => $alerts,
            'overdue_count' => $overdue->count(),
            'overdue_amount' => round((float) $overdue->sum(fn (Invoice $invoice): float => $invoice->balance()), 2),
            'due_soon_count' => $dueSoon->count(),
            'payments_today' => round((float) $paymentsToday, 2),
            'expiring_count' => $expiring->count(),
            'adjustments_due' => $adjustmentsDue->count(),
            'unsigned_acts' => $unsignedActs->count(),
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Invoice>  $invoices
     * @param  \Illuminate\Support\Collection<int, Invoice>  $overdue
     * @param  \Illuminate\Support\Collection<int, Invoice>  $dueSoon
     * @param  \Illuminate\Support\Collection<int, Rental>  $expiring
     * @param  \Illuminate\Support\Collection<int, Rental>  $adjustmentsDue
     * @param  \Illuminate\Support\Collection<int, DeliveryAct>  $unsignedActs
     * @param  list<array{severity: string, detail: string}>  $alerts
     * @return array<string, mixed>
     */
    private function tables($invoices, $overdue, $dueSoon, $expiring, $adjustmentsDue, $unsignedActs, float $paymentsToday, array $alerts): array
    {
        $invoiceRow = fn (Invoice $invoice): array => [
            'number' => $invoice->number,
            'tenant' => $invoice->rental?->tenant?->name ?? '—',
            'rental' => $invoice->rental?->name ?? '—',
            'amount' => $this->money($invoice->amount),
            'balance' => $this->money($invoice->balance()),
            'due_at' => $invoice->due_at->format('Y-m-d'),
            // Carbon 3: diffInDays es con signo; vencida = positiva.
            'days_late' => max(0, (int) $invoice->due_at->startOfDay()->diffInDays(today()->startOfDay())),
        ];

        $tables = [];

        if ($alerts !== []) {
            $tables['brief_alerts'] = collect($alerts)->map(fn (array $alert): array => [
                'severity' => __('assistant.briefing.severity_'.$alert['severity']),
                'detail' => $alert['detail'],
            ])->all();
        }

        if ($overdue->isNotEmpty()) {
            $tables['brief_overdue'] = $overdue->take(20)->map($invoiceRow)->values()->all();
        }

        if ($dueSoon->isNotEmpty()) {
            $tables['brief_due_soon'] = $dueSoon->take(20)->map($invoiceRow)->values()->all();
        }

        if ($paymentsToday > 0) {
            $tables['brief_payments'] = [[
                'date' => today()->format('Y-m-d'),
                'amount' => $this->money($paymentsToday),
            ]];
        }

        if ($expiring->isNotEmpty()) {
            $tables['brief_expiring'] = $expiring->map(fn (Rental $rental): array => [
                'name' => $rental->name,
                'tenant' => $rental->tenant?->name ?? '—',
                'property' => $rental->property?->name ?? '—',
                'end_date' => $rental->end_date->format('Y-m-d'),
                'days' => (int) today()->startOfDay()->diffInDays($rental->end_date->startOfDay()),
            ])->all();
        }

        if ($adjustmentsDue->isNotEmpty()) {
            $tables['brief_adjustments'] = $adjustmentsDue->map(fn (Rental $rental): array => [
                'name' => $rental->name,
                'tenant' => $rental->tenant?->name ?? '—',
                'due_date' => $rental->nextAdjustmentDueDate()->format('Y-m-d'),
                'days' => (int) today()->startOfDay()->diffInDays($rental->nextAdjustmentDueDate()->startOfDay()),
            ])->all();
        }

        if ($unsignedActs->isNotEmpty()) {
            $tables['brief_acts'] = $unsignedActs->map(fn (DeliveryAct $act): array => [
                'rental' => $act->rental?->name ?? '—',
                'type' => $act->type,
                'occurred_at' => ($act->occurred_at ?? $act->scheduled_at)?->format('Y-m-d') ?? '—',
                'signed' => $act->isSigned() ? __('assistant.yes') : __('assistant.no'),
            ])->all();
        }

        $tables['_titles'] = [
            'brief_alerts' => __('assistant.briefing.table_alerts'),
            'brief_overdue' => __('assistant.briefing.table_overdue'),
            'brief_due_soon' => __('assistant.briefing.table_due_soon'),
            'brief_payments' => __('assistant.briefing.table_payments'),
            'brief_expiring' => __('assistant.briefing.table_expiring'),
            'brief_adjustments' => __('assistant.briefing.table_adjustments'),
            'brief_acts' => __('assistant.briefing.table_acts'),
        ];

        return $tables;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Invoice>  $overdue
     * @param  \Illuminate\Support\Collection<int, Invoice>  $dueSoon
     * @param  \Illuminate\Support\Collection<int, Rental>  $adjustmentsDue
     * @param  \Illuminate\Support\Collection<int, DeliveryAct>  $unsignedActs
     * @return list<array{severity: string, detail: string}>
     */
    private function alerts($overdue, $dueSoon, $adjustmentsDue, $unsignedActs): array
    {
        $alerts = [];

        foreach ($dueSoon->take(5) as $invoice) {
            $alerts[] = [
                'severity' => 'medium',
                'detail' => __('assistant.briefing.alert_due_soon', [
                    'invoice' => $invoice->number,
                    'tenant' => $invoice->rental?->tenant?->name ?? '—',
                    'date' => $invoice->due_at->format('Y-m-d'),
                ]),
            ];
        }

        foreach ($adjustmentsDue->take(5) as $rental) {
            $alerts[] = [
                'severity' => 'medium',
                'detail' => __('assistant.briefing.alert_adjustment', [
                    'rental' => $rental->name,
                    'date' => $rental->nextAdjustmentDueDate()->format('Y-m-d'),
                ]),
            ];
        }

        foreach ($unsignedActs->take(5) as $act) {
            $alerts[] = [
                'severity' => 'low',
                'detail' => __('assistant.briefing.alert_unsigned_act', [
                    'rental' => $act->rental?->name ?? '—',
                    'date' => ($act->occurred_at ?? $act->scheduled_at)?->format('Y-m-d') ?? '—',
                ]),
            ];
        }

        // Deuda vieja por inquilino: mas de 45 dias de mora acumulada es la
        // senal que el analisis de riesgo ya usa; aqui solo se reporta.
        $oldDebtors = $overdue
            ->filter(fn (Invoice $invoice): bool => (int) $invoice->due_at->diffInDays(today()) > 45)
            ->groupBy(fn (Invoice $invoice): string => (string) ($invoice->rental?->tenant?->name ?? '—'))
            ->map(fn ($group) => [
                'tenant' => $group->first()->rental?->tenant?->name ?? '—',
                'count' => $group->count(),
                'amount' => round((float) $group->sum(fn (Invoice $invoice): float => $invoice->balance()), 2),
                'max_days' => (int) $group->max(fn (Invoice $invoice) => $invoice->due_at->diffInDays(today())),
            ])
            ->sortByDesc('amount')
            ->take(5)
            ->values();

        foreach ($oldDebtors as $debtor) {
            $alerts[] = [
                'severity' => 'high',
                'detail' => __('assistant.briefing.alert_old_debt', [
                    'tenant' => $debtor['tenant'],
                    'days' => $debtor['max_days'],
                    'amount' => $this->money($debtor['amount']),
                ]),
            ];
        }

        return $alerts;
    }

    /**
     * @param  array{tables: array<string, mixed>, alerts: list<array{severity: string, detail: string}>, overdue_count: int, overdue_amount: float, due_soon_count: int, payments_today: float, expiring_count: int, adjustments_due: int, unsigned_acts: int}  $digest
     */
    private function summaryText(array $digest): string
    {
        $money = fn (float $amount): string => app(AppSettings::class)->formatMoney($amount);
        $lines = [__('assistant.briefing.greeting', ['date' => today()->isoFormat('LL')])];

        if ($digest['overdue_count'] > 0) {
            $lines[] = __('assistant.briefing.line_overdue', [
                'count' => $digest['overdue_count'],
                'amount' => $money($digest['overdue_amount']),
            ]);
        }

        if ($digest['due_soon_count'] > 0) {
            $lines[] = __('assistant.briefing.line_due_soon', ['count' => $digest['due_soon_count']]);
        }

        if ($digest['payments_today'] > 0) {
            $lines[] = __('assistant.briefing.line_payments', ['amount' => $money($digest['payments_today'])]);
        }

        if ($digest['expiring_count'] > 0) {
            $lines[] = __('assistant.briefing.line_expiring', ['count' => $digest['expiring_count']]);
        }

        if ($digest['adjustments_due'] > 0) {
            $lines[] = __('assistant.briefing.line_adjustments', ['count' => $digest['adjustments_due']]);
        }

        if ($digest['unsigned_acts'] > 0) {
            $lines[] = __('assistant.briefing.line_acts', ['count' => $digest['unsigned_acts']]);
        }

        $allClear = $digest['overdue_count'] === 0
            && $digest['due_soon_count'] === 0
            && $digest['payments_today'] === 0
            && $digest['expiring_count'] === 0
            && $digest['adjustments_due'] === 0
            && $digest['unsigned_acts'] === 0;

        $lines[] = $allClear
            ? __('assistant.briefing.all_clear')
            : __('assistant.briefing.footer', ['alerts' => count($digest['alerts'])]);

        return implode("\n\n", $lines);
    }

    private function money(float $amount): string
    {
        return app(AppSettings::class)->formatMoney($amount);
    }
}
