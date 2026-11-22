<?php

namespace App\Services\Assistant;

use App\Dto\AssistantReply;
use App\Enums\ActItemState;
use App\Enums\InvoiceStatus;
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
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Rental;
use App\Models\Tenant;
use App\Models\User;
use App\Services\FinancialAnalysisService;
use Illuminate\Support\Carbon;
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
    ];

    private const ANALYSIS_INTENTS = ['financial_analysis'];

    private const DRAFT_INTENTS = ['draft_reminder', 'draft_notice'];

    private const ACTION_INTENTS = [
        'propose_generate_invoices',
        'propose_record_payment',
        'create_tenant',
        'create_property',
        'create_rental',
        'propose_update_profile',
    ];

    public function __construct(
        private readonly PortfolioContextBuilder $context,
        private readonly FinancialAnalysisService $analysis,
    ) {}

    /**
     * @param  array<string, mixed>  $params
     */
    public function invoke(string $intent, array $params, int $userId): AssistantReply
    {
        $reply = match (true) {
            $intent === 'navigate' => $this->navigate($params, $userId),
            in_array($intent, self::READ_INTENTS, true) => $this->read($intent, $params, $userId),
            in_array($intent, self::ANALYSIS_INTENTS, true) => $this->financialAnalysis($userId),
            in_array($intent, self::DRAFT_INTENTS, true) => $this->draft($intent, $params, $userId),
            in_array($intent, self::ACTION_INTENTS, true) => $this->propose($intent, $params, $userId),
            default => AssistantReply::say(__('assistant.unknown')),
        };

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

        $action = match (count($actions)) {
            0 => null,
            1 => $actions[0],
            default => [
                'type' => 'batch',
                'items' => $actions,
                'summary' => $this->batchSummary($actions),
            ],
        };

        return new AssistantReply(
            text: $texts === [] ? __('assistant.unknown') : implode("\n\n", array_filter($texts)),
            data: $data === [] ? null : $data,
            action: $action,
            redirect: $redirect,
            suggestions: array_slice($suggestions, 0, 4),
            intent: 'batch',
            sections: $sections === [] ? null : $sections,
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
            'next_adjustments' => $this->nextAdjustments($userId),
            'expiring_contracts' => $this->expiringContracts($userId),
            'list_tenants' => $this->listTenants($userId),
            'list_properties' => $this->listProperties($userId),
            'list_rentals' => $this->listRentals($userId),
            'list_invoices' => $this->listInvoices($userId),
            'list_payments' => $this->listPayments($userId),
            'list_delivery_acts' => $this->listDeliveryActs($userId),
            'list_properties_with_acts' => $this->listPropertiesWithActs($userId),
            'show_profile' => $this->showProfile($userId),
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

    private function nextAdjustments(int $userId): AssistantReply
    {
        $rentals = Rental::query()
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->with('tenant:id,name')
            ->get()
            ->map(fn (Rental $rental): array => [
                'rental' => $rental,
                'due' => $rental->nextAdjustmentDueDate(),
            ])
            ->sortBy(fn (array $row) => $row['due']->getTimestamp())
            ->take(10);

        if ($rentals->isEmpty()) {
            return AssistantReply::say(__('assistant.replies.no_rentals'), [
                __('assistant.suggestions.portfolio'),
            ]);
        }

        return new AssistantReply(
            text: __('assistant.replies.next_adjustments', ['count' => $rentals->count()]),
            data: [
                'adjustments' => $rentals->map(fn (array $row): array => [
                    'rental' => $row['rental']->name,
                    'tenant' => $row['rental']->tenant?->name ?? '—',
                    'due_date' => $row['due']->toDateString(),
                    'days' => (int) $row['due']->startOfDay()->diffInDays(today()->startOfDay()),
                    'overdue' => $row['rental']->isDueForAdjustment(),
                ])->all(),
            ],
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
    private function listTenants(int $userId): AssistantReply
    {
        $tenants = Tenant::query()
            ->where('user_id', $userId)
            ->with(['rentals' => fn ($query) => $query->where('is_active', true)])
            ->orderBy('name')
            ->get();

        if ($tenants->isEmpty()) {
            return AssistantReply::say(__('assistant.replies.no_tenants'), [
                __('assistant.suggestions.portfolio'),
            ]);
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
                        'rentals' => $tenant->rentals->count(),
                        'balance' => $this->money($balance),
                    ];
                })->all(),
            ],
            redirect: TenantResource::getUrl('index'),
            suggestions: [__('assistant.suggestions.pending'), __('assistant.suggestions.reminder')],
        );
    }

    private function listProperties(int $userId): AssistantReply
    {
        $properties = Property::query()
            ->where('user_id', $userId)
            ->withCount(['rentals' => fn ($query) => $query->where('is_active', true)])
            ->orderBy('name')
            ->get();

        if ($properties->isEmpty()) {
            return AssistantReply::say(__('assistant.replies.no_properties'), [
                __('assistant.suggestions.portfolio'),
            ]);
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
            redirect: PropertyResource::getUrl('index'),
            suggestions: [__('assistant.suggestions.portfolio'), __('assistant.suggestions.rentals')],
        );
    }

    private function listRentals(int $userId): AssistantReply
    {
        $rentals = Rental::query()
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->with(['tenant:id,name', 'property:id,name'])
            ->orderBy('name')
            ->get();

        if ($rentals->isEmpty()) {
            return AssistantReply::say(__('assistant.replies.no_rentals'), [
                __('assistant.suggestions.portfolio'),
            ]);
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
            redirect: RentalResource::getUrl('index'),
            suggestions: [__('assistant.suggestions.pending'), __('assistant.suggestions.adjustments')],
        );
    }

    private function listInvoices(int $userId): AssistantReply
    {
        $invoices = Invoice::query()
            ->where('user_id', $userId)
            ->with('rental:id,name', 'rental.tenant:id,name')
            ->orderByDesc('issued_at')
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        if ($invoices->isEmpty()) {
            return AssistantReply::say(__('assistant.replies.no_invoices'), [
                __('assistant.suggestions.portfolio'),
            ]);
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
            redirect: InvoiceResource::getUrl('index'),
            suggestions: [__('assistant.suggestions.overdue'), __('assistant.suggestions.pending')],
        );
    }

    private function listPayments(int $userId): AssistantReply
    {
        $payments = Payment::query()
            ->where('user_id', $userId)
            ->with('rental:id,name', 'rental.tenant:id,name', 'invoice:id,number')
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        if ($payments->isEmpty()) {
            return AssistantReply::say(__('assistant.replies.no_payments'), [
                __('assistant.suggestions.pending'),
            ]);
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
            redirect: InvoiceResource::getUrl('index'),
            suggestions: [__('assistant.suggestions.analysis'), __('assistant.suggestions.pending')],
        );
    }

    /**
     * Actas de entrega del usuario con su inventario relevante: ademas del
     * listado, se destacan los elementos en mal estado (reparable, por
     * reemplazar, destruido) que son lo que el arrendador debe vigilar.
     */
    private function listDeliveryActs(int $userId): AssistantReply
    {
        $acts = DeliveryAct::query()
            ->where('user_id', $userId)
            ->withCount('items')
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        if ($acts->isEmpty()) {
            return AssistantReply::say(__('assistant.replies.no_delivery_acts'), [
                __('assistant.suggestions.portfolio'),
            ]);
        }

        $attention = ActItem::query()
            ->whereIn('state', [
                ActItemState::REPARABLE,
                ActItemState::POR_REEMPLAZAR,
                ActItemState::DESTRUIDO,
            ])
            ->whereHas('deliveryAct', fn ($query) => $query->where('user_id', $userId))
            ->with('deliveryAct:id,tenant_name')
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        $unsigned = $acts->filter(fn (DeliveryAct $act): bool => ! $act->isSigned())->count();

        return new AssistantReply(
            text: __('assistant.replies.list_delivery_acts', [
                'count' => $acts->count(),
                'unsigned' => $unsigned,
                'attention' => $attention->count(),
            ]),
            data: [
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
            redirect: DeliveryActResource::getUrl('index'),
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
            ->with('rental:id,name', 'rental.tenant:id,name')
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
        $amountRaw = $params['amount'] ?? $params['valor'] ?? null;
        $amount = is_numeric($amountRaw)
            ? round((float) $amountRaw, 2)
            : $invoice->balance();

        $method = $this->mapMethod((string) ($params['method'] ?? $params['metodo'] ?? $params['medio'] ?? ''));
        $date = $this->parseDate($params['date'] ?? $params['fecha'] ?? null) ?? today()->toDateString();

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
                ],
                'summary' => __('assistant.actions.record_payment_summary', [
                    'invoice' => $invoice->number,
                    'amount' => $this->money($amount),
                ]),
            ],
        );
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
            return AssistantReply::say(__('assistant.replies.need_tenant_details', [
                'missing' => implode(', ', array_map(
                    fn (string $field): string => __('assistant.fields.'.$field),
                    $missing,
                )),
            ]), [
                __('assistant.suggestions.tenants'),
            ]);
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
            return AssistantReply::say(__('assistant.replies.need_property_details', [
                'missing' => implode(', ', array_map(
                    fn (string $field): string => __('assistant.fields.'.$field),
                    $missing,
                )),
            ]), [
                __('assistant.suggestions.properties'),
            ]);
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
            return AssistantReply::say(__('assistant.replies.need_rental_details', [
                'missing' => implode(', ', array_map(
                    fn (string $field): string => __('assistant.fields.'.$field),
                    $missing,
                )),
            ]), [
                __('assistant.suggestions.portfolio'),
                __('assistant.suggestions.tenants'),
            ]);
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

        return $model::query()
            ->where('user_id', $userId)
            ->get()
            ->first(fn ($entity): bool => Str::contains(Str::lower((string) $entity->name), $needle));
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
                redirect: $urls[$target].'?'.http_build_query(['af' => ['status' => Str::lower($filter)]]),
            );
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
            ->with('rental:id,name', 'rental.tenant:id,name')
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
        $name = trim((string) ($params['rental_name'] ?? $params['tenant'] ?? ''));

        if ($name === '') {
            return null;
        }

        $needle = Str::lower($name);

        return Rental::query()
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->with('tenant:id,name')
            ->get()
            ->first(fn (Rental $rental): bool => Str::contains(Str::lower($rental->name), $needle)
                || Str::contains(Str::lower((string) $rental->tenant?->name), $needle));
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

    private function mapMethod(string $raw): string
    {
        $value = Str::lower($raw);

        return match (true) {
            Str::contains($value, 'transfer') => 'transferencia',
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
}
