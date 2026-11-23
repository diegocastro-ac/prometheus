<?php

namespace App\Services\Assistant;

use App\Dto\AssistantReply;
use App\Enums\BillingCadence;
use App\Enums\InvoiceStatus;
use App\Enums\NotificationMethod;
use App\Enums\RentalType;
use App\Models\Invoice;
use App\Models\Property;
use App\Models\RentAdjustment;
use App\Models\Rental;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Acts\DeliveryActRecorder;
use App\Services\Billing\RecurringBillingService;
use App\Services\Ipc\IpcSynchronizer;
use App\Services\PaymentRecordingService;
use App\Services\RentalFacade;
use App\Services\SettingsService;
use App\ValueObjects\RentalPeriod;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * Ejecuta las acciones que el copiloto propuso y el usuario confirmo.
 *
 * Es el unico punto donde una intencion de la IA se convierte en escritura.
 * Llega aqui solo despues del clic en "Confirmar" de la pantalla de chat, y
 * cada ejecucion vuelve a validar el alcance por user_id: una accion
 * propuesta para un alquiler ajeno no encuentra su registro y falla en vez
 * de tocar datos de otro arrendador.
 */
class AssistantActionExecutor
{
    public function __construct(
        private readonly RecurringBillingService $billing,
        private readonly PaymentRecordingService $payments,
        private readonly RentalFacade $rentals,
        private readonly DeliveryActRecorder $acts,
        private readonly IpcSynchronizer $ipc,
        private readonly SettingsService $settings,
    ) {}

