<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fija el ancla de calculo en los alquileres que ya tienen reajustes.
 *
 * Es una migracion de datos, no de esquema, y es la parte delicate del cambio.
 *
 * La regla: el ancla es el canon que rigio ANTES del primer reajuste, no el
 * canon actual. Se tomo el previous_rent del reajuste mas antiguo porque es
 * exactamente la cifra que se le aplico al inquilino en su momento y, por lo
 * tanto, la unica que garantiza que el reproceso de la cadena devuelva los
 * mismos numeros que ya se le comunicaron.
 *
 * Si en su lugar se tomara monthly_amount, un alquiler con tres reajustes
 * tendria como ancla el canon del tercero, y al recalcular la cadena las cifras
 * de los dos primeros cambiarian. Una carta de reajuste ya emitida quedaria
 * contradicha por el sistema que la emitio.
 *
 * Los alquileres sin reajustes usan el canon vigente, porque en ese caso el
 * canon actual si es el canon original.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('rentals')->update(['anchor_monthly_amount' => DB::raw('monthly_amount')]);

        DB::table('rentals')->whereNotExists(function ($query): void {
            $query->select(DB::raw(1))
                ->from('rent_adjustments')
                ->whereColumn('rent_adjustments.rental_id', 'rentals.id');
        })->update([
            'anchor_monthly_amount' => DB::raw('monthly_amount'),
            'anchor_established_on' => DB::raw('start_date'),
        ]);

        // Para los que si tienen reajustes: el previous_rent del mas antiguo.
        DB::table('rentals')->whereExists(function ($query): void {
            $query->select(DB::raw(1))
                ->from('rent_adjustments')
                ->whereColumn('rent_adjustments.rental_id', 'rentals.id');
        })->update([
            'anchor_monthly_amount' => DB::raw(
                '(SELECT previous_rent FROM rent_adjustments
                  WHERE rent_adjustments.rental_id = rentals.id
                  ORDER BY effective_from ASC, id ASC
                  LIMIT 1)'
            ),
            'anchor_established_on' => DB::raw('start_date'),
        ]);

        // Numera la cadena de cada alquiler segun su orden real de vigencia.
        $rentals = DB::table('rentals')->pluck('id');

        foreach ($rentals as $rentalId) {
            $adjustments = DB::table('rent_adjustments')
                ->where('rental_id', $rentalId)
                ->orderBy('effective_from')
                ->orderBy('id')
                ->get(['id']);

            $sequence = 0;

            foreach ($adjustments as $adjustment) {
                $sequence++;

                DB::table('rent_adjustments')
                    ->where('id', $adjustment->id)
                    ->update(['sequence' => $sequence]);
            }
        }

        // Los reajustes ya registrados tenian un solo porcentaje, que era a la
        // vez el IPC legal y el aplicado: no existia el pacto menor. Se duplica
        // en los dos campos nuevos para que la cadena historique sea coherente
        // con el modelo sin tener que rellenar a mano.
        DB::table('rent_adjustments')
            ->whereNotNull('ipc_percentage')
            ->whereNull('legal_pct')
            ->update([
                'legal_pct' => DB::raw('ipc_percentage'),
                'applied_pct' => DB::raw('ipc_percentage'),
            ]);
    }

    public function down(): void
    {
        // El ancla es informacion derivada. Borrarla es seguro: se vuelve a
        // calcular desde el siguiente reajuste registrado.
        DB::table('rentals')->update(['anchor_monthly_amount' => null, 'anchor_established_on' => null]);
        DB::table('rent_adjustments')->update([
            'sequence' => 1,
            'legal_pct' => null,
            'applied_pct' => null,
        ]);
    }
};