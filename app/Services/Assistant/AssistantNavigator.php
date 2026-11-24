<?php

namespace App\Services\Assistant;

use App\Enums\InvoiceStatus;
use App\Filament\Pages\AjustesPage;
use App\Filament\Pages\DocumentsPage;
use App\Filament\Pages\EditProfile;
use App\Filament\Pages\FinanzasPage;
use App\Filament\Resources\DeliveryActResource;
use App\Filament\Resources\InvoiceResource;
use App\Filament\Resources\IpcSeriesResource;
use App\Filament\Resources\PropertyResource;
use App\Filament\Resources\RentAdjustmentResource;
use App\Filament\Resources\RentalResource;
use App\Filament\Resources\TenantResource;
use App\Models\Invoice;
use App\Models\Property;
use App\Models\Rental;
use App\Models\Tenant;
use Illuminate\Support\Str;

/**
 * Resuelve el destino inteligente de una navegacion del copiloto.
 *
 * El modelo (o el usuario) dice QUE quiere ver — una seccion, una entidad
 * concreta, un filtro — y este servicio decide la MEJOR pantalla:
 *
 *   entidad resuelta a UN arriendo  -> pagina "Ver arriendo" (infolist +
 *                                     facturas del arriendo)
 *   entidad sin pagina propia       -> lista filtrada con ?tableSearch=
 *   coleccion/general               -> pagina de la seccion, con filtro o
 *                                     busqueda si aplica
 *
 * Centralizarlo aqui evita que cada emisor de URLs (intent navigate,
 * entity_detail, acciones de reportes) duplique la logica de resolucion.
 */
class AssistantNavigator
{
    /**
     * Targets que tienen tabla con buscador (?tableSearch= via el trait
     * AssistantTableSearch). finances/documents/profile/settings no.
     */
    private const TABLE_SEARCH_TARGETS = [
        'invoices', 'rentals', 'tenants', 'properties',
        'delivery_acts', 'adjustments', 'ipc',
    ];

    /**
     * Targets donde la pagina "Ver arriendo" es el mejor destino cuando
     * la entidad resuelve a un unico arriendo (o un inquilino/propiedad
     * con un solo arriendo activo): ahi viven las facturas y el detalle
     * del contrato.
     */
    private const RENTAL_DETAIL_TARGETS = ['invoices', 'rentals'];

    /** Estados de factura aceptados en ?af[status]= (espejo de ListInvoices). */
    private const INVOICE_STATUS_FILTERS = ['EMITIDA', 'VENCIDA', 'PAGADA', 'ANULADA'];

    /**
     * @return array<string, class-string>|null  mapa target => pagina; null si el target no existe
     */
    private function pageFor(string $target): ?array
    {
        return match ($target) {
            'invoices' => ['class' => InvoiceResource::class, 'index' => InvoiceResource::getUrl('index'), 'search' => true],
            'rentals' => ['class' => RentalResource::class, 'index' => RentalResource::getUrl('index'), 'search' => true],
            'tenants' => ['class' => TenantResource::class, 'index' => TenantResource::getUrl('index'), 'search' => true],
            'properties' => ['class' => PropertyResource::class, 'index' => PropertyResource::getUrl('index'), 'search' => true],
            'delivery_acts' => ['class' => DeliveryActResource::class, 'index' => DeliveryActResource::getUrl('index'), 'search' => true],
            'adjustments' => ['class' => RentAdjustmentResource::class, 'index' => RentAdjustmentResource::getUrl('index'), 'search' => true],
            'finances' => ['class' => FinanzasPage::class, 'index' => FinanzasPage::getUrl(), 'search' => false],
            'documents' => ['class' => DocumentsPage::class, 'index' => DocumentsPage::getUrl(), 'search' => false],
            'profile' => ['class' => EditProfile::class, 'index' => EditProfile::getUrl(), 'search' => false],
            'settings' => ['class' => AjustesPage::class, 'index' => AjustesPage::getUrl(), 'search' => false],
            'ipc' => ['class' => IpcSeriesResource::class, 'index' => IpcSeriesResource::getUrl('index'), 'search' => true],
            default => null,
        };
    }

    public function supports(string $target): bool
    {
        return $this->pageFor($target) !== null;
    }

    public function hasTableSearch(string $target): bool
    {
        return in_array($target, self::TABLE_SEARCH_TARGETS, true);
    }

