<?php

namespace App\Services;

use App\Contracts\CurrentUserContextInterface;
use App\Models\Payment;
use App\Models\Rental;
use Filament\Notifications\Notification;
use Illuminate\Support\Carbon;

class PaymentPlanService
{
    private CurrentUserContextInterface $userContext;

    public function __construct(CurrentUserContextInterface $userContext)
    {
        $this->userContext = $userContext;
    }

    /**
     * Generate payment plan for a rental.
     */
    public function generateFor(Rental $rental): void
    {
        $start = Carbon::parse($rental->start_date);

        for ($i = 1; $i <= $rental->total_months; $i++) {
            $date = $start->copy()->addMonths($i)->format('Y-m-d');
            $rental->payments()->create([
                'date' => $date,
                'amount' => $rental->monthly_amount,
                'is_rent_paid' => false,
                'is_water_paid' => false,
                'is_energy_paid' => false,
                'is_gas_paid' => false,
                'user_id' => $this->userContext->id(),
            ]);
        }

        $this->notifySuccess();
    }

    /**
     * Send success notification.
     */
    private function notifySuccess(): void
    {
        Notification::make()
            ->success()
            ->title('Payment plan generated')
            ->body('To view it, go to the Payments section.')
            ->send();
    }
}