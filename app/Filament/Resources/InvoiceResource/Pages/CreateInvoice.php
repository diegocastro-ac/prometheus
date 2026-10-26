<?php

namespace App\Filament\Resources\InvoiceResource\Pages;

use App\Filament\Resources\InvoiceResource;
use App\Models\Invoice;
use App\Services\InvoiceNumberService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Carbon;

class CreateInvoice extends CreateRecord
{
    protected static string $resource = InvoiceResource::class;

    protected static ?string $title = null;

    public function getTitle(): string
    {
        return __('invoice.buttons.create');
    }

    /**
     * El consecutivo no se escribe en el formulario: lo genera el servicio por
     * landlord, asi que no puede quedar duplicado.
     *
     * @param  array<string, mixed>  $data
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['status'] = 'emitida';
        $data['user_id'] = auth()->id();

        // "other" es una opcion del desplegable, no un concepto guardable: se
        // reemplaza por lo que escribio el usuario.
        if (($data['concept'] ?? null) === 'other') {
            $data['concept'] = trim((string) ($data['custom_concept'] ?? ''));

            if ($data['concept'] === '') {
                $data['concept'] = __('invoice.concepts.other');
            }
        }

        unset($data['custom_concept']);

        $issuedAt = isset($data['issued_at']) ? Carbon::parse($data['issued_at']) : now();

        $data['number'] = app(InvoiceNumberService::class)->next(
            (int) auth()->id(),
            (int) $issuedAt->format('Y'),
        );

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function afterCreate(): void
    {
        Notification::make()
            ->success()
            ->title(__('invoice.created'))
            ->body($this->record->number)
            ->send();
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}