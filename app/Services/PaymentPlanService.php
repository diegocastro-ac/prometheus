<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\Rental;
use Filament\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class PaymentPlanService
{
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
                'user_id' => Auth::id(),
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