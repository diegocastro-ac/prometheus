<?php

namespace App\Services\PaymentPlans;

use App\Contracts\BusinessCalendar;
use App\Enums\BillingCadence;
use App\Models\Rental;
use App\Services\InvoiceNumberService;
use App\Settings\AppSettings;
use InvalidArgumentException;

class PaymentPlanGeneratorResolver
{
    public function __construct(
        private readonly InvoiceNumberService $numbers,
        private readonly AppSettings $settings,
        private readonly BusinessCalendar $calendar,
    ) {}

    public function resolve(Rental $rental, BillingCadence $cadence): PaymentPlanGenerator
    {
        return match ($cadence) {
            BillingCadence::MENSUAL => new MonthlyPlanGenerator($rental, $this->numbers, $this->settings, $this->calendar),
            BillingCadence::QUINCENAL => new QuincenalPlanGenerator($rental, $this->numbers, $this->settings, $this->calendar),
            BillingCadence::ANTICIPO => new AdvancePlanGenerator($rental, $this->numbers, $this->settings, $this->calendar),
            default => throw new InvalidArgumentException('Cadencia de cobro no soportada.'),
        };
    }
}
