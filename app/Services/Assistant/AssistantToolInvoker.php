<?php

namespace App\Services\Assistant;

use App\Dto\AssistantReply;
use App\Enums\ActItemState;
use App\Enums\BillingCadence;
use App\Enums\InvoiceStatus;
use App\Enums\RentalType;
use App\Filament\Pages\DocumentsPage;
use App\Filament\Pages\EditProfile;
use App\Filament\Pages\FinanzasPage;
use App\Filament\Resources\DeliveryActResource;
use App\Filament\Resources\InvoiceResource;
use App\Filament\Resources\PropertyResource;
use App\Filament\Resources\RentAdjustmentResource;
use App\Filament\Resources\RentalResource;
use App\Filament\Resources\TenantResource;
use App\Models\ActItem;
use App\Models\DeliveryAct;
use App\Models\Geography;
use App\Models\IpcSeries;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Property;
use App\Models\RentAdjustment;
use App\Models\Rental;
use App\Models\Tenant;
use App\Models\User;
use App\Services\FinancialAnalysisService;
use App\Services\RentalFacade;
use App\Services\SettingsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Traduce la intencion clasificada por la IA a datos reales del sistema.
 *
 * La IA nunca calcula ni escribe: devuelve {intent, params} y este invocador
 * ejecuta el intent contra los modelos con el user_id del arrendador. Los
 * intents de escritura no ejecutan nada: devuelven una accion propuesta que
 * la pantalla de chat muestra para confirmacion humana.
 */
class AssistantToolInvoker
{
    /** Intents que son solo lectura y devuelven datos. */
    private const READ_INTENTS = [
        'greeting',
        'portfolio_summary',
        'pending_balance',
        'overdue_invoices',
        'income_period',
        'next_adjustments',
        'expiring_contracts',
        'list_tenants',
        'list_properties',
        'list_rentals',
        'list_invoices',
        'list_payments',
        'list_delivery_acts',
        'list_properties_with_acts',
        'show_profile',
        'list_adjustments',
        'adjustment_detail',
        'preview_adjustment_ipc',
        'aging_report',
        'top_debtors',
        'payments_by_method',
        'collection_rate',
        'show_settings',
        'ipc_status',
        'entity_detail',
        'query_data',
        'general_answer',
        'app_guide',
        'business_insights',
    ];

    private const ANALYSIS_INTENTS = ['financial_analysis'];

    /**
     * Acciones de escritura maximas por confirmacion en un batch grande.
     * El resto queda en cola (form session 'batch_continue') y llega con
     * el "continuar" del usuario: evita un request eterno y deja revisar
     * cada grupo antes de ejecutarlo.
     */
    private const BATCH_CHUNK = 12;

    private const DRAFT_INTENTS = ['draft_reminder', 'draft_notice'];

    /** Intents que no leen ni proponen: gestionan el wizard en sesion. */
    private const FORM_INTENTS = ['form_field', 'cancel_form'];

    private const ACTION_INTENTS = [
        'propose_generate_invoices',
        'propose_record_payment',
        'create_tenant',
        'create_property',
        'create_rental',
        'propose_update_profile',
        'update_tenant',
        'update_property',
        'update_rental',
        'record_adjustment',
        'apply_adjustment',
        'mark_adjustment_notified',
        'recalculate_adjustments',
        'annul_invoice',
        'generate_document',
        'bill_single_rental',
        'create_delivery_act',
        'update_settings',
        'sync_ipc',
        'export_csv',
    ];

    /**
     * Referencia interna "Modelo:id" elegida por el usuario en una lista de
     * candidatos. Solo vive durante una llamada a invoke(): los resolvedores
     * la consumen para devolver ESA entidad aunque el nombre siga siendo
     * ambiguo (dos inquilinos "Camila"). Nunca se muestra en la UI.
     */
    private ?string $pendingEntityRef = null;

    /**
     * Ultimo error de `query_data` (validacion o ejecucion). El adapter lo
     * lee para reintentar la clasificacion una vez con el error como
     * contexto: el modelo corrige su propio SQL en vez de rendirse.
     */
    public ?string $lastQueryError = null;

    public function __construct(
        private readonly PortfolioContextBuilder $context,
        private readonly FinancialAnalysisService $analysis,
        private readonly AssistantFormSession $forms,
        private readonly RentalFacade $rentals,
        private readonly SettingsService $settings,
        private readonly AppGuide $guide,
        private readonly PortfolioInsightsService $insights,
        private readonly DatabaseSchemaDigest $schema,
    ) {}

    /**
     * @param  array<string, mixed>  $params
     */
    public function invoke(string $intent, array $params, int $userId): AssistantReply
    {
        $this->pendingEntityRef = isset($params['_entity_ref']) && is_string($params['_entity_ref'])
            ? $params['_entity_ref']
            : null;

        $this->lastQueryError = null;

        try {
            $reply = match (true) {
                $intent === 'navigate' => $this->navigate($params, $userId),
                $intent === 'continue_batch' => $this->continueBatch($params, $userId),
                in_array($intent, self::READ_INTENTS, true) => $this->read($intent, $params, $userId),
                in_array($intent, self::ANALYSIS_INTENTS, true) => $this->financialAnalysis($userId),
                in_array($intent, self::DRAFT_INTENTS, true) => $this->draft($intent, $params, $userId),
                in_array($intent, self::FORM_INTENTS, true) => $this->handleForm($intent, $params, $userId),
                in_array($intent, self::ACTION_INTENTS, true) => $this->propose($intent, $params, $userId),
                default => AssistantReply::say(__('assistant.unknown')),
            };
        } finally {
            $this->pendingEntityRef = null;
        }

        // El intent viaja en la respuesta para que el chat pueda repetir
        // los datos cuando el usuario pide "la tabla" como follow-up.
        return $reply->withIntent($intent);
    }

    /**
     * Varias peticiones en un solo mensaje ("muéstrame inquilinos y
     * propiedades", "registra estos 3 inquilinos y 2 propiedades").
     *
     * Fusiona las respuestas: textos en orden, tablas de datos unidas,
     * acciones de escritura en una sola accion compuesta "batch" que el
     * usuario confirma una vez y el preview muestra por secciones.
     *
     * @param  list<array{intent: string, params: array<string, mixed>}>  $requests
     */
    public function invokeBatch(array $requests, int $userId): AssistantReply
    {
        $texts = [];
        $data = [];
        $actions = [];
        $redirect = null;
        $suggestions = [];
        $sections = [];

        // Entidades que este mismo batch va a crear: un create_rental que
        // las referencie no debe pedir datos que la creacion ya trae.
        $pending = [
            'tenants' => $this->pendingNames($requests, ['create_tenant'], ['name', 'tenant_name']),
            'properties' => $this->pendingNames($requests, ['create_property'], ['name', 'property_name']),
        ];

        foreach ($this->orderForExecution($requests) as $request) {
            $intent = (string) ($request['intent'] ?? 'unknown');
            $params = is_array($request['params'] ?? null) ? $request['params'] : [];
            $params['__pending'] = $pending;

            $reply = $this->invoke($intent, $params, $userId);

            if ($reply->text !== '' || is_array($reply->data)) {
                $texts[] = $reply->text;
                $sections[] = ['text' => $reply->text, 'data' => $reply->data];
            }

            if (is_array($reply->data)) {
                $this->mergeData($data, $reply->data);
            }

            if ($reply->action !== null) {
                $actions[] = $reply->action;
            }

            $redirect ??= $reply->redirect;

            foreach ($reply->suggestions as $suggestion) {
                if (! in_array($suggestion, $suggestions, true)) {
                    $suggestions[] = $suggestion;
                }
            }
        }

        // Operacion grande: se proponen las primeras BATCH_CHUNK acciones
        // y el resto queda en cola; el usuario pide "continuar" y llega el
        // siguiente grupo. Sin cola si ya hay un wizard/formulario activo
        // (una sola sescion de formulario por usuario).
        $queued = 0;

        if (count($actions) > self::BATCH_CHUNK
            && ($this->forms->get($userId)['form'] ?? null) === null) {
            $remaining = array_splice($actions, self::BATCH_CHUNK);
            $queued = count($remaining);
            $this->forms->put($userId, 'batch_continue', ['items' => $remaining]);
        }

        $action = match (count($actions)) {
            0 => null,
            1 => $actions[0],
            default => [
                'type' => 'batch',
                'items' => $actions,
                'summary' => $this->batchSummary($actions),
            ],
        };

        $texts = array_filter($texts);

        if ($queued > 0) {
            $texts[] = __('assistant.replies.batch_chunk', ['count' => $queued]);
            $suggestions[] = __('assistant.suggestions.batch_continue');
        }

        return new AssistantReply(
            text: $texts === [] ? __('assistant.unknown') : implode("\n\n", $texts),
            data: $data === [] ? null : $data,
            action: $action,
            redirect: $redirect,
            suggestions: array_slice(array_values(array_unique($suggestions)), 0, 4),
            intent: 'batch',
            sections: $sections === [] ? null : $sections,
        );
    }

    /**
     * Siguiente grupo de un batch grande en cola ("continuar"). Propone el
     * chunk, vuelve a encolar el resto y libera la sesion al terminar.
     *
     * @param  array<string, mixed>  $params
     */
    private function continueBatch(array $params, int $userId): AssistantReply
    {
        $session = $this->forms->get($userId);
        $items = $session['data']['items'] ?? null;

        if (($session['form'] ?? null) !== 'batch_continue' || ! is_array($items) || $items === []) {
            return AssistantReply::say(__('assistant.replies.batch_continue_none'), [
                __('assistant.suggestions.portfolio'),
            ]);
        }

        $chunk = array_splice($items, 0, self::BATCH_CHUNK);
        $remaining = $items;

        if ($remaining === []) {
            $this->forms->clear($userId);
        } else {
            $this->forms->put($userId, 'batch_continue', ['items' => $remaining]);
        }

        $action = match (count($chunk)) {
            1 => $chunk[0],
            default => [
                'type' => 'batch',
                'items' => $chunk,
                'summary' => $this->batchSummary($chunk),
            ],
        };

        $text = __('assistant.replies.batch_continue_next', ['count' => count($chunk)]);
        $suggestions = [];

        if ($remaining !== []) {
            $text .= "\n\n".__('assistant.replies.batch_continue_more', ['count' => count($remaining)]);
            $suggestions[] = __('assistant.suggestions.batch_continue');
        }

        return new AssistantReply(
            text: $text,
            action: $action,
            suggestions: $suggestions,
            intent: 'batch_continue',
        );
    }

