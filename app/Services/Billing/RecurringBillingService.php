<?php

namespace App\Services\Billing;

use App\Contracts\BusinessCalendar;
use App\Enums\BillingCadence;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Rental;
use App\Services\Adjustment\AdjustmentChainCalculator;
use App\Services\InvoiceNumberService;
use App\Settings\AppSettings;
use Illuminate\Support\Carbon;

/**
 * Genera las facturas de un periodo de facturacion para un alquiler.
 *
 * Los PaymentPlanGenerator existentes solo cubren los meses del contrato
 * inicial (total_months). Este servicio cubre la facturacion recurrente:
 * el periodo que se esta cobrando hoy, con el canon vigente despues de los
 * reajustes aplicados.
 *
 * Es idempotente por diseno: antes de crear busca la factura del corte con
 * la misma clave que usan los generadores (periodo para mensual, periodo +
 * fecha de emision para quincenal). Correrlo dos veces no duplica nada.
 */
class RecurringBillingService
{
    public function __construct(
        private readonly InvoiceNumberService $numbers,
        private readonly AppSettings $settings,
        private readonly BusinessCalendar $calendar,
        private readonly AdjustmentChainCalculator $adjustments,
    ) {}

    /**
     * Facturas creadas para el periodo. Vacio si el alquiler no admite
     * facturacion recurrente (inactivo, contrato terminado, cadencia anticipo).
     *
     * @return list<Invoice>
     */
    public function billPeriodFor(Rental $rental, Carbon $periodStart): array
    {
        if (! $rental->is_active) {
            return [];
        }

        // Un contrato que ya termino no genera cortes nuevos. Quien sigue
        // cobrando despues del end_date lo hace renovando el contrato, no
        // dejando que el sistema facture periodos que nadie acordo.
        if ($rental->end_date !== null
            && $rental->end_date->startOfDay()->lt($periodStart->copy()->startOfMonth())) {
            return [];
        }

        $canon = $this->adjustments->currentRentFor($rental);

        if ($canon <= 0) {
            return [];
        }

        return match ($rental->billing_cadence ?? BillingCadence::MENSUAL) {
            BillingCadence::QUINCENAL => $this->billQuincenal($rental, $periodStart, $canon),
            BillingCadence::MENSUAL => [$this->ensureMonthly($rental, $periodStart, $canon)],
            BillingCadence::ANTICIPO => [],
        };
    }

    /**
     * Factura todos los alquileres activos del usuario para el periodo.
     *
     * @return array{created: int, skipped: int, invoices: list<Invoice>}
     */
    public function billAllFor(int $userId, ?Carbon $periodStart = null): array
    {
        $periodStart ??= today()->startOfMonth();

        $created = [];
        $skipped = 0;

        Rental::query()
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->orderBy('id')
            ->each(function (Rental $rental) use ($periodStart, &$created, &$skipped): void {
                $invoices = $this->billPeriodFor($rental, $periodStart);

                if ($invoices === []) {
                    $skipped++;

                    return;
                }

                $created = array_merge($created, $invoices);
            });

        return [
            'created' => count($created),
            'skipped' => $skipped,
            'invoices' => $created,
        ];
    }

    // ---------------------------------------------------------------
    // Cortes por cadencia
    // ---------------------------------------------------------------

    private function billQuincenal(Rental $rental, Carbon $periodStart, float $canon): array
    {
        $half = round($canon / 2, 2);
        $monthStart = $periodStart->copy()->startOfMonth();

        return [
            $this->ensureInvoice($rental, $monthStart, $half),
            $this->ensureInvoice($rental, $monthStart->copy()->addDays(14), $half),
        ];
    }

    /**
     * Mensual identifica el corte por periodo y nada mas, igual que
     * MonthlyPlanGenerator::findInvoice(): una factura del contrato emitida
     * el dia 15 cubre el mes igual que una recurrente emitida el dia 1, y
     * buscar por fecha exacta duplicaria el cobro.
     */
    private function ensureMonthly(Rental $rental, Carbon $periodStart, float $canon): Invoice
    {
        $periodStart = $periodStart->copy()->startOfMonth();

        $existing = Invoice::query()
            ->where('rental_id', $rental->id)
            ->where('user_id', $rental->user_id)
            ->where('period', Invoice::periodFor($periodStart))
            ->first();

        if ($existing !== null) {
            $existing->refreshStatus();

            return $existing;
        }

        return $this->createInvoice($rental, $periodStart, $canon);
    }

    /**
     * La misma idempotencia que findInvoice() de QuincenalPlanGenerator: el
     * corte se identifica por periodo Y fecha de emision, porque las dos
     * quincas comparten mes.
     */
    private function ensureInvoice(Rental $rental, Carbon $issuedAt, float $amount): Invoice
    {
        $existing = Invoice::query()
            ->where('rental_id', $rental->id)
            ->where('user_id', $rental->user_id)
            ->where('period', Invoice::periodFor($issuedAt))
            ->where('issued_at', $issuedAt->toDateString())
            ->first();

        if ($existing !== null) {
            $existing->refreshStatus();

            return $existing;
        }

        return $this->createInvoice($rental, $issuedAt, $amount);
    }

    private function createInvoice(Rental $rental, Carbon $issuedAt, float $amount): Invoice
    {
        return Invoice::create([
            'number' => $this->numbers->next((int) $rental->user_id, (int) $issuedAt->format('Y')),
            'concept' => 'rent',
            'amount' => $amount,
            'period' => Invoice::periodFor($issuedAt),
            'issued_at' => $issuedAt->toDateString(),
            'due_at' => $this->calendar
                ->nextBusinessDay($issuedAt->copy()->addDays($this->settings->invoiceDueDays()))
                ->toDateString(),
            'status' => InvoiceStatus::EMITIDA,
            'rental_id' => $rental->id,
            'user_id' => $rental->user_id,
        ]);
    }
}
