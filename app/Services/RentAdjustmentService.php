<?php

namespace App\Services;

use App\Models\IpcRate;
use App\Models\RentAdjustment;
use App\Models\Rental;
use App\ValueObjects\RentAdjustment as RentAdjustmentValue;
use RuntimeException;

/**
 * Calcula y registra reajustes del canon.
 *
 * El calculo del tope no vive en el documento: vive aqui. La carta de
 * reajuste solo imprime lo que este servicio decidio y guardo, de modo que lo
 * que se leyo en la carta y lo que quedo en la base de datos no pueden
 * discrepar.
 *
 * El IPC queda congelado en el ajuste (año, porcentaje y tope) aunque la tabla
 * ipc_rates se corrija despues. Un documento ya comunicado no puede cambiar de
 * contenido porque alguien corrigio una fila de referencia.
 */
class RentAdjustmentService
{
    /**
     * Registra un reajuste para el canon que ya rigio en el alquiler.
     *
     * @param  float  $newRent  Canon propuesto por el arrendador
     * @param  int|null  $ipcYear  Ano del IPC a aplicar. Por defecto, el
     *                             inmediatamente anterior al de vigencia, que es
     *                             lo que ordena el articulo 20 de la Ley 820.
     * @param  bool  $ipcOutsideStatutoryYear  Confirma que el rearrendador
     *                                          decide a proposito apartarse del
     *                                          ano que fija la ley. Es la
     *                                          unica forma de registrar un
     *                                          reajuste con un IPC que no es el
     *                                          del ano calendario anterior.
     * @param  string|null  $notes  Observaciones. Obligatorias cuando se
     *                              confirma el apartamiento, para que quede
     *                              escrito por que se hizo.
     */
    public function record(
        Rental $rental,
        float $newRent,
        \DateTimeInterface $effectiveFrom,
        ?int $ipcYear = null,
        ?string $notes = null,
        bool $ipcOutsideStatutoryYear = false,
    ): RentAdjustment {
        if ($newRent <= 0) {
            throw new RuntimeException('El canon reajustado debe ser mayor que cero.');
        }

        $this->guardStatutoryYear($rental, $effectiveFrom, $ipcYear, $notes, $ipcOutsideStatutoryYear);

        $ipc = $this->resolveIpc($rental, $effectiveFrom, $ipcYear);
        $previousRent = (float) $rental->monthly_amount;

        $calculation = RentAdjustmentValue::fromIpc(
            previousRent: $previousRent,
            requestedRent: $newRent,
            ipc: $ipc,
        );

        return $rental->rentAdjustments()->create([
            'user_id' => $rental->user_id,
            'ipc_rate_id' => $ipc->id,
            'previous_rent' => $calculation->previousRent,
            'new_rent' => $calculation->requestedRent,
            'ipc_year' => $calculation->ipcYear,
            'ipc_percentage' => $calculation->ipcPercentage,
            'legal_cap_rent' => $calculation->legalCapRent,
            'excess_over_cap' => $calculation->excessOverCap(),
            'requires_written_agreement' => $calculation->requiresWrittenAgreement(),
            'effective_from' => $effectiveFrom,
            'notes' => $notes,
        ]);
    }

    /**
     * Marca el reajuste como comunicado.
     *
     * El articulo 20 dice que el reajuste es inoponible al arrendatario si no
     * se le informa el monto y la fecha. Guardar la fecha de comunicacion es la
     * forma de dejar constancia de que ese deber se cumplio.
     */
    public function markNotified(RentAdjustment $adjustment, \DateTimeInterface $notifiedOn): RentAdjustment
    {
        $adjustment->update(['notified_on' => $notifiedOn]);

        return $adjustment;
    }

    /**
     * Aplica el canon reajustado al alquiler.
     *
     * No aplica el reajuste que exceda el tope sin confirmacion explicita: el
     * exceso solo opera con acuerdo escrito, y eso lo decide el arrendador, no
     * el sistema.
     */
    public function applyToRental(RentAdjustment $adjustment): Rental
    {
        if ($adjustment->exceedsCap() && ! $adjustment->notes) {
            throw new RuntimeException(
                'El reajuste supera el tope del IPC. Registre el acuerdo escrito en las '
                .'observaciones antes de aplicarlo al alquiler.'
            );
        }

        $adjustment->rental->update(['monthly_amount' => $adjustment->new_rent]);

        return $adjustment->rental;
    }

    /**
     * Impide registrar un reajuste con un IPC de otro ano sin que alguien lo
     * decida por escrito.
     *
     * La ley fija el IPC del ano calendario anterior al de vigencia. Si el
     * formulario propone el ultimo IPC disponible, que puede ser de un ano
     * distinto al que corresponde, el sistema no lo acepta en silencio: o se
     * elige el ano que corresponde, o se confirma el apartamiento y queda
     * escrito por que.
     *
     * Sin esta comprobacion un documento puede citar un IPC que la ley no
     * permite, y la carta de reajuste lo imprimiria con toda seguridad.
     */
    private function guardStatutoryYear(
        Rental $rental,
        \DateTimeInterface $effectiveFrom,
        ?int $ipcYear,
        ?string $notes,
        bool $confirmed,
    ): void {
        if ($ipcYear === null) {
            // Sin ano explicito se usa el que la ley manda, y no hay nada que
            // confirmar.
            return;
        }

        $statutoryYear = (int) $effectiveFrom->format('Y') - 1;

        if ($ipcYear === $statutoryYear) {
            return;
        }

        if (! $confirmed) {
            throw new RuntimeException(sprintf(
                'El articulo 20 de la Ley 820 fija el IPC de %d para un reajuste que '
                .'rige desde %s, y se eligio el de %d. Confirme el apartamiento si '
                .'ese es el ajuste que quiere registrar.',
                $statutoryYear,
                $effectiveFrom->format('Y-m-d'),
                $ipcYear,
            ));
        }

        if ($notes === null || trim($notes) === '') {
            throw new RuntimeException(
                'Un reajuste con un IPC distinto al del año calendario anterior exige '
                .'dejar escrito el motivo en las observaciones.'
            );
        }
    }

    private function resolveIpc(Rental $rental, \DateTimeInterface $effectiveFrom, ?int $ipcYear): IpcRate
    {
        $year = $ipcYear ?? ((int) $effectiveFrom->format('Y') - 1);

        $ipc = IpcRate::query()->where('year', $year)->first();

        if ($ipc === null) {
            throw new RuntimeException(sprintf(
                'No hay IPC registrado para %d, y sin ese dato no se puede calcular el tope '
                .'legal del reajuste del alquiler %s.',
                $year,
                $rental->name,
            ));
        }

        return $ipc;
    }
}
