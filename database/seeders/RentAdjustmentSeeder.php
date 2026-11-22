<?php

namespace Database\Seeders;

use App\Models\IpcRate;
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
 * ano no esta sembrado, el servicio lanza y el seeder lo avisa en vez de
 * inventar un porcentaje.
 */
class RentAdjustmentSeeder extends Seeder
{
    public function __construct(private readonly RentAdjustmentService $service) {}

    public function run(): void
    {
        $year = (int) now()->format('Y');

        if (! IpcRate::query()->where('year', $year - 1)->exists()) {
            $this->command?->warn(sprintf(
                'No hay IPC de %d, se omiten los reajustes de demostracion.',
                $year - 1,
            ));

            return;
        }

        $alterno = Rental::query()->where('is_active', true)->first();

        if ($alterno === null) {
            return;
        }

        try {
            // Un reajuste dentro del tope, que es el caso normal. La vigencia
            // no se pone a mano: se deriva del ano de IPC para que el par cumpla
            // el articulo 20, que es lo que el servicio exige.
            $this->service->record(
                rental: $alterno,
                newRent: $this->withinCapFor($alterno),
                effectiveFrom: $this->effectiveFromForLastIpc(),
            );

            // Y uno por encima del tope, para que se vea el aviso de la carta.
            // El acuerdo escrito queda registrado porque sin el el servicio no
            // dejaria aplicarlo.
            $this->service->record(
                rental: $alterno,
                newRent: $this->withinCapFor($alterno) + 180_000,
                effectiveFrom: $this->effectiveFromForLastIpc(),
                notes: 'Acuerdo escrito firmado por las dos partes. Reajuste aceptado por encima del IPC.',
            );
        } catch (RuntimeException $exception) {
            $this->command?->warn('No se pudieron sembrar los reajustes: '.$exception->getMessage());
        }
    }

    /**
     * Vigencia que la ley permite para el ultimo IPC sembrado.
     *
     * El IPC del ano N rige desde el 1 de enero de N+1. Si esa fecha ya paso,
     * el unico dia del ano correcto que queda es hoy, y un reajuste con vigencia
     * pasada descuadra las facturas ya emitidas.
     */
    private function effectiveFromForLastIpc(): Carbon
    {
        $ipc = IpcRate::query()->where('year', (int) now()->format('Y') - 1)->firstOrFail();
        $start = Carbon::create($ipc->year + 1, 1, 1);

        return $start->isFuture() ? $start : today()->startOfDay();
    }

    private function withinCapFor(Rental $rental): float
    {
        $ipc = IpcRate::query()->where('year', (int) now()->format('Y') - 1)->firstOrFail();

        return round((float) $rental->monthly_amount * (1 + $ipc->percentage / 100));
    }
}