    /**
     * URL final para una navegacion. Devuelve null si el target no existe.
     *
     * @return array{url: string, mode: 'entity'|'filtered'|'search'|'plain', term: string|null}|null
     */
    public function urlFor(string $target, ?string $entity, ?string $filter, ?string $search, int $userId): ?array
    {
        $page = $this->pageFor($target);

        if ($page === null) {
            return null;
        }

        $filter = strtoupper(trim((string) $filter));
        $search = trim((string) $search);
        $entity = trim((string) $entity);

        $entityTerm = null;
        $detailUrl = null;

        if ($entity !== '' && mb_strlen($entity) >= 3) {
            $resolved = $this->resolveEntity($entity, $userId);

            if ($resolved !== null) {
                $entityTerm = $resolved['term'];
                $detailUrl = $resolved['detail_url'] ?? null;
            }
        }

        return $this->buildUrl($target, $entityTerm, $detailUrl, $filter, $search);
    }

    /**
     * Igual que urlFor() pero con la entidad ya resuelta a un modelo:
     * lo usan los intents entity_detail, que conocen el Rental/Tenant/
     * Property/Invoice concreto y no deben re-resolver por nombre (el
     * orden de matching por texto puede elegir otra entidad).
     */
    public function urlForModel(string $target, \Illuminate\Database\Eloquent\Model $model, int $userId): ?array
    {
        $resolved = match (true) {
            $model instanceof Rental => $this->rentalDestination($model, $userId),
            $model instanceof Tenant => $this->tenantDestination($model, $userId),
            $model instanceof Property => $this->propertyDestination($model, $userId),
            $model instanceof Invoice => ['term' => $model->number, 'detail_url' => null],
            default => null,
        };

        if ($resolved === null) {
            return null;
        }

        return $this->buildUrl($target, $resolved['term'], $resolved['detail_url'], '', '');
    }

    /**
     * Logica compartida de decision URL: pagina de detalle si aplica,
     * si no lista con filtro/busqueda, si no pagina plana.
     *
     * @return array{url: string, mode: 'entity'|'filtered'|'search'|'plain', term: string|null}|null
     */
    private function buildUrl(string $target, ?string $entityTerm, ?string $detailUrl, string $filter, string $search): ?array
    {
        $page = $this->pageFor($target);

        if ($page === null) {
            return null;
        }

        $filter = strtoupper(trim($filter));
        $search = trim($search);

        // La pagina "Ver arriendo" resume arriendo + inquilino +
        // propiedad + facturas: solo es el mejor destino para targets
        // centrados en esas entidades. Para delivery_acts, adjustments,
        // ipc, etc. la entidad va como termino de busqueda de la tabla.
        $entityFriendlyTargets = ['invoices', 'rentals', 'tenants', 'properties'];

        // Un filtro de estado no se puede aplicar dentro de la pagina de
        // detalle: si el usuario pidio "facturas vencidas de X" va a la
        // lista filtrada por entidad + estado, no a la vista del arriendo.
        $hasStatusFilter = $target === 'invoices' && in_array($filter, self::INVOICE_STATUS_FILTERS, true);

        if ($detailUrl !== null && in_array($target, $entityFriendlyTargets, true) && ! $hasStatusFilter) {
            return ['url' => $detailUrl, 'mode' => 'entity', 'term' => $entityTerm];
        }

        $term = $search !== '' ? $search : $entityTerm;

        if (! $page['search']) {
            // Secciones sin tabla (finanzas, documentos, perfil...): la
            // entidad no tiene como aterrizar; se ignora y va la pagina.
            return ['url' => $page['index'], 'mode' => 'plain', 'term' => null];
        }

        $query = [];

        if ($hasStatusFilter) {
            $query['af'] = ['status' => Str::lower($filter)];
        }

        if ($term !== null && $term !== '') {
            $query['tableSearch'] = $term;
        }

        $url = $page['index'].($query === [] ? '' : '?'.http_build_query($query));

        if ($hasStatusFilter) {
            return ['url' => $url, 'mode' => 'filtered', 'term' => $term];
        }

        return ['url' => $url, 'mode' => $term !== null && $term !== '' ? 'search' : 'plain', 'term' => $term];
    }

