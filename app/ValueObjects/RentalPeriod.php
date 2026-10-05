<?php

namespace App\ValueObjects;

use Carbon\Carbon;

class RentalPeriod
{
    private Carbon $startDate;
    private int $months;

    public function __construct(Carbon $startDate, int $months)
    {
        $this->startDate = $startDate;
        $this->months = $months;
    }

    public function startDate(): Carbon
    {
        return $this->startDate;
    }

    public function months(): int
    {
        return $this->months;
    }

    public function endDate(): Carbon
    {
        return $this->startDate->copy()->addMonths($this->months);
    }

    public function endDateFormatted(): string
    {
        return $this->endDate()->format('Y-m-d');
    }
}