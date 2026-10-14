<?php

use App\Enums\RentalType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Base de calculo y datos del articulo 18 en el alquiler.
 *
 * Tres grupos de columnas que responde a tres problemas distintos.
 *
 * ANCLA (anchor_monthly_amount)
 * El articulo 20 cuenta los doce meses "bajo un mismo precio": cada vez que el
 * canon cambia, el reloj se reinicia. Para poder reprocesar la cadena de
 * reajustes hace falta saber desde que canon se encadeno. Ese es el ancla, y
 * se congela porque es el punto de partida historico del contrato.
 *
 * Una vez que existen reajustes aplicados, el ancla correcta es el canon que
 * rigio ANTES del primero, no el canon actual. Si se tomara el canon actual, un
 * alquiler con tres reajustes se calcularia desde el ultimo, y el recálculo
 * produciria numeros distintos de los que se le registraron al inquilino.
 *
 * ALCANCE (rental_type)
 * El articulo 1 limita la ley a vivienda urbana. Un local comercial se rige
 * por el Codigo de Comercio, y calcularle un "tope legal" del IPC no tiene
 * respaldo. Por defecto es vivienda, que es el caso al que aplica la Mayoria de
 * los alquileres de este sistema.
 *
 * ARTICULO 18 (commercial_value, catastral_appraisal)
 * El articulo 18 impone dos cosas encadenadas: el canon no puede exceder el 1 %
 * del valor comercial, y la estimacion de ese valor comercial no puede exceder
 * dos veces el avaluo catastral. La segunda se suele olvidar y es la que
 * realmente acota el primero.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rentals', function (Blueprint $table) {
            $table->enum('rental_type', [
                RentalType::Residential->value,
                RentalType::Commercial->value,
            ])->default(RentalType::Residential->value)->after('monthly_amount');

            $table->decimal('commercial_value', 14, 2)->nullable()->after('rental_type');
            $table->decimal('catastral_appraisal', 14, 2)->nullable()->after('commercial_value');

            // Decimal, no float: un canon se redondea al peso y el acumulado de
            // una cadena de reajustes no admite error binario.
            $table->decimal('anchor_monthly_amount', 12, 2)->nullable()->after('catastral_appraisal');
            $table->date('anchor_established_on')->nullable()->after('anchor_monthly_amount');

            $table->index('rental_type', 'rentals_rental_type_index');
        });
    }

    public function down(): void
    {
        Schema::table('rentals', function (Blueprint $table) {
            $table->dropIndex('rentals_rental_type_index');
            $table->dropColumn([
                'rental_type',
                'commercial_value',
                'catastral_appraisal',
                'anchor_monthly_amount',
                'anchor_established_on',
            ]);
        });
    }
};