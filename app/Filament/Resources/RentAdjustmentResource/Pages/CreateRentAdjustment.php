<?php

namespace App\Filament\Resources\RentAdjustmentResource\Pages;

use App\Enums\IpcBasis;
use App\Filament\Resources\RentAdjustmentResource;
use App\Models\RentAdjustment;
use App\Models\Rental;
use App\Services\RentalFacade;
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
     * El formulario pide canon reajustado, vigencia, año del IPC, base y pactado.
     * El canon anterior, el tope legal, el exceso, la secuencia y toda la
     * trazabilidad los calcula el servicio, porque son reglas del articulo 20 y
     * no datos que deba escribir la persona.
     *
     * Si los escribiera el formulario, bastaria una casilla mal digitada para que
     * el sistema registrara un reajuste con un canon anterior que nunca rigio, y
     * la cadena entera quedaria mal encadenada.
     *
     * @param  array<string, mixed>  $data
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $rental = Rental::find($data['rental_id'] ?? null);

        if ($rental === null) {
            Notification::make()
                ->danger()
                ->title('El alquiler seleccionado no existe.')
                ->send();

            $this->halt();
        }

        $pactado = $data['pactado_pct'] ?? null;

        try {
            $this->adjustment = app(RentalFacade::class)->recordAdjustment(
                rental: $rental,
                newRent: (float) $data['new_rent'],
                effectiveFrom: \Illuminate\Support\Carbon::parse($data['effective_from']),
                ipcYear: isset($data['ipc_year']) ? (int) $data['ipc_year'] : null,
                notes: $data['notes'] ?? null,
                ipcOutsideStatutoryYear: (bool) ($data['ipc_outside_statutory_year'] ?? false),
                basis: IpcBasis::from($data['ipc_basis'] ?? IpcBasis::National->value),
                geographyCode: $data['geography_code'] ?? null,
                pactadoPercentage: ($pactado === null || $pactado === '') ? null : (float) $pactado,
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
        $adjustment = $this->adjustment;

        // El exceso ya no bloquea. Se avisa porque el articulo 20 exige que el
        // acuerdo quede documentado, y eso es lo que hace la carta.
        if ($adjustment?->exceedsCap() === true) {
            Notification::make()
                ->warning()
                ->title(__('rent_adjustment.notifications.needs_agreement'))
                ->body(sprintf(
                    'El IPC del %s da un canon de %s y se pidió %s. Queda por encima en %s. '
                    .'Registre el acuerdo en las observaciones para que la carta lo documente.',
                    $adjustment->ipc_year,
                    '$'.number_format((float) $adjustment->legal_cap_rent, 0, ',', '.'),
                    '$'.number_format((float) $adjustment->new_rent, 0, ',', '.'),
                    '$'.number_format((float) $adjustment->excess_over_cap, 0, ',', '.'),
                ))
                ->persistent()
                ->send();

            return;
        }

        if ($adjustment?->hasPactadoCap() === true) {
            Notification::make()
                ->info()
                ->title('Se aplicó el porcentaje pactado, no el IPC completo.')
                ->body(sprintf(
                    'El IPC del %s fue %s %% y se aplicó %s %%.',
                    $adjustment->ipc_year,
                    number_format((float) $adjustment->legal_pct, 2, ',', '.'),
                    number_format((float) $adjustment->applied_pct, 2, ',', '.'),
                ))
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