    /**
     * Resuelve un nombre libre a la mejor pagina/termino posible.
     *
     * Orden: arriendo (cubre nombre propio, inquilino y propiedad del
     * arriendo) -> inquilino -> propiedad -> factura. El primero que
     * resuelve gana; si ninguno, la entidad se usa como termino de
     * busqueda crudo.
     *
     * @return array{term: string, detail_url: string|null}|null
     */
    private function resolveEntity(string $entity, int $userId): ?array
    {
        $needle = Str::lower($entity);

        $rental = $this->matchRental($needle, $userId);

        if ($rental !== null) {
            return $this->rentalDestination($rental, $userId);
        }

        $tenant = $this->matchTenant($needle, $userId);

        if ($tenant !== null) {
            return $this->tenantDestination($tenant, $userId);
        }

        $property = $this->matchProperty($needle, $userId);

        if ($property !== null) {
            return $this->propertyDestination($property, $userId);
        }

        $invoice = $this->matchInvoice($needle, $userId);

        if ($invoice !== null) {
            return ['term' => $invoice->number, 'detail_url' => null];
        }

        // Nada resuelto: el termino crudo igual sirve para la tabla.
        return ['term' => $entity, 'detail_url' => null];
    }

    /**
     * @return array{term: string, detail_url: string|null}|null
     */
    private function rentalDestination(Rental $rental, int $userId): ?array
    {
        return [
            'term' => (string) ($rental->tenant?->name ?: $rental->name),
            'detail_url' => RentalResource::getUrl('view', ['record' => $rental]),
        ];
    }

    /**
     * Inquilino: si tiene UN solo arriendo activo la vista de ese
     * arriendo es el mejor destino para invoices/rentals; con varios,
     * la lista filtrada por su nombre.
     *
     * @return array{term: string, detail_url: string|null}|null
     */
    private function tenantDestination(Tenant $tenant, int $userId): ?array
    {
        $active = $tenant->rentals()->where('is_active', true)->get();

        if ($active->count() === 1) {
            return $this->rentalDestination($active->first(), $userId);
        }

        return ['term' => $tenant->name, 'detail_url' => null];
    }

    /**
     * @return array{term: string, detail_url: string|null}|null
     */
    private function propertyDestination(Property $property, int $userId): ?array
    {
        $active = $property->rentals()->where('is_active', true)->get();

        if ($active->count() === 1) {
            return $this->rentalDestination($active->first(), $userId);
        }

        return ['term' => $property->name, 'detail_url' => null];
    }

    private function matchRental(string $needle, int $userId): ?Rental
    {
        $matches = Rental::query()
            ->where('user_id', $userId)
            ->with('tenant:id,name', 'property:id,name')
            ->get()
            ->filter(fn (Rental $rental): bool => Str::contains(Str::lower((string) $rental->name), $needle)
                || Str::contains(Str::lower((string) $rental->tenant?->name), $needle)
                || Str::contains(Str::lower((string) $rental->property?->name), $needle))
            ->values();

        if ($matches->isEmpty()) {
            return null;
        }

        $active = $matches->where('is_active', true)->values();

        if ($active->isNotEmpty()) {
            $matches = $active;
        }

        $exacts = $matches->filter(fn (Rental $rental): bool => Str::lower($rental->name) === $needle
            || Str::lower((string) $rental->tenant?->name) === $needle)->values();

        if ($exacts->count() === 1) {
            return $exacts->first();
        }

        return $matches->first();
    }

    private function matchTenant(string $needle, int $userId): ?Tenant
    {
        $matches = Tenant::query()
            ->where('user_id', $userId)
            ->get(['id', 'name', 'document', 'phone_number', 'email', 'rentals'])
            ->filter(fn (Tenant $tenant): bool => Str::contains(Str::lower((string) $tenant->name), $needle)
                || Str::contains(Str::lower((string) $tenant->document), $needle)
                || Str::contains(Str::lower((string) $tenant->phone_number), $needle));

        if ($matches->isEmpty()) {
            return null;
        }

        $exacts = $matches->filter(fn (Tenant $tenant): bool => Str::lower($tenant->name) === $needle)->values();

        return $exacts->count() === 1 ? $exacts->first() : $matches->first();
    }

    private function matchProperty(string $needle, int $userId): ?Property
    {
        $matches = Property::query()
            ->where('user_id', $userId)
            ->get(['id', 'name', 'address'])
            ->filter(fn (Property $property): bool => Str::contains(Str::lower((string) $property->name), $needle)
                || Str::contains(Str::lower((string) $property->address), $needle));

        if ($matches->isEmpty()) {
            return null;
        }

        $exacts = $matches->filter(fn (Property $property): bool => Str::lower($property->name) === $needle)->values();

        return $exacts->count() === 1 ? $exacts->first() : $matches->first();
    }

    private function matchInvoice(string $needle, int $userId): ?Invoice
    {
        return Invoice::query()
            ->where('user_id', $userId)
            ->where('number', 'like', '%'.$needle.'%')
            ->orderByDesc('issued_at')
            ->first();
    }
}
