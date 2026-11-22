<?php

namespace App\Services\Assistant;

use App\Dto\AssistantReply;
use App\Enums\BillingCadence;
use App\Enums\InvoiceStatus;
use App\Enums\RentalType;
use App\Models\Invoice;
use App\Models\Property;
use App\Models\Rental;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\RecurringBillingService;
use App\Services\PaymentRecordingService;
use App\ValueObjects\RentalPeriod;
use DomainException;
use Illuminate\Support\Carbon;
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
                'batch' => $this->executeBatch($action['items'] ?? [], $userId),
                default => AssistantReply::say(__('assistant.actions.unknown')),
            };
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
        $period = (string) ($params['period'] ?? today()->format('Y-m'));
        $periodStart = Carbon::createFromFormat('Y-m-d', $period.'-01')->startOfMonth();

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
     * @param  array<string, mixed>  $params
     */
    private function recordPayment(array $params, int $userId): AssistantReply
    {
        $invoice = Invoice::query()
            ->where('user_id', $userId)
            ->whereKey((int) ($params['invoice_id'] ?? 0))
            ->with('rental:id,name', 'rental.tenant:id,name')
            ->first();

        if ($invoice === null) {
            return AssistantReply::say(__('assistant.actions.invoice_missing'));
        }

        if (! in_array($invoice->status, [InvoiceStatus::EMITIDA, InvoiceStatus::VENCIDA], true)) {
            return AssistantReply::say(__('assistant.actions.invoice_closed', [
                'number' => $invoice->number,
            ]));
        }

        $result = $this->payments->record(
            invoice: $invoice,
            amount: (float) ($params['amount'] ?? $invoice->balance()),
            method: isset($params['method']) ? (string) $params['method'] : null,
            paidAt: isset($params['date']) ? Carbon::parse((string) $params['date']) : null,
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

    private function nullable(mixed $value): ?string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        return $value === '' ? null : $value;
    }
}