    /**
     * @param  array{type?: string, params?: array<string, mixed>}  $action
     */
    public function execute(array $action, int $userId): AssistantReply
    {
        try {
            return match ($action['type'] ?? '') {
                'generate_invoices' => $this->generateInvoices($action['params'] ?? [], $userId),
                'record_payment' => $this->recordPayment($action['params'] ?? [], $userId),
                'create_tenant' => $this->createTenant($action['params'] ?? [], $userId),
                'create_property' => $this->createProperty($action['params'] ?? [], $userId),
                'create_rental' => $this->createRental($action['params'] ?? [], $userId),
                'update_profile' => $this->updateProfile($action['params'] ?? [], $userId),
                'update_tenant' => $this->updateTenant($action['params'] ?? [], $userId),
                'update_property' => $this->updateProperty($action['params'] ?? [], $userId),
                'update_rental' => $this->updateRental($action['params'] ?? [], $userId),
                'record_adjustment' => $this->recordAdjustment($action['params'] ?? [], $userId),
                'apply_adjustment' => $this->applyAdjustment($action['params'] ?? [], $userId),
                'mark_adjustment_notified' => $this->markAdjustmentNotified($action['params'] ?? [], $userId),
                'recalculate_adjustments' => $this->recalculateAdjustments($action['params'] ?? [], $userId),
                'annul_invoice' => $this->annulInvoice($action['params'] ?? [], $userId),
                'bill_rental_invoices' => $this->billRentalInvoices($action['params'] ?? [], $userId),
                'create_delivery_act' => $this->createDeliveryAct($action['params'] ?? [], $userId),
                'update_settings' => $this->updateSettings($action['params'] ?? [], $userId),
                'sync_ipc' => $this->syncIpc($userId),
                'generate_document' => $this->generateDocument($action['params'] ?? [], $userId),
                'export_csv' => $this->exportCsv($action['params'] ?? [], $userId),
                'batch' => $this->executeBatch($action['items'] ?? [], $userId),
                default => AssistantReply::say(__('assistant.actions.unknown')),
            };
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return AssistantReply::say(__('assistant.actions.entity_missing'));
        } catch (DomainException $exception) {
            return AssistantReply::say($exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return AssistantReply::say(__('assistant.actions.failed'));
        }
    }

    /**
     * Paquete de acciones confirmadas de una sola vez ("registra estos 3
     * inquilinos y 2 propiedades"). Cada item se ejecuta de forma
     * independiente: si uno falla por validacion, los demas igual se
     * procesan y el resultado lista item por item que paso.
     *
     * @param  array<int, mixed>  $items
     */
    private function executeBatch(array $items, int $userId): AssistantReply
    {
        $lines = [];
        $processed = 0;

        foreach ($items as $item) {
            if (! is_array($item) || ! isset($item['type']) || ! is_string($item['type'])) {
                continue;
            }

            $sub = $this->execute([
                'type' => $item['type'],
                'params' => is_array($item['params'] ?? null) ? $item['params'] : [],
            ], $userId);

            $processed++;
            $lines[] = '• '.$sub->text;
        }

        if ($processed === 0) {
            return AssistantReply::say(__('assistant.actions.unknown'));
        }

        return AssistantReply::say(
            __('assistant.actions.batch_done', ['count' => $processed])."\n\n".implode("\n", $lines),
            [__('assistant.suggestions.portfolio')],
        );
    }

    /**
     * Actualizacion parcial del perfil del arrendador. Solo escribe los
     * campos que la propuesta incluyo; valida email/documento/telefono
     * unicos igual que el formulario de perfil.
     *
     * @param  array<string, mixed>  $params
     */
    private function updateProfile(array $params, int $userId): AssistantReply
    {
        $user = User::query()->find($userId);

        if ($user === null) {
            return AssistantReply::say(__('assistant.actions.failed'));
        }

        $allowed = ['name', 'email', 'document_type', 'document', 'phone_number'];
        $changes = [];

        foreach ($allowed as $field) {
            $value = $this->nullable($params[$field] ?? null);

            if ($value !== null) {
                $changes[$field] = $value;
            }
        }

        if ($changes === []) {
            return AssistantReply::say(__('assistant.replies.need_profile_changes'));
        }

        if (isset($changes['email'])
            && ! filter_var($changes['email'], FILTER_VALIDATE_EMAIL)) {
            return AssistantReply::say(__('assistant.actions.profile_invalid', ['field' => __('assistant.fields.profile_email')]));
        }

        // Unicidad igual que el formulario de perfil: otro usuario no puede
        // tener el mismo correo, documento ni telefono.
        foreach (['email', 'document', 'phone_number'] as $uniqueField) {
            if (! isset($changes[$uniqueField])) {
                continue;
            }

            $taken = User::query()
                ->where($uniqueField, $changes[$uniqueField])
                ->whereKeyNot($user->id)
                ->exists();

            if ($taken) {
                return AssistantReply::say(__('assistant.actions.profile_taken', [
                    'field' => __('assistant.fields.profile_'.$uniqueField),
                ]));
            }
        }

        if (isset($changes['document_type'])) {
            $changes['document_type'] = strtoupper($changes['document_type']);
        }

        $user->fill($changes)->save();

        return AssistantReply::say(__('assistant.actions.profile_done'), [
            __('assistant.suggestions.show_profile'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function generateInvoices(array $params, int $userId): AssistantReply
    {
        $periodStart = $this->billingPeriodStart($params['period'] ?? null);

        if ($periodStart === null) {
            return AssistantReply::say(__('assistant.replies.need_period'));
        }

        $result = $this->billing->billAllFor($userId, $periodStart);

        return AssistantReply::say(
            __('assistant.actions.invoices_done', [
                'created' => $result['created'],
                'skipped' => $result['skipped'],
                'period' => $periodStart->format('m/Y'),
            ]),
        );
    }

    /**
     * "2027-01", "2027", "enero 2027", "01/2027" -> primer dia del mes;
     * null si no se interpreta (el usuario puede haber editado el campo
     * del preview con texto libre).
     */
    private function billingPeriodStart(mixed $raw): ?Carbon
    {
        $value = trim((string) ($raw ?? ''));

        if ($value === '') {
            return today()->startOfMonth();
        }

        foreach (['Y-m-d', 'Y-m', 'm/Y', 'Y'] as $format) {
            try {
                if (Carbon::hasFormat($value, $format)) {
                    return Carbon::createFromFormat($format, $value)->startOfMonth();
                }
            } catch (\Throwable) {
                continue;
            }
        }

        try {
            return Carbon::parse($value)->startOfMonth();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function recordPayment(array $params, int $userId): AssistantReply
    {
        $invoice = Invoice::query()
            ->where('user_id', $userId)
            ->whereKey((int) ($params['invoice_id'] ?? 0))
            ->with('rental:id,name,tenant_id', 'rental.tenant:id,name')
            ->first();

        if ($invoice === null) {
            return AssistantReply::say(__('assistant.actions.invoice_missing'));
        }

        if (! in_array($invoice->status, [InvoiceStatus::EMITIDA, InvoiceStatus::VENCIDA], true)) {
            return AssistantReply::say(__('assistant.actions.invoice_closed', [
                'number' => $invoice->number,
            ]));
        }

        try {
            $paidAt = isset($params['date'])
                ? Carbon::parse((string) $params['date'])
                : null;
        } catch (\Throwable) {
            throw new DomainException(__('assistant.actions.need_valid_date'));
        }

        $result = $this->payments->record(
            invoice: $invoice,
            amount: (float) ($params['amount'] ?? $invoice->balance()),
            method: isset($params['method']) ? (string) $params['method'] : null,
            reference: $this->nullable($params['reference'] ?? null),
            paidAt: $paidAt,
        );

        return AssistantReply::say(
            __('assistant.actions.payment_done', [
                'invoice' => $result['invoice']->number,
                'tenant' => $result['invoice']->rental?->tenant?->name ?? '—',
                'amount' => '$'.number_format($result['payment']->amount, 0, ',', '.'),
                'status' => $result['invoice']->status->label(),
            ]),
        );
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function createTenant(array $params, int $userId): AssistantReply
    {
        $name = trim((string) ($params['name'] ?? ''));
        $document = $this->nullable($params['document'] ?? null);
        $phone = $this->nullable($params['phone_number'] ?? $params['phone'] ?? null);

        if ($name === '' || $document === null || $phone === null) {
            return AssistantReply::say(__('assistant.replies.need_tenant_details', [
                'missing' => __('assistant.fields.all_tenant'),
            ]));
        }

        $tenant = Tenant::query()->create([
            'user_id' => $userId,
            'name' => $name,
            'phone_number' => $phone,
            'document' => $document,
            'email' => $this->nullable($params['email'] ?? null),
        ]);

        return AssistantReply::say(__('assistant.actions.tenant_done', [
            'name' => $tenant->name,
            'phone' => $tenant->phone_number ?? '—',
        ]));
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function createProperty(array $params, int $userId): AssistantReply
    {
        $name = trim((string) ($params['name'] ?? ''));
        $address = $this->nullable($params['address'] ?? null);

        if ($name === '' || $address === null) {
            return AssistantReply::say(__('assistant.replies.need_property_details', [
                'missing' => __('assistant.fields.all_property'),
            ]));
        }

        $property = Property::query()->create([
            'user_id' => $userId,
            'name' => $name,
            'address' => $address,
        ]);

        return AssistantReply::say(__('assistant.actions.property_done', [
            'name' => $property->name,
            'address' => $property->address ?? '—',
        ]));
    }

    /**
     * Crea el arriendo completo. Si el inquilino o la propiedad no existian
     * al proponer la accion, se crean aqui con el nombre que el usuario dio:
     * la confirmacion cubre todo el paquete.
     *
     * @param  array<string, mixed>  $params
     */
    private function createRental(array $params, int $userId): AssistantReply
    {
        $tenant = $this->resolveOrCreateTenant($params, $userId);
        $property = $this->resolveOrCreateProperty($params, $userId);

        $start = Carbon::parse((string) ($params['start_date'] ?? today()->toDateString()))->startOfDay();
        $months = max(1, (int) ($params['total_months'] ?? 12));
        $amount = round((float) ($params['monthly_amount'] ?? 0), 2);

        if ($amount <= 0) {
            return AssistantReply::say(__('assistant.replies.need_rental_details', ['missing' => __('assistant.fields.amount')]));
        }

        $cadence = BillingCadence::tryFrom(strtoupper((string) ($params['billing_cadence'] ?? 'MENSUAL')))
            ?? BillingCadence::MENSUAL;

        $endDate = RentalPeriod::for($start, $months)->endDateFormatted();

        $rental = Rental::query()->create([
            'user_id' => $userId,
            'tenant_id' => $tenant->id,
            'property_id' => $property->id,
            'name' => Str::limit($tenant->name.' — '.$property->name, 100, ''),
            'description' => null,
            'start_date' => $start->toDateString(),
            'end_date' => $endDate,
            'total_months' => $months,
            'total_persons' => null,
            'monthly_amount' => $amount,
            'agreement_path' => null,
            'is_active' => true,
            'billing_cadence' => $cadence->value,
            'rental_type' => RentalType::Residential->value,
            'commercial_value' => null,
            'catastral_appraisal' => null,
            'anchor_monthly_amount' => $amount,
            'anchor_established_on' => $start->toDateString(),
        ]);

        return AssistantReply::say(__('assistant.actions.rental_done', [
            'tenant' => $tenant->name,
            'property' => $property->name,
            'amount' => '$'.number_format($amount, 0, ',', '.'),
            'months' => $months,
            'start' => $start->toDateString(),
        ]), [
            __('assistant.suggestions.generate_invoices'),
            __('assistant.suggestions.portfolio'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function resolveOrCreateTenant(array $params, int $userId): Tenant
    {
        $id = (int) ($params['tenant_id'] ?? 0);

        if ($id > 0) {
            $tenant = Tenant::query()->where('user_id', $userId)->find($id);

            if ($tenant !== null) {
                return $tenant;
            }
        }

        $name = trim((string) ($params['tenant_name'] ?? ''));

        if ($name === '') {
            throw new DomainException(__('assistant.replies.need_tenant_name'));
        }

        $existing = Tenant::query()
            ->where('user_id', $userId)
            ->get()
            ->first(fn (Tenant $t): bool => Str::contains(Str::lower($t->name), Str::lower($name)));

        if ($existing !== null) {
            return $existing;
        }

        $document = $this->nullable($params['tenant_document'] ?? $params['document'] ?? null);
        $phone = $this->nullable($params['tenant_phone'] ?? $params['phone'] ?? null);

        if ($document === null || $phone === null) {
            throw new DomainException(__('assistant.replies.need_tenant_details', [
                'missing' => __('assistant.fields.all_tenant'),
            ]));
        }

        return Tenant::query()->create([
            'user_id' => $userId,
            'name' => $name,
            'document' => $document,
            'phone_number' => $phone,
        ]);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function resolveOrCreateProperty(array $params, int $userId): Property
    {
        $id = (int) ($params['property_id'] ?? 0);

        if ($id > 0) {
            $property = Property::query()->where('user_id', $userId)->find($id);

            if ($property !== null) {
                return $property;
            }
        }

        $name = trim((string) ($params['property_name'] ?? ''));

        if ($name === '') {
            throw new DomainException(__('assistant.replies.need_property_name'));
        }

        $existing = Property::query()
            ->where('user_id', $userId)
            ->get()
            ->first(fn (Property $p): bool => Str::contains(Str::lower($p->name), Str::lower($name)));

        if ($existing !== null) {
            return $existing;
        }

        $address = $this->nullable($params['property_address'] ?? $params['address'] ?? null);

        if ($address === null) {
            throw new DomainException(__('assistant.replies.need_property_details', [
                'missing' => __('assistant.fields.all_property'),
            ]));
        }

        return Property::query()->create([
            'user_id' => $userId,
            'name' => $name,
            'address' => $address,
        ]);
    }

    // ---------------------------------------------------------------
    // Updates de entidades (diff ya mostrado en el preview)
    // ---------------------------------------------------------------

    /**
     * Campos que cada update acepta. La whitelist existe porque el modal
     * permite editar params: sin ella un input manipulado podria escribir
     * user_id o campos ajenos al diff.
     */
    private const UPDATABLE = [
        'tenant' => ['name', 'document', 'phone_number', 'email'],
        'property' => ['name', 'address', 'description'],
        'rental' => [
            'monthly_amount', 'billing_cadence', 'start_date', 'end_date',
            'total_months', 'commercial_value', 'catastral_appraisal',
            'rental_type', 'is_active', 'description',
        ],
    ];

    /**
     * Campos que el usuario puede dejar vacios para limpiarlos en BD.
     * Mantener sincronizado con AssistantToolInvoker::editableFields:
     * un campo no listado aqui ignorara el null en vez de borrarlo.
     */
    private const NULLABLE_FIELDS = [
        // name/document/phone son obligatorios en el alta: no se pueden
        // limpiar, solo reemplazar.
        'tenant' => ['email'],
        'property' => ['address', 'description'],
        'rental' => ['end_date', 'total_months', 'commercial_value', 'catastral_appraisal', 'description'],
    ];

    /**
     * @param  array<string, mixed>  $params
     */
    private function updateTenant(array $params, int $userId): AssistantReply
    {
        $tenant = Tenant::query()->where('user_id', $userId)
            ->find((int) ($params['tenant_id'] ?? 0));

        if ($tenant === null) {
            return AssistantReply::say(__('assistant.actions.entity_missing'));
        }

        $changes = $this->whitelistChanges($params, 'tenant');

        if ($changes === []) {
            return AssistantReply::say(__('assistant.actions.no_changes'));
        }

        // Email/documento/teléfono no tienen unique en Tenant, pero el
        // formulario si valida formato de correo.
        if (isset($changes['email']) && ! filter_var($changes['email'], FILTER_VALIDATE_EMAIL)) {
            return AssistantReply::say(__('assistant.actions.profile_invalid', ['field' => __('assistant.fields.email')]));
        }

        // El nombre ANTES de guardar: tras fill() ya es el nuevo y el
        // mensaje "actualizado X de Y" quedaria incoherente.
        $before = $tenant->name;
        $tenant->fill($changes)->save();

        if (isset($changes['name'])) {
            return AssistantReply::say(__('assistant.actions.name_updated', [
                'from' => $before,
                'to' => $tenant->name,
            ]), [
                __('assistant.suggestions.tenants'),
            ]);
        }

        return AssistantReply::say(__('assistant.actions.tenant_updated', [
            'name' => $before,
            'fields' => $this->fieldList($changes),
        ]), [
            __('assistant.suggestions.tenants'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function updateProperty(array $params, int $userId): AssistantReply
    {
        $property = Property::query()->where('user_id', $userId)
            ->find((int) ($params['property_id'] ?? 0));

        if ($property === null) {
            return AssistantReply::say(__('assistant.actions.entity_missing'));
        }

        $changes = $this->whitelistChanges($params, 'property');

        if ($changes === []) {
            return AssistantReply::say(__('assistant.actions.no_changes'));
        }

        $before = $property->name;
        $property->fill($changes)->save();

        if (isset($changes['name'])) {
            return AssistantReply::say(__('assistant.actions.property_name_updated', [
                'from' => $before,
                'to' => $property->name,
            ]), [
                __('assistant.suggestions.properties'),
            ]);
        }

        return AssistantReply::say(__('assistant.actions.property_updated', [
            'name' => $before,
            'fields' => $this->fieldList($changes),
        ]), [
            __('assistant.suggestions.properties'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function updateRental(array $params, int $userId): AssistantReply
    {
        $rental = Rental::query()->where('user_id', $userId)
            ->find((int) ($params['rental_id'] ?? 0));

        if ($rental === null) {
            return AssistantReply::say(__('assistant.actions.entity_missing'));
        }

        $changes = $this->whitelistChanges($params, 'rental');

        if ($changes === []) {
            return AssistantReply::say(__('assistant.actions.no_changes'));
        }

        // Casts del modelo: enums y booleanos llegan como string desde el
        // chat; se normalizan antes del fill.
        if (array_key_exists('billing_cadence', $changes)) {
            $changes['billing_cadence'] = BillingCadence::tryFrom(Str::upper((string) $changes['billing_cadence']))?->value
                ?? BillingCadence::MENSUAL->value;
        }

        if (array_key_exists('rental_type', $changes)) {
            $changes['rental_type'] = RentalType::tryFrom(Str::lower((string) $changes['rental_type']))?->value
                ?? RentalType::Residential->value;
        }

        if (array_key_exists('is_active', $changes)) {
            // (bool) 'false' es true en PHP: hay que normalizar strings.
            $raw = Str::lower(trim((string) $changes['is_active']));
            $changes['is_active'] = ! in_array($raw, ['0', 'false', 'no', 'off', ''], true);
        }

        $rental->fill($changes)->save();

        return AssistantReply::say(__('assistant.actions.rental_updated', [
            'name' => $rental->name,
            'fields' => $this->fieldList($changes),
        ]), [
            __('assistant.suggestions.rentals'),
        ]);
    }

    /**
     * Nombres de campo legibles para el mensaje de confirmacion:
     * "Teléfono, Correo" en vez de "phone_number, email". Reutiliza las
     * etiquetas de assistant.fields y les quita el articulo.
     *
     * @param  array<string, mixed>  $changes
     */
    private function fieldList(array $changes): string
    {
        // Campos del modelo -> clave en assistant.fields (algunos difieren:
        // phone_number se dice "teléfono", no "phone_number").
        $keyMap = [
            'phone_number' => 'phone',
            'default_city_code' => 'default_city',
        ];

        $labels = [];

        foreach (array_keys($changes) as $field) {
            $key = $keyMap[$field] ?? $field;
            $label = __('assistant.fields.'.$key);

            if ($label === 'assistant.fields.'.$key) {
                $label = __("assistant.columns.{$field}");
            }

            if ($label === "assistant.columns.{$field}") {
                $label = str_replace('_', ' ', $field);
            }

            $label = trim((string) preg_replace('/^(?:el|la|los|las|the)\s+/iu', '', $label));
            $labels[] = $label === '' ? $field : mb_strtoupper(mb_substr($label, 0, 1)).mb_substr($label, 1);
        }

        return implode(', ', $labels);
    }

    /**
     * Filtra params a los campos actualizables de la entidad, descartando
     * nulls (el diff no propuso ese campo) y valores no escalares.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function whitelistChanges(array $params, string $entity): array
    {
        $changes = [];

        foreach (self::UPDATABLE[$entity] as $field) {
            if (! array_key_exists($field, $params)) {
                continue;
            }

            $value = $params[$field];

            // null explicito = "limpiar el campo", pero solo donde la
            // columna lo admite; en los demas se ignora.
            if ($value === null) {
                if (in_array($field, self::NULLABLE_FIELDS[$entity] ?? [], true)) {
                    $changes[$field] = null;
                }

                continue;
            }

            if (! is_scalar($value) && ! is_bool($value)) {
                continue;
            }

            $changes[$field] = $value;
        }

        return $changes;
    }

    // ---------------------------------------------------------------
    // Ciclo de vida de reajustes
    // ---------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $params
     */
    private function ownedAdjustment(array $params, int $userId): ?RentAdjustment
    {
        return RentAdjustment::query()
            ->where('user_id', $userId)
            ->find((int) ($params['adjustment_id'] ?? 0));
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function recordAdjustment(array $params, int $userId): AssistantReply
    {
        $rental = Rental::query()->where('user_id', $userId)
            ->find((int) ($params['rental_id'] ?? 0));

        if ($rental === null) {
            return AssistantReply::say(__('assistant.actions.entity_missing'));
        }

        try {
            $effectiveFrom = Carbon::parse((string) ($params['effective_from'] ?? today()->toDateString()));
        } catch (\Throwable) {
            throw new DomainException(__('assistant.actions.need_valid_date'));
        }

        $newRent = (float) ($params['new_rent'] ?? 0);

        if ($newRent <= 0) {
            return AssistantReply::say(__('assistant.replies.need_rental_details', ['missing' => __('assistant.fields.new_rent')]));
        }

        $basis = \App\Enums\IpcBasis::tryFrom((string) ($params['basis'] ?? '')) ?? \App\Enums\IpcBasis::National;

        try {
            $adjustment = $this->rentals->recordAdjustment(
                rental: $rental,
                newRent: $newRent,
                effectiveFrom: $effectiveFrom,
                basis: $basis,
                geographyCode: $this->nullable($params['geography_code'] ?? null),
                pactadoPercentage: isset($params['pactado_percentage']) && is_numeric($params['pactado_percentage'])
                    ? (float) $params['pactado_percentage'] : null,
                notes: $this->nullable($params['notes'] ?? null),
            );
        } catch (DomainException $exception) {
            return AssistantReply::say($exception->getMessage());
        }

        return AssistantReply::say(__('assistant.actions.adjustment_recorded', [
            'rental' => $rental->name,
            'new' => '$'.number_format($newRent, 0, ',', '.'),
            'date' => $effectiveFrom->toDateString(),
        ]), [
            __('assistant.suggestions.adjustments'),
            __('assistant.suggestions.mark_notified'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function applyAdjustment(array $params, int $userId): AssistantReply
    {
        $adjustment = $this->ownedAdjustment($params, $userId);

        if ($adjustment === null) {
            return AssistantReply::say(__('assistant.actions.entity_missing'));
        }

        if (! $adjustment->canApply()) {
            return AssistantReply::say(__('assistant.replies.adjustment_cannot_apply', [
                'state' => $adjustment->state()->label(),
            ]));
        }

        try {
            $rental = $this->rentals->applyAdjustment($adjustment);
        } catch (DomainException $exception) {
            return AssistantReply::say($exception->getMessage());
        }

        return AssistantReply::say(__('assistant.actions.adjustment_applied', [
            'rental' => $rental->name,
            'new' => '$'.number_format((float) $adjustment->new_rent, 0, ',', '.'),
        ]), [__('assistant.suggestions.adjustments')]);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function markAdjustmentNotified(array $params, int $userId): AssistantReply
    {
        $adjustment = $this->ownedAdjustment($params, $userId);

        if ($adjustment === null) {
            return AssistantReply::say(__('assistant.actions.entity_missing'));
        }

        if (! $adjustment->canNotify()) {
            return AssistantReply::say(__('assistant.replies.adjustment_cannot_notify', [
                'state' => $adjustment->state()->label(),
            ]));
        }

        $method = NotificationMethod::tryFrom((string) ($params['method'] ?? '')) ?? NotificationMethod::Contractual;

        try {
            $notifiedOn = Carbon::parse((string) ($params['notified_on'] ?? today()->toDateString()));
        } catch (\Throwable) {
            throw new DomainException(__('assistant.actions.need_valid_date'));
        }

        try {
            $this->rentals->markAdjustmentNotified(
                $adjustment,
                $notifiedOn,
                $method,
            );
        } catch (DomainException $exception) {
            return AssistantReply::say($exception->getMessage());
        }

        return AssistantReply::say(__('assistant.actions.adjustment_notified', [
            'method' => $method->label(),
        ]), [__('assistant.suggestions.apply_adjustment')]);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function recalculateAdjustments(array $params, int $userId): AssistantReply
    {
        $rental = Rental::query()->where('user_id', $userId)
            ->find((int) ($params['rental_id'] ?? 0));

        if ($rental === null) {
            return AssistantReply::say(__('assistant.actions.entity_missing'));
        }

        try {
            $result = $this->rentals->recalculateAdjustmentChain($rental);
        } catch (DomainException $exception) {
            return AssistantReply::say($exception->getMessage());
        }

        return AssistantReply::say(__('assistant.actions.chain_recalculated', [
            'rental' => $rental->name,
            'touched' => $result['touched'],
            'rent' => '$'.number_format($result['current_rent'], 0, ',', '.'),
        ]), [__('assistant.suggestions.adjustments')]);
    }

    // ---------------------------------------------------------------
    // Facturas, documentos y facturacion por alquiler
    // ---------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $params
     */
    private function annulInvoice(array $params, int $userId): AssistantReply
    {
        $invoice = Invoice::query()->where('user_id', $userId)
            ->find((int) ($params['invoice_id'] ?? 0));

        if ($invoice === null) {
            return AssistantReply::say(__('assistant.actions.invoice_missing'));
        }

        if ($invoice->status === InvoiceStatus::ANULADA) {
            return AssistantReply::say(__('assistant.replies.invoice_already_void', [
                'number' => $invoice->number,
            ]));
        }

        $invoice->update(['status' => InvoiceStatus::ANULADA]);

        return AssistantReply::say(__('assistant.actions.invoice_voided', [
            'number' => $invoice->number,
        ]), [__('assistant.suggestions.invoices')]);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function billRentalInvoices(array $params, int $userId): AssistantReply
    {
        $rental = Rental::query()->where('user_id', $userId)
            ->find((int) ($params['rental_id'] ?? 0));

        if ($rental === null) {
            return AssistantReply::say(__('assistant.actions.entity_missing'));
        }

        $periodStart = $this->billingPeriodStart($params['period'] ?? null);

        if ($periodStart === null) {
            return AssistantReply::say(__('assistant.replies.need_period'));
        }

        // billPeriodFor devuelve la lista de facturas aseguradas del periodo
        // (una por corte: mensual o dos quincenales), no un resumen.
        $invoices = $this->billing->billPeriodFor($rental, $periodStart);

        return AssistantReply::say(
            $invoices === []
                ? __('assistant.actions.rental_invoices_none', [
                    'rental' => $rental->name,
                    'period' => $periodStart->format('m/Y'),
                ])
                : __('assistant.actions.rental_invoices_done', [
                    'rental' => $rental->name,
                    'period' => $periodStart->format('m/Y'),
                    'created' => count($invoices),
                ]),
            [__('assistant.suggestions.invoices')],
        );
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function createDeliveryAct(array $params, int $userId): AssistantReply
    {
        try {
            $act = $this->acts->record($params, $userId);
        } catch (\RuntimeException $exception) {
            return AssistantReply::say($exception->getMessage());
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return AssistantReply::say(__('assistant.actions.entity_missing'));
        }

        return AssistantReply::say(__('assistant.actions.act_done', [
            'rental' => $act->rental?->name ?? '—',
        ]), [
            __('assistant.suggestions.delivery_acts'),
            __('assistant.suggestions.portfolio'),
        ]);
    }

    // ---------------------------------------------------------------
    // Settings, IPC y exportacion
    // ---------------------------------------------------------------

    /**
     * Settings es fila unica del negocio (no por usuario): la confirmacion
     * escribe lo que el diff mostro. Se re-mergea sobre current() para no
     * pisar campos que el usuario no toco.
     *
     * @param  array<string, mixed>  $params
     */
    private function updateSettings(array $params, int $userId): AssistantReply
    {
        $allowed = ['business_name', 'tax_id', 'address', 'email', 'phone',
            'currency', 'invoice_due_days', 'legal_footer', 'default_city_code'];

        $data = $this->settings->current();
        $changes = [];
        $changed = false;

        foreach ($allowed as $field) {
            if (! array_key_exists($field, $params) || $params[$field] === null) {
                continue;
            }

            $data[$field] = $params[$field];
            $changes[$field] = $params[$field];
            $changed = true;
        }

        if (! $changed) {
            return AssistantReply::say(__('assistant.actions.no_changes'));
        }

        $this->settings->save($data);

        return AssistantReply::say(__('assistant.actions.settings_done', [
            'fields' => $this->fieldList($changes),
        ]), [
            __('assistant.suggestions.settings'),
        ]);
    }

    private function syncIpc(int $userId): AssistantReply
    {
        try {
            $results = $this->ipc->syncAll(includeCities: false);
        } catch (Throwable $exception) {
            report($exception);

            return AssistantReply::say(__('assistant.actions.ipc_failed', [
                'error' => $exception->getMessage(),
            ]));
        }

        $rows = collect($results)->sum(fn (array $row): int => (int) ($row['stored'] ?? 0));

        return AssistantReply::say(__('assistant.actions.ipc_done', [
            'rows' => $rows,
        ]), [__('assistant.suggestions.ipc_status')]);
    }

    /**
     * Genera el documento y lo deja en cache con un token: el chat muestra
     * el preview y el boton de descarga pide el archivo por token.
     *
     * @param  array<string, mixed>  $params
     */
    private function generateDocument(array $params, int $userId): AssistantReply
    {
        $document = $this->resolveDocument($params, $userId);

        if ($document === null) {
            return AssistantReply::say(__('assistant.actions.document_failed'));
        }

        // El estado de cuenta de un periodo sin facturas es un documento
        // vacio: mejor un mensaje claro que un PDF en cero.
        if ($document->isEmpty()) {
            return AssistantReply::say(__('assistant.replies.document_no_movements'));
        }

        $token = (string) Str::uuid();

        Cache::put("assistant:file:{$token}", [
            'user_id' => $userId,
            'filename' => $document->filename(),
            'mime' => $document->mimeType(),
            'content' => $document->content(),
        ], now()->addMinutes(30));

        return new AssistantReply(
            text: __('assistant.actions.document_done', [
                'filename' => $document->filename(),
            ]),
            data: [
                'file' => [
                    'token' => $token,
                    'filename' => $document->filename(),
                    'preview' => $document->isDownload() ? null : $document->preview(),
                ],
            ],
            suggestions: [__('assistant.suggestions.documents')],
        );
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function resolveDocument(array $params, int $userId): ?\App\Documents\Contracts\Document
    {
        $kind = (string) ($params['kind'] ?? 'invoice');
        $format = $this->nullable($params['format'] ?? null);

        // findOrFail lanza ModelNotFoundException (ajeno o inexistente) y
        // los generadores lanzan DomainException con el motivo real
        // (factura sin pagar para comprobante, etc.): ambos mensajes son
        // utiles, asi que solo se traduce el "no existe" y el resto sube.
        try {
            return match ($kind) {
                'receipt' => $this->rentals->receiptFor(
                    Invoice::query()->where('user_id', $userId)->findOrFail((int) ($params['invoice_id'] ?? 0)),
                    $format,
                ),
                'adjustment_letter' => $this->rentals->adjustmentLetterFor(
                    RentAdjustment::query()->where('user_id', $userId)->findOrFail((int) ($params['adjustment_id'] ?? 0)),
                    $format,
                ),
                'statement' => $this->rentals->statementFor(
                    Rental::query()->where('user_id', $userId)->findOrFail((int) ($params['rental_id'] ?? 0)),
                    (string) ($params['period'] ?? today()->format('Y-m')),
                    $format,
                ),
                default => $this->rentals->invoiceFor(
                    Invoice::query()->where('user_id', $userId)->findOrFail((int) ($params['invoice_id'] ?? 0)),
                    $format,
                ),
            };
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            throw new DomainException(__('assistant.actions.entity_missing'));
        }
    }

    /**
     * CSV de la ultima tabla mostrada: el chat inyecta las filas en
     * params.tables al proponer; aqui se serializan y cachean.
     *
     * @param  array<string, mixed>  $params
     */
    private function exportCsv(array $params, int $userId): AssistantReply
    {
        $tables = is_array($params['tables'] ?? null) ? $params['tables'] : [];

        if ($tables === []) {
            return AssistantReply::say(__('assistant.actions.export_empty'));
        }

        // Una sola tabla por ahora: la primera con filas.
        $rows = [];
        $headers = [];

        foreach ($tables as $table) {
            if (! is_array($table)) {
                continue;
            }

            $tableRows = $table['rows'] ?? $table;
            $tableHeaders = $table['headers'] ?? null;

            if (! is_array($tableRows) || $tableRows === []) {
                continue;
            }

            if (is_array($tableHeaders) && $tableHeaders !== []) {
                $headers = array_values(array_map('strval', $tableHeaders));
            }

            $rows = array_values(array_filter($tableRows, 'is_array'));

            break;
        }

        if ($rows === []) {
            return AssistantReply::say(__('assistant.actions.export_empty'));
        }

        if ($headers === []) {
            $headers = array_keys($rows[0]);
        }

        $csv = fopen('php://temp', 'r+');
        fputcsv($csv, $headers, ';');

        foreach ($rows as $row) {
            fputcsv($csv, array_map(
                fn ($key) => is_scalar($row[$key] ?? null) ? (string) $row[$key] : '',
                $headers,
            ), ';');
        }

        rewind($csv);
        $content = stream_get_contents($csv);
        fclose($csv);

        $filename = 'assistant-'.now()->format('Ymd-His').'.csv';
        $token = (string) Str::uuid();

        Cache::put("assistant:file:{$token}", [
            'user_id' => $userId,
            'filename' => $filename,
            'mime' => 'text/csv; charset=UTF-8',
            'content' => $content,
        ], now()->addMinutes(30));

        return new AssistantReply(
            text: __('assistant.actions.export_done', [
                'filename' => $filename,
                'rows' => count($rows),
            ]),
            data: [
                'file' => [
                    'token' => $token,
                    'filename' => $filename,
                ],
            ],
            suggestions: [__('assistant.suggestions.portfolio')],
        );
    }

    /**
     * Devuelve el archivo cacheado por token para la descarga del chat.
     *
     * @return array{filename: string, mime: string, content: string}|null
     */
    public function fileForDownload(string $token, int $userId): ?array
    {
        $file = Cache::get("assistant:file:{$token}");

        // El token es un uuid aleatorio, pero el contenido puede llevar
        // datos del negocio: solo el usuario que lo genero lo descarga.
        if (! is_array($file) || (int) ($file['user_id'] ?? 0) !== $userId) {
            return null;
        }

        return $file;
    }

    private function nullable(mixed $value): ?string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        return $value === '' ? null : $value;
    }
}
