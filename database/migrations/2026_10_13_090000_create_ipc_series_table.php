<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Serie mensual del IPC por geografia.
 *
 * Sustituye a la idea de guardar "el IPC de un ano" y sustituye a la lista
 * hardcodeada de ciudades. Las tres cosas que motivan el cambio:
 *
 * 1. El total nacional no es una lista de ciudades excluidas. Es una fila del
 *    propio modelo del DANE, con PAI_ID 50. Guardarlo aparte obligaba a
 *    mantener esa lista a mano y cualquier omission cambiaba el resultado.
 *
 * 2. El IPC por ciudad si existe y es consultable. Se comprobo que filtrar por
 *    PAI_ID 20001 devuelve la serie de Valledupar. Ocultarlo obligaba a
 *    duplicar la logica de filtrado por ciudad.
 *
 * 3. Guardar solo la variacion anual no permite datar el dato. Con la serie
 *    completa cada reajuste puede citar el mes exacto del IPC que uso, y una
 *    correccion posterior del DANE se puede auditar contra el dato original.
 *
 * El indice es decimal de cuatro decimales porque el DANE los publica con esa
 * precision y las variaciones pueden llegar a 0,0048 %.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ipc_series', function (Blueprint $table) {
            $table->id();

            // '50' es el total nacional, tal como lo publica el CDA. Los demas
            // son DIVIPOLA de cinco digitos con el cero a la izquierda.
            $table->string('geography_code', 5);

            // Se guarda el nombre que devuelve el origen, para no depender de
            // una segunda tabla al mostrar un dato historico congelado.
            $table->string('geography_name');

            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');

            $table->decimal('index_value', 12, 4);
            $table->decimal('variation_monthly', 10, 4)->nullable();
            $table->decimal('variation_yoy', 10, 4)->nullable();
            $table->decimal('variation_year', 10, 4)->nullable();

            $table->string('source_name')->default('DANE');
            $table->string('source_url')->nullable();

            // Cuando se descargo. Un reajuste que cita un IPC tiene que poder
            // decir de que fecha viene el dato que usado.
            $table->timestamp('synced_at')->nullable();

            $table->timestamps();

            // Una geografia tiene un unico dato por periodo. Es lo que permite
            // el upsert de la sincronizacion y lo que evita duplicados cuando
            // el comando corre dos veces el mismo dia.
            $table->unique(['geography_code', 'year', 'month'], 'ipc_series_period_unique');

            // Las consultas siempre preguntan por geografia y ordenan por
            // periodo.
            $table->index(['geography_code', 'year', 'month'], 'ipc_series_lookup_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ipc_series');
    }
};