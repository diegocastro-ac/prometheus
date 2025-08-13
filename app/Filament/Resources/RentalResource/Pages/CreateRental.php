<?php

namespace App\Filament\Resources\RentalResource\Pages;

use App\Filament\Resources\RentalResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Filament\Notifications\Notification;

class CreateRental extends CreateRecord
{
    protected static string $resource = RentalResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['end_date'] = Carbon::parse($data['start_date'])->addMonths((int) $data['total_months']);
        return $data;
    }

    protected function handleRecordCreation(array $data): \Illuminate\Database\Eloquent\Model
    {
        $rental = parent::handleRecordCreation($data);

        $start = Carbon::parse($rental->start_date);
        for ($i = 1; $i <= $rental->total_months; $i++) {
            $date = $start->copy()->addMonths($i)->format('Y-m-d');
            $rental->payments()->create([
                'date'         => $date,
                'amount'       => $rental->monthly_amount,
                'is_rent_paid' => false,
                'is_water_paid' => false,
                'is_energy_paid' => false,
                'is_gas_paid'  => false,
                'user_id'      => Auth::id(),
            ]);
        }

        Notification::make()
            ->success()
            ->title('Payment plan generated')
            ->body('To view it, go to the Payments section.')
            ->send();

        return $rental;
    }
}
