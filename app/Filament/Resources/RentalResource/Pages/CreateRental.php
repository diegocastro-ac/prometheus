<?php

namespace App\Filament\Resources\RentalResource\Pages;

use App\Filament\Resources\RentalResource;
use App\Services\RentalFacade;
use App\ValueObjects\RentalPeriod;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Carbon;

class CreateRental extends CreateRecord
{
    protected static string $resource = RentalResource::class;

    private RentalFacade $rentals;

    public function __construct()
    {
        $this->rentals = app(RentalFacade::class);
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $period = RentalPeriod::for(Carbon::parse($data['start_date']), (int) $data['total_months']);
        $data['end_date'] = $period->endDateFormatted();

        return $data;
    }

    protected function handleRecordCreation(array $data): \Illuminate\Database\Eloquent\Model
    {
        $rental = parent::handleRecordCreation($data);

        $this->rentals->generatePaymentPlan($rental);

        return $rental;
    }
}
