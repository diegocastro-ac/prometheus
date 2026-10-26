<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Variacion anual del IPC por ano calendario.
 *
 * La carta de reajuste tiene que citar la fuente oficial del dato, asi que la
 * tabla guarda tambien la url y la fecha de publicacion del DANE. Un numero
 * suelto sin referencia no sirve para sustentar un cobro.
 *
 * El IPC de vivir arriendo es el general: asi lo aplica el articulo 20 de la
 * Ley 820 de 2003, que no distingue divisiones de gasto para el reajuste.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ipc_rates', function (Blueprint $table) {
            $table->id();

            // Ano calendario al que corresponde la variacion, no al que se
            // aplica el reajuste. El articulo 20 obliga a usar el ano
            // inmediatamente anterior.
            $table->unsignedSmallInteger('year')->unique();

            // Porcentaje, no factor: 9.28 significa 9,28 %.
            $table->decimal('percentage', 6, 2);

            $table->string('source_name')->default('DANE');
            $table->string('source_url');
            $table->date('published_on')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ipc_rates');
    }
};