    /**
     * Nombres (en minusculas) que las peticiones indicadas van a crear,
     * para que intents posteriores sepan que la entidad existira.
     *
     * @param  list<array{intent: string, params: array<string, mixed>}>  $requests
     * @param  list<string>  $intents
     * @param  list<string>  $nameKeys
     * @return list<string>
     */
    private function pendingNames(array $requests, array $intents, array $nameKeys): array
    {
        $names = [];

        foreach ($requests as $request) {
            if (! in_array($request['intent'] ?? '', $intents, true)) {
                continue;
            }

            $params = is_array($request['params'] ?? null) ? $request['params'] : [];

            foreach ($nameKeys as $key) {
                $name = trim((string) ($params[$key] ?? ''));

                if ($name !== '') {
                    $names[] = mb_strtolower($name);
                }
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * true si el nombre coincide (o esta contenido) en una lista de
     * nombres pendientes del batch, ya normalizados en minusculas.
     *
     * @param  list<string>  $pendingNames
     */
    private function namePending(string $name, array $pendingNames): bool
    {
        $needle = mb_strtolower(trim($name));

        if ($needle === '') {
            return false;
        }

        foreach ($pendingNames as $pending) {
            if ($pending === $needle || str_contains($pending, $needle) || str_contains($needle, $pending)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Mueve los create_rental detras de los create_property/create_tenant
     * del mismo batch: al confirmar, el executor los resuelve por nombre
     * y necesitan existir primero.
     *
     * @param  list<array{intent: string, params: array<string, mixed>}>  $requests
     * @return list<array{intent: string, params: array<string, mixed>}>
     */
    private function orderForExecution(array $requests): array
    {
        $ordered = [];
        $rentals = [];
        $insertAt = null;

        foreach ($requests as $request) {
            $intent = (string) ($request['intent'] ?? '');

            if ($intent === 'create_rental') {
                $rentals[] = $request;

                continue;
            }

            $ordered[] = $request;

            if (in_array($intent, ['create_property', 'create_tenant'], true)) {
                $insertAt = count($ordered);
            }
        }

        if ($rentals !== []) {
            array_splice($ordered, $insertAt ?? count($ordered), 0, $rentals);
        }

        return $ordered;
    }

    /**
     * @param  array<string, mixed>  $target
     * @param  array<string, mixed>  $source
     */
    private function mergeData(array &$target, array $source): void
    {
        foreach ($source as $key => $rows) {
            $mergedKey = (string) $key;
            $suffix = 2;

            while (array_key_exists($mergedKey, $target)) {
                $mergedKey = $key.'_'.$suffix;
                $suffix++;
            }

            $target[$mergedKey] = $rows;
        }
    }

    /**
     * Resumen legible del paquete: "3 inquilinos, 2 propiedades".
     *
     * @param  list<array<string, mixed>>  $actions
     */
    private function batchSummary(array $actions): string
    {
        $counts = [];

        foreach ($actions as $action) {
            $type = (string) ($action['type'] ?? '');
            $counts[$type] = ($counts[$type] ?? 0) + 1;
        }

        $parts = [];

        foreach ($counts as $type => $count) {
            $parts[] = trans_choice('assistant.batch_types.'.$type, $count, ['count' => $count]);
        }

        return __('assistant.actions.batch_summary', [
            'items' => implode(', ', $parts),
        ]);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function read(string $intent, array $params, int $userId): AssistantReply
    {
        return match ($intent) {
            'greeting' => $this->greeting(),
            'portfolio_summary' => $this->portfolioSummary($userId),
            'pending_balance' => $this->pendingBalance($params, $userId),
            'overdue_invoices' => $this->overdueInvoices($userId),
            'income_period' => $this->incomePeriod($params, $userId),
            'next_adjustments' => $this->nextAdjustments($params, $userId),
            'expiring_contracts' => $this->expiringContracts($userId),
            'list_tenants' => $this->listTenants($params, $userId),
            'list_properties' => $this->listProperties($params, $userId),
            'list_rentals' => $this->listRentals($params, $userId),
            'list_invoices' => $this->listInvoices($params, $userId),
            'list_payments' => $this->listPayments($params, $userId),
            'list_delivery_acts' => $this->listDeliveryActs($params, $userId),
            'list_properties_with_acts' => $this->listPropertiesWithActs($userId),
            'show_profile' => $this->showProfile($userId),
            'list_adjustments' => $this->listAdjustments($params, $userId),
            'adjustment_detail' => $this->adjustmentDetail($params, $userId),
            'preview_adjustment_ipc' => $this->previewAdjustmentIpc($params, $userId),
            'aging_report' => $this->agingReport($userId),
            'top_debtors' => $this->topDebtors($params, $userId),
            'payments_by_method' => $this->paymentsByMethod($userId),
            'collection_rate' => $this->collectionRate($userId),
            'show_settings' => $this->showSettings($userId),
            'ipc_status' => $this->ipcStatus($userId),
            'entity_detail' => $this->entityDetail($params, $userId),
            'query_data' => $this->freeQuery($params, $userId),
            'general_answer' => $this->generalAnswer($params),
            'app_guide' => $this->appGuide($params),
            'business_insights' => $this->businessInsights($params, $userId),
            default => AssistantReply::say(__('assistant.unknown')),
        };
    }

    private function greeting(): AssistantReply
    {
        return AssistantReply::say(
            __('assistant.greeting'),
            $this->defaultSuggestions(),
        );
    }

    private function portfolioSummary(int $userId): AssistantReply
    {
        $digest = $this->context->digestFor($userId);
        $totals = $digest->totals;

        $text = __('assistant.replies.portfolio_summary', [
            'rentals' => $totals['active_rentals'],
            'balance' => $this->money($totals['open_balance']),
            'overdue' => $this->money($totals['overdue_amount']),
            'income' => $this->money($totals['income_this_month']),
        ]);

        return new AssistantReply(
            text: $text,
            data: [
                'rentals' => array_map(fn (array $r): array => [
                    'name' => $r['name'],
                    'tenant' => $r['tenant'] ?? '—',
                    'rent' => $this->money((float) $r['current_rent']),
                    'cadence' => $r['billing_cadence'] ?? '—',
                ], $digest->rentals),
                'totals' => $totals,
            ],
            suggestions: [
                __('assistant.suggestions.overdue'),
                __('assistant.suggestions.analysis'),
                __('assistant.suggestions.adjustments'),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function pendingBalance(array $params, int $userId): AssistantReply
    {
        $invoices = $this->openInvoices($userId);
        $filter = $this->tenantFilter($params);

        if ($filter !== null) {
            $invoices = $invoices->filter(
                fn (Invoice $invoice): bool => Str::contains(Str::lower((string) $invoice->rental?->tenant?->name), $filter),
            );
        }

        $byRental = $invoices->groupBy('rental_id')->map(fn ($group): array => [
            'rental' => $group->first()->rental?->name ?? '—',
            'tenant' => $group->first()->rental?->tenant?->name ?? '—',
            'invoices' => $group->count(),
            'balance' => round((float) $group->sum(fn (Invoice $i): float => $i->balance()), 2),
        ])->sortByDesc('balance')->values();

        $total = round((float) $byRental->sum('balance'), 2);

        if ($total <= 0) {
            return AssistantReply::say(__('assistant.replies.no_debt'), [
                __('assistant.suggestions.analysis'),
            ]);
        }

        return new AssistantReply(
            text: $filter !== null
                ? __('assistant.replies.pending_balance_tenant', ['tenant' => $filter, 'amount' => $this->money($total)])
                : __('assistant.replies.pending_balance', ['amount' => $this->money($total)]),
            data: ['debts' => $byRental->all()],
            redirect: InvoiceResource::getUrl('index'),
            suggestions: [__('assistant.suggestions.reminder'), __('assistant.suggestions.overdue')],
        );
    }

    private function overdueInvoices(int $userId): AssistantReply
    {
        $overdue = $this->openInvoices($userId)
            ->filter(fn (Invoice $invoice): bool => $invoice->status === InvoiceStatus::VENCIDA)
            ->sortBy('due_at')
            ->values();

        if ($overdue->isEmpty()) {
            return AssistantReply::say(__('assistant.replies.no_overdue'), [
                __('assistant.suggestions.analysis'),
            ]);
        }

        $total = round((float) $overdue->sum(fn (Invoice $i): float => $i->balance()), 2);
        $oldestDays = (int) today()->startOfDay()->diffInDays($overdue->first()->due_at->startOfDay());

        return new AssistantReply(
            text: __('assistant.replies.overdue_invoices', [
                'count' => $overdue->count(),
                'amount' => $this->money($total),
                'days' => $oldestDays,
            ]),
            data: [
                'invoices' => $overdue->map(fn (Invoice $invoice): array => [
                    'number' => $invoice->number,
                    'rental' => $invoice->rental?->name ?? '—',
                    'tenant' => $invoice->rental?->tenant?->name ?? '—',
                    'balance' => $this->money($invoice->balance()),
                    'due_at' => $invoice->due_at->toDateString(),
                    'days_late' => (int) today()->startOfDay()->diffInDays($invoice->due_at->startOfDay()),
                ])->all(),
            ],
            redirect: InvoiceResource::getUrl('index'),
            suggestions: [__('assistant.suggestions.reminder'), __('assistant.suggestions.analysis')],
        );
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function incomePeriod(array $params, int $userId): AssistantReply
    {
        $period = $this->resolvePeriod($params['period'] ?? null);
        $from = Carbon::createFromFormat('Y-m-d', $period.'-01')->startOfMonth();
        $to = $from->copy()->endOfMonth();

        $income = $this->analysis->incomeBetween($userId, $from, $to);

        $previous = $from->copy()->subMonthNoOverflow();
        $prevIncome = $this->analysis->incomeBetween($userId, $previous, $previous->copy()->endOfMonth());

        $byRental = Payment::query()
            ->where('user_id', $userId)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->with('rental:id,name')
            ->get()
            ->groupBy('rental_id')
            ->map(fn ($group): array => [
                'rental' => $group->first()->rental?->name ?? '—',
                'amount' => round((float) $group->sum('amount'), 2),
            ])
            ->sortByDesc('amount')
            ->values();

        $change = $prevIncome > 0 ? round(($income - $prevIncome) / $prevIncome * 100, 1) : null;

        return new AssistantReply(
            text: __('assistant.replies.income_period', [
                'period' => $from->format('m/Y'),
                'amount' => $this->money($income),
                'previous' => $this->money($prevIncome),
                'change' => $change === null ? '—' : ($change >= 0 ? '+' : '').$change.' %',
            ]),
            data: [
                'income' => $income,
                'previous' => $prevIncome,
                'change_pct' => $change,
                'by_rental' => $byRental->all(),
            ],
            suggestions: [__('assistant.suggestions.analysis'), __('assistant.suggestions.pending')],
        );
    }

    private function nextAdjustments(array $params, int $userId): AssistantReply
    {
        $query = Rental::query()
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->with('tenant:id,name');

        // Si la pregunta nombra un arriendo/inquilino, la respuesta es
        // SOLO de ese: "cuando toca el reajuste de X" no es "dame todos".
        $named = $this->param($params, ['rental_name', 'tenant', 'entity', 'name']);
        $scopedRental = null;

        if ($named !== null) {
            $resolved = $this->resolveRentalCandidates($named, $userId);

            if (($resolved['entity'] ?? null) instanceof Rental) {
                $scopedRental = $resolved['entity'];
            } elseif (! empty($resolved['candidates'])) {
                return $this->ambiguousReply($named, $resolved['candidates'], 'adjustments', [
                    'intent' => 'next_adjustments',
                    'params' => ['rental_name' => $named],
                    'name_key' => 'rental_name',
                ]);
            }
        }

        $rentals = $query
            ->when($scopedRental !== null, fn ($q) => $q->whereKey($scopedRental->id))
            ->get()
            ->map(fn (Rental $rental): array => [
                'rental' => $rental,
                'due' => $rental->nextAdjustmentDueDate(),
            ])
            ->sortBy(fn (array $row) => $row['due']->getTimestamp())
            ->take(10);

        if ($rentals->isEmpty()) {
            return AssistantReply::say(
                $named !== null
                    ? __('assistant.replies.no_adjustment_for', ['entity' => $named])
                    : __('assistant.replies.no_rentals'),
                [__('assistant.suggestions.portfolio')],
            );
        }

        // Dias siempre positivos + bandera de vencido: un "-288" en la
        // tabla no le dice nada al usuario; "vencido hace 288 dias" si.
        $rows = $rentals->map(fn (array $row): array => [
            'rental' => $row['rental']->name,
            'tenant' => $row['rental']->tenant?->name ?? '—',
            'due_date' => $row['due']->toDateString(),
            'days' => (int) abs($row['due']->startOfDay()->diffInDays(today()->startOfDay())),
            'overdue' => $row['due']->isBefore(today()->startOfDay()) ? __('assistant.yes') : __('assistant.no'),
        ])->all();

        $text = $rentals->count() === 1
            ? __('assistant.replies.next_adjustment_single', [
                'rental' => $rows[0]['rental'],
                'tenant' => $rows[0]['tenant'],
                'date' => $rows[0]['due_date'],
            ])
            : __('assistant.replies.next_adjustments', ['count' => $rentals->count()]);

        return new AssistantReply(
            text: $text,
            data: ['adjustments' => $rows],
            redirect: RentAdjustmentResource::getUrl('index'),
            suggestions: [__('assistant.suggestions.analysis'), __('assistant.suggestions.portfolio')],
        );
    }

    private function expiringContracts(int $userId): AssistantReply
    {
        $limit = today()->addDays(90)->startOfDay();

        $rentals = Rental::query()
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->whereNotNull('end_date')
            ->where('end_date', '<=', $limit)
            ->where('end_date', '>=', today()->startOfDay())
            ->with('tenant:id,name')
            ->orderBy('end_date')
            ->get();

        if ($rentals->isEmpty()) {
            return AssistantReply::say(__('assistant.replies.no_expiring'), [
                __('assistant.suggestions.portfolio'),
            ]);
        }

        return new AssistantReply(
            text: __('assistant.replies.expiring_contracts', ['count' => $rentals->count()]),
            data: [
                'contracts' => $rentals->map(fn (Rental $rental): array => [
                    'rental' => $rental->name,
                    'tenant' => $rental->tenant?->name ?? '—',
                    'end_date' => $rental->end_date->toDateString(),
                    'days' => (int) $rental->end_date->startOfDay()->diffInDays(today()->startOfDay()),
                ])->all(),
            ],
            redirect: RentalResource::getUrl('index'),
            suggestions: [__('assistant.suggestions.portfolio'), __('assistant.suggestions.pending')],
        );
    }

    /**
     * Inquilinos reales del usuario (Tenant), no arriendos: un inquilino
     * puede tener varios arriendos y no existe el concepto "activo".
     */
    private function listTenants(array $params, int $userId): AssistantReply
    {
        $search = $this->searchTerm($params);

        $tenants = $this->filterModels(
            Tenant::query()
                ->where('user_id', $userId)
                ->with(['rentals' => fn ($query) => $query->where('is_active', true)])
                ->orderBy('name')
                ->get(),
            $search,
            fn (Tenant $tenant): string => implode(' ', array_filter([
                $tenant->name, $tenant->document, $tenant->phone_number, $tenant->email,
            ])),
        );

        if ($tenants->isEmpty()) {
            return $this->emptyListReply($search, 'tenants');
        }

        $openByRental = $this->openInvoices($userId)->groupBy('rental_id')
            ->map(fn ($group): float => round((float) $group->sum(fn (Invoice $i): float => $i->balance()), 2));

        return new AssistantReply(
            text: __('assistant.replies.list_tenants', ['count' => $tenants->count()]),
            data: [
                'tenants' => $tenants->map(function (Tenant $tenant) use ($openByRental): array {
                    $balance = round((float) $tenant->rentals->sum(
                        fn (Rental $rental): float => $openByRental[$rental->id] ?? 0.0,
                    ), 2);

                    return [
                        'name' => $tenant->name,
                        'phone' => $tenant->phone_number ?? '—',
                        'document' => $tenant->document ?? '—',
                        'email' => $tenant->email ?? '—',
                        'rentals' => $tenant->rentals->count(),
                        'balance' => $this->money($balance),
                    ];
                })->all(),
            ],
            redirect: $this->redirectWithSearch(TenantResource::getUrl('index'), $search),
            suggestions: [__('assistant.suggestions.pending'), __('assistant.suggestions.reminder')],
        );
    }

    private function listProperties(array $params, int $userId): AssistantReply
    {
        $search = $this->searchTerm($params);

        $properties = $this->filterModels(
            Property::query()
                ->where('user_id', $userId)
                ->withCount(['rentals' => fn ($query) => $query->where('is_active', true)])
                ->orderBy('name')
                ->get(),
            $search,
            fn (Property $property): string => implode(' ', array_filter([
                $property->name, $property->address,
            ])),
        );

        if ($properties->isEmpty()) {
            return $this->emptyListReply($search, 'properties');
        }

        return new AssistantReply(
            text: __('assistant.replies.list_properties', ['count' => $properties->count()]),
            data: [
                'properties' => $properties->map(fn (Property $property): array => [
                    'name' => $property->name,
                    'address' => $property->address ?? '—',
                    'rentals' => $property->rentals_count,
                ])->all(),
            ],
            redirect: $this->redirectWithSearch(PropertyResource::getUrl('index'), $search),
            suggestions: [__('assistant.suggestions.portfolio'), __('assistant.suggestions.rentals')],
        );
    }

    private function listRentals(array $params, int $userId): AssistantReply
    {
        $search = $this->searchTerm($params);

        $rentals = $this->filterModels(
            Rental::query()
                ->where('user_id', $userId)
                ->where('is_active', true)
                ->with(['tenant:id,name', 'property:id,name'])
                ->orderBy('name')
                ->get(),
            $search,
            fn (Rental $rental): string => implode(' ', array_filter([
                $rental->name, $rental->tenant?->name, $rental->property?->name,
            ])),
        );

        if ($rentals->isEmpty()) {
            return $this->emptyListReply($search, 'rentals');
        }

        return new AssistantReply(
            text: __('assistant.replies.list_rentals', ['count' => $rentals->count()]),
            data: [
                'rentals' => $rentals->map(fn (Rental $rental): array => [
                    'name' => $rental->name,
                    'tenant' => $rental->tenant?->name ?? '—',
                    'property' => $rental->property?->name ?? '—',
                    'rent' => $this->money((float) $rental->monthly_amount),
                    'cadence' => $rental->billing_cadence?->value ?? '—',
                    'end_date' => $rental->end_date?->toDateString() ?? '—',
                ])->all(),
            ],
            redirect: $this->redirectWithSearch(RentalResource::getUrl('index'), $search),
            suggestions: [__('assistant.suggestions.pending'), __('assistant.suggestions.adjustments')],
        );
    }

    private function listInvoices(array $params, int $userId): AssistantReply
    {
        $search = $this->searchTerm($params);

        $invoices = Invoice::query()
            ->where('user_id', $userId)
            ->with('rental:id,name,tenant_id', 'rental.tenant:id,name')
            ->orderByDesc('issued_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        $invoices = $this->filterModels(
            $invoices,
            $search,
            fn (Invoice $invoice): string => implode(' ', array_filter([
                $invoice->number, $invoice->rental?->name, $invoice->rental?->tenant?->name,
            ])),
        )->take(10)->values();

        if ($invoices->isEmpty()) {
            return $this->emptyListReply($search, 'invoices');
        }

        return new AssistantReply(
            text: __('assistant.replies.list_invoices', ['count' => $invoices->count()]),
            data: [
                'invoices' => $invoices->map(fn (Invoice $invoice): array => [
                    'number' => $invoice->number,
                    'tenant' => $invoice->rental?->tenant?->name ?? '—',
                    'rental' => $invoice->rental?->name ?? '—',
                    'amount' => $this->money((float) $invoice->amount),
                    'balance' => $this->money($invoice->balance()),
                    'status' => $invoice->status->value,
                    'due_at' => $invoice->due_at->toDateString(),
                ])->all(),
            ],
            redirect: $this->redirectWithSearch(InvoiceResource::getUrl('index'), $search),
            suggestions: [__('assistant.suggestions.overdue'), __('assistant.suggestions.pending')],
        );
    }

    private function listPayments(array $params, int $userId): AssistantReply
    {
        $search = $this->searchTerm($params);

        $payments = Payment::query()
            ->where('user_id', $userId)
            ->with('rental:id,name,tenant_id', 'rental.tenant:id,name', 'invoice:id,number')
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        $payments = $this->filterModels(
            $payments,
            $search,
            fn (Payment $payment): string => implode(' ', array_filter([
                $payment->rental?->name, $payment->rental?->tenant?->name,
                $payment->invoice?->number, $payment->method,
            ])),
        )->take(10)->values();

        if ($payments->isEmpty()) {
            return $this->emptyListReply($search, 'payments');
        }

        return new AssistantReply(
            text: __('assistant.replies.list_payments', ['count' => $payments->count()]),
            data: [
                'payments' => $payments->map(fn (Payment $payment): array => [
                    'date' => $payment->date?->toDateString() ?? '—',
                    'tenant' => $payment->rental?->tenant?->name ?? '—',
                    'invoice' => $payment->invoice?->number ?? '—',
                    'amount' => $this->money((float) $payment->amount),
                    'method' => $payment->methodLabel(),
                ])->all(),
            ],
            redirect: $this->redirectWithSearch(InvoiceResource::getUrl('index'), $search),
            suggestions: [__('assistant.suggestions.analysis'), __('assistant.suggestions.pending')],
        );
    }

    /**
     * Actas de entrega del usuario con su inventario relevante: ademas del
     * listado, se destacan los elementos en mal estado (reparable, por
     * reemplazar, destruido) que son lo que el arrendador debe vigilar.
     */
    private function listDeliveryActs(array $params, int $userId): AssistantReply
    {
        $search = $this->searchTerm($params);

        // Scope por entidad ("el acta del arriendo de X", "actas de Juan"):
        // si la pregunta nombra un arriendo o inquilino, el listado se
        // acota a ese — nunca se devuelven actas ajenas a lo pedido.
        $rental = $this->param($params, ['rental_name', 'tenant_name', 'tenant', 'entity', 'name']) !== null
            ? $this->findRental($params, $userId)
            : null;

        $query = DeliveryAct::query()
            ->where('user_id', $userId)
            ->withCount('items')
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(50);

        if ($rental !== null) {
            $tenantName = (string) ($rental->tenant?->name ?? '');
            $query->where(fn ($q) => $q
                ->where('rental_id', $rental->id)
                ->when($tenantName !== '', fn ($qq) => $qq->orWhere('tenant_name', $tenantName)));
        }

        $acts = $this->filterModels(
            $query->get(),
            $search,
            fn (DeliveryAct $act): string => implode(' ', array_filter([
                $act->tenant_name, $act->landlord_name, $act->type,
            ])),
        )->take(10)->values();

        if ($acts->isEmpty()) {
            if ($rental !== null) {
                return AssistantReply::say(__('assistant.replies.no_acts_for', [
                    'name' => (string) ($rental->tenant?->name ?: $rental->name),
                ]), [
                    __('assistant.suggestions.delivery_act'),
                ]);
            }

            return $this->emptyListReply($search, 'acts');
        }

        $attention = ActItem::query()
            ->whereIn('state', [
                ActItemState::REPARABLE,
                ActItemState::POR_REEMPLAZAR,
                ActItemState::DESTRUIDO,
            ])
            ->whereHas('deliveryAct', fn ($query) => $query->where('user_id', $userId)
                ->when($rental !== null, fn ($q) => $q->where(fn ($qq) => $qq
                    ->where('rental_id', $rental->id)
                    ->when((string) ($rental->tenant?->name ?? '') !== '',
                        fn ($qqq) => $qqq->orWhere('tenant_name', (string) $rental->tenant?->name)))))
            ->with('deliveryAct:id,tenant_name')
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        $unsigned = $acts->filter(fn (DeliveryAct $act): bool => ! $act->isSigned())->count();
        $scopeName = $rental !== null
            ? (string) ($rental->tenant?->name ?: $rental->name)
            : null;

        return new AssistantReply(
            text: $scopeName !== null
                ? __('assistant.replies.list_delivery_acts_scoped', [
                    'name' => $scopeName,
                    'count' => $acts->count(),
                    'unsigned' => $unsigned,
                    'attention' => $attention->count(),
                ])
                : __('assistant.replies.list_delivery_acts', [
                    'count' => $acts->count(),
                    'unsigned' => $unsigned,
                    'attention' => $attention->count(),
                ]),
            data: [
                ...(is_string($scopeName) ? ['_titles' => [
                    'acts' => __('assistant.data_titles.acts_named', ['name' => $scopeName]),
                ]] : []),
                'acts' => $acts->map(fn (DeliveryAct $act): array => [
                    'type' => __("act.types.{$act->type}"),
                    'tenant' => $act->tenant_name ?? '—',
                    'occurred_at' => $act->occurred_at?->toDateString() ?? '—',
                    'signed' => $act->isSigned() ? __('assistant.yes') : __('assistant.no'),
                    'items' => $act->items_count,
                ])->all(),
                'attention_items' => $attention->map(fn (ActItem $item): array => [
                    'tenant' => $item->deliveryAct?->tenant_name ?? '—',
                    'space' => $item->spaceNode?->name ?? (string) $item->space,
                    'item' => $item->name,
                    'state' => $item->state->label(),
                ])->all(),
            ],
            redirect: $this->redirectWithSearch(DeliveryActResource::getUrl('index'), $search),
            suggestions: [__('assistant.suggestions.portfolio'), __('assistant.suggestions.pending')],
        );
    }

    /**
     * Propiedades del usuario con las actas de entrega de sus arriendos
     * anidadas dentro de cada fila: una sola tabla expandible en vez de
     * 1+N tablas separadas.
     */
    private function listPropertiesWithActs(int $userId): AssistantReply
    {
        $properties = Property::query()
            ->where('user_id', $userId)
            ->with(['rentals.deliveryActs' => fn ($query) => $query
                ->withCount('items')
                ->orderByDesc('occurred_at')])
            ->orderBy('name')
            ->get();

        if ($properties->isEmpty()) {
            return AssistantReply::say(__('assistant.replies.no_properties'), [
                __('assistant.suggestions.portfolio'),
            ]);
        }

        return new AssistantReply(
            text: __('assistant.replies.list_properties_with_acts', ['count' => $properties->count()]),
            data: [
                'properties' => $properties->map(fn (Property $property): array => [
                    'name' => $property->name,
                    'address' => $property->address ?? '—',
                    'rentals' => $property->rentals->count(),
                    'acts' => $property->rentals
                        ->flatMap(fn (Rental $rental) => $rental->deliveryActs->map(
                            fn (DeliveryAct $act): array => [
                                'type' => __("act.types.{$act->type}"),
                                'tenant' => $act->tenant_name ?? $rental->tenant?->name ?? '—',
                                'occurred_at' => $act->occurred_at?->toDateString() ?? '—',
                                'signed' => $act->isSigned() ? __('assistant.yes') : __('assistant.no'),
                                'items' => $act->items_count,
                            ],
                        ))
                        ->sortByDesc('occurred_at')
                        ->take(5)
                        ->values()->all(),
                ])->all(),
            ],
            redirect: PropertyResource::getUrl('index'),
            suggestions: [__('assistant.suggestions.portfolio'), __('assistant.suggestions.properties')],
        );
    }

    /**
     * Datos de la cuenta del propio arrendador (los mismos que edita en
     * Perfil). Nunca incluye la contrasena.
     */
    private function showProfile(int $userId): AssistantReply
    {
        $user = User::query()->find($userId);

        if ($user === null) {
            return AssistantReply::say(__('assistant.replies.profile_not_found'));
        }

        return new AssistantReply(
            text: __('assistant.replies.show_profile'),
            data: [
                'profile' => [[
                    'name' => $user->name,
                    'email' => $user->email,
                    'document_type' => $user->document_type ?? '—',
                    'document' => $user->document ?? '—',
                    'phone' => $user->phone_number ?? '—',
                ]],
            ],
            redirect: EditProfile::getUrl(),
            suggestions: [__('assistant.suggestions.update_profile')],
        );
    }

    private function financialAnalysis(int $userId): AssistantReply
    {
        $metrics = $this->analysis->analyze($userId);

        $lines = [
            __('assistant.replies.analysis.income', [
                'current' => $this->money($metrics['income_this_month']),
                'previous' => $this->money($metrics['income_previous_month']),
            ]),
            __('assistant.replies.analysis.collection', [
                'rate' => $metrics['collection_rate'] === null ? '—' : $metrics['collection_rate'].' %',
            ]),
        ];

        if ($metrics['avg_days_late'] !== null) {
            $lines[] = __('assistant.replies.analysis.days_late', ['days' => $metrics['avg_days_late']]);
        }

        if ($metrics['overdue_count'] > 0) {
            $lines[] = __('assistant.replies.analysis.overdue', [
                'count' => $metrics['overdue_count'],
                'amount' => $this->money($metrics['overdue_amount']),
            ]);
        }

        return new AssistantReply(
            text: implode("\n", $lines),
            data: $metrics,
            redirect: FinanzasPage::getUrl(),
            suggestions: [
                __('assistant.suggestions.overdue'),
                __('assistant.suggestions.pending'),
                __('assistant.suggestions.adjustments'),
            ],
        );
    }

    /**
     * Analisis predictivo de corto plazo. Los numeros los computa
     * PortfolioInsightsService de forma determinista; la IA solo narra
     * el resultado via draft para que distinga hechos de proyecciones.
     */
    private function businessInsights(array $params, int $userId): AssistantReply
    {
        // La pregunta original y el foco viajan en params: sin ellos el
        // analisis era siempre el mismo panorama completo.
        $question = is_string($params['_question'] ?? null) ? $params['_question'] : null;

        $insights = $this->insights->insightsFor($userId, [
            'focus' => isset($params['focus']) && is_string($params['focus']) ? $params['focus'] : null,
            'entity' => $this->param($params, ['entity', 'entity_name', 'tenant', 'rental_name', 'name']),
            'question' => $question,
        ]);
        $forecast = $insights['forecast'];

        $text = $forecast['has_history']
            ? __('assistant.insights.rec_project_title', [
                'amount' => $this->moneyShortText((float) $forecast['projected_next_month']),
            ])
            : __('assistant.insights.no_history');

        // El draft recibe una version compacta: suficiente para narrar
        // sin inflar el contexto. La pregunta original va incluida para
        // que la narrativa responda lo que se pidio, no un texto generico.
        $draftContext = [
            'user_question' => $question,
            'focus' => $insights['focus'] ?? null,
            'forecast' => $forecast,
            'risky_tenants' => collect($insights['tenants'])
                ->filter(fn (array $t): bool => $t['risk_score'] >= 20)
                ->sortByDesc('risk_score')
                ->take(5)
                ->values()
                ->all(),
            'recommendations' => collect($insights['recommendations'])
                ->map(fn (array $r): array => ['title' => $r['title'], 'reason' => $r['reason']])
                ->all(),
        ];

        return new AssistantReply(
            text: $text.' '.__('assistant.insights.disclaimer'),
            draft: [
                'instruction' => __('assistant.insights.draft_instruction'),
                'context' => $draftContext,
            ],
            data: ['insights' => $insights],
            suggestions: [
                __('assistant.insights.rec_see_debtors'),
                __('assistant.suggestions.overdue'),
                __('assistant.suggestions.portfolio'),
            ],
        );
    }

    private function moneyShortText(float $amount): string
    {
        if (abs($amount) >= 1_000_000) {
            return '$'.rtrim(rtrim(number_format($amount / 1_000_000, 1, ',', '.'), '0'), ',').'M';
        }

        return '$'.number_format($amount, 0, ',', '.');
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function draft(string $intent, array $params, int $userId): AssistantReply
    {
        $rental = $this->findRental($params, $userId);

        if ($rental === null) {
            return AssistantReply::say(
                __('assistant.replies.draft_need_rental'),
                [__('assistant.suggestions.pending'), __('assistant.suggestions.portfolio')],
            );
        }

        $invoices = $this->openInvoices($userId)
            ->where('rental_id', $rental->id)
            ->sortBy('due_at')
            ->values();

        $context = [
            'tenant_name' => $rental->tenant?->name,
            'rental_name' => $rental->name,
            'open_invoices' => $invoices->map(fn (Invoice $invoice): array => [
                'number' => $invoice->number,
                'amount' => $invoice->amount,
                'balance' => $invoice->balance(),
                'due_at' => $invoice->due_at->toDateString(),
                'status' => $invoice->status->value,
            ])->all(),
            'total_balance' => round((float) $invoices->sum(fn (Invoice $i): float => $i->balance()), 2),
            'next_adjustment_due' => $rental->isDueForAdjustment() ? $rental->nextAdjustmentDueDate()->toDateString() : null,
            'current_rent' => $rental->monthly_amount,
        ];

        $instruction = $intent === 'draft_reminder'
            ? __('assistant.draft.reminder_instruction')
            : __('assistant.draft.notice_instruction');

        $fallback = $this->ReminderTextFallback($context, $intent);

        return new AssistantReply(
            text: $fallback,
            draft: ['instruction' => $instruction, 'context' => $context],
            data: ['rental' => $rental->name, 'tenant' => $rental->tenant?->name],
        );
    }

    /**
     * Texto de respaldo si el proveedor de IA no esta disponible: el mensaje
     * igual sirve, solo que menos personalizado.
     *
     * @param  array<string, mixed>  $context
     */
    private function ReminderTextFallback(array $context, string $intent): string
    {
        $tenant = $context['tenant_name'] ?? '—';
        $rental = $context['rental_name'] ?? '—';
        $balance = $this->money((float) $context['total_balance']);

        if ($intent === 'draft_notice') {
            return __('assistant.replies.draft_notice_fallback', [
                'tenant' => $tenant,
                'rental' => $rental,
                'rent' => $this->money((float) $context['current_rent']),
                'date' => $context['next_adjustment_due'] ?? '—',
            ]);
        }

        return __('assistant.replies.draft_reminder_fallback', [
            'tenant' => $tenant,
            'rental' => $rental,
            'balance' => $balance,
        ]);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function propose(string $intent, array $params, int $userId): AssistantReply
    {
        return match ($intent) {
            'propose_generate_invoices' => $this->proposeGenerateInvoices($params, $userId),
            'propose_record_payment' => $this->proposeRecordPayment($params, $userId),
            'create_tenant' => $this->proposeCreateTenant($params, $userId),
            'create_property' => $this->proposeCreateProperty($params, $userId),
            'create_rental' => $this->proposeCreateRental($params, $userId),
            'propose_update_profile' => $this->proposeUpdateProfile($params, $userId),
            'update_tenant' => $this->proposeUpdateTenant($params, $userId),
            'update_property' => $this->proposeUpdateProperty($params, $userId),
            'update_rental' => $this->proposeUpdateRental($params, $userId),
            'record_adjustment' => $this->proposeRecordAdjustment($params, $userId),
            'apply_adjustment' => $this->proposeApplyAdjustment($params, $userId),
            'mark_adjustment_notified' => $this->proposeMarkAdjustmentNotified($params, $userId),
            'recalculate_adjustments' => $this->proposeRecalculateAdjustments($params, $userId),
            'annul_invoice' => $this->proposeAnnulInvoice($params, $userId),
            'generate_document' => $this->proposeGenerateDocument($params, $userId),
            'bill_single_rental' => $this->proposeBillSingleRental($params, $userId),
            'create_delivery_act' => $this->proposeCreateDeliveryAct($params, $userId),
            'update_settings' => $this->proposeUpdateSettings($params, $userId),
            'sync_ipc' => $this->proposeSyncIpc($userId),
            'export_csv' => $this->proposeExportCsv($params, $userId),
            default => AssistantReply::say(__('assistant.unknown')),
        };
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function proposeGenerateInvoices(array $params, int $userId): AssistantReply
    {
        $period = $this->resolvePeriod($params['period'] ?? null);
        $rentals = Rental::query()
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->count();

        if ($rentals === 0) {
            return AssistantReply::say(__('assistant.replies.no_rentals'));
        }

        return new AssistantReply(
            text: __('assistant.replies.propose_invoices', [
                'period' => Carbon::createFromFormat('Y-m-d', $period.'-01')->format('m/Y'),
                'rentals' => $rentals,
            ]),
            action: [
                'type' => 'generate_invoices',
                'params' => ['period' => $period],
                'summary' => __('assistant.actions.generate_invoices_summary', [
                    'period' => Carbon::createFromFormat('Y-m-d', $period.'-01')->format('m/Y'),
                    'rentals' => $rentals,
                ]),
            ],
            suggestions: [],
        );
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function proposeRecordPayment(array $params, int $userId): AssistantReply
    {
        $number = trim((string) ($params['invoice_number'] ?? ''));

        if ($number === '') {
            return AssistantReply::say(__('assistant.replies.need_invoice_number'), [
                __('assistant.suggestions.pending'),
            ]);
        }

        $matches = Invoice::query()
            ->where('user_id', $userId)
            ->where('number', 'like', '%'.$number.'%')
            ->whereIn('status', [InvoiceStatus::EMITIDA, InvoiceStatus::VENCIDA])
            ->with('rental:id,name,tenant_id', 'rental.tenant:id,name')
            ->limit(5)
            ->get();

        if ($matches->isEmpty()) {
            return AssistantReply::say(__('assistant.replies.invoice_not_found', ['number' => $number]), [
                __('assistant.suggestions.overdue'),
                __('assistant.suggestions.pending'),
            ]);
        }

        if ($matches->count() > 1) {
            return new AssistantReply(
                text: __('assistant.replies.invoice_ambiguous', ['number' => $number]),
                data: [
                    'candidates' => $matches->map(fn (Invoice $invoice): array => [
                        'number' => $invoice->number,
                        'rental' => $invoice->rental?->name ?? '—',
                        'tenant' => $invoice->rental?->tenant?->name ?? '—',
                        'balance' => $this->money($invoice->balance()),
                    ])->all(),
                ],
            );
        }

        $invoice = $matches->first();
        $amount = $this->parseMoney($params['amount'] ?? $params['valor'] ?? null) ?? $invoice->balance();

        if ($amount <= 0) {
            return AssistantReply::say(__('assistant.replies.need_amount'), [
                __('assistant.suggestions.pending'),
            ]);
        }

        $method = $this->mapMethod((string) ($params['method'] ?? $params['metodo'] ?? $params['medio'] ?? ''));
        $date = $this->parseDate($params['date'] ?? $params['fecha'] ?? null) ?? today()->toDateString();
        $reference = $this->param($params, ['reference', 'referencia', 'reference_number']);

        return new AssistantReply(
            text: __('assistant.replies.propose_payment', [
                'invoice' => $invoice->number,
                'tenant' => $invoice->rental?->tenant?->name ?? '—',
                'amount' => $this->money($amount),
                'date' => $date,
            ]),
            action: [
                'type' => 'record_payment',
                'params' => [
                    'invoice_id' => (int) $invoice->id,
                    'amount' => $amount,
                    'method' => $method,
                    'date' => $date,
                    'reference' => $reference,
                ],
                'changes' => [
                    $this->changeRow('invoice', __('assistant.fields.invoice'), null, $invoice->number, 'text'),
                    $this->changeRow('balance', __('assistant.fields.balance'), $this->money($invoice->balance()), $this->money(max(0, $invoice->balance() - $amount)), 'money'),
                    $this->changeRow('date', __('assistant.fields.date'), null, $date, 'date'),
                ],
                'editable' => [
                    ['key' => 'amount', 'param' => 'amount', 'label' => __('assistant.fields.amount'), 'kind' => 'number', 'value' => (string) $amount],
                    ['key' => 'method', 'param' => 'method', 'label' => __('assistant.fields.method'), 'kind' => 'select', 'value' => $method, 'options' => [
                        ['value' => 'efectivo', 'label' => __('invoice.methods.efectivo')],
                        ['value' => 'transferencia', 'label' => __('invoice.methods.transferencia')],
                        ['value' => 'consignacion', 'label' => __('invoice.methods.consignacion')],
                    ]],
                    ['key' => 'date', 'param' => 'date', 'label' => __('assistant.fields.date'), 'kind' => 'date', 'value' => $date],
                    ['key' => 'reference', 'param' => 'reference', 'label' => __('assistant.fields.reference'), 'kind' => 'text', 'value' => $reference ?? ''],
                ],
                'summary' => __('assistant.actions.record_payment_summary', [
                    'invoice' => $invoice->number,
                    'amount' => $this->money($amount),
                ]),
            ],
        );
    }

    /**
     * "$500.000", "500000", "1.200.000,50" -> float; null si no es numero.
     * Gemini puede mandar el monto como string con formato de moneda.
     */
    private function parseMoney(mixed $raw): ?float
    {
        if (is_int($raw) || is_float($raw)) {
            return (float) $raw;
        }

        if (! is_string($raw)) {
            return null;
        }

        $clean = preg_replace('/[^\d.,-]/', '', $raw) ?? '';

        if ($clean === '' || $clean === '-') {
            return null;
        }

        if (str_contains($clean, ',')) {
            $clean = str_replace('.', '', $clean);
            $clean = str_replace(',', '.', $clean);
        } elseif (preg_match('/^\d{1,3}\.\d{3}$/', $clean) === 1
            || substr_count($clean, '.') > 1) {
            $clean = str_replace('.', '', $clean);
        }

        return is_numeric($clean) ? (float) $clean : null;
    }

    /**
     * Alta de inquilino: solo el nombre es obligatorio; el resto queda
     * opcional y se puede completar despues en el recurso.
     *
     * @param  array<string, mixed>  $params
     */
    private function proposeCreateTenant(array $params, int $userId): AssistantReply
    {
        $name = $this->param($params, ['name', 'tenant_name']);
        $document = $this->param($params, ['document', 'tenant_document', 'cedula']);
        $phone = $this->param($params, ['phone', 'phone_number', 'tenant_phone', 'telefono', 'celular']);

        $missing = [];

        foreach (['name' => $name, 'document' => $document, 'phone' => $phone] as $field => $value) {
            if ($value === null) {
                $missing[] = $field;
            }
        }

        if ($missing !== []) {
            return $this->startCreateForm('tenant', $params, $userId);
        }

        $exists = Tenant::query()
            ->where('user_id', $userId)
            ->where('name', 'like', '%'.$name.'%')
            ->exists();

        if ($exists) {
            return AssistantReply::say(__('assistant.replies.tenant_exists', ['name' => $name]), [
                __('assistant.suggestions.tenants'),
                __('assistant.suggestions.portfolio'),
            ]);
        }

        $this->forms->clearCreate($userId);

        return new AssistantReply(
            text: __('assistant.replies.propose_create_tenant', ['name' => $name]),
            action: [
                'type' => 'create_tenant',
                'params' => [
                    'name' => $name,
                    'phone_number' => $phone,
                    'document' => $document,
                    'email' => $this->param($params, ['email', 'correo']),
                ],
                'summary' => __('assistant.actions.create_tenant_summary', ['name' => $name]),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function proposeCreateProperty(array $params, int $userId): AssistantReply
    {
        $name = $this->param($params, ['name', 'property_name']);
        $address = $this->param($params, ['address', 'property_address', 'direccion']);

        $missing = [];

        foreach (['name' => $name, 'address' => $address] as $field => $value) {
            if ($value === null) {
                $missing[] = $field;
            }
        }

        if ($missing !== []) {
            return $this->startCreateForm('property', $params, $userId);
        }

        $exists = Property::query()
            ->where('user_id', $userId)
            ->where('name', 'like', '%'.$name.'%')
            ->exists();

        if ($exists) {
            return AssistantReply::say(__('assistant.replies.property_exists', ['name' => $name]), [
                __('assistant.suggestions.properties'),
                __('assistant.suggestions.portfolio'),
            ]);
        }

        $this->forms->clearCreate($userId);

        return new AssistantReply(
            text: __('assistant.replies.propose_create_property', ['name' => $name]),
            action: [
                'type' => 'create_property',
                'params' => [
                    'name' => $name,
                    'address' => $address,
                ],
                'summary' => __('assistant.actions.create_property_summary', ['name' => $name]),
            ],
        );
    }

    /**
     * Cambio de datos personales del arrendador. Solo los campos que el
     * usuario menciono viajan en la accion: es una actualizacion parcial.
     * La contrasena nunca pasa por aqui (el prompt lo prohibe).
     *
     * @param  array<string, mixed>  $params
     */
    private function proposeUpdateProfile(array $params, int $userId): AssistantReply
    {
        $changes = array_filter([
            'name' => $this->param($params, ['name', 'nombre']),
            'email' => $this->param($params, ['email', 'correo']),
            'document_type' => $this->param($params, ['document_type', 'tipo_documento', 'tipo de documento']),
            'document' => $this->param($params, ['document', 'documento', 'cedula']),
            'phone_number' => $this->param($params, ['phone_number', 'phone', 'telefono', 'celular']),
        ], fn (?string $value): bool => $value !== null);

        $documentType = strtoupper((string) ($changes['document_type'] ?? ''));

        if ($documentType !== '' && ! in_array($documentType, ['CC', 'TI', 'CE', 'PAS', 'NIT'], true)) {
            unset($changes['document_type']);
        } elseif ($documentType !== '') {
            $changes['document_type'] = $documentType;
        }

        if ($changes === []) {
            return AssistantReply::say(__('assistant.replies.need_profile_changes'), [
                __('assistant.suggestions.update_profile'),
            ]);
        }

        $labels = implode(', ', array_map(
            fn (string $field): string => __('assistant.fields.profile_'.$field),
            array_keys($changes),
        ));

        return new AssistantReply(
            text: __('assistant.replies.propose_update_profile', ['fields' => $labels]),
            action: [
                'type' => 'update_profile',
                'params' => $changes,
                'summary' => __('assistant.actions.update_profile_summary'),
            ],
        );
    }

    /**
     * Arriendo nuevo. Si el inquilino o la propiedad no existen, la accion
     * los crea con el nombre dado: el usuario confirma una sola vez y el
     * resultado es el arriendo completo.
     *
     * @param  array<string, mixed>  $params
     */
    private function proposeCreateRental(array $params, int $userId): AssistantReply
    {
        $tenantName = $this->param($params, ['tenant_name', 'tenant', 'inquilino']) ?? '';
        $propertyName = $this->param($params, ['property_name', 'property', 'propiedad']) ?? '';
        $amountRaw = $params['amount'] ?? $params['monthly_amount'] ?? $params['canon'] ?? null;
        $amount = is_numeric($amountRaw) ? round((float) $amountRaw, 2) : null;

        $missing = [];

        if ($tenantName === '') {
            $missing[] = 'tenant';
        }

        if ($propertyName === '') {
            $missing[] = 'property';
        }

        if ($amount === null || $amount <= 0) {
            $missing[] = 'amount';
        }

        // Si el inquilino no existe habra que crearlo, y el esquema exige
        // documento y telefono: se piden junto con los demas datos. Si el
        // mismo batch ya lo crea (o la propiedad), esos datos llegan por la
        // otra peticion y no hace falta pedirlos aqui.
        $pending = is_array($params['__pending'] ?? null) ? $params['__pending'] : [];
        $pendingTenants = array_map('mb_strtolower', $pending['tenants'] ?? []);
        $pendingProperties = array_map('mb_strtolower', $pending['properties'] ?? []);

        $tenantExists = $tenantName !== '' && $this->findEntity(Tenant::class, $userId, $tenantName) !== null;
        $propertyExists = $propertyName !== '' && $this->findEntity(Property::class, $userId, $propertyName) !== null;
        $tenantPending = $this->namePending($tenantName, $pendingTenants);
        $propertyPending = $this->namePending($propertyName, $pendingProperties);

        if ($tenantName !== '' && ! $tenantExists && ! $tenantPending) {
            foreach (['document' => ['tenant_document', 'document', 'cedula'], 'phone' => ['tenant_phone', 'phone', 'phone_number', 'telefono']] as $field => $aliases) {
                if ($this->param($params, $aliases) === null) {
                    $missing[] = $field;
                }
            }
        }

        // Igual con la propiedad: el esquema exige direccion.
        if ($propertyName !== '' && ! $propertyExists && ! $propertyPending
            && $this->param($params, ['property_address', 'address', 'direccion']) === null) {
            $missing[] = 'address';
        }

        if ($missing !== []) {
            return $this->startCreateForm('rental', $params, $userId);
        }

        $monthsRaw = $params['months'] ?? $params['total_months'] ?? $params['duracion'] ?? null;
        $months = is_numeric($monthsRaw) ? max(1, min(120, (int) $monthsRaw)) : 12;

        $cadenceRaw = strtoupper((string) ($params['cadence'] ?? $params['billing_cadence'] ?? $params['frecuencia'] ?? ''));
        $cadence = in_array($cadenceRaw, ['MENSUAL', 'QUINCENAL', 'ANTICIPO'], true)
            ? $cadenceRaw
            : 'MENSUAL';

        $startDate = $this->parseDate($params['start_date'] ?? $params['fecha_inicio'] ?? null) ?? today()->toDateString();

        $tenant = $this->findEntity(Tenant::class, $userId, $tenantName);
        $property = $this->findEntity(Property::class, $userId, $propertyName);

        $this->forms->clearCreate($userId);

        return new AssistantReply(
            text: __('assistant.replies.propose_create_rental', [
                'tenant' => $tenantName,
                'property' => $propertyName,
                'amount' => $this->money($amount),
                'months' => $months,
            ]),
            action: [
                'type' => 'create_rental',
                'params' => [
                    'tenant_name' => $tenantName,
                    'tenant_id' => $tenant?->id,
                    'tenant_document' => $this->param($params, ['tenant_document', 'document', 'cedula']),
                    'tenant_phone' => $this->param($params, ['tenant_phone', 'phone', 'phone_number', 'telefono']),
                    'property_name' => $propertyName,
                    'property_id' => $property?->id,
                    'property_address' => $this->param($params, ['property_address', 'address', 'direccion']),
                    'monthly_amount' => $amount,
                    'total_months' => $months,
                    'start_date' => $startDate,
                    'billing_cadence' => $cadence,
                ],
                'summary' => __('assistant.actions.create_rental_summary', [
                    'tenant' => $tenantName,
                    'property' => $propertyName,
                    'amount' => $this->money($amount),
                ]),
            ],
            suggestions: [],
        );
    }

    /**
     * Busca una entidad del usuario por nombre difuso; null si no existe.
     */
    private function findEntity(string $model, int $userId, string $name): ?object
    {
        $needle = Str::lower($name);

        $matches = $model::query()
            ->where('user_id', $userId)
            ->get()
            ->filter(fn ($entity): bool => Str::contains(Str::lower((string) $entity->name), $needle))
            ->values();

        // Match exacto antes que substring: "Maria" no debe traer a "Maria
        // Fernanda" si existe una "Maria" exacta.
        return $matches->first(fn ($entity): bool => Str::lower((string) $entity->name) === $needle)
            ?? $matches->first();
    }

    private function cleanParam(mixed $value): ?string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        return $value === '' ? null : $value;
    }

    /**
     * Primer valor no vacio entre varios alias: la IA puede emitir
     * "phone_number", "phone", "tenant_phone", etc. para el mismo campo.
     *
     * @param  array<string, mixed>  $params
     * @param  list<string>  $aliases
     */
    private function param(array $params, array $aliases): ?string
    {
        foreach ($aliases as $alias) {
            $value = $this->cleanParam($params[$alias] ?? null);

            if ($value !== null) {
                return $value;
            }
        }

        return null;
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

    /**
     * @param  array<string, mixed>  $params
     */
    private function navigate(array $params, int $userId): AssistantReply
    {
        $target = (string) ($params['target'] ?? '');
        $filter = strtoupper(trim((string) ($params['filter'] ?? '')));
        $search = trim((string) ($params['search'] ?? $params['query'] ?? ''));

        $urls = [
            'invoices' => InvoiceResource::getUrl('index'),
            'rentals' => RentalResource::getUrl('index'),
            'tenants' => TenantResource::getUrl('index'),
            'properties' => PropertyResource::getUrl('index'),
            'delivery_acts' => DeliveryActResource::getUrl('index'),
            'adjustments' => RentAdjustmentResource::getUrl('index'),
            'finances' => FinanzasPage::getUrl(),
            'documents' => DocumentsPage::getUrl(),
            'profile' => EditProfile::getUrl(),
        ];

        if (! isset($urls[$target])) {
            return AssistantReply::say(__('assistant.replies.navigate_unknown'), [
                __('assistant.suggestions.portfolio'),
            ]);
        }

        // Filtro soportado: la tabla de facturas acepta ?af[status]=X y lo
        // aplica al montar (ver ListInvoices::mount).
        $invoiceStatuses = ['EMITIDA', 'VENCIDA', 'PAGADA', 'ANULADA'];

        if ($target === 'invoices' && in_array($filter, $invoiceStatuses, true)) {
            return new AssistantReply(
                text: __('assistant.replies.navigate_filtered', [
                    'section' => __('assistant.sections.'.$target),
                    'filter' => $this->filterLabel($filter),
                ]),
                redirect: $urls[$target].'?'.http_build_query(array_filter([
                    'af' => ['status' => Str::lower($filter)],
                    'tableSearch' => $search !== '' ? $search : null,
                ])),
            );
        }

        // Busqueda sobre una seccion con tabla: todas las list pages
        // exponen ?tableSearch= via el trait AssistantTableSearch.
        if ($search !== '' && $this->targetHasTableSearch($target)) {
            return new AssistantReply(
                text: __('assistant.replies.navigate_search', [
                    'section' => __('assistant.sections.'.$target),
                    'term' => $search,
                ]),
                redirect: $urls[$target].'?'.http_build_query(['tableSearch' => $search]),
            );
        }

        // Busqueda pedida pero la seccion no tiene tabla (finanzas,
        // documentos, perfil): se ejecuta la consulta filtrada en el chat.
        if ($search !== '') {
            $listIntent = match ($target) {
                'invoices' => 'list_invoices',
                'rentals' => 'list_rentals',
                'tenants' => 'list_tenants',
                'properties' => 'list_properties',
                'delivery_acts' => 'list_delivery_acts',
                'adjustments' => 'list_adjustments',
                default => null,
            };

            if ($listIntent !== null) {
                $dataReply = $this->invoke($listIntent, ['search' => $search], $userId);

                return new AssistantReply(
                    text: __('assistant.replies.navigate_search_chat', [
                        'section' => __('assistant.sections.'.$target),
                        'term' => $search,
                    ])."\n\n".$dataReply->text,
                    data: $dataReply->data,
                    redirect: $urls[$target],
                    suggestions: $dataReply->suggestions,
                );
            }
        }

        // Filtro pedido pero no aplicable en esa seccion: se explica y se
        // muestran los datos filtrados aqui en el chat.
        if ($filter !== '') {
            $listIntent = match ($target) {
                'invoices' => $filter === 'VENCIDA' ? 'overdue_invoices' : 'list_invoices',
                'rentals' => 'list_rentals',
                'tenants' => 'list_tenants',
                'properties' => 'list_properties',
                'delivery_acts' => 'list_delivery_acts',
                'adjustments' => 'next_adjustments',
                'finances' => 'financial_analysis',
                'documents' => null,
                default => null,
            };

            $dataReply = $listIntent !== null
                ? $this->invoke($listIntent, [], $userId)
                : AssistantReply::say(__('assistant.replies.navigate_no_data', [
                    'section' => __('assistant.sections.'.$target),
                ]));

            return new AssistantReply(
                text: __('assistant.replies.navigate_no_filter', [
                    'section' => __('assistant.sections.'.$target),
                    'filter' => $this->filterLabel($filter),
                ])."\n\n".$dataReply->text,
                data: $dataReply->data,
                redirect: $urls[$target],
                suggestions: $dataReply->suggestions,
            );
        }

        return new AssistantReply(
            text: __('assistant.replies.navigate', ['section' => __('assistant.sections.'.$target)]),
            redirect: $urls[$target],
        );
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /**
     * Facturas abiertas del usuario con alquiler e inquilino precargados.
     */
    private function openInvoices(int $userId)
    {
        return Invoice::query()
            ->where('user_id', $userId)
            ->whereIn('status', [InvoiceStatus::EMITIDA, InvoiceStatus::VENCIDA])
            ->with('rental:id,name,tenant_id', 'rental.tenant:id,name')
            ->orderBy('due_at')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function tenantFilter(array $params): ?string
    {
        $name = trim((string) ($params['tenant'] ?? $params['rental_name'] ?? ''));

        return $name === '' ? null : Str::lower($name);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function findRental(array $params, int $userId): ?Rental
    {
        // Eleccion desde la lista de candidatos: manda sobre el nombre.
        $byRef = $this->entityFromRef(Rental::class, $userId);

        if ($byRef !== null) {
            return $byRef;
        }

        $name = trim((string) ($this->param($params, [
            'rental_name', 'tenant', 'name', 'entity', 'arriendo',
            'alquiler', 'contrato', 'inquilino', 'rental', 'property_name',
        ]) ?? ''));

        if ($name === '') {
            return null;
        }

        $needle = Str::lower($name);

        // Se cargan TODOS los arriendos (activos e inactivos): documentos,
        // actas y estados de cuenta de periodos pasados son validos sobre
        // arriendos finalizados. La preferencia por activos se aplica al
        // elegir entre coincidencias, no al buscar.
        $all = Rental::query()
            ->where('user_id', $userId)
            ->with('tenant:id,name')
            ->get()
            ->filter(fn (Rental $rental): bool => Str::contains(Str::lower($rental->name), $needle)
                || Str::contains(Str::lower((string) $rental->tenant?->name), $needle))
            ->values();

        $matches = $all->where('is_active', true)->values();
        if ($matches->isEmpty()) {
            $matches = $all;
        }

        // Match exacto primero: "apto 302" no debe resolver al "apto 3020"
        // solo porque aparecio antes en la tabla. Solo gana si es UNICO:
        // dos arriendos de inquilinos homonimos no se desempatan solos.
        $exacts = $matches->filter(fn (Rental $rental): bool => Str::lower($rental->name) === $needle
            || Str::lower((string) $rental->tenant?->name) === $needle)->values();

        if ($exacts->count() === 1) {
            return $exacts->first();
        }

        if ($exacts->count() > 1) {
            $matches = $exacts;
        }

        if ($matches->isNotEmpty()) {
            // Varias coincidencias: si todas son del mismo inquilino, el
            // usuario probablemente hablo del inquilino — se toma el
            // arriendo mas reciente en vez de preguntar de nuevo.
            $tenantIds = $matches->pluck('tenant_id')->unique();

            if ($tenantIds->count() === 1) {
                return $matches->sortByDesc('id')->first();
            }

            return $matches->first();
        }

        // Ultimo recurso: el nombre puede ser el del INQUILINO y su
        // arriendo tener un nombre que no lo contiene ("el de Camila").
        // Se filtra en PHP como el resto del metodo: `ilike` no existe
        // en SQLite/MySQL y rompia la consulta entera.
        $tenant = Tenant::query()
            ->where('user_id', $userId)
            ->get()
            ->first(fn (Tenant $t): bool => Str::contains(Str::lower($t->name), Str::lower($name)));

        if ($tenant !== null) {
            $tenantRentals = Rental::query()
                ->where('user_id', $userId)
                ->where('tenant_id', $tenant->id)
                ->orderByDesc('is_active')
                ->orderByDesc('id')
                ->get();

            return $tenantRentals->firstWhere('is_active', true) ?? $tenantRentals->first();
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function resolvePeriod(mixed $raw): string
    {
        $value = strtolower(trim((string) ($raw ?? '')));

        if ($value === 'last_month' || $value === 'mes_pasado' || $value === 'anterior') {
            return today()->subMonthNoOverflow()->format('Y-m');
        }

        if (preg_match('/^\d{4}-\d{2}$/', $value) === 1) {
            return $value;
        }

        if (preg_match('/^\d{4}$/', $value) === 1) {
            return $value.'-'.today()->format('m');
        }

        return today()->format('Y-m');
    }

    /**
     * "2027-01", "2027", "enero 2027", "01/2027" -> Carbon del primer dia
     * del mes; null si no se puede interpretar (mejor preguntar que
     * facturar el periodo equivocado).
     */
    private function resolveBillingPeriod(mixed $raw): ?Carbon
    {
        $value = trim((string) ($raw ?? ''));

        if ($value === '') {
            return today()->startOfMonth();
        }

        foreach (['Y-m-d', 'Y-m', 'm/Y', 'Y'] as $format) {
            try {
                if (! Carbon::hasFormat($value, $format)) {
                    continue;
                }

                return Carbon::createFromFormat($format, $value)->startOfMonth();
            } catch (\Throwable) {
                continue;
            }
        }

        // "enero 2027" y afines los resuelve Carbon::parse.
        try {
            return Carbon::parse($value)->startOfMonth();
        } catch (\Throwable) {
            return null;
        }
    }

    private function mapMethod(string $raw): string
    {
        $value = Str::lower($raw);

        return match (true) {
            Str::contains($value, 'transfer') => 'transferencia',
            Str::contains($value, ['nequi', 'daviplata', 'billetera', 'bre-b', 'breb', 'digital']) => 'transferencia',
            Str::contains($value, 'consigna') => 'consignacion',
            default => 'efectivo',
        };
    }

    private function parseDate(mixed $raw): ?string
    {
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        try {
            return Carbon::parse($raw)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Etiqueta legible de un filtro de navegacion (EMITIDA -> "emitidas").
     */
    private function filterLabel(string $filter): string
    {
        $key = 'assistant.filters.'.Str::lower($filter);
        $label = __($key);

        return $label === $key ? $filter : $label;
    }

    private function money(float $amount): string
    {
        return '$'.number_format($amount, 0, ',', '.');
    }

    // ---------------------------------------------------------------
    // Vias libres: consulta SQL y respuesta general
    // ---------------------------------------------------------------

    /**
     * Consulta de solo lectura escrita por el modelo (intent query_data).
     *
     * Es la escotilla de escape para CUALQUIER pregunta de datos que las
     * herramientas especificas no cubran: cruces raros, comparaciones,
     * periodos personalizados. El SQL se valida antes de ejecutar
     * (verifyFreeSql) y el resultado se pinta como tabla normal; el texto
     * lo redacta la IA via draft con los datos reales.
     *
     * @param  array<string, mixed>  $params
     */
    private function freeQuery(array $params, int $userId): AssistantReply
    {
        $lookup = $this->lookupData($params, $userId);

        if (isset($lookup['error'])) {
            $this->lastQueryError = $lookup['error'];

            return AssistantReply::say(__('assistant.replies.query_failed'));
        }

        $clean = $lookup['rows'];

        if ($clean === []) {
            $question = trim((string) ($params['question'] ?? ''));

            return AssistantReply::say($question !== ''
                ? __('assistant.replies.query_empty_scoped', ['question' => $question])
                : __('assistant.replies.query_empty'), [
                    __('assistant.suggestions.portfolio'),
                ]);
        }

        return new AssistantReply(
            text: __('assistant.replies.query_results', ['count' => count($clean)]),
            data: ['results' => $clean],
            suggestions: $this->defaultSuggestions(),
            draft: [
                'instruction' => 'Resume en 2-4 frases, en el idioma del usuario, lo que muestran estos resultados de una consulta a la base de datos del negocio. Menciona los datos clave (nombres, montos, fechas) tal cual vienen; no inventes cifras ni conclusiones que los datos no soporten. Si la lista es larga, destaca lo mas relevante.',
                'context' => ['pregunta' => $params['question'] ?? '', 'resultados' => array_slice($clean, 0, 30)],
            ],
        );
    }

    /**
     * Guia de uso de la app (intent app_guide): pasos ordenados con
     * acciones clicables (navegar a la seccion o lanzar la accion por
     * chat). El tema lo elige el modelo segun la pregunta; sin tema
     * valido cae al onboarding completo.
     *
     * @param  array<string, mixed>  $params
     */
    private function appGuide(array $params): AssistantReply
    {
        $topic = strtolower(trim((string) ($params['topic'] ?? '')));
        $question = (string) ($params['_question'] ?? '');

        // El modelo a veces devuelve 'start' o nada aunque la pregunta
        // sea de un flujo concreto ("registrar propiedades e
        // inquilinos"): se infiere el tema por palabras clave para no
        // responder siempre la onboarding completa.
        if ($topic === '' || $topic === 'start' || ! in_array($topic, AppGuide::TOPICS, true)) {
            $topic = ($question !== '' ? $this->guide->topicForQuestion($question) : null) ?? 'start';
        }

        $guide = $this->guide->for($topic);

        if ($guide === null) {
            return AssistantReply::say(__('assistant.unknown'), $this->defaultSuggestions());
        }

        return new AssistantReply(
            text: $guide['intro'] !== '' ? $guide['intro'] : $guide['title'],
            data: ['guide' => $guide],
            suggestions: [
                __('assistant.suggestions.portfolio'),
                __('assistant.suggestions.overdue'),
            ],
        );
    }

    /**
     * Respuesta en texto libre redactada por el propio modelo en la
     * clasificacion (intent general_answer): preguntas conceptuales,
     * de uso de la app o conversacion del dominio que no piden datos.
     *
     * @param  array<string, mixed>  $params
     */
    private function generalAnswer(array $params): AssistantReply
    {
        $answer = trim((string) ($params['answer'] ?? ''));

        if ($answer === '') {
            return AssistantReply::say(__('assistant.unknown'), $this->defaultSuggestions());
        }

        return AssistantReply::say(mb_substr($answer, 0, 2000), $this->defaultSuggestions());
    }

    /**
     * Ejecuta una consulta de apoyo del modelo (intent lookup_data):
     * el SQL lo escribe la IA para resolver una referencia que el
     * contexto del prompt no cubre ("la factura mas antigua de X").
     * Devuelve las filas crudas para que el modelo razone con ellas;
     * NUNCA se muestran al usuario directamente.
     *
     * @param  array<string, mixed>  $params
     * @return array{rows?: list<array<string, mixed>>, error?: string}
     */
    public function lookupData(array $params, int $userId): array
    {
        // Modo exploracion de esquema: el modelo pide la ficha completa
        // de una tabla (columnas, enums, relaciones, volumen) cuando
        // duda de su estructura a mitad del razonamiento.
        $table = strtolower(trim((string) ($params['table'] ?? '')));

        if ($table !== '') {
            $description = $this->schema->describe($table, $userId);

            if ($description === null) {
                return ['error' => 'La tabla "'.$table.'" no existe en el esquema del negocio. Tablas validas: '.implode(', ', DatabaseSchemaDigest::domainTables())];
            }

            return ['rows' => [$description]];
        }

        $sql = trim((string) ($params['sql'] ?? $params['query'] ?? ''));
        $sql = rtrim($sql, "; \t\n\r");

        if ($sql === '') {
            return ['error' => 'Debes enviar params.sql (una consulta SELECT) o params.table (la ficha de una tabla).'];
        }

        $rejection = $this->validateFreeSql($sql, $userId);

        if ($rejection !== null) {
            return ['error' => $rejection];
        }

        try {
            // Subconsulta + LIMIT externo: acota memoria sin importar si
            // el SQL del modelo ya traia LIMIT u ORDER BY.
            $rows = DB::select('SELECT * FROM ('.$sql.') AS assistant_rows LIMIT '.self::FREE_QUERY_MAX_ROWS);
        } catch (\Throwable $exception) {
            return ['error' => 'SQL error: '.$exception->getMessage()];
        }

        $clean = [];

        foreach ($rows as $row) {
            $assoc = (array) $row;

            // Columnas sensibles: nunca salen del servidor aunque el
            // modelo las haya pedido por descuido.
            unset($assoc['password'], $assoc['remember_token'], $assoc['api_token'], $assoc['secret']);

            $clean[] = $assoc;
        }

        return ['rows' => $clean];
    }

    /**
     * Validacion del SQL libre. Devuelve null si es ejecutable o el
     * motivo del rechazo (alimenta el reintento del modelo).
     */
    private function validateFreeSql(string $sql, int $userId): ?string
    {
        if ($sql === '') {
            return 'La consulta esta vacia.';
        }

        if (str_contains($sql, ';')) {
            return 'Solo se permite UNA sentencia SQL; quita los punto y coma.';
        }

        if (! preg_match('/^\s*(select|with)\b/i', $sql)) {
            return 'Solo se permiten consultas SELECT de lectura.';
        }

        // Palabras que convierten la consulta en escritura o en acceso al
        // sistema de archivos/servidor. \b evita falsos positivos con
        // columnas como updated_at o nombres compuestos.
        if (preg_match('/\b(insert|update|delete|drop|alter|create|truncate|grant|revoke|exec|execute|replace|merge|call|do|pragma|attach|detach|vacuum|reindex|checkpoint|listen|notify|unlisten|discard|prepare|declare|fetch|move|copy|lock|unlock|set|show|use|into|outfile|dumpfile|load_extension)\b/i', $sql)) {
            return 'La consulta contiene operaciones no permitidas (escritura, DDL o acceso al sistema).';
        }

        $tables = $this->extractSqlTables($sql);

        if ($tables === []) {
            return 'No se encontro ninguna tabla en la consulta.';
        }

        $blocked = array_intersect($tables, self::FREE_QUERY_BLOCKED_TABLES);

        if ($blocked !== []) {
            return 'Las tablas '.implode(', ', $blocked).' no son consultables.';
        }

        // Scope por usuario: si toca datos del arrendador debe filtrar
        // user_id = {$userId}; las tablas globales (IPC, geo, ajustes)
        // no lo llevan. `users` es la tabla del propio arrendador: se
        // exige id = userId.
        $needsUserScope = array_intersect($tables, self::FREE_QUERY_USER_TABLES) !== []
            || in_array('users', $tables, true);

        if ($needsUserScope && ! str_contains(strtolower($sql), 'user_id') && ! str_contains(strtolower($sql), 'users.id')) {
            return 'Toda consulta a datos del usuario debe filtrar por user_id = '.$userId.' (o users.id = '.$userId.' para la tabla users).';
        }

        return null;
    }

    /**
     * Tablas referenciadas en FROM/JOIN del SQL (minusculas).
     *
     * @return list<string>
     */
    private function extractSqlTables(string $sql): array
    {
        preg_match_all('/\b(?:from|join)\s+([a-zA-Z_][a-zA-Z0-9_]*)/i', $sql, $matches);

        return array_values(array_unique(array_map('strtolower', $matches[1])));
    }

    /**
     * Tablas de infraestructura/secretos: jamas consultables.
     *
     * @var list<string>
     */
    private const FREE_QUERY_BLOCKED_TABLES = [
        'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs',
        'migrations', 'password_reset_tokens', 'sessions', 'personal_access_tokens',
    ];

    /**
     * Tablas con datos del arrendador: el SQL debe filtrar user_id.
     *
     * @var list<string>
     */
    private const FREE_QUERY_USER_TABLES = [
        'tenants', 'properties', 'spaces', 'master_inventory_items',
        'rentals', 'invoices', 'invoice_sequences', 'payments',
        'rent_adjustments', 'delivery_acts', 'act_items',
        'document_accesses', 'assistant_chats', 'assistant_messages',
    ];

    /** Filas maximas que devuelve una consulta libre. */
    private const FREE_QUERY_MAX_ROWS = 200;

    // ---------------------------------------------------------------
    // Formularios guiados (wizard multi-turno)
    // ---------------------------------------------------------------

    /**
     * Punto de entrada cuando hay un wizard activo en sesion. Lo llama el
     * adapter con la pregunta CRUDA (no params de Gemini): el handler del
     * formulario parsea el texto de forma determinista, porque respuestas
     * como "entrega" o "sí" no deben pasar por un clasificador.
     */
    public function handleFormField(string $question, int $userId): AssistantReply
    {
        $session = $this->forms->get($userId);

        if ($session === null) {
            return AssistantReply::say(__('assistant.replies.form_none'));
        }

        return match ($session['form']) {
            'delivery_act' => $this->deliveryActStep($question, $session['data'], $userId),
            'update_clarify' => $this->updateClarifyStep($question, $session['data'], $userId),
            'update_value' => $this->updateValueStep($question, $session['data'], $userId),
            default => $this->cancelActiveForm($userId),
        };
    }

    /**
     * Intents form_field/cancel_form que llegan ya clasificados (Gemini).
     *
     * @param  array<string, mixed>  $params
     */
    private function handleForm(string $intent, array $params, int $userId): AssistantReply
    {
        return match ($intent) {
            'cancel_form' => $this->cancelActiveForm($userId),
            'form_field' => $this->formFieldParams($params, $userId),
            default => AssistantReply::say(__('assistant.unknown')),
        };
    }

    /**
     * Params ya extraidos por Gemini: se fusionan al borrador y se continua
     * el wizard. Si no hay wizard activo se ignora.
     *
     * @param  array<string, mixeD>  $params
     */
    private function formFieldParams(array $params, int $userId): AssistantReply
    {
        $session = $this->forms->get($userId);

        if ($session === null) {
            return AssistantReply::say(__('assistant.replies.form_none'));
        }

        if ($session['form'] === 'delivery_act') {
            return $this->deliveryActStep(null, $session['data'], $userId, $params);
        }

        return $this->cancelActiveForm($userId);
    }

    private function cancelActiveForm(int $userId): AssistantReply
    {
        $this->forms->clear($userId);
        $this->forms->clearCreate($userId);

        return AssistantReply::say(__('assistant.replies.form_cancelled'), [
            __('assistant.suggestions.portfolio'),
        ]);
    }

    /**
     * Inicia el wizard del acta de entrega. Si ya hay datos (rental_name en
     * la peticion inicial) se prefillan; si no, el primer paso pide el
     * arriendo.
     *
     * @param  array<string, mixed>  $params
     */
    private function proposeCreateDeliveryAct(array $params, int $userId): AssistantReply
    {
        // Un wizard de acta ya en curso se continua, no se reinicia.
        $existing = $this->forms->get($userId);

        if ($existing !== null && $existing['form'] === 'delivery_act') {
            return $this->deliveryActStep(null, $existing['data'], $userId, $params);
        }

        $data = [];

        $rentalName = $this->param($params, ['rental_name', 'rental', 'arriendo', 'tenant_name', 'inquilino']);

        if ($rentalName !== null) {
            $rental = $this->findRental(['rental_name' => $rentalName], $userId);

            if ($rental !== null) {
                $data['rental_id'] = (int) $rental->id;
                $data['rental_name'] = $rental->name;
                $data['tenant_name'] = (string) ($rental->tenant?->name ?? '');
                $data['landlord_name'] = (string) (User::query()->whereKey($userId)->value('name') ?? '');
            } else {
                $data['rental_name_hint'] = $rentalName;
            }
        }

        $this->forms->put($userId, 'delivery_act', $data);

        return $this->deliveryActStep(null, $data, $userId);
    }

    /**
     * Un turno del wizard del acta. $question es el texto crudo del usuario
     * (camino local); $params llega cuando Gemini ya extrajo campos.
     *
     * El orden de pasos: rental -> type -> date -> parties -> meters ->
     * items -> extra -> review. Cada turno fusiona lo que el usuario dio,
     * valida el paso actual y o pregunta el siguiente faltante o, si ya
     * esta todo, propone la accion final editable.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>|null  $extracted
     */
    private function deliveryActStep(?string $question, array $data, int $userId, ?array $extracted = null): AssistantReply
    {
        $question = $question === null ? '' : trim($question);

        if ($this->isCancelCommand($question)) {
            return $this->cancelActiveForm($userId);
        }

        // Pregunta nueva mientras el wizard espera un dato: no se traga el
        // texto como si fuera respuesta del formulario; se avisa que hay
        // un acta en curso y se repite el campo que falta.
        if ($question !== '' && $this->looksLikeNewTopic($question)) {
            $current = $this->actPendingForm($data, $this->wizardCurrentStep($data));

            return new AssistantReply(
                text: __('assistant.act_wizard.new_topic', ['prompt' => $current['prompt']]),
                pendingForm: $current,
            );
        }

        $data = $this->extractActFields($question, $data, $userId, $extracted);

        // Paso 1: arriendo. Si el hint no resolvio, se busca de nuevo.
        if (empty($data['rental_id'])) {
            $rentalName = trim((string) ($data['rental_name_hint'] ?? $data['rental_name'] ?? $question));

            if ($rentalName !== '') {
                // "cualquiera"/"una aleatoria": se toma un arriendo al
                // azar y el acta se arma sobre el; el usuario lo ve en
                // la revision final antes de confirmar.
                $resolved = $this->isRandomPick($rentalName)
                    ? ['entity' => Rental::query()->where('user_id', $userId)->where('is_active', true)->inRandomOrder()->first(), 'candidates' => []]
                    : $this->resolveRentalCandidates($rentalName, $userId);

                if ($resolved['entity'] !== null) {
                    $rental = $resolved['entity'];
                    $data['rental_id'] = (int) $rental->id;
                    $data['rental_name'] = $rental->name;
                    $data['tenant_name'] = (string) ($rental->tenant?->name ?? '');
                    $data['landlord_name'] = (string) (User::query()->whereKey($userId)->value('name') ?? '');
                    unset($data['rental_name_hint']);
                } else {
                    $this->forms->put($userId, 'delivery_act', $data);

                    if ($resolved['candidates'] !== []) {
                        return new AssistantReply(
                            text: __('assistant.act_wizard.rental_ambiguous'),
                            data: ['candidates' => $resolved['candidates']],
                            pendingForm: $this->actPendingForm($data, 'rental'),
                        );
                    }
                }
            }

            if (empty($data['rental_id'])) {
                $this->forms->put($userId, 'delivery_act', $data);

                return new AssistantReply(
                    text: $question === ''
                        ? __('assistant.act_wizard.ask_rental_start')
                        : __('assistant.act_wizard.ask_rental_again'),
                    pendingForm: $this->actPendingForm($data, 'rental'),
                );
            }
        }

        // Paso 2: tipo de acta.
        if (empty($data['type'])) {
            $type = $this->extractActType($question);

            if ($type !== null) {
                $data['type'] = $type;
            } else {
                $this->forms->put($userId, 'delivery_act', $data);

                return new AssistantReply(
                    text: __('assistant.act_wizard.ask_type'),
                    pendingForm: $this->actPendingForm($data, 'type'),
                );
            }
        }

        // Paso 3: fecha del acta.
        if (empty($data['occurred_at'])) {
            $date = $this->parseDate($question) ?? $this->parseDate($extracted['occurred_at'] ?? null);

            if ($date !== null) {
                $data['occurred_at'] = $date;
            } else {
                $data['occurred_at'] = today()->toDateString();
                $this->forms->merge($userId, ['occurred_at' => $data['occurred_at']]);
            }
        }

        // Paso 4: partes (nombres/documentos). Pre-llenados del arriendo;
        // solo se confirman o corrigen.
        if (empty($data['landlord_name']) || empty($data['tenant_name'])) {
            $rental = Rental::query()->with('tenant:id,name')->find($data['rental_id']);

            if ($rental !== null) {
                $data['landlord_name'] = $data['landlord_name'] ?? (string) (User::query()->whereKey($userId)->value('name') ?? '');
                $data['tenant_name'] = $data['tenant_name'] ?? (string) ($rental->tenant?->name ?? '');
            }
        }

        // Paso 5: medidores (opcionales: "siguiente"/"listo"/"omitir" avanza).
        if (! isset($data['meters_done'])) {
            if ($this->isSkipCommand($question)) {
                $data['meters_done'] = true;
            } else {
                $this->extractMeters($question, $data);

                // Si el usuario dio al menos una lectura o pide seguir, se da
                // por completada la seccion; si no, se pregunta una vez y el
                // siguiente turno (con cualquier respuesta) la cierra.
                $hasReadings = ! empty($data['water_reading']) || ! empty($data['energy_reading']) || ! empty($data['gas_reading']);

                if ($hasReadings || $this->isAffirmative($question)) {
                    $data['meters_done'] = true;
                } else {
                    $this->forms->put($userId, 'delivery_act', $data);

                    return new AssistantReply(
                        text: __('assistant.act_wizard.ask_meters'),
                        pendingForm: $this->actPendingForm($data, 'meters'),
                    );
                }
            }
        }

        // Paso 6: inventario. Cada turno puede agregar un elemento; con
        // "listo/siguiente/omitir" se cierra la seccion.
        if (! isset($data['items_done'])) {
            $closing = $this->isSkipCommand($question) || $this->isAffirmative($question);
            $noteConsumed = false;

            // Los comandos de cierre NO se interpretan como items: si no,
            // "listo" terminaria guardando un elemento llamado "listo".
            if (! $closing) {
                // Un elemento "por_reemplazar/destruido/reparable" sin nota
                // queda pendiente: el siguiente turno se toma como su nota.
                if (isset($data['pending_item']) && ! $this->looksLikeNewTopic($question)) {
                    $note = $this->extractItemNote($question) ?? trim($question);

                    if ($note !== '') {
                        $data['pending_item']['note'] = $note;
                        $data['items'][] = $data['pending_item'];
                        unset($data['pending_item'], $data['items_error']);
                        $noteConsumed = true;
                    }
                }

                // Si el turno ya se gasto como nota del item pendiente, no se
                // interpreta otra vez como un elemento nuevo. Las lecturas
                // de medidores ("agua 10") tampoco son items: se quitan del
                // texto antes de parsear el inventario.
                $itemQuestion = $this->stripMeterReadings($question);
                $item = $noteConsumed || $itemQuestion === '' ? null : $this->extractActItem($itemQuestion, $userId);

                if ($item !== null && isset($item['_needs_note'])) {
                    unset($item['_needs_note']);
                    $data['pending_item'] = $item;
                    $data['items_error'] = __('assistant.act_wizard.ask_item_note', [
                        'item' => $item['name'],
                    ]);
                    $item = null;
                }

                if ($item !== null) {
                    $data['items'][] = $item;
                    unset($data['items_error']);
                }
            }

            if ($closing) {
                // Si quedo un item esperando nota y el usuario quiere
                // cerrar, se acepta con nota generica para no perderlo.
                if (isset($data['pending_item'])) {
                    $data['pending_item']['note'] = $data['pending_item']['note']
                        ?? __('assistant.act_wizard.default_item_note');
                    $data['items'][] = $data['pending_item'];
                    unset($data['pending_item']);
                }

                $data['items_done'] = true;
            } else {
                // Estado que exige nota y no la trajo: se repregunta.
                if (isset($data['items_error'])) {
                    $this->forms->put($userId, 'delivery_act', $data);

                    return new AssistantReply(
                        text: $data['items_error'],
                        pendingForm: $this->actPendingForm($data, 'items'),
                    );
                }

                $this->forms->put($userId, 'delivery_act', $data);

                $count = count($data['items'] ?? []);

                return new AssistantReply(
                    text: $count > 0
                        ? __('assistant.act_wizard.ask_item_next', ['count' => $count])
                        : __('assistant.act_wizard.ask_item_first'),
                    pendingForm: $this->actPendingForm($data, 'items'),
                );
            }
        }

        // Paso 7: observaciones/compromisos (opcionales).
        if (! isset($data['extra_done'])) {
            if ($this->isSkipCommand($question) || $this->isAffirmative($question)) {
                $data['extra_done'] = true;
            } else {
                $data['observations'] = trim((string) ($data['observations'] ?? '').' '.$question);
                $data['extra_done'] = true;
            }
        }

        // Paso 8: revision -> accion propuesta. Antes de limpiar el wizard
        // se validan los items contra el catalogo de espacios: el Builder
        // exige un espacio valido por elemento y prefiero repreguntar aqui
        // que fallar en la confirmacion con el borrador ya borrado.
        $normalized = $this->normalizeActItems($data, $userId);

        if ($normalized['error'] !== null) {
            $data['items'] = $normalized['items'];
            unset($data['items_done']);
            $data['items_error'] = $normalized['error'];
            $this->forms->put($userId, 'delivery_act', $data);

            return new AssistantReply(
                text: $normalized['error'],
                pendingForm: $this->actPendingForm($data, 'items'),
            );
        }

        $data['items'] = $normalized['items'];
        $this->forms->clear($userId);

        return $this->proposeDeliveryActAction($data, $userId);
    }

    /**
     * Cada item del inventario necesita un espacio del catalogo del
     * usuario (el Builder lo exige). Aqui se resuelve el nombre libre a
     * space_id canonico; si el usuario solo tiene un espacio se asigna
     * solo; si no, se devuelve el error para repreguntar sin perder el
     * borrador.
     *
     * @param  array<string, mixed>  $data
     * @return array{items: list<array<string, mixed>>, error: ?string}
     */
    private function normalizeActItems(array $data, int $userId): array
    {
        $items = array_values(array_filter(
            (array) ($data['items'] ?? []),
            fn ($item): bool => is_array($item) && trim((string) ($item['name'] ?? '')) !== '',
        ));

        if ($items === []) {
            return ['items' => [], 'error' => null];
        }

        $spaces = \App\Models\Space::query()->where('user_id', $userId)->get();

        if ($spaces->isEmpty()) {
            return ['items' => $items, 'error' => __('assistant.act_wizard.no_spaces')];
        }

        $kept = [];
        $dropped = [];

        foreach ($items as $item) {
            $spaceId = $item['space_id'] ?? null;
            $spaceName = trim((string) ($item['space'] ?? ''));

            if ($spaceId === null) {
                if ($spaceName === '') {
                    // Sin espacio y el usuario tiene uno solo: se asigna
                    // solo; con varios, el item no se puede adivinar.
                    if ($spaces->count() === 1) {
                        $only = $spaces->first();
                        $item['space_id'] = (int) $only->id;
                        $item['space'] = (string) $only->name;
                    } else {
                        $dropped[] = (string) ($item['name'] ?? '—');

                        continue;
                    }
                } else {
                    $hit = $this->findSpaceByName($spaceName, $userId);

                    if ($hit === null) {
                        $dropped[] = (string) ($item['name'] ?? '—');

                        continue;
                    }

                    // El Builder compara el nombre EXACTO contra el
                    // catalogo; el space_id canonico evita que "Sala" no
                    // matchee "sala". Se conserva el nombre para mostrarlo
                    // en el diff y la tabla del acta.
                    $item['space_id'] = (int) $hit->id;
                    $item['space'] = (string) $hit->name;
                }
            } elseif (($item['space_id'] ?? null) !== null && ($item['space'] ?? '') === '') {
                // space_id ya venia (p.ej. de un item agregado antes de la
                // normalizacion): se resuelve el nombre para la vista.
                $known = $spaces->firstWhere('id', $item['space_id']);

                if ($known !== null) {
                    $item['space'] = (string) $known->name;
                }
            }

            $kept[] = $item;
        }

        if ($dropped !== []) {
            return [
                'items' => $kept,
                'error' => __('assistant.act_wizard.items_dropped', [
                    'items' => implode(', ', $dropped),
                ]),
            ];
        }

        return ['items' => $kept, 'error' => null];
    }

    /**
     * Propuesta final del acta: diff "todo nuevo" + campos editables en el
     * modal. Las firmas quedan fuera (archivos): el acta se crea sin firmar.
     *
     * @param  array<string, mixed>  $data
     */
    private function proposeDeliveryActAction(array $data, int $userId): AssistantReply
    {
        $rental = Rental::query()->with('tenant:id,name')->find($data['rental_id'] ?? 0);

        if ($rental === null) {
            return AssistantReply::say(__('assistant.act_wizard.rental_lost'), [
                __('assistant.suggestions.portfolio'),
            ]);
        }

        $params = [
            'rental_id' => (int) $rental->id,
            'type' => (string) ($data['type'] ?? 'entrega'),
            'occurred_at' => (string) ($data['occurred_at'] ?? today()->toDateString()),
            'scheduled_at' => $this->blankToNull($data['scheduled_at'] ?? null),
            'landlord_name' => (string) ($data['landlord_name'] ?? ''),
            'tenant_name' => (string) ($data['tenant_name'] ?? ''),
            'landlord_document' => $this->blankToNull($data['landlord_document'] ?? null),
            'tenant_document' => $this->blankToNull($data['tenant_document'] ?? null),
            'water_reading' => $this->blankToNull($data['water_reading'] ?? null),
            'energy_reading' => $this->blankToNull($data['energy_reading'] ?? null),
            'gas_reading' => $this->blankToNull($data['gas_reading'] ?? null),
            'items' => array_values($data['items'] ?? []),
            'commitments' => $this->blankToNull($data['commitments'] ?? null),
            'observations' => $this->blankToNull($data['observations'] ?? null),
        ];

        $changes = [
            $this->changeRow('rental', __('assistant.fields.rental'), null, $rental->name, 'text'),
            $this->changeRow('type', __('assistant.fields.type'), null, __("act.types.{$params['type']}"), 'enum'),
            $this->changeRow('occurred_at', __('assistant.fields.date'), null, $params['occurred_at'], 'date'),
            $this->changeRow('tenant', __('assistant.fields.tenant'), null, $params['tenant_name'], 'text'),
        ];

        foreach ($params['items'] as $item) {
            $changes[] = $this->changeRow(
                'item',
                __('assistant.act_wizard.item_label'),
                null,
                trim(implode(' · ', array_filter([
                    $item['space'] ?? null,
                    $item['name'] ?? null,
                    isset($item['state']) ? ActItemState::tryFrom((string) $item['state'])?->label() : null,
                ]))),
                'text',
            );
        }

        return new AssistantReply(
            text: __('assistant.act_wizard.review', [
                'rental' => $rental->name,
                'tenant' => $params['tenant_name'],
                'items' => count($params['items']),
            ]),
            data: [
                'act_items' => array_map(fn (array $item): array => [
                    'space' => $item['space'] ?? '—',
                    'item' => $item['name'] ?? '—',
                    'state' => isset($item['state']) ? (ActItemState::tryFrom((string) $item['state'])?->label() ?? $item['state']) : '—',
                ], $params['items']),
            ],
            action: [
                'type' => 'create_delivery_act',
                'params' => $params,
                'changes' => $changes,
                'editable' => [
                    ['key' => 'occurred_at', 'param' => 'occurred_at', 'label' => __('assistant.fields.date'), 'kind' => 'date', 'value' => $params['occurred_at']],
                    ['key' => 'scheduled_at', 'param' => 'scheduled_at', 'label' => __('assistant.fields.scheduled_at'), 'kind' => 'date', 'value' => $params['scheduled_at'] ?? ''],
                    ['key' => 'landlord_name', 'param' => 'landlord_name', 'label' => __('assistant.fields.landlord'), 'kind' => 'text', 'value' => $params['landlord_name']],
                    ['key' => 'tenant_name', 'param' => 'tenant_name', 'label' => __('assistant.fields.tenant'), 'kind' => 'text', 'value' => $params['tenant_name']],
                    ['key' => 'landlord_document', 'param' => 'landlord_document', 'label' => __('assistant.fields.landlord_document'), 'kind' => 'text', 'value' => $params['landlord_document'] ?? ''],
                    ['key' => 'tenant_document', 'param' => 'tenant_document', 'label' => __('assistant.fields.tenant_document'), 'kind' => 'text', 'value' => $params['tenant_document'] ?? ''],
                    ['key' => 'water_reading', 'param' => 'water_reading', 'label' => __('assistant.fields.water_reading'), 'kind' => 'text', 'value' => $params['water_reading'] ?? ''],
                    ['key' => 'energy_reading', 'param' => 'energy_reading', 'label' => __('assistant.fields.energy_reading'), 'kind' => 'text', 'value' => $params['energy_reading'] ?? ''],
                    ['key' => 'gas_reading', 'param' => 'gas_reading', 'label' => __('assistant.fields.gas_reading'), 'kind' => 'text', 'value' => $params['gas_reading'] ?? ''],
                    ['key' => 'commitments', 'param' => 'commitments', 'label' => __('assistant.fields.commitments'), 'kind' => 'textarea', 'value' => $params['commitments'] ?? ''],
                    ['key' => 'observations', 'param' => 'observations', 'label' => __('assistant.fields.observations'), 'kind' => 'textarea', 'value' => $params['observations'] ?? ''],
                ],
                'summary' => __('assistant.actions.create_act_summary', [
                    'rental' => $rental->name,
                    'tenant' => $params['tenant_name'],
                ]),
            ],
            suggestions: [],
        );
    }

    /**
     * Extrae del texto libre los campos del acta que pueda reconocer.
     * Best-effort: lo que no se entienda simplemente no se guarda y el
     * paso correspondiente lo vuelve a pedir.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>|null  $extracted
     * @return array<string, mixed>
     */
    private function extractActFields(string $question, array $data, int $userId, ?array $extracted): array
    {
        if ($extracted !== null) {
            foreach (['rental_name', 'type', 'occurred_at', 'scheduled_at', 'landlord_name',
                'tenant_name', 'landlord_document', 'tenant_document', 'water_reading',
                'energy_reading', 'gas_reading', 'commitments', 'observations'] as $key) {
                if (! empty($extracted[$key]) && empty($data[$key])) {
                    $data[$key] = $extracted[$key];
                }
            }
        }

        if ($question === '') {
            return $data;
        }

        $q = Str::lower($question);

        // Tipo de acta.
        if (empty($data['type'])) {
            $type = $this->extractActType($question);

            if ($type !== null) {
                $data['type'] = $type;
            }
        }

        // Fecha.
        if (empty($data['occurred_at'])) {
            $date = $this->parseDate($question);

            if ($date !== null) {
                $data['occurred_at'] = $date;
            }
        }

        // Lecturas de medidores: "agua 123", "energía: 45", etc. Solo
        // antes de cerrar la seccion: si el usuario ya dio lecturas, un
        // texto posterior del inventario ("nevera junto a la llave de
        // agua 30") no debe pisar el dato real.
        if (! isset($data['meters_done'])) {
            $this->extractMeters($question, $data);
        }

        // Nombres de partes si el usuario los da explicitamente.
        if (preg_match('/\b(?:arrendador|propietario|landlord)\s+(?:llamad[oa]\s+|de nombre\s+)?([a-záéíóúñ][\wáéíóúñ.\' -]{1,50})/iu', $question, $m) === 1) {
            $data['landlord_name'] = trim($m[1]);
        }

        if (preg_match('/\b(?:arrendatario|inquilino|inquilina|tenant)\s+(?:llamad[oa]\s+|de nombre\s+)?([a-záéíóúñ][\wáéíóúñ.\' -]{1,50})/iu', $question, $m) === 1) {
            $data['tenant_name'] = trim($m[1]);
        }

        // Documentos: "cc 123456" tras cada nombre de parte.
        $this->extractContactInto($question, $data);

        return $data;
    }

    /**
     * Lee "agua 123", "energía 45", "gas: 67" del texto libre.
     *
     * @param  array<string, mixed>  $data
     */
    private function extractMeters(string $question, array &$data): void
    {
        foreach (['water' => ['agua', 'water'], 'energy' => ['energía', 'energia', 'energy', 'luz'], 'gas' => ['gas']] as $key => $words) {
            foreach ($words as $word) {
                if (preg_match('/\b'.preg_quote($word, '/').'[a-z]*\s*[:#]?\s*(\d{1,7})/iu', $question, $m) === 1) {
                    $data[$key.'_reading'] = $m[1];

                    break 2;
                }
            }
        }
    }

    /**
     * Documentos de las partes: "arrendador cc 123" / "inquilino cc 456".
     *
     * @param  array<string, mixed>  $data
     */
    private function extractContactInto(string $question, array &$data): void
    {
        foreach (['landlord' => ['arrendador', 'propietario', 'landlord'], 'tenant' => ['arrendatario', 'inquilino', 'inquilina', 'tenant']] as $who => $words) {
            foreach ($words as $word) {
                if (preg_match('/\b'.preg_quote($word, '/').'[^,.;]{0,80}?\b(?:cc|cedula|cédula|documento|nit)\s*[:#]?\s*([a-z0-9][a-z0-9.-]{2,})/iu', $question, $m) === 1) {
                    $data[$who.'_document'] = $m[1];

                    break 2;
                }
            }
        }
    }

    private function extractActType(string $question): ?string
    {
        $q = Str::lower($question);

        if (Str::contains($q, ['recepci', 'reception', 'entrega de llaves'])) {
            return 'recepcion';
        }

        if (Str::contains($q, ['cambio', 'cambio_arrendatario', 'nuevo inquilino', 'new tenant'])) {
            return 'cambio_arrendatario';
        }

        if (Str::contains($q, ['entrega', 'delivery', 'inspecci'])) {
            return 'entrega';
        }

        return null;
    }

    /**
     * Un elemento del inventario por turno. Formato esperado (se explica en
     * la pregunta): "<espacio> <elemento> <estado>" — ej: "sala nevera
     * bueno", "cocina estufa por reemplazar". El espacio se valida contra
     * el catalogo del usuario en la revision final via Builder.
     *
     * @return array{space?: string, name: string, state: string, note?: string}|null
     */
    private function extractActItem(string $question, int $userId): ?array
    {
        $q = trim($question);

        if ($q === '') {
            return null;
        }

        $state = null;
        $statePos = null;
        $stateLen = 0;

        foreach (ActItemState::cases() as $case) {
            $label = Str::lower($case->label());
            $pos = mb_stripos($q, $label);

            if ($pos !== false && ($statePos === null || $pos < $statePos)) {
                $state = $case;
                $statePos = $pos;
                $stateLen = mb_strlen($case->label());
            }

            // Alias comunes: se guarda el largo del ALIAS matcheado, no el
            // del label, o "dañado" cortaria 2 caracteres de mas.
            foreach (['por reemplazar' => ActItemState::POR_REEMPLAZAR, 'dañado' => ActItemState::DESTRUIDO, 'danado' => ActItemState::DESTRUIDO, 'averiado' => ActItemState::REPARABLE] as $alias => $aliasState) {
                $pos = mb_stripos($q, $alias);

                if ($pos !== false && ($statePos === null || $pos < $statePos)) {
                    $state = $aliasState;
                    $statePos = $pos;
                    $stateLen = mb_strlen($alias);
                }
            }
        }

        $text = $q;

        if ($state !== null && $statePos !== null) {
            // Quita la palabra de estado del texto.
            $text = trim(mb_substr($q, 0, $statePos).' '.mb_substr($q, $statePos + $stateLen));
        }

        // Espacio: "en <espacio>" al inicio o "<espacio> <elemento>".
        $space = null;
        $name = $text;

        if (preg_match('/^(.+?)\s+(?:en|@)\s+(.+)$/iu', $text, $m) === 1) {
            $name = trim($m[2]);
            $space = trim($m[1]);
        } else {
            // Primer token como posible espacio si el catalogo lo confirma.
            $first = Str::before($text.' ', ' ');
            $catalogHit = $this->findSpaceByName($first, $userId);

            if ($catalogHit !== null && mb_strlen($text) > mb_strlen($first) + 1) {
                $space = $first;
                $name = trim(mb_substr($text, mb_strlen($first)));
            }
        }

        $name = trim(preg_replace('/\s+/', ' ', $name) ?? $name);

        if ($name === '' || mb_strlen($name) < 2) {
            return null;
        }

        $stateValue = $state?->value ?? ActItemState::BUENO->value;

        $item = [
            'space' => $space,
            'name' => $name,
            'state' => $stateValue,
        ];

        // Estados que exigen nota (reparable, por_reemplazar, destruido):
        // si no vino en el mismo mensaje se marca para que el wizard la
        // pida en el turno siguiente en vez de perder el elemento.
        if ($state !== null && $state->needsNote()) {
            $note = $this->extractItemNote($q);

            if ($note !== null) {
                $item['note'] = $note;
            } else {
                $item['_needs_note'] = true;
            }
        }

        return $item;
    }

    /**
     * Quita del texto las lecturas de medidores ("agua 10, energía 45"):
     * cuando el usuario responde el paso de medidores, el mismo mensaje
     * llega al paso de inventario y sin esto "agua 10" se guardaria como
     * un elemento llamado "agua 10". Devuelve '' si el mensaje era solo
     * lecturas.
     */
    private function stripMeterReadings(string $question): string
    {
        $stripped = preg_replace(
            '/\b(?:agua|energ[ií]a|energia|luz|gas|water|energy)[a-z]*\s*[:#]?\s*\d{1,7}\b/iu',
            ' ',
            $question,
        ) ?? $question;

        $stripped = trim(preg_replace('/[\s,;.\-]+/u', ' ', $stripped) ?? $stripped);

        return $stripped;
    }

    /**
     * Nota del elemento: lo que va tras "nota:", "porque", "porque" o
     * parentesis. Devuelve null si no hay nada reconocible.
     */
    private function extractItemNote(string $question): ?string
    {
        if (preg_match('/(?:nota|note|por\s*que|porque)\s*[:\-]?\s*(.+?)(?=[.,;]|$)/iu', $question, $m) === 1) {
            $note = trim($m[1]);

            if ($note !== '') {
                return $note;
            }
        }

        if (preg_match('/\(([^)]+)\)/u', $question, $m) === 1) {
            return trim($m[1]);
        }

        return null;
    }

    /**
     * Busca un espacio del catalogo del usuario por nombre o ruta parcial.
     */
    private function findSpaceByName(string $name, int $userId): ?\App\Models\Space
    {
        $needle = Str::lower(trim($name));

        if ($needle === '') {
            return null;
        }

        return \App\Models\Space::query()
            ->where('user_id', $userId)
            ->get()
            ->first(fn (\App\Models\Space $space): bool => Str::contains(Str::lower($space->name), $needle)
                || Str::contains(Str::lower($space->path()), $needle));
    }

    /**
     * pendingForm del paso actual del wizard, con quick_replies para los
     * pasos de opcion cerrada (tipo, estados).
     *
     * @param  array<string, mixed>  $data
     * @return array{form: string, step: int, total_steps: int, field: string, prompt: string, quick_replies?: list<string>}
     */
    private function actPendingForm(array $data, string $step): array
    {
        // Solo los pasos que el wizard realmente pregunta: fecha y partes
        // se pre-llenan solas, asi que no cuentan en la barra de progreso.
        $steps = ['rental', 'type', 'meters', 'items', 'extra', 'review'];
        $index = array_search($step, $steps, true);
        $index = $index === false ? 0 : $index;

        $quickReplies = match ($step) {
            'type' => [
                __('act.types.entrega'),
                __('act.types.recepcion'),
                __('act.types.cambio_arrendatario'),
            ],
            'meters' => [__('assistant.act_wizard.skip'), __('assistant.act_wizard.next')],
            'items' => [__('assistant.act_wizard.skip'), __('assistant.act_wizard.next')],
            'extra' => [__('assistant.act_wizard.skip'), __('assistant.act_wizard.next')],
            default => [],
        };

        return [
            'form' => 'delivery_act',
            'step' => $index + 1,
            'total_steps' => count($steps),
            'field' => $step,
            'prompt' => __('assistant.act_wizard.step_prompts.'.$step),
            'quick_replies' => $quickReplies === [] ? null : $quickReplies,
        ];
    }

    /**
     * Paso del wizard segun lo acumulado en $data (para reanudar la barra
     * de progreso cuando llega una pregunta nueva a mitad de camino).
     *
     * @param  array<string, mixed>  $data
     */
    private function wizardCurrentStep(array $data): string
    {
        if (empty($data['rental_id'])) {
            return 'rental';
        }

        if (empty($data['type'])) {
            return 'type';
        }

        if (! isset($data['meters_done'])) {
            return 'meters';
        }

        if (! isset($data['items_done'])) {
            return 'items';
        }

        if (! isset($data['extra_done'])) {
            return 'extra';
        }

        return 'review';
    }

    /**
     * Resuelve un arriendo por nombre con candidatos cuando hay empate.
     *
     * @return array{entity: ?Rental, candidates: list<array<string, string>>}
     */
    private function resolveRentalCandidates(string $name, int $userId): array
    {
        // Eleccion desde la lista de candidatos: manda sobre el nombre.
        $byRef = $this->entityFromRef(Rental::class, $userId);

        if ($byRef !== null) {
            return ['entity' => $byRef, 'candidates' => []];
        }

        $needle = Str::lower(trim($name));

        if ($needle === '') {
            return ['entity' => null, 'candidates' => []];
        }

        // Activos primero; si ninguno matchea se consideran los
        // inactivos (documentos/actas de periodos pasados).
        $all = Rental::query()
            ->where('user_id', $userId)
            ->with('tenant:id,name', 'property:id,name,address')
            ->get()
            ->filter(fn (Rental $rental): bool => Str::contains(Str::lower($rental->name), $needle)
                || Str::contains(Str::lower((string) $rental->tenant?->name), $needle))
            ->values();

        $matches = $all->where('is_active', true)->values();
        if ($matches->isEmpty()) {
            $matches = $all;
        }

        // Match exacto antes que substring (igual que findRental), pero
        // solo cuando es UNICO: dos arriendos de inquilinos homonimos
        // siguen siendo ambiguos y deben listarse como candidatos.
        $exacts = $matches->filter(fn (Rental $rental): bool => Str::lower($rental->name) === $needle
            || Str::lower((string) $rental->tenant?->name) === $needle)->values();

        if ($exacts->count() === 1) {
            return ['entity' => $exacts->first(), 'candidates' => []];
        }

        if ($exacts->count() > 1) {
            $matches = $exacts;
        } elseif ($matches->count() === 1) {
            return ['entity' => $matches->first(), 'candidates' => []];
        }

        if ($matches->count() > 1) {
            return [
                'entity' => null,
                'candidates' => $matches->take(5)->map(fn (Rental $rental): array => [
                    'name' => $rental->name,
                    'tenant' => $rental->tenant?->name ?? '—',
                    'token' => class_basename($rental).':'.$rental->id,
                    'detail' => collect([$rental->property?->name, $rental->property?->address])
                        ->filter()->implode(' · '),
                ])->all(),
            ];
        }

        return ['entity' => null, 'candidates' => []];
    }

    /**
     * "cualquiera", "una aleatoria", "la primera", "da igual": el usuario
     * delega la eleccion de la entidad. Solo aplica cuando lo dice
     * explicitamente — nunca se adivina un registro al azar por defecto.
     */
    private function isRandomPick(?string $name): bool
    {
        return $name !== null && $name !== '' && preg_match(
            '/\b(cualquiera|cualquier|cual sea|cualquie|aleatori\w*|la primera|el primero|la que sea|el que sea|da igual|lo que sea|any|random|dont care|don\'t care)\b/iu',
            $name,
        ) === 1;
    }

    private function isCancelCommand(string $question): bool
    {
        return $question !== '' && preg_match(
            '/\b(cancela\w*|cancelar|sal de|salir|olvida\w*|dejalo|dejalo asi|no quiero|forget|stop|cancel)\b/iu',
            $question,
        ) === 1;
    }

    private function isSkipCommand(string $question): bool
    {
        $q = Str::lower(trim($question));

        if ($q === '') {
            return false;
        }

        // Tokens ambiguos solo cuentan si son TODA la respuesta: "no hay
        // nevera" no debe cerrar la seccion de inventario, pero "no" si.
        if (preg_match('/^(no|nada|ningun[oa]?|listo|ya|eso es todo|terminar|finalizar|next|skip|no thanks|no hay nada|no hay items)[.! ]*$/iu', $q) === 1) {
            return true;
        }

        return preg_match(
            '/^(saltar|siguiente|omitir|skip|nada mas|sin medidores|sin inventario|sin observaciones|sin novedad|todo bien|ninguno|ninguna)\b/iu',
            $q,
        ) === 1;
    }

    /**
     * El wizard se come TODO texto mientras esta activo: sin esta guarda,
     * una pregunta nueva ("cuánto me deben") se guardaria como observacion
     * del acta o como nombre de elemento. Se detectan solo comienzos muy
     * claros de peticion nueva; el resto sigue siendo dato del formulario.
     */
    private function looksLikeNewTopic(string $question): bool
    {
        $q = Str::lower(trim($question));

        if ($q === '') {
            return false;
        }

        return preg_match(
            '/^(cuanto|cuantos|cuantas|como va|como esta|lista|listar|muestra|muestrame|dame|ver |llevame|ir a|analiza|analizar|mi portafolio|mis |tengo |hay |genera|genera|crea|registra|agenda|redacta|ayudame|exporta|descarga|sincroniza|cambia|actualiza|elimina|borra|anula)\b/iu',
            $q,
        ) === 1;
    }

    private function isAffirmative(string $question): bool
    {
        $q = Str::lower(trim($question));

        return preg_match('/^(si|sí|dale|ok|okay|bueno|va|correcto|confirmo|yes|y|ajá|aja)\b[.!]?$/iu', $q) === 1;
    }

    private function blankToNull(mixed $value): mixed
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        return $value;
    }

    // ---------------------------------------------------------------
    // Propuestas de escritura: updates con diff before/after
    // ---------------------------------------------------------------

    /**
     * Mapa de campos editables por entidad -> [param aliases, label key,
     * kind para el diff]. Solo entran campos que el usuario menciono.
     *
     * @return array<string, array{aliases: list<string>, label: string, kind: string, cast?: string}>
     */
    private function editableFields(string $entity): array
    {
        return match ($entity) {
            'tenant' => [
                'name' => ['aliases' => ['name', 'nombre', 'new_name'], 'label' => __('assistant.fields.name'), 'kind' => 'text'],
                'document' => ['aliases' => ['document', 'documento', 'cedula', 'cc'], 'label' => __('assistant.fields.document'), 'kind' => 'text'],
                'phone_number' => ['aliases' => ['phone_number', 'phone', 'telefono', 'celular', 'tel'], 'label' => __('assistant.fields.phone'), 'kind' => 'tel'],
                'email' => ['aliases' => ['email', 'correo'], 'label' => __('assistant.fields.email'), 'kind' => 'email'],
            ],
            'property' => [
                'name' => ['aliases' => ['name', 'nombre', 'new_name'], 'label' => __('assistant.fields.name'), 'kind' => 'text'],
                'address' => ['aliases' => ['address', 'direccion', 'address_line'], 'label' => __('assistant.fields.address'), 'kind' => 'text'],
                'description' => ['aliases' => ['description', 'descripcion'], 'label' => __('assistant.fields.description'), 'kind' => 'text'],
            ],
            'rental' => [
                'monthly_amount' => ['aliases' => ['monthly_amount', 'amount', 'monto', 'canon', 'arriendo'], 'label' => __('assistant.fields.monthly_amount'), 'kind' => 'money'],
                'billing_cadence' => ['aliases' => ['billing_cadence', 'cadence', 'periodicidad'], 'label' => __('assistant.fields.billing_cadence'), 'kind' => 'enum'],
                'start_date' => ['aliases' => ['start_date', 'fecha_inicio', 'inicio'], 'label' => __('assistant.fields.start_date'), 'kind' => 'date'],
                'end_date' => ['aliases' => ['end_date', 'fecha_fin', 'fin'], 'label' => __('assistant.fields.end_date'), 'kind' => 'date'],
                'total_months' => ['aliases' => ['total_months', 'months', 'meses', 'duracion'], 'label' => __('assistant.fields.total_months'), 'kind' => 'number'],
                'commercial_value' => ['aliases' => ['commercial_value', 'valor_comercial'], 'label' => __('assistant.fields.commercial_value'), 'kind' => 'money'],
                'catastral_appraisal' => ['aliases' => ['catastral_appraisal', 'valor_catastral'], 'label' => __('assistant.fields.catastral_appraisal'), 'kind' => 'money'],
                'rental_type' => ['aliases' => ['rental_type', 'tipo_arriendo'], 'label' => __('assistant.fields.rental_type'), 'kind' => 'enum'],
                'is_active' => ['aliases' => ['is_active', 'active', 'activo'], 'label' => __('assistant.fields.is_active'), 'kind' => 'bool'],
            ],
            'settings' => [
                'business_name' => ['aliases' => ['business_name', 'nombre_negocio', 'empresa'], 'label' => __('assistant.fields.business_name'), 'kind' => 'text'],
                'tax_id' => ['aliases' => ['tax_id', 'nit'], 'label' => __('assistant.fields.tax_id'), 'kind' => 'text'],
                'address' => ['aliases' => ['address', 'direccion'], 'label' => __('assistant.fields.address'), 'kind' => 'text'],
                'email' => ['aliases' => ['email', 'correo'], 'label' => __('assistant.fields.email'), 'kind' => 'email'],
                'phone' => ['aliases' => ['phone', 'telefono'], 'label' => __('assistant.fields.phone'), 'kind' => 'tel'],
                'currency' => ['aliases' => ['currency', 'moneda'], 'label' => __('assistant.fields.currency'), 'kind' => 'text'],
                'invoice_due_days' => ['aliases' => ['invoice_due_days', 'dias_factura', 'vencimiento'], 'label' => __('assistant.fields.invoice_due_days'), 'kind' => 'number'],
                'legal_footer' => ['aliases' => ['legal_footer', 'pie_legal', 'footer'], 'label' => __('assistant.fields.legal_footer'), 'kind' => 'textarea'],
                'default_city_code' => ['aliases' => ['default_city_code', 'ciudad'], 'label' => __('assistant.fields.default_city'), 'kind' => 'text'],
            ],
            default => [],
        };
    }

    /**
     * Extrae de $params los cambios pedidos para $entity: solo campos con
     * valor nuevo y distinto del actual. Devuelve params listos para la
     * accion, filas de diff y campos editables.
     *
     * @param  array<string, mixed>  $params
     * @return array{params: array<string, mixed>, changes: list<array<string, mixed>>, editable: list<array<string, mixed>>, labels: list<string>}
     */
    private function collectChanges(array $params, string $entity, object $record): array
    {
        $out = ['params' => [], 'changes' => [], 'editable' => [], 'labels' => []];

        foreach ($this->editableFields($entity) as $field => $meta) {
            // Se lee el valor crudo (no cleanParam): un booleano false de
            // Gemini ("desactiva el arriendo") no puede perderse al
            // convertirse a string vacio.
            $raw = null;

            foreach ($meta['aliases'] as $alias) {
                if (array_key_exists($alias, $params)
                    && $params[$alias] !== null
                    && $params[$alias] !== ''
                    && ! is_array($params[$alias])) {
                    $raw = $params[$alias];

                    break;
                }
            }

            if ($raw === null) {
                continue;
            }

            $value = $this->castEditableValue($raw, $meta['kind'], $field);
            $before = $record->{$field} ?? null;

            // Enums y casts: comparar contra el valor crudo del modelo.
            $beforeComparable = $before instanceof \BackedEnum ? $before->value : $before;
            $beforeStr = $this->formatForDiff($beforeComparable, $meta['kind']);
            $afterStr = $this->formatForDiff($value, $meta['kind']);

            if ($beforeStr !== null && $afterStr !== null && Str::lower($beforeStr) === Str::lower($afterStr)) {
                continue;
            }

            if ($beforeStr === $afterStr) {
                continue;
            }

            $out['params'][$field] = $value;
            $out['changes'][] = $this->changeRow($field, $meta['label'], $beforeStr, $afterStr, $meta['kind']);
            $out['editable'][] = $this->editableField($field, $meta, $value);
            $out['labels'][] = $meta['label'];
        }

        return $out;
    }

    /**
     * Entrada del campo editable para el modal: conserva el tipo real
     * (bool, select con opciones, number, date, textarea) para que el
     * input se pinte adecuado en vez de un texto generico.
     *
     * @param  array{aliases: list<string>, label: string, kind: string}  $meta
     * @return array<string, mixed>
     */
    private function editableField(string $field, array $meta, mixed $value): array
    {
        $kind = match (true) {
            in_array($meta['kind'], ['money', 'number'], true) => 'number',
            $meta['kind'] === 'textarea' => 'textarea',
            $meta['kind'] === 'date' => 'date',
            $meta['kind'] === 'bool' => 'bool',
            $meta['kind'] === 'enum' => 'select',
            in_array($meta['kind'], ['email', 'tel', 'password', 'range', 'datetime'], true) => $meta['kind'],
            default => 'text',
        };

        $entry = [
            'key' => $field,
            'param' => $field,
            'label' => $meta['label'],
            'kind' => $kind,
            'value' => match (true) {
                $kind === 'bool' => ($value ? '1' : '0'),
                $kind === 'select' && is_string($value) => $value,
                default => $value === null ? '' : (string) $value,
            },
        ];

        // Selects con opciones reales del enum para que el usuario elija
        // en vez de escribir el valor a mano.
        if ($kind === 'select') {
            $entry['options'] = match ($field) {
                'billing_cadence' => \App\Enums\BillingCadence::options(),
                'rental_type' => \App\Enums\RentalType::options(),
                default => [],
            };
        }

        return $entry;
    }

    /**
     * Normaliza el valor nuevo segun el tipo de campo. Devuelve null para
     * "borrar el campo" cuando el kind lo permite.
     */
    private function castEditableValue(mixed $raw, string $kind, string $field): mixed
    {
        if ($kind === 'bool') {
            if (is_bool($raw)) {
                return $raw;
            }

            $v = Str::lower(trim((string) $raw));

            return in_array($v, ['0', 'false', 'no', 'n', 'off', 'desactiva', 'inactivo', 'pausa'], true) ? false : true;
        }

        if ($kind === 'number' || $kind === 'money') {
            $clean = preg_replace('/[^\d.,-]/', '', (string) $raw) ?? '';

            if (str_contains($clean, ',')) {
                // "1.200.000,50": puntos de miles, coma decimal.
                $clean = str_replace('.', '', $clean);
                $clean = str_replace(',', '.', $clean);
            } elseif (preg_match('/^\d{1,3}\.\d{3}$/', $clean) === 1
                || substr_count($clean, '.') > 1) {
                // "1.500" o "1.200.000": puntos de miles. Un punto con
                // 1-2 decimales ("1200000.50") si se respeta.
                $clean = str_replace('.', '', $clean);
            }

            return is_numeric($clean) ? (float) $clean : null;
        }

        $value = trim((string) $raw);

        if ($field === 'billing_cadence') {
            $key = Str::lower(Str::ascii($value));

            $mapped = [
                'mensual' => 'MENSUAL', 'monthly' => 'MENSUAL',
                'quincenal' => 'QUINCENAL', 'biweekly' => 'QUINCENAL', 'fortnightly' => 'QUINCENAL',
                'anticipo' => 'ANTICIPO', 'advance' => 'ANTICIPO', 'adelantado' => 'ANTICIPO',
            ][$key] ?? null;

            return BillingCadence::tryFrom($mapped ?? Str::upper($value))?->value ?? $value;
        }

        if ($field === 'rental_type') {
            $key = Str::lower(Str::ascii($value));

            $mapped = [
                'residencial' => 'residential', 'residential' => 'residential', 'vivienda' => 'residential',
                'comercial' => 'commercial', 'commercial' => 'commercial', 'local' => 'commercial',
                'oficina' => 'commercial',
            ][$key] ?? null;

            return RentalType::tryFrom($mapped ?? Str::lower($value))?->value ?? $value;
        }

        return $value === '' ? null : $value;
    }

    /**
     * Respuesta cuando no se pidio ningun campo valido para editar.
     */
    private function needChangesReply(string $entity): AssistantReply
    {
        return AssistantReply::say(__('assistant.replies.need_'.$entity.'_changes'), [
            __('assistant.suggestions.portfolio'),
        ]);
    }

    // ---------------------------------------------------------------
    // Actualizacion guiada: entidad resuelta -> campo -> valor
    // ---------------------------------------------------------------
    //
    // Cuando el update llega con entidad pero sin campo ("cambia el
    // nombre de X" sin valor, o "actualiza a Juan"), no se adivina: se
    // abre un mini-wizard de dos pasos que funciona IGUAL para cualquier
    // campo de tenant/property/rental. La entidad ya quedo resuelta por
    // id, asi que no hay ambiguedad de nombres en los turnos siguientes
    // y el valor nuevo nunca se confunde con el nombre de la entidad.

    /**
     * @param  array{kind: string, label: string, options?: list<string>}|null  $input
     * @return array{form: string, step: int, total_steps: int, field: string, prompt: string, input?: array<string, mixed>}|null
     */
    private function updatePendingForm(
        string $stage,
        string $entityName,
        string $fieldLabel = '',
        int $step = 1,
        int $total = 2,
        ?array $input = null,
    ): ?array {
        $form = [
            'form' => 'update',
            'form_label' => __('assistant.form_names.update'),
            'step' => $stage === 'field' ? 1 : $step,
            'total_steps' => $stage === 'field' ? 2 : $total,
            'field' => $stage,
            'prompt' => $stage === 'field'
                ? __('assistant.replies.choose_update_field_prompt', ['entity' => $entityName])
                : __('assistant.replies.update_value_prompt', ['field' => $fieldLabel, 'entity' => $entityName]),
        ];

        if ($input !== null) {
            $form['input'] = $input;
        }

        return $form;
    }

    /**
     * Descriptor del input que la barra del wizard debe pintar para el
     * campo actual: el tipo sale del `kind` real del campo editable para
     * que una fecha sea date picker, un bool un switch y un enum un
     * select con las opciones del dominio.
     *
     * @return array{kind: string, label: string, options?: list<string>}|null
     */
    private function updateFieldInput(string $entity, string $field, string $fieldLabel): ?array
    {
        $meta = $this->editableFields($entity)[$field] ?? null;

        if ($meta === null) {
            return null;
        }

        $kind = match ($meta['kind']) {
            'money' => 'number',
            'enum' => 'select',
            'textarea' => 'textarea',
            'date' => 'date',
            'number' => 'number',
            'bool' => 'bool',
            'email', 'tel', 'password', 'range', 'datetime' => $meta['kind'],
            default => 'text',
        };

        $input = ['kind' => $kind, 'label' => $fieldLabel];

        if ($kind === 'select') {
            $input['options'] = match ($field) {
                'billing_cadence' => array_map(
                    fn ($case): string => $case->value,
                    \App\Enums\BillingCadence::cases(),
                ),
                'rental_type' => array_map(
                    fn ($case): string => $case->value,
                    \App\Enums\RentalType::cases(),
                ),
                default => [],
            };
        }

        return $input;
    }

    /**
     * Abre el paso 1 del update guiado: el usuario elige que campo
     * cambiar de la lista real de campos editables de la entidad.
     */
    private function startUpdateClarify(string $entity, object $record, int $userId): AssistantReply
    {
        $idKey = $this->updateIdKey($entity);

        if ($idKey === null) {
            return $this->needChangesReply($entity);
        }

        $this->forms->put($userId, 'update_clarify', [
            'entity_type' => $entity,
            'entity_id' => (int) $record->getKey(),
            'entity_name' => $this->updateEntityLabel($entity, $record),
            'mode' => 'form',
        ]);

        $name = $this->updateEntityLabel($entity, $record);

        // Form dinamico: TODOS los campos editables de la entidad, cada
        // uno con su tipo real (fecha, numero, switch, select, textarea).
        // El usuario llena solo lo que quiere cambiar; lo vacio no se
        // toca. Si prefiere escribir, el texto sigue yendo al wizard
        // conversacional (update_clarify) como antes.
        $pending = $this->updatePendingForm('field', $name);
        $pending['fields'] = $this->updateFormFields($entity, $record);
        $pending['prompt'] = __('assistant.replies.update_form_prompt', ['entity' => $name]);
        $pending['step'] = 1;
        $pending['total_steps'] = 1;

        return new AssistantReply(
            text: __('assistant.replies.update_form_prompt', ['entity' => $name]),
            suggestions: [],
            pendingForm: $pending,
        );
    }

    /**
     * Descriptores de TODOS los campos editables de la entidad para el
     * formulario vacio: mismo formato que `editable` de una propuesta
     * (key/label/kind/options), con el valor actual solo en bool y
     * select — el resto arranca vacio porque no tienen "vacio"
     * significativo y el usuario debe escribir el cambio.
     *
     * @return list<array<string, mixed>>
     */
    private function updateFormFields(string $entity, object $record): array
    {
        $fields = [];

        foreach ($this->editableFields($entity) as $field => $meta) {
            $raw = $record->{$field} ?? null;
            $current = $raw instanceof \BackedEnum ? $raw->value : $raw;

            $kind = match ($meta['kind']) {
                'money', 'number' => 'number',
                'enum' => 'select',
                'textarea' => 'textarea',
                'date' => 'date',
                'bool' => 'bool',
                'email', 'tel', 'password', 'range', 'datetime' => $meta['kind'],
                default => 'text',
            };

            $fields[] = $this->editableField(
                $field,
                $meta,
                in_array($kind, ['bool', 'select'], true) ? $current : '',
            );
        }

        return $fields;
    }

    /**
     * Recibe el formulario vacio ya diligenciado por el usuario (cada
     * campo con su tipo correcto) y arma la propuesta de update con
     * diff — el mismo camino que un update directo de la IA.
     *
     * @param  array<string, mixed>  $values
     */
    public function submitUpdateForm(array $values, int $userId): AssistantReply
    {
        $session = $this->forms->get($userId);

        if ($session === null || $session['form'] !== 'update_clarify') {
            return AssistantReply::say(__('assistant.replies.form_none'));
        }

        $entity = (string) ($session['data']['entity_type'] ?? '');
        $record = $this->loadUpdateRecord($entity, (int) ($session['data']['entity_id'] ?? 0), $userId);

        $this->forms->clear($userId);

        if ($record === null) {
            return AssistantReply::say(__('assistant.actions.entity_missing'));
        }

        $editable = $this->editableFields($entity);
        $params = [];

        foreach ($values as $field => $value) {
            if (! isset($editable[$field]) || is_array($value)) {
                continue;
            }

            $v = is_bool($value) ? $value : trim((string) $value);

            // Vacio = "no cambiar": el usuario lleno solo lo que queria.
            if ($v === '') {
                continue;
            }

            $params[$field] = $v;
        }

        $collected = $this->collectChanges($params, $entity, $record);

        if ($collected['params'] === []) {
            return AssistantReply::say(__('assistant.actions.no_changes'), [
                __('assistant.suggestions.portfolio'),
            ]);
        }

        return $this->updateProposalReply($entity, $record, $collected);
    }

    /**
     * Paso 1: el mensaje debe nombrar uno de los campos editables de la
     * entidad (por alias, etiqueta o nombre canonico). Si no matchea se
     * repite la lista — nunca se inventa un campo.
     *
     * @param  array<string, mixed>  $data
     */
    private function updateClarifyStep(string $question, array $data, int $userId): AssistantReply
    {
        if (preg_match('/\b(cancela\w*|cancelar|olvida\w*|stop|cancel)\b/iu', $question)) {
            return $this->cancelActiveForm($userId);
        }

        $entity = (string) ($data['entity_type'] ?? '');
        $entityName = (string) ($data['entity_name'] ?? '');
        $fieldMatches = $this->matchEditableFields($question, $entity);

        if ($fieldMatches === []) {
            $fields = collect($this->editableFields($entity))
                ->map(fn (array $meta): string => $meta['label'])
                ->implode(', ');

            return new AssistantReply(
                text: __('assistant.replies.choose_update_field', [
                    'entity' => $entityName,
                    'fields' => $fields,
                ]),
                suggestions: [],
                pendingForm: $this->updatePendingForm('field', $entityName),
            );
        }

        // El usuario ya dijo QUE campos quiere ("nombre y teléfono"):
        // no se vuelve a preguntar el campo, se arma la cola de valores.
        // Uno o varios da igual — el mismo mecanismo los recorre.
        $editable = $this->editableFields($entity);
        $queue = [];

        foreach (array_keys($fieldMatches) as $field) {
            $queue[$field] = $editable[$field]['label'] ?? $field;
        }

        $data['queue'] = $queue;
        $data['changes'] = [];
        unset($data['field'], $data['field_label']);

        return $this->updateNextValueStep($data, $entityName, $entity, $userId);
    }

    /**
     * Paso 2: el mensaje ES el valor nuevo. Se carga el registro por id
     * (ya resuelto, sin ambiguedad), se arma el diff con collectChanges
     * y se propone la accion con preview editable — igual que un update
     * directo, pero sin que el modelo tuviera que adivinar nada.
     *
     * @param  array<string, mixed>  $data
     */
    private function updateValueStep(string $question, array $data, int $userId): AssistantReply
    {
        if (preg_match('/\b(cancela\w*|cancelar|olvida\w*|stop|cancel)\b/iu', $question)) {
            return $this->cancelActiveForm($userId);
        }

        $entity = (string) ($data['entity_type'] ?? '');
        $record = $this->loadUpdateRecord($entity, (int) ($data['entity_id'] ?? 0), $userId);

        // Cola de campos pendientes: "nombre y teléfono" deja dos; un
        // update simple deja uno. El campo actual es el primero.
        $queue = is_array($data['queue'] ?? null) && $data['queue'] !== []
            ? $data['queue']
            : (isset($data['field']) && $data['field'] !== ''
                ? [$data['field'] => (string) ($data['field_label'] ?? $data['field'])]
                : []);

        if ($record === null || $queue === []) {
            $this->forms->clear($userId);

            return AssistantReply::say(__('assistant.actions.entity_missing'));
        }

        $field = (string) array_key_first($queue);
        $fieldLabel = (string) $queue[$field];
        $value = $this->cleanUpdateValue($question, $entity, $field);

        if ($value === '') {
            return $this->updateNextValuePrompt($data, $entity, $field, $fieldLabel, $userId);
        }

        $changes = is_array($data['changes'] ?? null) ? $data['changes'] : [];
        $changes[$field] = $value;
        unset($queue[$field]);

        if ($queue !== []) {
            $data['queue'] = $queue;
            $data['changes'] = $changes;
            $this->forms->put($userId, 'update_value', $data);

            return $this->updateNextValueStep($data, (string) ($data['entity_name'] ?? ''), $entity, $userId);
        }

        $this->forms->clear($userId);

        $collected = $this->collectChanges($changes, $entity, $record);

        if ($collected['params'] === []) {
            return AssistantReply::say(__('assistant.actions.no_changes'), [
                __('assistant.suggestions.portfolio'),
            ]);
        }

        return $this->updateProposalReply($entity, $record, $collected);
    }

    /**
     * Pide el valor del siguiente campo de la cola. El paso/total del
     * pendingForm reflejan cuantos valores faltan de verdad.
     *
     * @param  array<string, mixed>  $data
     */
    private function updateNextValueStep(array $data, string $entityName, string $entity, int $userId): AssistantReply
    {
        $queue = is_array($data['queue'] ?? null) ? $data['queue'] : [];
        $changes = is_array($data['changes'] ?? null) ? $data['changes'] : [];

        if ($queue === []) {
            return AssistantReply::say(__('assistant.actions.no_changes'));
        }

        $this->forms->put($userId, 'update_value', $data);

        $total = count($queue) + count($changes);
        $done = count($changes);
        $next = (string) array_key_first($queue);
        $fieldLabel = (string) $queue[$next];

        return new AssistantReply(
            text: $total > 1
                ? __('assistant.replies.update_value_prompt_step', [
                    'field' => $fieldLabel,
                    'entity' => $entityName,
                    'step' => $done + 1,
                    'total' => $total,
                ])
                : __('assistant.replies.update_value_prompt_full', [
                    'field' => $fieldLabel,
                    'entity' => $entityName,
                ]),
            suggestions: [],
            pendingForm: $this->updatePendingForm(
                'value',
                $entityName,
                $fieldLabel,
                $done + 1,
                $total,
                $this->updateFieldInput($entity, $next, $fieldLabel),
            ),
        );
    }

    /**
     * Repregunta el valor del campo actual cuando el mensaje llego vacio
     * o no se pudo interpretar.
     *
     * @param  array<string, mixed>  $data
     */
    private function updateNextValuePrompt(array $data, string $entity, string $field, string $fieldLabel, int $userId): AssistantReply
    {
        $queue = is_array($data['queue'] ?? null) ? $data['queue'] : [];
        $changes = is_array($data['changes'] ?? null) ? $data['changes'] : [];
        $total = count($queue) + count($changes);
        $done = count($changes);

        $this->forms->put($userId, 'update_value', $data);

        return new AssistantReply(
            text: __('assistant.replies.update_value_empty', [
                'field' => $fieldLabel,
            ]),
            suggestions: [],
            pendingForm: $this->updatePendingForm(
                'value',
                (string) ($data['entity_name'] ?? ''),
                $fieldLabel,
                $done + 1,
                max($total, 1),
                $this->updateFieldInput($entity, $field, $fieldLabel),
            ),
        );
    }

    // ---------------------------------------------------------------
    // Formularios de creacion (tenant/property/rental)
    // ---------------------------------------------------------------
    //
    // Cuando un create llega incompleto no se pide el dato por texto:
    // se abre el MISMO formulario dinamico del update pero vacio, con
    // los campos ya dados pre-llenados. A diferencia del wizard, este
    // form NO intercepta el chat: la IA sigue clasificando mensajes
    // normales y el formulario es una via paralela de diligenciar.

    /**
     * Abre el formulario de creacion con los campos que el modelo ya
     * extrajo pre-llenados; los faltantes quedan vacios.
     *
     * @param  array<string, mixed>  $params
     */
    private function startCreateForm(string $entity, array $params, int $userId): AssistantReply
    {
        $fields = $this->createFields($entity);

        if ($fields === []) {
            return AssistantReply::say(__('assistant.unknown'));
        }

        // Conserva lo ya diligenciado en envios anteriores del form:
        // cada intento de creacion vuelve a abrirlo con lo acumulado.
        $previous = $this->forms->getCreate($userId);
        $known = array_merge(
            $previous !== null && $previous['entity'] === $entity ? $previous['values'] : [],
            $this->normalizeCreateValues($entity, $params),
        );

        $this->forms->putCreate($userId, $entity, $known);

        if ($entity === 'rental') {
            $this->applyRentalFieldRequirements($fields, $known, $userId);
        }

        foreach ($fields as &$field) {
            $field['value'] = (string) ($known[$field['key']] ?? '');
        }
        unset($field);

        $entityLabel = __('assistant.fields.'.$entity);

        return new AssistantReply(
            text: __('assistant.replies.create_form_prompt', ['entity' => $entityLabel]),
            suggestions: [],
            pendingForm: [
                'form' => 'create_'.$entity,
                'form_label' => __('assistant.form_names.create_'.$entity),
                'step' => 1,
                'total_steps' => 1,
                'prompt' => __('assistant.replies.create_form_prompt', ['entity' => $entityLabel]),
                'fields' => $fields,
            ],
        );
    }

    /**
     * Campos del formulario de creacion por entidad: tipos reales y
     * opciones de enum, en el orden en que tiene sentido diligenciar.
     *
     * @return list<array<string, mixed>>
     */
    private function createFields(string $entity): array
    {
        return match ($entity) {
            'tenant' => [
                ['key' => 'name', 'label' => __('assistant.fields.name'), 'kind' => 'text', 'required' => true],
                ['key' => 'document', 'label' => __('assistant.fields.document'), 'kind' => 'text', 'required' => true],
                ['key' => 'phone_number', 'label' => __('assistant.fields.phone'), 'kind' => 'tel', 'required' => true],
                ['key' => 'email', 'label' => __('assistant.fields.email'), 'kind' => 'email', 'required' => false],
            ],
            'property' => [
                ['key' => 'name', 'label' => __('assistant.fields.name'), 'kind' => 'text', 'required' => true],
                ['key' => 'address', 'label' => __('assistant.fields.address'), 'kind' => 'text', 'required' => true],
            ],
            'rental' => [
                ['key' => 'tenant_name', 'label' => __('assistant.fields.tenant'), 'kind' => 'text', 'required' => true],
                ['key' => 'property_name', 'label' => __('assistant.fields.property'), 'kind' => 'text', 'required' => true],
                ['key' => 'monthly_amount', 'label' => __('assistant.fields.monthly_amount'), 'kind' => 'number', 'required' => true, 'min' => 0],
                ['key' => 'total_months', 'label' => __('assistant.fields.total_months'), 'kind' => 'number', 'required' => false, 'min' => 1, 'max' => 120],
                ['key' => 'start_date', 'label' => __('assistant.fields.start_date'), 'kind' => 'date', 'required' => false],
                ['key' => 'billing_cadence', 'label' => __('assistant.fields.billing_cadence'), 'kind' => 'select', 'required' => false, 'options' => \App\Enums\BillingCadence::options()],
                ['key' => 'rental_type', 'label' => __('assistant.fields.rental_type'), 'kind' => 'select', 'required' => false, 'options' => \App\Enums\RentalType::options()],
                ['key' => 'tenant_document', 'label' => __('assistant.fields.tenant_document'), 'kind' => 'text', 'required' => false],
                ['key' => 'tenant_phone', 'label' => __('assistant.fields.phone'), 'kind' => 'tel', 'required' => false],
                ['key' => 'property_address', 'label' => __('assistant.fields.address'), 'kind' => 'text', 'required' => false],
            ],
            default => [],
        };
    }

    /**
     * En el form de arriendo, los datos del inquilino/propiedad solo son
     * obligatorios cuando la entidad NO existe y habra que crearla:
     * si ya esta registrada esos campos son opcionales (se ignoran).
     *
     * @param  list<array<string, mixed>>  $fields
     * @param  array<string, mixed>  $known
     */
    private function applyRentalFieldRequirements(array &$fields, array $known, int $userId): void
    {
        $tenantName = trim((string) ($known['tenant_name'] ?? ''));
        $propertyName = trim((string) ($known['property_name'] ?? ''));

        $needsTenant = $tenantName !== ''
            && $this->findEntity(Tenant::class, $userId, $tenantName) === null;
        $needsProperty = $propertyName !== ''
            && $this->findEntity(Property::class, $userId, $propertyName) === null;

        foreach ($fields as &$field) {
            $field['required'] = match ($field['key']) {
                'tenant_name', 'property_name', 'monthly_amount' => true,
                'tenant_document', 'tenant_phone' => $needsTenant,
                'property_address' => $needsProperty,
                default => false,
            };
        }
        unset($field);
    }

    /**
     * Traduce los params crudos del modelo a las llaves canonicas del
     * formulario de creacion (las mismas que leen los propose_*).
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function normalizeCreateValues(string $entity, array $params): array
    {
        $map = match ($entity) {
            'tenant' => [
                'name' => ['name', 'tenant_name'],
                'document' => ['document', 'tenant_document', 'cedula'],
                'phone_number' => ['phone_number', 'phone', 'tenant_phone', 'telefono', 'celular'],
                'email' => ['email', 'correo'],
            ],
            'property' => [
                'name' => ['name', 'property_name'],
                'address' => ['address', 'property_address', 'direccion'],
            ],
            'rental' => [
                'tenant_name' => ['tenant_name', 'tenant', 'inquilino'],
                'property_name' => ['property_name', 'property', 'propiedad'],
                'monthly_amount' => ['monthly_amount', 'amount', 'canon'],
                'total_months' => ['total_months', 'months', 'duracion'],
                'start_date' => ['start_date', 'fecha_inicio'],
                'billing_cadence' => ['billing_cadence', 'cadence', 'frecuencia'],
                'rental_type' => ['rental_type', 'tipo_arriendo'],
                'tenant_document' => ['tenant_document', 'document', 'cedula'],
                'tenant_phone' => ['tenant_phone', 'phone', 'phone_number', 'telefono'],
                'property_address' => ['property_address', 'address', 'direccion'],
            ],
            default => [],
        };

        $values = [];

        foreach ($map as $key => $aliases) {
            foreach ($aliases as $alias) {
                $raw = $params[$alias] ?? null;

                if ($raw !== null && ! is_array($raw) && trim((string) $raw) !== '') {
                    $values[$key] = is_bool($raw) ? $raw : trim((string) $raw);

                    break;
                }
            }
        }

        return $values;
    }

    /**
     * Punto de entrada del formulario dinamico: decide si el envio es
     * del form de creacion o del de actualizacion.
     *
     * @param  array<string, mixed>  $values
     */
    public function submitForm(array $values, int $userId): AssistantReply
    {
        if ($this->forms->getCreate($userId) !== null) {
            return $this->submitCreateForm($values, $userId);
        }

        return $this->submitUpdateForm($values, $userId);
    }

    /**
     * Recibe el formulario de creacion diligenciado, fusiona con lo ya
     * guardado y re-ejecuta la propuesta: si aun falta algo, el form se
     * re-abre con lo acumulado; si esta completo, sale la accion.
     *
     * @param  array<string, mixed>  $values
     */
    public function submitCreateForm(array $values, int $userId): AssistantReply
    {
        $session = $this->forms->getCreate($userId);

        if ($session === null) {
            return AssistantReply::say(__('assistant.replies.form_none'));
        }

        $entity = $session['entity'];
        $merged = $session['values'];
        $keys = array_column($this->createFields($entity), 'key');

        foreach ($values as $key => $value) {
            if (! in_array($key, $keys, true) || is_array($value)) {
                continue;
            }

            $v = trim((string) $value);

            if ($v !== '') {
                $merged[$key] = $v;
            }
        }

        $this->forms->clearCreate($userId);

        return match ($entity) {
            'tenant' => $this->proposeCreateTenant($merged, $userId),
            'property' => $this->proposeCreateProperty($merged, $userId),
            'rental' => $this->proposeCreateRental($merged, $userId),
            default => AssistantReply::say(__('assistant.unknown')),
        };
    }

    /**
     * Busca en el texto del usuario un campo editable de la entidad:
     * por nombre canonico, alias o etiqueta traducida. Gana el alias
     * mas largo para que "correo electronico" no pierda contra "correo".
     */
    private function matchEditableField(string $question, string $entity): ?string
    {
        $matches = $this->matchEditableFields($question, $entity);

        return $matches === [] ? null : array_key_first($matches);
    }

    /**
     * TODOS los campos editables que el usuario nombro en el texto, en el
     * orden en que aparecen ("nombre y teléfono" -> [name, phone_number]).
     * Es lo que permite que el wizard pida varios valores en una sola
     * actualizacion en vez de quedarse con el primero.
     *
     * @return array<string, int> field => posicion del alias en el texto
     */
    private function matchEditableFields(string $question, string $entity): array
    {
        $haystack = ' '.preg_replace('/\s+/u', ' ', Str::lower(Str::ascii($question))).' ';
        $matches = [];

        foreach ($this->editableFields($entity) as $field => $meta) {
            $bestPos = null;

            foreach (array_merge($meta['aliases'], [$field, Str::lower(Str::ascii($meta['label']))]) as $candidate) {
                $needle = Str::lower(Str::ascii((string) $candidate));

                if (mb_strlen($needle) < 3 || $needle === '') {
                    continue;
                }

                $pos = $this->aliasPosition($haystack, $needle);

                if ($pos !== null && ($bestPos === null || $pos < $bestPos)) {
                    $bestPos = $pos;
                }
            }

            if ($bestPos !== null) {
                $matches[$field] = $bestPos;
            }
        }

        asort($matches);

        return $matches;
    }

    /**
     * Posicion del alias dentro del texto (palabra completa), o null.
     */
    private function aliasPosition(string $haystack, string $needle): ?int
    {
        if (str_contains($haystack, ' '.$needle.' ')
            || str_contains($haystack, ' '.$needle.',')
            || str_contains($haystack, ' '.$needle.'.')) {
            $pos = strpos($haystack, ' '.$needle);

            return $pos === false ? null : $pos;
        }

        if (preg_match('/(?<!\w)'.preg_quote($needle, '/').'(?!\w)/u', $haystack, $m, PREG_OFFSET_CAPTURE) === 1) {
            return (int) $m[0][1];
        }

        return null;
    }

    /**
     * Limpia el valor nuevo de muletillas: "el nombre es Juan",
     * "telefono: 300...", "el nuevo canon a $1.200.000" -> el valor.
     */
    private function cleanUpdateValue(string $question, string $entity, string $field): string
    {
        $value = trim($question);

        $aliases = $this->editableFields($entity)[$field]['aliases'] ?? [$field];
        $aliasGroup = implode('|', array_map(
            fn (string $a): string => preg_quote(Str::lower(Str::ascii($a)), '/'),
            $aliases,
        ));

        // "<alias> <es|a|:|=>": separador obligatorio para no comerse
        // valores que casualmente empiezan con el nombre del campo
        // ("Nombre Guiado" no pierde su primera palabra). Se opera
        // sobre el original para conservar mayusculas y tildes.
        $aliasLed = preg_replace(
            '/^(?:'.$aliasGroup.')\s*(?:[:=]\s*|\b(?:es|sea|seria|será|a)\b\s+)/iu',
            '',
            $value,
            1,
        );

        if (is_string($aliasLed) && trim($aliasLed) !== '' && $aliasLed !== $value) {
            return trim($aliasLed);
        }

        // Articulo/muletillas opcionales + alias opcional + copula:
        // "el nombre es Juan", "el nuevo telefono es 3001234567".
        return trim((string) preg_replace(
            '/^(?:(?:el|la|los|las|un|una)\s+)?(?:(?:'.$aliasGroup.'|nuevo|nueva|valor|cambio)\s+)*(?:es|sea|seria|será|a|=|:)\s*/iu',
            '',
            $value,
        ));
    }

    private function updateIdKey(string $entity): ?string
    {
        return match ($entity) {
            'tenant' => 'tenant_id',
            'property' => 'property_id',
            'rental' => 'rental_id',
            default => null,
        };
    }

    private function loadUpdateRecord(string $entity, int $id, int $userId): ?object
    {
        if ($id <= 0) {
            return null;
        }

        return match ($entity) {
            'tenant' => Tenant::query()->where('user_id', $userId)->find($id),
            'property' => Property::query()->where('user_id', $userId)->find($id),
            'rental' => Rental::query()->where('user_id', $userId)->find($id),
            default => null,
        };
    }

    private function updateEntityLabel(string $entity, object $record): string
    {
        return (string) ($record->name ?? $record->business_name ?? '');
    }

    /**
     * Tarjeta de propuesta identica a la de un update directo: la
     * confirmacion y la edicion inline funcionan igual.
     *
     * @param  array{params: array<string, mixed>, changes: list<array<string, mixed>>, editable: list<array<string, mixed>>, labels: list<string>}  $collected
     */
    private function updateProposalReply(string $entity, object $record, array $collected): AssistantReply
    {
        $idKey = $this->updateIdKey($entity);
        $name = $this->updateEntityLabel($entity, $record);
        $type = 'update_'.$entity;

        $textKey = match ($entity) {
            'tenant' => 'propose_update_tenant',
            'property' => 'propose_update_property',
            'rental' => 'propose_update_rental',
            default => 'propose_update_tenant',
        };

        $paramKey = match ($entity) {
            'tenant' => 'tenant',
            'property' => 'property',
            'rental' => 'rental',
            default => 'tenant',
        };

        return new AssistantReply(
            text: __("assistant.replies.{$textKey}", [
                $paramKey => $name,
                'fields' => implode(', ', $collected['labels']),
            ]),
            action: [
                'type' => $type,
                'params' => [$idKey => (int) $record->getKey()] + $collected['params'],
                'changes' => $collected['changes'],
                'editable' => $collected['editable'],
                'warnings' => [],
                'summary' => __("assistant.actions.update_{$entity}_summary", [$paramKey => $name]),
            ],
            suggestions: [],
        );
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function proposeUpdateTenant(array $params, int $userId): AssistantReply
    {
        // Claves dedicadas primero; "name" queda al final porque tambien
        // es alias del campo nombre — si hay tenant_name/entity_name el
        // "name" se interpreta como valor nuevo, no como entidad.
        $name = trim((string) ($this->param($params, [
            'tenant_name', 'entity_name', 'entity', 'inquilino', 'inquilina', 'arrendatario', 'name',
        ]) ?? ''));

        if ($name === '') {
            return AssistantReply::say(__('assistant.replies.need_tenant_name'), [
                __('assistant.suggestions.tenants'),
            ]);
        }

        // "cualquiera", "una aleatoria", "la primera": el usuario delega
        // la eleccion — se toma una al azar y la advertencia queda en el
        // preview para que confirme a sabiendas.
        $randomPick = $this->isRandomPick($name);
        $tenant = $randomPick
            ? Tenant::query()->where('user_id', $userId)->inRandomOrder()->first()
            : null;

        if ($tenant === null) {
            $resolved = $this->resolveEntity(Tenant::class, $userId, $name, ['name', 'document', 'phone_number', 'email']);

            if ($resolved['entity'] === null) {
                return $resolved['candidates'] !== []
                    ? $this->ambiguousReply($name, $resolved['candidates'], 'tenants', [
                        'intent' => 'update_tenant',
                        'params' => $params,
                        'name_key' => 'tenant_name',
                    ])
                    : AssistantReply::say(__('assistant.replies.entity_not_found', ['name' => $name]));
            }

            $tenant = $resolved['entity'];
        }

        $collected = $this->collectChanges($params, 'tenant', $tenant);

        if ($collected['params'] === []) {
            return $this->startUpdateClarify('tenant', $tenant, $userId);
        }

        return new AssistantReply(
            text: __('assistant.replies.propose_update_tenant', [
                'tenant' => $tenant->name,
                'fields' => implode(', ', $collected['labels']),
            ]),
            action: [
                'type' => 'update_tenant',
                'params' => ['tenant_id' => (int) $tenant->id] + $collected['params'],
                'changes' => $collected['changes'],
                'editable' => $collected['editable'],
                'warnings' => $randomPick ? [__('assistant.warnings.random_pick')] : [],
                'summary' => __('assistant.actions.update_tenant_summary', ['tenant' => $tenant->name]),
            ],
            suggestions: [],
        );
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function proposeUpdateProperty(array $params, int $userId): AssistantReply
    {
        $name = trim((string) ($this->param($params, [
            'property_name', 'entity_name', 'entity', 'propiedad', 'inmueble', 'name',
        ]) ?? ''));

        if ($name === '') {
            return AssistantReply::say(__('assistant.replies.need_property_name'), [
                __('assistant.suggestions.properties'),
            ]);
        }

        $randomPick = $this->isRandomPick($name);
        $property = $randomPick
            ? Property::query()->where('user_id', $userId)->inRandomOrder()->first()
            : null;

        if ($property === null) {
            $resolved = $this->resolveEntity(Property::class, $userId, $name, ['name', 'address']);

            if ($resolved['entity'] === null) {
                return $resolved['candidates'] !== []
                    ? $this->ambiguousReply($name, $resolved['candidates'], 'properties', [
                        'intent' => 'update_property',
                        'params' => $params,
                        'name_key' => 'property_name',
                    ])
                    : AssistantReply::say(__('assistant.replies.entity_not_found', ['name' => $name]));
            }

            $property = $resolved['entity'];
        }

        $collected = $this->collectChanges($params, 'property', $property);

        if ($collected['params'] === []) {
            return $this->startUpdateClarify('property', $property, $userId);
        }

        return new AssistantReply(
            text: __('assistant.replies.propose_update_property', [
                'property' => $property->name,
                'fields' => implode(', ', $collected['labels']),
            ]),
            action: [
                'type' => 'update_property',
                'params' => ['property_id' => (int) $property->id] + $collected['params'],
                'changes' => $collected['changes'],
                'editable' => $collected['editable'],
                'warnings' => $randomPick ? [__('assistant.warnings.random_pick')] : [],
                'summary' => __('assistant.actions.update_property_summary', ['property' => $property->name]),
            ],
            suggestions: [],
        );
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function proposeUpdateRental(array $params, int $userId): AssistantReply
    {
        // "cualquiera"/"una aleatoria": se toma un arriendo al azar y la
        // advertencia queda visible en el preview antes de confirmar.
        $randomPickName = trim((string) ($this->param($params, [
            'rental_name', 'tenant', 'name', 'entity', 'arriendo', 'alquiler', 'contrato',
        ]) ?? ''));
        $randomPick = $this->isRandomPick($randomPickName);

        $resolved = ['entity' => null, 'candidates' => []];

        if ($randomPick) {
            $resolved['entity'] = Rental::query()->where('user_id', $userId)->where('is_active', true)->inRandomOrder()->first();
        } elseif ($randomPickName !== '') {
            // Candidatos antes que findRental: con homonimos (dos Camila)
            // el update NO debe elegir en silencio — se listan y el
            // usuario elige con el chip.
            $resolved = $this->resolveRentalCandidates($randomPickName, $userId);
        }

        $rental = $resolved['entity'] ?? $this->findRental($params, $userId);

        if ($resolved['candidates'] !== []) {
            return $this->ambiguousReply($randomPickName, $resolved['candidates'], 'rentals', [
                'intent' => 'update_rental',
                'params' => $params,
                'name_key' => 'rental_name',
            ]);
        }

        if ($rental === null) {
            $name = trim((string) ($params['rental_name'] ?? $params['tenant'] ?? ''));

            if ($name !== '') {
                $resolved = $this->resolveRentalCandidates($name, $userId);

                if ($resolved['candidates'] !== []) {
                    return $this->ambiguousReply($name, $resolved['candidates'], 'rentals', [
                        'intent' => 'update_rental',
                        'params' => $params,
                        'name_key' => 'rental_name',
                    ]);
                }
            }

            return AssistantReply::say(__('assistant.replies.need_rental_name'), [
                __('assistant.suggestions.rentals'),
            ]);
        }

        $collected = $this->collectChanges($params, 'rental', $rental);

        if ($collected['params'] === []) {
            return $this->startUpdateClarify('rental', $rental, $userId);
        }

        // Cambiar el canon o las fechas afecta la cadena de reajustes:
        // se avisa en el preview para que la confirmacion sea informada.
        $warnings = [];

        if (isset($collected['params']['monthly_amount']) || isset($collected['params']['start_date']) || isset($collected['params']['end_date'])) {
            $warnings[] = __('assistant.warnings.rental_dates');
        }

        if ($randomPick) {
            $warnings[] = __('assistant.warnings.random_pick');
        }

        if (isset($collected['params']['is_active']) && $collected['params']['is_active'] === false) {
            $warnings[] = __('assistant.warnings.rental_deactivate');
        }

        return new AssistantReply(
            text: __('assistant.replies.propose_update_rental', [
                'rental' => $rental->name,
                'fields' => implode(', ', $collected['labels']),
            ]),
            action: [
                'type' => 'update_rental',
                'params' => ['rental_id' => (int) $rental->id] + $collected['params'],
                'changes' => $collected['changes'],
                'editable' => $collected['editable'],
                'warnings' => $warnings,
                'summary' => __('assistant.actions.update_rental_summary', ['rental' => $rental->name]),
            ],
            suggestions: [],
        );
    }

    // ---------------------------------------------------------------
    // Propuestas: ciclo de vida de reajustes
    // ---------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $params
     */
    private function proposeRecordAdjustment(array $params, int $userId): AssistantReply
    {
        $name = trim((string) ($this->param($params, [
            'rental_name', 'tenant', 'name', 'entity', 'arriendo',
            'alquiler', 'contrato', 'inquilino', 'rental', 'property_name',
        ]) ?? ''));

        $resolved = $name !== '' ? $this->resolveRentalCandidates($name, $userId) : ['entity' => null, 'candidates' => []];
        $rental = $resolved['entity'] ?? $this->findRental($params, $userId);

        if ($resolved['candidates'] !== []) {
            return $this->ambiguousReply($name, $resolved['candidates'], 'adjustments', [
                'intent' => 'record_adjustment',
                'params' => $params,
                'name_key' => 'rental_name',
            ]);
        }

        if ($rental === null) {
            return AssistantReply::say(__('assistant.replies.need_rental_name'), [
                __('assistant.suggestions.adjustments'),
            ]);
        }

        $effectiveFrom = $this->parseDate($params['effective_from'] ?? $params['fecha'] ?? $params['desde'] ?? null)
            ?? Carbon::parse($rental->suggestedAdjustmentDate())->toDateString();

        // new_rent explicito o porcentaje pactado/IPC para derivarlo.
        $newRent = isset($params['new_rent']) && is_numeric($params['new_rent'])
            ? round((float) $params['new_rent'], 2)
            : null;

        $basisRaw = Str::lower((string) ($params['basis'] ?? ''));
        $basis = str_contains($basisRaw, 'ciudad') || str_contains($basisRaw, 'city')
            ? \App\Enums\IpcBasis::City
            : \App\Enums\IpcBasis::National;

        $pactado = isset($params['pactado_percentage']) && is_numeric($params['pactado_percentage'])
            ? (float) $params['pactado_percentage']
            : null;

        $current = $this->rentals->currentRentFor($rental);

        if ($newRent === null) {
            try {
                $snapshot = $this->rentals->previewAdjustmentIpc(
                    rental: $rental,
                    effectiveFrom: Carbon::parse($effectiveFrom),
                    basis: $basis,
                    geographyCode: $rental->property?->geography_code,
                    pactadoPercentage: $pactado,
                );
                $newRent = round($current * (1 + $snapshot->percentage / 100), 2);
            } catch (\Throwable $exception) {
                return AssistantReply::say(__('assistant.replies.ipc_preview_failed', [
                    'error' => $exception->getMessage(),
                ]), [__('assistant.suggestions.adjustments')]);
            }
        }

        // Las reglas legales se evaluan ANTES de proponer: el preview ya
        // muestra si el reajuste excede el tope o rompe la cadena.
        try {
            $validation = $this->rentals->validateAdjustment(
                rental: $rental,
                effectiveFrom: Carbon::parse($effectiveFrom),
                requestedRent: $newRent,
                basis: $basis,
                geographyCode: $rental->property?->geography_code,
                pactadoPercentage: $pactado,
                notes: $this->param($params, ['notes', 'notas', 'motivo']),
            );
        } catch (\Throwable) {
            $validation = null;
        }

        $warnings = [];

        if ($validation !== null) {
            foreach ($validation['messages'] as $message) {
                if (! is_array($message)) {
                    $warnings[] = (string) $message;

                    continue;
                }

                $severity = (string) ($message['severity'] ?? 'info');
                $prefix = match ($severity) {
                    'blocking' => '⛔ '.__('assistant.severity.blocking'),
                    'warning' => '⚠ '.__('assistant.severity.warning'),
                    default => 'ℹ '.__('assistant.severity.info'),
                };

                $warnings[] = $prefix.' '.(string) ($message['message'] ?? '');
            }
        }

        $changes = [
            $this->changeRow('rental', __('assistant.fields.rental'), null, $rental->name, 'text'),
            $this->changeRow('effective_from', __('assistant.fields.effective_from'), null, $effectiveFrom, 'date'),
            $this->changeRow('previous_rent', __('assistant.fields.previous_rent'), null, $this->money($current), 'money'),
            $this->changeRow('new_rent', __('assistant.fields.new_rent'), null, $this->money($newRent), 'money'),
        ];

        return new AssistantReply(
            text: __('assistant.replies.propose_record_adjustment', [
                'rental' => $rental->name,
                'date' => $effectiveFrom,
                'new' => $this->money($newRent),
            ]),
            action: [
                'type' => 'record_adjustment',
                'params' => [
                    'rental_id' => (int) $rental->id,
                    'effective_from' => $effectiveFrom,
                    'new_rent' => $newRent,
                    'basis' => $basis->value,
                    'geography_code' => $rental->property?->geography_code,
                    'pactado_percentage' => $pactado,
                    'notes' => $this->param($params, ['notes', 'notas', 'motivo']),
                ],
                'changes' => $changes,
                'editable' => [
                    ['key' => 'effective_from', 'param' => 'effective_from', 'label' => __('assistant.fields.effective_from'), 'kind' => 'date', 'value' => $effectiveFrom],
                    ['key' => 'new_rent', 'param' => 'new_rent', 'label' => __('assistant.fields.new_rent'), 'kind' => 'number', 'value' => (string) $newRent],
                    ['key' => 'notes', 'param' => 'notes', 'label' => __('assistant.fields.notes'), 'kind' => 'textarea', 'value' => (string) ($params['notes'] ?? '')],
                ],
                'warnings' => $warnings,
                'summary' => __('assistant.actions.record_adjustment_summary', [
                    'rental' => $rental->name,
                    'new' => $this->money($newRent),
                ]),
            ],
            suggestions: [],
        );
    }

    /**
     * Resuelve un RentAdjustment por id explicito o "el ultimo del arriendo".
     *
     * @param  array<string, mixed>  $params
     */
    private function findAdjustment(array $params, int $userId): ?RentAdjustment
    {
        // El modelo no tiene numero de factura: el usuario lo referencia
        // por id (el que ve en la tabla) o por "el N" del arriendo, que
        // en la UI es sequence.
        $id = (int) ($params['adjustment_id'] ?? $params['id'] ?? 0);
        $number = (int) ($params['number'] ?? $params['adjustment_number'] ?? $params['numero'] ?? 0);

        if ($id > 0) {
            return RentAdjustment::query()->where('user_id', $userId)->find($id);
        }

        $rental = $this->findRental($params, $userId);

        if ($number > 0 && $rental !== null) {
            $bySequence = RentAdjustment::query()
                ->where('user_id', $userId)
                ->where('rental_id', $rental->id)
                ->where('sequence', $number)
                ->first();

            if ($bySequence !== null) {
                return $bySequence;
            }
        }

        return RentAdjustment::query()
            ->where('user_id', $userId)
            ->when($rental !== null, fn ($q) => $q->where('rental_id', $rental->id))
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function proposeApplyAdjustment(array $params, int $userId): AssistantReply
    {
        $adjustment = $this->findAdjustment($params, $userId);

        if ($adjustment === null) {
            return AssistantReply::say(__('assistant.replies.need_adjustment'), [
                __('assistant.suggestions.adjustments'),
            ]);
        }

        if (! $adjustment->canApply()) {
            return AssistantReply::say(__('assistant.replies.adjustment_cannot_apply', [
                'state' => $adjustment->state()->label(),
            ]), [__('assistant.suggestions.adjustments')]);
        }

        $adjustment->load('rental:id,name');

        return new AssistantReply(
            text: __('assistant.replies.propose_apply_adjustment', [
                'rental' => $adjustment->rental?->name ?? '—',
                'new' => $this->money((float) $adjustment->new_rent),
                'date' => $adjustment->effective_from?->toDateString() ?? '—',
            ]),
            action: [
                'type' => 'apply_adjustment',
                'params' => ['adjustment_id' => (int) $adjustment->id],
                'changes' => [
                    $this->changeRow('rental', __('assistant.fields.rental'), null, $adjustment->rental?->name, 'text'),
                    $this->changeRow('new_rent', __('assistant.fields.new_rent'), $this->money((float) $adjustment->previous_rent), $this->money((float) $adjustment->new_rent), 'money'),
                    $this->changeRow('effective_from', __('assistant.fields.effective_from'), null, $adjustment->effective_from?->toDateString(), 'date'),
                ],
                'summary' => __('assistant.actions.apply_adjustment_summary'),
            ],
            suggestions: [],
        );
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function proposeMarkAdjustmentNotified(array $params, int $userId): AssistantReply
    {
        $adjustment = $this->findAdjustment($params, $userId);

        if ($adjustment === null) {
            return AssistantReply::say(__('assistant.replies.need_adjustment'), [
                __('assistant.suggestions.adjustments'),
            ]);
        }

        if (! $adjustment->canNotify()) {
            return AssistantReply::say(__('assistant.replies.adjustment_cannot_notify', [
                'state' => $adjustment->state()->label(),
            ]), [__('assistant.suggestions.adjustments')]);
        }

        $notifiedOn = $this->parseDate($params['notified_on'] ?? $params['fecha'] ?? null)
            ?? today()->toDateString();

        $methodRaw = Str::lower((string) ($params['method'] ?? $params['notification_method'] ?? $params['metodo'] ?? ''));
        $method = match (true) {
            str_contains($methodRaw, 'correo') || str_contains($methodRaw, 'email') => \App\Enums\NotificationMethod::Email,
            str_contains($methodRaw, 'cart') || str_contains($methodRaw, 'postal') => \App\Enums\NotificationMethod::Postal,
            str_contains($methodRaw, 'presencial') || str_contains($methodRaw, 'persona') => \App\Enums\NotificationMethod::InPerson,
            str_contains($methodRaw, 'contrato') || str_contains($methodRaw, 'contractual') => \App\Enums\NotificationMethod::Contractual,
            default => \App\Enums\NotificationMethod::Contractual,
        };

        $adjustment->load('rental:id,name');

        return new AssistantReply(
            text: __('assistant.replies.propose_mark_notified', [
                'rental' => $adjustment->rental?->name ?? '—',
                'date' => $notifiedOn,
                'method' => $method->label(),
            ]),
            action: [
                'type' => 'mark_adjustment_notified',
                'params' => [
                    'adjustment_id' => (int) $adjustment->id,
                    'notified_on' => $notifiedOn,
                    'method' => $method->value,
                ],
                'changes' => [
                    $this->changeRow('notified_on', __('assistant.fields.notified_on'), $adjustment->notified_on?->toDateString(), $notifiedOn, 'date'),
                    $this->changeRow('method', __('assistant.fields.notification_method'), $adjustment->notification_method?->label() ?? null, $method->label(), 'text'),
                ],
                'summary' => __('assistant.actions.mark_notified_summary'),
            ],
            suggestions: [],
        );
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function proposeRecalculateAdjustments(array $params, int $userId): AssistantReply
    {
        $rental = $this->findRental($params, $userId);

        if ($rental === null) {
            return AssistantReply::say(__('assistant.replies.need_rental_name'), [
                __('assistant.suggestions.adjustments'),
            ]);
        }

        $count = $rental->rentAdjustments()->count();

        return new AssistantReply(
            text: __('assistant.replies.propose_recalculate', [
                'rental' => $rental->name,
                'count' => $count,
            ]),
            action: [
                'type' => 'recalculate_adjustments',
                'params' => ['rental_id' => (int) $rental->id],
                'warnings' => [__('assistant.warnings.recalculate_chain')],
                'summary' => __('assistant.actions.recalculate_summary', ['rental' => $rental->name]),
            ],
            suggestions: [],
        );
    }

    // ---------------------------------------------------------------
    // Propuestas: facturas y documentos
    // ---------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $params
     */
    private function proposeAnnulInvoice(array $params, int $userId): AssistantReply
    {
        $invoice = $this->findInvoice($params, $userId);

        if ($invoice === null) {
            return AssistantReply::say(__('assistant.replies.need_invoice'), [
                __('assistant.suggestions.invoices'),
            ]);
        }

        if ($invoice->status === InvoiceStatus::ANULADA) {
            return AssistantReply::say(__('assistant.replies.invoice_already_void', [
                'number' => $invoice->number,
            ]), [__('assistant.suggestions.invoices')]);
        }

        $warnings = [__('assistant.warnings.annul_invoice')];

        if ($invoice->paidAmount() > 0) {
            $warnings[] = __('assistant.warnings.annul_has_payments', [
                'amount' => $this->money($invoice->paidAmount()),
            ]);
        }

        $invoice->load('rental:id,name,tenant_id', 'rental.tenant:id,name');

        return new AssistantReply(
            text: __('assistant.replies.propose_annul_invoice', [
                'number' => $invoice->number,
                'tenant' => $invoice->rental?->tenant?->name ?? '—',
                'amount' => $this->money((float) $invoice->amount),
            ]),
            action: [
                'type' => 'annul_invoice',
                'params' => ['invoice_id' => (int) $invoice->id],
                'changes' => [
                    $this->changeRow('status', __('assistant.fields.status'), $invoice->status->label(), InvoiceStatus::ANULADA->label(), 'text'),
                ],
                'warnings' => $warnings,
                'summary' => __('assistant.actions.annul_invoice_summary', ['number' => $invoice->number]),
            ],
            suggestions: [],
        );
    }

    /**
     * Factura por numero o id, con scope de usuario.
     *
     * @param  array<string, mixed>  $params
     */
    private function findInvoice(array $params, int $userId): ?Invoice
    {
        // Eleccion desde la lista de candidatos: manda sobre el numero.
        $byRef = $this->entityFromRef(Invoice::class, $userId);

        if ($byRef !== null) {
            return $byRef;
        }

        $id = (int) ($params['invoice_id'] ?? 0);

        if ($id > 0) {
            return Invoice::query()->where('user_id', $userId)->find($id);
        }

        $number = trim((string) ($params['number'] ?? $params['invoice_number'] ?? $params['factura'] ?? ''));

        if ($number === '') {
            return null;
        }

        return Invoice::query()
            ->where('user_id', $userId)
            ->get()
            ->first(fn (Invoice $invoice): bool => Str::contains(Str::lower($invoice->number), Str::lower($number)));
    }

    /**
     * Genera un documento del catalogo (factura, comprobante, carta de
     * reajuste, estado de cuenta) en el formato pedido. La confirmacion
     * produce el archivo; el preview del modal muestra metadatos.
     *
     * @param  array<string, mixed>  $params
     */
    private function proposeGenerateDocument(array $params, int $userId): AssistantReply
    {
        // Gemini manda doc_type con valores canonicos; el matcher local y
        // el usuario libre usan las palabras en espanol. Se aceptan ambos.
        $kindRaw = Str::lower((string) ($params['document_type'] ?? $params['doc_type'] ?? $params['tipo'] ?? $params['documento'] ?? ''));
        $format = Str::lower((string) ($params['format'] ?? $params['formato'] ?? 'text'));

        $kind = match (true) {
            $kindRaw === 'payment_receipt' || str_contains($kindRaw, 'receipt') || str_contains($kindRaw, 'comprobante') || str_contains($kindRaw, 'pago') => 'receipt',
            $kindRaw === 'adjustment_letter' || str_contains($kindRaw, 'carta') || str_contains($kindRaw, 'reajuste') || str_contains($kindRaw, 'adjustment') => 'adjustment_letter',
            $kindRaw === 'monthly_statement' || str_contains($kindRaw, 'estado') || str_contains($kindRaw, 'statement') || str_contains($kindRaw, 'cuenta') => 'statement',
            $kindRaw === 'rent_invoice' || str_contains($kindRaw, 'factura') || str_contains($kindRaw, 'invoice') || $kindRaw === '' => 'invoice',
            default => null,
        };

        if ($kind === null) {
            return AssistantReply::say(__('assistant.replies.unknown_document'), [
                __('assistant.suggestions.documents'),
            ]);
        }

        $resolved = $this->resolveDocumentTarget($kind, $params, $userId);

        if (isset($resolved['error'])) {
            return AssistantReply::say($resolved['error'], [
                __('assistant.suggestions.documents'),
            ]);
        }

        $target = $resolved['target'];
        $formats = $this->rentals->documentFormats();

        if (! isset($formats[$format])) {
            $format = array_key_first($formats) ?? 'text';
        }

        return new AssistantReply(
            text: __('assistant.replies.propose_document', [
                'document' => __('assistant.doc_types.'.$kind),
                'target' => $target['label'],
                'format' => $format,
            ]),
            action: [
                'type' => 'generate_document',
                'params' => [
                    'kind' => $kind,
                    'format' => $format,
                    'invoice_id' => $target['invoice_id'] ?? null,
                    'adjustment_id' => $target['adjustment_id'] ?? null,
                    'rental_id' => $target['rental_id'] ?? null,
                    'period' => $target['period'] ?? null,
                ],
                'summary' => __('assistant.actions.document_summary', [
                    'document' => __('assistant.doc_types.'.$kind),
                    'target' => $target['label'],
                ]),
            ],
            suggestions: [],
        );
    }

    /**
     * Resuelve la entidad sobre la que se genera el documento.
     *
     * @param  array<string, mixed>  $params
     * @return array{target?: array<string, mixed>, error?: string}
     */
    private function resolveDocumentTarget(string $kind, array $params, int $userId): array
    {
        if ($kind === 'adjustment_letter') {
            $adjustment = $this->findAdjustment($params, $userId);

            if ($adjustment === null) {
                return ['error' => __('assistant.replies.need_adjustment')];
            }

            $adjustment->load('rental:id,name');

            return ['target' => [
                'adjustment_id' => (int) $adjustment->id,
                'label' => $adjustment->rental?->name ?? '#'.$adjustment->id,
            ]];
        }

        if ($kind === 'statement') {
            $rental = $this->findRental($params, $userId);

            if ($rental === null) {
                return ['error' => __('assistant.replies.need_rental_name')];
            }

            $period = trim((string) ($params['period'] ?? $params['periodo'] ?? ''));
            $periodKey = $period !== '' ? $period : today()->format('Y-m');

            // Un estado de cuenta sin facturas en el periodo es un papel en
            // cero: mejor decirlo aqui que generar un documento vacio.
            if (! $rental->invoicesForPeriod($periodKey)->exists()) {
                return ['error' => __('assistant.replies.no_statement_data', [
                    'rental' => $rental->name,
                    'period' => $periodKey,
                ])];
            }

            return ['target' => [
                'rental_id' => (int) $rental->id,
                'period' => $periodKey,
                'label' => $rental->name.' '.$periodKey,
            ]];
        }

        // invoice / receipt: por numero de factura o, si solo dieron el
        // arriendo, la factura mas reciente (receipt: la ultima pagada).
        $invoice = $this->findInvoice($params, $userId);
        $rental = $this->findRental($params, $userId);

        if ($invoice === null && $rental !== null) {
            $invoice = Invoice::query()
                ->where('user_id', $userId)
                ->where('rental_id', $rental->id)
                ->when($kind === 'receipt',
                    fn ($q) => $q->where('status', InvoiceStatus::PAGADA),
                    fn ($q) => $q->whereIn('status', [InvoiceStatus::EMITIDA, InvoiceStatus::VENCIDA, InvoiceStatus::PAGADA]))
                ->orderByDesc($kind === 'receipt' ? 'paid_at' : 'issued_at')
                ->orderByDesc('id')
                ->first();
        }

        if ($invoice === null) {
            return ['error' => __('assistant.replies.need_invoice')];
        }

        $invoice->load('rental:id,name');

        return ['target' => [
            'invoice_id' => (int) $invoice->id,
            'rental_id' => $invoice->rental_id ? (int) $invoice->rental_id : null,
            'label' => $invoice->number,
        ]];
    }

    /**
     * Factura el periodo de UN arriendo (no el portafolio completo).
     *
     * @param  array<string, mixed>  $params
     */
    private function proposeBillSingleRental(array $params, int $userId): AssistantReply
    {
        $rental = $this->findRental($params, $userId);

        if ($rental === null) {
            return AssistantReply::say(__('assistant.replies.need_rental_name'), [
                __('assistant.suggestions.invoices'),
            ]);
        }

        $periodStart = $this->resolveBillingPeriod(
            $params['period'] ?? $params['periodo'] ?? $params['month'] ?? null,
        );

        if ($periodStart === null) {
            return AssistantReply::say(__('assistant.replies.need_period'), [
                __('assistant.suggestions.invoices'),
            ]);
        }

        return new AssistantReply(
            text: __('assistant.replies.propose_bill_rental', [
                'rental' => $rental->name,
                'period' => $periodStart->format('m/Y'),
            ]),
            action: [
                'type' => 'bill_rental_invoices',
                'params' => [
                    'rental_id' => (int) $rental->id,
                    'period' => $periodStart->format('Y-m'),
                ],
                'changes' => [
                    $this->changeRow('rental', __('assistant.fields.rental'), null, $rental->name, 'text'),
                    $this->changeRow('period', __('assistant.fields.period'), null, $periodStart->format('m/Y'), 'text'),
                ],
                'summary' => __('assistant.actions.bill_rental_summary', [
                    'rental' => $rental->name,
                    'period' => $periodStart->format('m/Y'),
                ]),
            ],
            suggestions: [],
        );
    }

    // ---------------------------------------------------------------
    // Propuestas: settings, IPC y exportacion
    // ---------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $params
     */
    private function proposeUpdateSettings(array $params, int $userId): AssistantReply
    {
        // Settings no son por usuario: son del negocio (fila unica). Se
        // comparan contra current() y se proponen solo los campos pedidos.
        $current = $this->settings->current();
        $collected = $this->collectChanges($params, 'settings', (object) $current);

        if ($collected['params'] === []) {
            return AssistantReply::say(__('assistant.replies.need_settings_changes'), [
                __('assistant.suggestions.settings'),
            ]);
        }

        return new AssistantReply(
            text: __('assistant.replies.propose_update_settings', [
                'fields' => implode(', ', $collected['labels']),
            ]),
            action: [
                'type' => 'update_settings',
                'params' => $collected['params'],
                'changes' => $collected['changes'],
                'editable' => $collected['editable'],
                'warnings' => [__('assistant.warnings.settings_legal')],
                'summary' => __('assistant.actions.update_settings_summary'),
            ],
            suggestions: [],
        );
    }

    private function proposeSyncIpc(int $userId): AssistantReply
    {
        return new AssistantReply(
            text: __('assistant.replies.propose_sync_ipc'),
            action: [
                'type' => 'sync_ipc',
                'params' => [],
                'summary' => __('assistant.actions.sync_ipc_summary'),
            ],
            suggestions: [__('assistant.suggestions.ipc_status'), __('assistant.suggestions.adjustments')],
        );
    }

    /**
     * Exporta a CSV la ultima tabla que el chat mostro. El payload viaja
     * en params para que la confirmacion exporte exactamente lo visto.
     *
     * @param  array<string, mixed>  $params
     */
    private function proposeExportCsv(array $params, int $userId): AssistantReply
    {
        $section = trim((string) ($params['section'] ?? ''));

        return new AssistantReply(
            text: __('assistant.replies.propose_export_csv', ['section' => $section !== '' ? $section : __('assistant.sections.last_table')]),
            action: [
                'type' => 'export_csv',
                'params' => ['section' => $section],
                'summary' => __('assistant.actions.export_csv_summary'),
            ],
            suggestions: [],
        );
    }

    // ---------------------------------------------------------------
    // Helpers de busqueda, diff y resolucion de entidades
    // ---------------------------------------------------------------

    /**
     * Termino de busqueda pedido en params (search/query/q).
     *
     * @param  array<string, mixed>  $params
     */
    private function searchTerm(array $params): ?string
    {
        $term = trim((string) ($params['search'] ?? $params['query'] ?? $params['q'] ?? ''));

        return $term === '' ? null : $term;
    }

    /**
     * Filtra una coleccion de modelos por un texto libre sobre los campos
     * que $haystack devuelva (nombre, documento, etc.).
     *
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  \Illuminate\Support\Collection<int, T>  $models
     * @return \Illuminate\Support\Collection<int, T>
     */
    private function filterModels(\Illuminate\Support\Collection $models, ?string $search, callable $haystack): \Illuminate\Support\Collection
    {
        if ($search === null) {
            return $models;
        }

        $needle = Str::lower($search);

        return $models
            ->filter(fn ($model): bool => Str::contains(Str::lower((string) $haystack($model)), $needle))
            ->values();
    }

    /**
     * Respuesta cuando un listado filtrado no encontro nada: distingue "no
     * hay datos" de "no hay nada con ese nombre" para que el usuario sepa
     * si el problema es el termino o el portafolio vacio.
     */
    private function emptyListReply(?string $search, string $kind): AssistantReply
    {
        if ($search !== null) {
            return AssistantReply::say(__('assistant.replies.search_empty', [
                'term' => $search,
                'section' => __('assistant.sections.'.$kind),
            ]), [
                __('assistant.suggestions.portfolio'),
            ]);
        }

        return AssistantReply::say(__('assistant.replies.no_'.$kind), [
            __('assistant.suggestions.portfolio'),
        ]);
    }

    /**
     * URL de seccion con ?tableSearch= cuando hay termino: las list pages
     * de Filament lo leen nativo (#[Url]) y las Manage pages via el trait
     * AssistantTableSearch.
     */
    private function redirectWithSearch(string $url, ?string $search): string
    {
        if ($search === null || $search === '') {
            return $url;
        }

        return $url.'?'.http_build_query(['tableSearch' => $search]);
    }

    /**
     * Targets de navigate que tienen tabla con buscador.
     */
    private function targetHasTableSearch(string $target): bool
    {
        return in_array($target, [
            'invoices', 'rentals', 'tenants', 'properties', 'delivery_acts', 'adjustments',
        ], true);
    }

    /**
     * Fila de diff before/after para el preview de acciones.
     *
     * @return array{field: string, label: string, before: ?string, after: ?string, kind: string}
     */
    private function changeRow(string $field, string $label, mixed $before, mixed $after, string $kind = 'text'): array
    {
        return [
            'field' => $field,
            'label' => $label,
            'before' => $before === null ? null : (string) $before,
            'after' => $after === null ? null : (string) $after,
            'kind' => $kind,
        ];
    }

    /**
     * Formatea un valor de modelo para el diff: money/date/enum/bool.
     */
    private function formatForDiff(mixed $value, string $kind): ?string
    {
        if ($value === null) {
            return null;
        }

        return match ($kind) {
            'money' => $this->money((float) $value),
            'date' => $value instanceof \DateTimeInterface
                ? $value->format('Y-m-d')
                : (string) $value,
            'bool' => $value ? __('assistant.yes') : __('assistant.no'),
            default => (string) $value,
        };
    }

    /**
     * Resuelve una entidad del usuario por nombre difuso y, si hay varios
     * candidatos, devuelve la lista para que el chat pregunte cual.
     *
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  class-string<T>  $model
     * @param  list<string>  $columns  Atributos/relaciones a buscar.
     * @return array{entity: ?T, candidates: list<array<string, mixed>>}
     */
    /**
     * Entidad elegida explicitamente en una lista de candidatos
     * ("_entity_ref" = "App\Models\Tenant:5"). Devuelve null si la ref no
     * corresponde a este modelo o el registro no pertenece al usuario.
     */
    private function entityFromRef(string $model, int $userId): ?object
    {
        $ref = $this->pendingEntityRef;

        if ($ref === null) {
            return null;
        }

        $separator = strrpos($ref, ':');

        // El token usa el basename de la clase ("Tenant:5") para no llevar
        // backslashes por el cableado de Livewire.
        if ($separator === false || substr($ref, 0, $separator) !== class_basename($model)) {
            return null;
        }

        return $model::query()
            ->where('user_id', $userId)
            ->find((int) substr($ref, $separator + 1));
    }

    private function resolveEntity(string $model, int $userId, string $name, array $columns = ['name']): array
    {
        // Eleccion explicita desde la lista de candidatos: manda sobre
        // cualquier coincidencia por nombre.
        $byRef = $this->entityFromRef($model, $userId);

        if ($byRef !== null) {
            return ['entity' => $byRef, 'candidates' => []];
        }

        $needle = Str::lower(trim($name));

        if ($needle === '') {
            return ['entity' => null, 'candidates' => []];
        }

        $matches = $model::query()
            ->where('user_id', $userId)
            ->get()
            ->filter(function ($entity) use ($needle, $columns): bool {
                foreach ($columns as $column) {
                    $value = data_get($entity, $column);

                    if ($value !== null && Str::contains(Str::lower((string) $value), $needle)) {
                        return true;
                    }
                }

                return false;
            })
            ->values();

        // Match exacto antes que substring, pero solo cuando es UNICO:
        // dos inquilinos llamados igual siguen siendo ambiguos.
        $exacts = $matches->filter(function ($entity) use ($needle, $columns): bool {
            foreach ($columns as $column) {
                $value = data_get($entity, $column);

                if ($value !== null && Str::lower(trim((string) $value)) === $needle) {
                    return true;
                }
            }

            return false;
        })->values();

        if ($exacts->count() === 1) {
            return ['entity' => $exacts->first(), 'candidates' => []];
        }

        if ($exacts->count() > 1) {
            $matches = $exacts;
        } elseif ($matches->count() === 1) {
            return ['entity' => $matches->first(), 'candidates' => []];
        }

        return [
            'entity' => null,
            'candidates' => $matches->take(5)->map(fn ($entity): array => [
                'name' => (string) ($entity->name ?? $entity->number ?? '—'),
                // token interno para re-elegir la fila desde la UI; no se
                // muestra nunca (chips usan name/detail).
                'token' => class_basename($entity).':'.$entity->id,
                'detail' => implode(' · ', collect($columns)
                    ->reject(fn (string $column): bool => in_array($column, ['name', 'number'], true))
                    ->map(fn (string $column): string => (string) (data_get($entity, $column) ?? ''))
                    ->filter()->unique()->values()->all()),
            ])->all(),
        ];
    }

    /**
     * Respuesta de "varios candidatos": tabla con los matches para que el
     * usuario precise cual quiso.
     *
     * @param  list<array<string, mixed>>  $candidates
     */
    private function ambiguousReply(string $term, array $candidates, string $sectionKey, ?array $resolution = null): AssistantReply
    {
        $data = ['candidates' => $candidates];

        // Contexto para re-ejecutar la peticion cuando el usuario elige un
        // candidato en la UI: intent + params originales + la clave donde
        // va el nombre. El token interno (_entity_ref) lo agrega el chat.
        if ($resolution !== null) {
            $data['pending_resolution'] = $resolution;
        }

        return new AssistantReply(
            text: __('assistant.replies.entity_ambiguous', ['term' => $term]),
            data: $data,
            suggestions: [__('assistant.suggestions.'.$sectionKey)],
        );
    }

    // ---------------------------------------------------------------
    // Reajustes: lectura
    // ---------------------------------------------------------------

    /**
     * Registros de reajuste del usuario (RentAdjustment reales), no solo
     * las fechas proximas de next_adjustments.
     *
     * @param  array<string, mixed>  $params
     */
    private function listAdjustments(array $params, int $userId): AssistantReply
    {
        $search = $this->searchTerm($params);

        $adjustments = $this->filterModels(
            RentAdjustment::query()
                ->where('user_id', $userId)
                ->with('rental:id,name,tenant_id', 'rental.tenant:id,name')
                ->orderByDesc('effective_from')
                ->orderByDesc('id')
                ->limit(50)
                ->get(),
            $search,
            fn (RentAdjustment $adjustment): string => implode(' ', array_filter([
                $adjustment->rental?->name,
                $adjustment->rental?->tenant?->name,
            ])),
        )->take(15)->values();

        if ($adjustments->isEmpty()) {
            return $this->emptyListReply($search, 'adjustments');
        }

        return new AssistantReply(
            text: __('assistant.replies.list_adjustments', ['count' => $adjustments->count()]),
            data: [
                'adjustments' => $adjustments->map(fn (RentAdjustment $adjustment): array => [
                    'rental' => $adjustment->rental?->name ?? '—',
                    'tenant' => $adjustment->rental?->tenant?->name ?? '—',
                    'previous_rent' => $this->money((float) $adjustment->previous_rent),
                    'new' => $this->money((float) $adjustment->new_rent),
                    'pct' => $adjustment->appliedPercentage().' %',
                    'ipc' => $adjustment->ipc_year !== null
                        ? $adjustment->ipc_year.'-'.str_pad((string) ($adjustment->ipc_month ?? 12), 2, '0', STR_PAD_LEFT)
                        : '—',
                    'state' => $adjustment->state()->label(),
                    'effective_from' => $adjustment->effective_from?->toDateString() ?? '—',
                ])->all(),
            ],
            redirect: $this->redirectWithSearch(RentAdjustmentResource::getUrl('index'), $search),
            suggestions: [__('assistant.suggestions.adjustments'), __('assistant.suggestions.analysis')],
        );
    }

    /**
     * Cadena de reajustes de un arriendo: canon actual, ancla, tope y cada
     * reajuste con su estado.
     *
     * @param  array<string, mixed>  $params
     */
    private function adjustmentDetail(array $params, int $userId): AssistantReply
    {
        $name = trim((string) ($this->param($params, [
            'rental_name', 'tenant', 'name', 'entity', 'arriendo',
            'alquiler', 'contrato', 'inquilino', 'rental', 'property_name',
        ]) ?? ''));

        $resolved = $name !== '' ? $this->resolveRentalCandidates($name, $userId) : ['entity' => null, 'candidates' => []];
        $rental = $resolved['entity'] ?? $this->findRental($params, $userId);

        if ($rental === null) {
            if ($resolved['candidates'] !== []) {
                return $this->ambiguousReply($name, $resolved['candidates'], 'adjustments', [
                    'intent' => 'adjustment_detail',
                    'params' => $params,
                    'name_key' => 'rental_name',
                ]);
            }

            return AssistantReply::say(__('assistant.replies.need_rental_name'), [
                __('assistant.suggestions.adjustments'),
                __('assistant.suggestions.rentals'),
            ]);
        }

        $rental->load('tenant:id,name', 'rentAdjustments');

        $adjustments = $rental->rentAdjustments
            ->sortBy('effective_from')
            ->values();

        $current = $this->rentals->currentRentFor($rental);
        $anchor = $rental->anchorRent();
        $cap = $rental->monthlyCapFromArticle18();

        $text = __('assistant.replies.adjustment_detail', [
            'rental' => $rental->name,
            'tenant' => $rental->tenant?->name ?? '—',
            'current' => $this->money($current),
            'anchor' => $anchor !== null ? $this->money($anchor) : '—',
            'count' => $adjustments->count(),
        ]);

        return new AssistantReply(
            text: $text,
            data: [
                'rental' => [[
                    'name' => $rental->name,
                    'tenant' => $rental->tenant?->name ?? '—',
                    'current_rent' => $this->money($current),
                    'anchor' => $anchor !== null ? $this->money($anchor) : '—',
                    'cap' => $cap !== null ? $this->money($cap) : '—',
                    'next_due' => $rental->nextAdjustmentDueDate()->toDateString(),
                ]],
                'adjustments' => $adjustments->map(fn (RentAdjustment $adjustment): array => [
                    'effective_from' => $adjustment->effective_from?->toDateString() ?? '—',
                    'previous_rent' => $this->money((float) $adjustment->previous_rent),
                    'new' => $this->money((float) $adjustment->new_rent),
                    'pct' => $adjustment->appliedPercentage().' %',
                    'legal_pct' => $adjustment->legalPercentage().' %',
                    'pactado' => $adjustment->hasPactadoCap() ? __('assistant.yes') : __('assistant.no'),
                    'state' => $adjustment->state()->label(),
                    'notified_on' => $adjustment->notified_on?->toDateString() ?? '—',
                    'applied_on' => $adjustment->applied_on?->toDateString() ?? '—',
                ])->all(),
            ],
            redirect: RentAdjustmentResource::getUrl('index'),
            suggestions: [__('assistant.suggestions.adjustments'), __('assistant.suggestions.analysis')],
        );
    }

    /**
     * Que IPC aplicaria un reajuste hipotetico: previewIpc del facade.
     *
     * @param  array<string, mixed>  $params
     */
    private function previewAdjustmentIpc(array $params, int $userId): AssistantReply
    {
        // "el IPC de Camila" llega como nombre de inquilino: los
        // candidatos resuelven arriendo por nombre O por inquilino, y si
        // hay varios (dos Camila, o un inquilino con dos arriendos) se
        // listan para elegir en vez de adivinar.
        $name = trim((string) ($this->param($params, [
            'rental_name', 'tenant', 'name', 'entity', 'arriendo',
            'alquiler', 'contrato', 'inquilino', 'rental', 'property_name',
        ]) ?? ''));

        $resolved = $name !== '' ? $this->resolveRentalCandidates($name, $userId) : ['entity' => null, 'candidates' => []];
        $rental = $resolved['entity'];

        if ($rental === null) {
            if ($resolved['candidates'] !== []) {
                return $this->ambiguousReply($name, $resolved['candidates'], 'adjustments', [
                    'intent' => 'preview_adjustment_ipc',
                    'params' => $params,
                    'name_key' => 'rental_name',
                ]);
            }

            return AssistantReply::say(__('assistant.replies.need_rental_name'), [
                __('assistant.suggestions.adjustments'),
            ]);
        }

        $effectiveFrom = $this->parseDate($params['effective_from'] ?? $params['fecha'] ?? null)
            ?? $rental->suggestedAdjustmentDate()->toDateString();

        $basisRaw = strtolower((string) ($params['basis'] ?? ''));
        $basis = str_contains($basisRaw, 'ciudad') || str_contains($basisRaw, 'city')
            ? \App\Enums\IpcBasis::City
            : \App\Enums\IpcBasis::National;

        $pactado = isset($params['pactado_percentage']) && is_numeric($params['pactado_percentage'])
            ? (float) $params['pactado_percentage']
            : null;

        try {
            $snapshot = $this->rentals->previewAdjustmentIpc(
                rental: $rental,
                effectiveFrom: \Illuminate\Support\Carbon::parse($effectiveFrom),
                basis: $basis,
                geographyCode: $rental->property?->geography_code,
                pactadoPercentage: $pactado,
            );
        } catch (\Throwable $exception) {
            return AssistantReply::say(__('assistant.replies.ipc_preview_failed', [
                'error' => $exception->getMessage(),
            ]), [
                __('assistant.suggestions.adjustments'),
            ]);
        }

        $current = $this->rentals->currentRentFor($rental);
        $suggested = round($current * (1 + $snapshot->percentage / 100), 0);

        return new AssistantReply(
            text: __('assistant.replies.ipc_preview', [
                'rental' => $rental->name,
                'date' => $effectiveFrom,
                'pct' => $snapshot->percentage,
                'place' => $snapshot->geographyName,
                'current' => $this->money($current),
                'suggested' => $this->money($suggested),
            ]),
            data: [
                'ipc' => [[
                    'rental' => $rental->name,
                    'effective_from' => $effectiveFrom,
                    'percentage' => $snapshot->percentage.' %',
                    'geography' => $snapshot->geographyName,
                    'basis' => $snapshot->isStatutory ? __('assistant.ipc.statutory') : __('assistant.ipc.informative'),
                    'current_rent' => $this->money($current),
                    'suggested_rent' => $this->money($suggested),
                ]],
            ],
            suggestions: [__('assistant.suggestions.adjustments')],
        );
    }

    // ---------------------------------------------------------------
    // Metricas financieras desglosadas
    // ---------------------------------------------------------------

    private function agingReport(int $userId): AssistantReply
    {
        $aging = $this->analysis->agingBuckets($userId);
        $labels = [
            'current' => __('assistant.finance.buckets.current'),
            'd30' => __('assistant.finance.buckets.d30'),
            'd60' => __('assistant.finance.buckets.d60'),
            'd90' => __('assistant.finance.buckets.d90'),
            'd90plus' => __('assistant.finance.buckets.d90plus'),
        ];

        return new AssistantReply(
            text: __('assistant.replies.aging_report', ['total' => $this->money($aging['total'])]),
            data: [
                'aging' => collect($aging['buckets'])->map(fn (float $amount, string $key): array => [
                    'bucket' => $labels[$key] ?? $key,
                    'amount' => $this->money($amount),
                ])->values()->all(),
            ],
            redirect: FinanzasPage::getUrl(),
            suggestions: [__('assistant.suggestions.top_debtors'), __('assistant.suggestions.pending')],
        );
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function topDebtors(array $params, int $userId): AssistantReply
    {
        $debtors = $this->analysis->topDebtors($userId, 5);
        $search = $this->searchTerm($params);

        if ($search !== null) {
            $needle = Str::lower($search);
            $debtors = array_values(array_filter(
                $debtors,
                fn (array $row): bool => Str::contains(Str::lower($row['tenant'].' '.$row['rental']), $needle),
            ));
        }

        if ($debtors === []) {
            return AssistantReply::say(
                $search !== null
                    ? __('assistant.replies.search_empty', ['term' => $search, 'section' => __('assistant.sections.debtors')])
                    : __('assistant.replies.no_debtors'),
                [__('assistant.suggestions.pending'), __('assistant.suggestions.analysis')],
            );
        }

        return new AssistantReply(
            text: __('assistant.replies.top_debtors', ['count' => count($debtors)]),
            data: ['debtors' => $debtors],
            redirect: FinanzasPage::getUrl(),
            suggestions: [__('assistant.suggestions.aging'), __('assistant.suggestions.pending')],
        );
    }

    private function paymentsByMethod(int $userId): AssistantReply
    {
        $methods = $this->analysis->paymentsByMethod($userId, 12);

        if ($methods === []) {
            return AssistantReply::say(__('assistant.replies.no_payments'), [
                __('assistant.suggestions.pending'),
            ]);
        }

        return new AssistantReply(
            text: __('assistant.replies.payments_by_method'),
            data: ['payment_methods' => $methods],
            redirect: FinanzasPage::getUrl(),
            suggestions: [__('assistant.suggestions.analysis'), __('assistant.suggestions.income')],
        );
    }

    private function collectionRate(int $userId): AssistantReply
    {
        $rate = $this->analysis->collectionRate($userId);
        $avgLate = $this->analysis->averageDaysLate($userId);
        $expected = $this->analysis->expectedThisMonth($userId);

        return new AssistantReply(
            text: __('assistant.replies.collection_rate', [
                'rate' => $rate === null ? '—' : $rate.' %',
                'days' => $avgLate === null ? '—' : $avgLate,
                'expected' => $this->money($expected),
            ]),
            data: [
                'collection' => [[
                    'collection_rate' => $rate === null ? '—' : $rate.' %',
                    'avg_days_late' => $avgLate === null ? '—' : $avgLate,
                    'expected_this_month' => $this->money($expected),
                ]],
            ],
            redirect: FinanzasPage::getUrl(),
            suggestions: [__('assistant.suggestions.aging'), __('assistant.suggestions.top_debtors')],
        );
    }

    // ---------------------------------------------------------------
    // Settings del negocio e IPC
    // ---------------------------------------------------------------

    private function showSettings(int $userId): AssistantReply
    {
        $settings = $this->settings->current();

        return new AssistantReply(
            text: __('assistant.replies.show_settings'),
            data: [
                'settings' => [[
                    'business_name' => $settings['business_name'] ?? '—',
                    'tax_id' => $settings['tax_id'] ?? '—',
                    'address' => $settings['address'] ?? '—',
                    'email' => $settings['email'] ?? '—',
                    'phone' => $settings['phone'] ?? '—',
                    'currency' => $settings['currency'] ?? '—',
                    'invoice_due_days' => (string) ($settings['invoice_due_days'] ?? '—'),
                    'default_city' => $settings['default_city_code'] ?? '—',
                ]],
            ],
            suggestions: [__('assistant.suggestions.update_settings')],
        );
    }

    private function ipcStatus(int $userId): AssistantReply
    {
        $series = IpcSeries::query()
            ->selectRaw('geography_code, geography_name, MAX(year) as max_year, MAX(month) as max_month, MAX(synced_at) as last_sync')
            ->groupBy('geography_code', 'geography_name')
            ->orderBy('geography_name')
            ->get();

        if ($series->isEmpty()) {
            return AssistantReply::say(__('assistant.replies.ipc_empty'), [
                __('assistant.suggestions.sync_ipc'),
            ]);
        }

        $staleThreshold = now()->subDays(45);
        $stale = $series->filter(fn ($row): bool => $row->last_sync === null || \Illuminate\Support\Carbon::parse($row->last_sync)->lt($staleThreshold));

        return new AssistantReply(
            text: $stale->isNotEmpty()
                ? __('assistant.replies.ipc_stale', ['count' => $stale->count()])
                : __('assistant.replies.ipc_fresh', ['count' => $series->count()]),
            data: [
                'ipc_series' => $series->map(fn ($row): array => [
                    'geography' => $row->geography_name,
                    'latest' => $row->max_year.'-'.str_pad((string) $row->max_month, 2, '0', STR_PAD_LEFT),
                    'synced_at' => $row->last_sync ?? '—',
                ])->all(),
            ],
            redirect: \App\Filament\Resources\IpcSeriesResource::getUrl('index'),
            suggestions: [__('assistant.suggestions.sync_ipc'), __('assistant.suggestions.adjustments')],
        );
    }

    // ---------------------------------------------------------------
    // Ficha de entidad
    // ---------------------------------------------------------------

    /**
     * "Cuéntame del arriendo/propiedad/inquilino/factura X": ficha completa
     * con lo esencial de la entidad, no solo la fila de tabla.
     *
     * @param  array<string, mixed>  $params
     */
    private function entityDetail(array $params, int $userId): AssistantReply
    {
        $type = strtolower(trim((string) ($params['entity_type'] ?? $params['type'] ?? '')));
        $name = trim((string) ($params['name'] ?? $params['rental_name'] ?? $params['tenant'] ?? $params['number'] ?? ''));

        if ($name === '') {
            return AssistantReply::say(__('assistant.replies.need_entity_name'), [
                __('assistant.suggestions.portfolio'),
            ]);
        }

        return match ($type) {
            'rental', 'arriendo', 'alquiler', 'contrato' => $this->rentalDetail($name, $userId),
            'tenant', 'inquilino', 'arrendatario' => $this->tenantDetail($name, $userId),
            'property', 'propiedad', 'inmueble' => $this->propertyDetail($name, $userId),
            'invoice', 'factura' => $this->invoiceDetail($name, $userId),
            'adjustment', 'reajuste' => $this->adjustmentEntityDetail($name, $userId),
            default => $this->guessEntityDetail($name, $userId),
        };
    }

    /**
     * Sin entity_type explicito: prueba cada entidad con los mismos
     * resolvedores que usan las fichas (no con el texto de la respuesta:
     * la traduccion lleva :name y el contains nunca matchearia). La
     * primera que encuentre —o que tenga candidatos ambiguos— responde.
     */
    private function guessEntityDetail(string $name, int $userId): AssistantReply
    {
        $probes = [
            'rentalDetail' => function () use ($name, $userId): bool {
                $resolved = $this->resolveRentalCandidates($name, $userId);

                return $resolved['entity'] !== null || $resolved['candidates'] !== [];
            },
            'tenantDetail' => fn (): bool => $this->resolveEntity(Tenant::class, $userId, $name, ['name', 'document', 'phone_number', 'email'])['entity'] !== null
                || $this->resolveEntity(Tenant::class, $userId, $name, ['name', 'document', 'phone_number', 'email'])['candidates'] !== [],
            'propertyDetail' => fn (): bool => $this->resolveEntity(Property::class, $userId, $name, ['name', 'address'])['entity'] !== null
                || $this->resolveEntity(Property::class, $userId, $name, ['name', 'address'])['candidates'] !== [],
            'invoiceDetail' => fn (): bool => $this->findInvoice(['number' => $name], $userId) !== null,
        ];

        foreach ($probes as $method => $probe) {
            if ($probe()) {
                return $this->{$method}($name, $userId);
            }
        }

        return AssistantReply::say(__('assistant.replies.entity_not_found', ['name' => $name]), [
            __('assistant.suggestions.portfolio'),
        ]);
    }

    private function rentalDetail(string $name, int $userId): AssistantReply
    {
        $resolved = $this->resolveRentalCandidates($name, $userId);

        if ($resolved['entity'] === null) {
            return $resolved['candidates'] !== []
                ? $this->ambiguousReply($name, $resolved['candidates'], 'rentals', [
                    'intent' => 'entity_detail',
                    'params' => ['name' => $name, 'entity_type' => 'rental'],
                    'name_key' => 'name',
                ])
                : AssistantReply::say(__('assistant.replies.entity_not_found', ['name' => $name]));
        }

        $rental = $resolved['entity'];
        $rental->load('tenant:id,name,document,phone_number,email', 'property:id,name,address', 'rentAdjustments');

        $current = $this->rentals->currentRentFor($rental);
        $openBalance = round((float) $this->openInvoices($userId)
            ->where('rental_id', $rental->id)
            ->sum(fn (Invoice $invoice): float => $invoice->balance()), 2);

        $nextDue = $rental->nextAdjustmentDueDate();

        return new AssistantReply(
            text: __('assistant.replies.rental_detail', [
                'rental' => $rental->name,
                'tenant' => $rental->tenant?->name ?? '—',
                'property' => $rental->property?->name ?? '—',
                'rent' => $this->money($current),
                'balance' => $this->money($openBalance),
                'next' => $nextDue->toDateString(),
            ]),
            data: [
                '_titles' => [
                    'rental_detail' => __('assistant.data_titles.rental_detail_named', ['name' => $rental->name]),
                ],
                'rental_detail' => [[
                    'name' => $rental->name,
                    'tenant' => $rental->tenant?->name ?? '—',
                    'tenant_phone' => $rental->tenant?->phone_number ?? '—',
                    'tenant_email' => $rental->tenant?->email ?? '—',
                    'property' => $rental->property?->name ?? '—',
                    'property_address' => $rental->property?->address ?? '—',
                    'current_rent' => $this->money($current),
                    'cadence' => $rental->billing_cadence?->label() ?? '—',
                    'start_date' => $rental->start_date?->toDateString() ?? '—',
                    'end_date' => $rental->end_date?->toDateString() ?? '—',
                    'total_months' => (string) ($rental->total_months ?? '—'),
                    'open_balance' => $this->money($openBalance),
                    'next_adjustment' => $nextDue->toDateString(),
                    'rental_type' => $rental->rental_type?->label() ?? '—',
                    'is_active' => $rental->is_active ? __('assistant.yes') : __('assistant.no'),
                ]],
            ],
            redirect: RentalResource::getUrl('index'),
            suggestions: [__('assistant.suggestions.update_rental'), __('assistant.suggestions.adjustments')],
        );
    }

    private function tenantDetail(string $name, int $userId): AssistantReply
    {
        $resolved = $this->resolveEntity(Tenant::class, $userId, $name, ['name', 'document', 'phone_number', 'email']);

        if ($resolved['entity'] === null) {
            return $resolved['candidates'] !== []
                ? $this->ambiguousReply($name, $resolved['candidates'], 'tenants', [
                    'intent' => 'entity_detail',
                    'params' => ['name' => $name, 'entity_type' => 'tenant'],
                    'name_key' => 'name',
                ])
                : AssistantReply::say(__('assistant.replies.entity_not_found', ['name' => $name]));
        }

        $tenant = $resolved['entity'];
        $tenant->load(['rentals' => fn ($query) => $query->where('is_active', true)->with('property:id,name')]);

        $openByRental = $this->openInvoices($userId)->groupBy('rental_id')
            ->map(fn ($group): float => round((float) $group->sum(fn (Invoice $i): float => $i->balance()), 2));

        $balance = round((float) $tenant->rentals->sum(
            fn (Rental $rental): float => $openByRental[$rental->id] ?? 0.0,
        ), 2);

        return new AssistantReply(
            text: __('assistant.replies.tenant_detail', [
                'tenant' => $tenant->name,
                'rentals' => $tenant->rentals->count(),
                'balance' => $this->money($balance),
            ]),
            data: [
                '_titles' => [
                    'tenant_detail' => __('assistant.data_titles.tenant_detail_named', ['name' => $tenant->name]),
                ],
                'tenant_detail' => [[
                    'name' => $tenant->name,
                    'document' => $tenant->document ?? '—',
                    'phone' => $tenant->phone_number ?? '—',
                    'email' => $tenant->email ?? '—',
                    'rentals' => (string) $tenant->rentals->count(),
                    'open_balance' => $this->money($balance),
                ]],
                'tenant_rentals' => $tenant->rentals->map(fn (Rental $rental): array => [
                    'name' => $rental->name,
                    'property' => $rental->property?->name ?? '—',
                    'rent' => $this->money((float) $rental->monthly_amount),
                    'end_date' => $rental->end_date?->toDateString() ?? '—',
                ])->all(),
            ],
            redirect: TenantResource::getUrl('index'),
            suggestions: [__('assistant.suggestions.update_tenant'), __('assistant.suggestions.pending')],
        );
    }

    private function propertyDetail(string $name, int $userId): AssistantReply
    {
        $resolved = $this->resolveEntity(Property::class, $userId, $name, ['name', 'address']);

        if ($resolved['entity'] === null) {
            return $resolved['candidates'] !== []
                ? $this->ambiguousReply($name, $resolved['candidates'], 'properties', [
                    'intent' => 'entity_detail',
                    'params' => ['name' => $name, 'entity_type' => 'property'],
                    'name_key' => 'name',
                ])
                : AssistantReply::say(__('assistant.replies.entity_not_found', ['name' => $name]));
        }

        $property = $resolved['entity'];
        $property->load(['rentals' => fn ($query) => $query->where('is_active', true)->with('tenant:id,name'), 'geography:id,name']);

        return new AssistantReply(
            text: __('assistant.replies.property_detail', [
                'property' => $property->name,
                'rentals' => $property->rentals->count(),
            ]),
            data: [
                '_titles' => [
                    'property_detail' => __('assistant.data_titles.property_detail_named', ['name' => $property->name]),
                ],
                'property_detail' => [[
                    'name' => $property->name,
                    'address' => $property->address ?? '—',
                    'city' => $property->geography?->name ?? $property->geography_code ?? '—',
                    'rentals' => (string) $property->rentals->count(),
                ]],
                'property_rentals' => $property->rentals->map(fn (Rental $rental): array => [
                    'name' => $rental->name,
                    'tenant' => $rental->tenant?->name ?? '—',
                    'rent' => $this->money((float) $rental->monthly_amount),
                    'end_date' => $rental->end_date?->toDateString() ?? '—',
                ])->all(),
            ],
            redirect: PropertyResource::getUrl('index'),
            suggestions: [__('assistant.suggestions.update_property'), __('assistant.suggestions.portfolio')],
        );
    }

    private function invoiceDetail(string $name, int $userId): AssistantReply
    {
        $resolved = $this->resolveEntity(Invoice::class, $userId, $name, ['number']);

        if ($resolved['entity'] === null) {
            return $resolved['candidates'] !== []
                ? $this->ambiguousReply($name, $resolved['candidates'], 'invoices', [
                    'intent' => 'entity_detail',
                    'params' => ['name' => $name, 'entity_type' => 'invoice'],
                    'name_key' => 'name',
                ])
                : AssistantReply::say(__('assistant.replies.entity_not_found', ['name' => $name]));
        }

        $invoice = $resolved['entity'];
        $invoice->load('rental:id,name,tenant_id', 'rental.tenant:id,name');

        return new AssistantReply(
            text: __('assistant.replies.invoice_detail', [
                'number' => $invoice->number,
                'tenant' => $invoice->rental?->tenant?->name ?? '—',
                'amount' => $this->money((float) $invoice->amount),
                'balance' => $this->money($invoice->balance()),
                'status' => $invoice->status->label(),
            ]),
            data: [
                '_titles' => [
                    'invoice_detail' => __('assistant.data_titles.invoice_detail_named', ['name' => $invoice->number]),
                ],
                'invoice_detail' => [[
                    'number' => $invoice->number,
                    'tenant' => $invoice->rental?->tenant?->name ?? '—',
                    'rental' => $invoice->rental?->name ?? '—',
                    'amount' => $this->money((float) $invoice->amount),
                    'paid' => $this->money($invoice->paidAmount()),
                    'balance' => $this->money($invoice->balance()),
                    'status' => $invoice->status->label(),
                    'issued_at' => $invoice->issued_at?->toDateString() ?? '—',
                    'due_at' => $invoice->due_at->toDateString(),
                    'paid_at' => $invoice->paid_at?->toDateString() ?? '—',
                ]],
            ],
            redirect: InvoiceResource::getUrl('index'),
            suggestions: [__('assistant.suggestions.record_payment'), __('assistant.suggestions.documents')],
        );
    }

    private function adjustmentEntityDetail(string $name, int $userId): AssistantReply
    {
        $rental = $this->findRental(['rental_name' => $name], $userId);

        if ($rental === null) {
            return AssistantReply::say(__('assistant.replies.entity_not_found', ['name' => $name]));
        }

        return $this->adjustmentDetail(['rental_name' => $name], $userId);
    }
}
