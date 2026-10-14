<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trazabilidad de la cadena de reajustes.
 *
 * El cambio de fondo es conceptual: previous_rent deja de ser un dato guardado
 * y pasa a ser un dato derivado.
 *
 * Antes, previous_rent se copiaba del canon vigente en el momento de crear el
 * ajuste. Como el canon vigente es un valor que applyToRental() modifica, esa
 * copia se quedaba obsoleta en cuanto se editaba un ajuste anterior, y un
 * ajuste borrado dejaba el canon del alquiler sin ninguna explicacion. Con estas
 * columnas la cadena se puede reprocesar entera desde el ancla y obtener el
 * mismo resultado siempre.
 *
 * sequence fija el orden dentro de la cadena. effective_from puede empates (dos
 * reajustes con la misma vigencia), y sin un desempate explicito el orden de
 * la base de datos es indeterminate y el replay no seria reproducible.
 *
 * notification_method acompana a notified_on porque el articulo 20 no exige un
 * "aviso escrito" generico: nombra el servicio postal autorizado y el mecanismo
 * pactado en el contrato, y de eso depende que el incremento sea oponible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rent_adjustments', function (Blueprint $table) {
            // Orden dentro de la cadena de reajustes del alquiler.
            $table->unsignedSmallInteger('sequence')->default(1)->after('rental_id');

            // El canon desde el que se encadeno este ajuste: el ancla del
            // alquiler en el primer ajuste, y el canon acordado en el anterior.
            $table->decimal('anchor_rent', 12, 2)->nullable()->after('previous_rent');

            // El IPC que fija la ley, separado del porcentaje que realmente se
            // aplico. Cuando las partes pactan menos, ambos conviven y la carta
            // puede explicar por que difieren.
            $table->decimal('legal_pct', 6, 2)->nullable()->after('ipc_percentage');
            $table->decimal('applied_pct', 6, 2)->nullable()->after('legal_pct');

            // De que serie salio el dato: la base elegida y la geografia.
            $table->string('ipc_basis', 20)->default('nacional')->after('applied_pct');
            $table->string('geography_code', 5)->nullable()->after('ipc_basis');
            $table->string('geography_name')->nullable()->after('geography_code');
            $table->unsignedTinyInteger('ipc_month')->nullable()->after('geography_name');

            // Cuando se descargo el dato. La carta puede citarlo.
            $table->timestamp('ipc_synced_at')->nullable()->after('ipc_month');
            $table->string('ipc_source_url')->nullable()->after('ipc_synced_at');

            // Cuando el canon nuevo quedo vigente en el alquiler. Es lo que
            // convierte un reajuste en parte de la cadena.
            $table->date('applied_on')->nullable()->after('notified_on');

            // Como se comunico. El articulo 20 lo exige para que el incremento
            // sea oponible.
            $table->string('notification_method', 20)->nullable()->after('notified_on');

            // Huella de los insumos con los que se calculo. Si un ajuste fue
            // editado, deja de ser coherente con los que se derivan de el y se
            // puede avisar en vez de dejar la cadena corrupta en silencio.
            $table->string('chain_hash', 64)->nullable()->after('applied_pct');

            $table->index(['rental_id', 'sequence'], 'rent_adjustments_chain_index');
            $table->index(['rental_id', 'effective_from'], 'rent_adjustments_effective_index');
        });
    }

    public function down(): void
    {
        Schema::table('rent_adjustments', function (Blueprint $table) {
            $table->dropIndex('rent_adjustments_chain_index');
            $table->dropIndex('rent_adjustments_effective_index');

            $table->dropColumn([
                'sequence',
                'anchor_rent',
                'legal_pct',
                'applied_pct',
                'ipc_basis',
                'geography_code',
                'geography_name',
                'ipc_month',
                'ipc_synced_at',
                'ipc_source_url',
                'applied_on',
                'notification_method',
                'chain_hash',
            ]);
        });
    }
};