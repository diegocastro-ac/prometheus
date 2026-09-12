<?php

namespace App\Filament\Resources\RentalResource\Pages;

use App\Filament\Resources\RentalResource;
use App\Services\PaymentPlanService;
use App\ValueObjects\RentalPeriod;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Carbon;

class CreateRental extends CreateRecord
{
    protected static string $resource = RentalResource::class;

    private PaymentPlanService $paymentPlanService;

    public function __construct()
    {
        $this->paymentPlanService = app(PaymentPlanService::class);
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $period = new RentalPeriod(Carbon::parse($data['start_date']), (int) $data['total_months']);
        $data['end_date'] = $period->endDateFormatted();
        return $data;
    }

    protected function handleRecordCreation(array $data): \Illuminate\Database\Eloquent\Model
    {
        $rental = parent::handleRecordCreation($data);

        $this->paymentPlanService->generateFor($rental);

        return $rental;
    }
}
