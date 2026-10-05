<?php

namespace App\Filament\Resources\RentAdjustmentResource\Pages;

use App\Filament\Resources\RentAdjustmentResource;
use App\Models\RentAdjustment;
use App\Models\Rental;
use App\Services\RentAdjustmentService;
use Illuminate\Support\Carbon;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class CreateRentAdjustment extends CreateRecord
{
    protected static string $resource = RentAdjustmentResource::class;

    /**
     * El alta la hace RentAdjustmentService y no el formulario de Filament.
     *
     * El formulario solo pide canon reajustado, vigencia y año del IPC. El
     * canon anterior, el tope legal, el exceso y la bandera de acuerdo escrito
     * los calcula el servicio, porque son reglas del articulo 20 de la Ley 820
     * y no datos que deba escribir la persona. Si los escribiera el formulario,
     * basta una casilla mal digitada para que el sistema registre un reajuste
     * por encima del tope como si fuera legal.
     *
     * @param  array<string, mixed>  $data
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $rental = Rental::find($data['rental_id']);

        if ($rental === null) {
            Notification::make()
                ->danger()
                ->title('El alquiler seleccionado no existe.')
                ->send();

            $this->halt();
        }

try {
            $this->adjustment = app(RentAdjustmentService::class)->record(
                rental: $rental,
                newRent: (float) $data['new_rent'],
                effectiveFrom: Carbon::parse($data['effective_from']),
                ipcYear: isset($data['ipc_year']) ? (int) $data['ipc_year'] : null,
                notes: $data['notes'] ?? null,
                ipcOutsideStatutoryYear: (bool) ($data['ipc_outside_statutory_year'] ?? false),
            );
        } catch (RuntimeException $exception) {
            Notification::make()
                ->warning()
                ->title($exception->getMessage())
                ->persistent()
                ->send();

            $this->halt();
        }

        // La relacion con el IPC y los importes ya quedaron en el registro; el
        // formulario devuelve solo el alquiler, que es lo unico que Filament
        // necesita para resolver la foreign key.
        return ['rental_id' => $data['rental_id']];
    }

    private ?RentAdjustment $adjustment = null;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function afterCreate(): void
    {
        if ($this->adjustment?->exceedsCap() === true) {
            Notification::make()
                ->warning()
                ->title(__('rent_adjustment.notifications.needs_agreement'))
                ->body(sprintf(
                    'El tope legal con el IPC del %s es %s. El incremento pedido lo supera en %s, '
                    .'asi que solo opera si deja el acuerdo escrito en las observaciones.',
                    $this->adjustment->ipc_year,
                    '$'.number_format((float) $this->adjustment->legal_cap_rent, 0, ',', '.'),
                    '$'.number_format((float) $this->adjustment->excess_over_cap, 0, ',', '.'),
                ))
                ->persistent()
                ->send();

            return;
        }

        Notification::make()
            ->success()
            ->title(__('rent_adjustment.notifications.created'))
            ->send();
    }

    protected function handleRecordCreation(array $data): Model
    {
        // El registro ya se creo en mutateFormDataBeforeCreate.
        return $this->adjustment;
    }
}