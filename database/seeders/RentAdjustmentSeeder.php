<?php

namespace Database\Seeders;

use App\Dto\Geography;
use App\Models\IpcSeries;
use App\Models\Rental;
use App\Services\RentAdjustmentService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Reajustes de demostracion.
 *
 * Se siembran con RentAdjustmentService y no con la fabrica, para que el tope
 * legal quede calculado por la misma regla que usa la aplicacion. Un ajuste
 * sembrado a mano con un IPC inventado ensenaria un tope que el sistema nunca
 * produciria.
 *
 * Solo se genera un reajuste por alquiler, con el IPC del ano anterior. Si ese
 * dato no esta sembrado, el seeder lo avisa y omite los reajustes en vez de
 * inventar un porcentaje.
 *
 * La comprobacion se hace sobre ipc_series, que es la fuente que consulta el
 * calculo. Mirar ipc_rates no era suficiente: esa tabla es derivada y podia
 * existir aunque faltara el dato mensual de diciembre, que es el que el servicio
 * necesita.
 */
class RentAdjustmentSeeder extends Seeder
{
    public function __construct(private readonly RentAdjustmentService $service) {}

    public function run(): void
    {
        $adjustmentYear = (int) now()->format('Y');
        $ipc = $this->decemberIpc($adjustmentYear - 1);
        $variation = $ipc?->calendarYearVariation();

        if ($ipc === null || $variation === null) {
            $this->command?->warn(sprintf(
                'No hay IPC de diciembre de %d, se omiten los reajustes de demostracion.',
                $adjustmentYear - 1,
            ));

            return;
        }

        // Solo sirven los alquileres que ya cumplieron los doce meses bajo un
        // mismo precio. Elegir uno cualquiera hacia que el servicio rechazara el
        // registro por apartarse del articulo 20, y el seeder terminaba sin
        // sembrar nada. RentalSeeder deja dos alquileres antiguos para esto.
        $rentals = Rental::query()
            ->where('is_active', true)
            ->orderBy('start_date')
            ->orderBy('id')
            ->get()
            ->filter(fn (Rental $rental): bool => $rental->isDueForAdjustment())
            ->values();

        if ($rentals->isEmpty()) {
            $this->command?->warn(
                'No hay un alquiler activo que haya cumplido los doce meses bajo un mismo precio, '
                .'se omiten los reajustes de demostracion.',
            );

            return;
        }

        $effectiveFrom = $this->effectiveFromFor($ipc);

        try {
            // Un reajuste dentro del tope, que es el caso normal. La vigencia
            // no se pone a mano: se deriva del ano de IPC para que el par cumpla
            // el articulo 20, que es lo que el servicio exige.
            $within = $rentals->first();
            $this->service->record(
                rental: $within,
                newRent: $this->withinCapFor($within, $variation),
                effectiveFrom: $effectiveFrom,
            );

            // Y uno por encima del tope, para que se vea el aviso de la carta.
            // Va en otro alquiler para no encadenar dos reajustes en la misma
            // fecha: cada uno es una cadena independiente. El acuerdo escrito
            // queda registrado porque sin el el servicio no dejaria aplicarlo.
            $above = $rentals->get(1);

            if ($above !== null) {
                $this->service->record(
                    rental: $above,
                    newRent: $this->withinCapFor($above, $variation) + 180_000,
                    effectiveFrom: $effectiveFrom,
                    notes: 'Acuerdo escrito firmado por las dos partes. Reajuste aceptado por encima del IPC.',
                );
            }
        } catch (RuntimeException $exception) {
            // No se silencia: un reajuste que no se pudo sembrar deja la demo
            // sin datos y, si el seeder termina en DONE, el problema pasa
            // inadvertido. El comando debe fallar para que se vea.
            throw new RuntimeException(
                'No se pudieron sembrar los reajustes: '.$exception->getMessage(),
                0,
                $exception,
            );
        }
    }

    /**
     * Vigencia que la ley permite para el ultimo IPC sembrado.
     *
     * El IPC del ano N rige desde el 1 de enero de N+1. Si esa fecha ya paso,
     * el unico dia del ano correcto que queda es hoy, y un reajuste con vigencia
     * pasada descuadra las facturas ya emitidas.
     */
    private function effectiveFromFor(IpcSeries $ipc): Carbon
    {
        $start = Carbon::create($ipc->year + 1, 1, 1);

        return $start->isFuture() ? $start : today()->startOfDay();
    }

    private function withinCapFor(Rental $rental, float $percentage): float
    {
        return round((float) $rental->monthly_amount * (1 + $percentage / 100));
    }

    /**
     * El dato de diciembre del total nacional de un ano.
     */
    private function decemberIpc(int $year): ?IpcSeries
    {
        return IpcSeries::query()
            ->forGeography(Geography::nationalCode())
            ->forPeriod($year, 12)
            ->first();
    }
}
