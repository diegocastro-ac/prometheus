<?php

namespace App\ValueObjects;

use App\Contracts\BusinessCalendar;
use App\Settings\AppSettings;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Periodo de un contrato de alquiler.
 *
 * Ademas de contar los meses del contrato, sabe cuando termina: la fecha del
 * ultimo corte mas los dias de plazo de pago, corrida al siguiente dia habil.
 * La regla vive en un solo lugar y delega los festivos en el BusinessCalendar
 * (Adapter), de modo que el formulario y las paginas de alta y edicion
 * comparten exactamente el mismo calculo.
 */
class RentalPeriod
{
    public function __construct(
        private Carbon $startDate,
        private int $months,
        private BusinessCalendar $calendar,
        private int $dueDays,
    ) {}

    /**
     * Construye el periodo resolviendo del contenedor el calendario de dias
     * habiles y el plazo de pago configurado en Ajustes.
     */
    public static function for(Carbon $startDate, int $months): self
    {
        return new self(
            $startDate,
            $months,
            app(BusinessCalendar::class),
            app(AppSettings::class)->invoiceDueDays(),
        );
    }

    public function startDate(): Carbon
    {
        return $this->startDate;
    }

    public function months(): int
    {
        return $this->months;
    }

    /**
     * Fin del contrato: el ultimo corte del plan mas el plazo de pago, en dia
     * habil. Se usa addMonthsNoOverflow para que el corte coincida con el que
     * generan las cadencias de cobro (un inicio a fin de mes no se desplaza).
     */
    public function endDate(): CarbonInterface
    {
        $lastPeriod = $this->startDate->copy()->addMonthsNoOverflow($this->months);

        return $this->calendar->nextBusinessDay($lastPeriod->addDays($this->dueDays));
    }

    public function endDateFormatted(): string
    {
        return $this->endDate()->format('Y-m-d');
    }
}
