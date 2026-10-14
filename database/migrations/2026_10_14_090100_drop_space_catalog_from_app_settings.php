<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El catalogo de espacios sale de `app_settings`.
 *
 * Hacia falta una columna, no dos. Antes de esta migracion el vocabulario de
 * espacios vivia en dos sitios —la tabla `spaces` y esta columna— y ya
 * divergian. La tabla es la que se queda, por dos razones:
 *
 * 1. Esta columna es GLOBAL (una fila unica para toda la instalacion), asi que
 *    dos arrendadores comparten espacios. El vocabulario de un inventario es de
 *    cada uno: uno dice «Sala» y el otro «Estar».
 * 2. La tabla ya tiene `user_id` e indice unico por (nombre, usuario), que es
 *    exactamente lo que hace falta. No habia que inventar nada.
 *
 * La migracion anterior (`backfill_space_catalog_into_spaces_table`) copia los
 * espacios que estaban aqui a la tabla ANTES de que esta columna desaparezca.
 * El orden importa: al revertir, las filas de `spaces` sobreviven, y eso es
 * correcto porque son espacios legitimos de un arrendador.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_settings', function (Blueprint $table): void {
            $table->dropColumn('space_catalog');
        });
    }

    public function down(): void
    {
        Schema::table('app_settings', function (Blueprint $table): void {
            // Se restaura VACIA y no con el catalogo de antes.
            //
            // El `down` de una migracion debe devolver el estado anterior para
            // que el esquema sea coherente, pero devolver una copia de los datos
            // seria mentir: no hay forma de reconstruir un array global a partir
            // de filas por usuario sin decidir cual de los arrendadores manda, y
            // esa decision no pertenece a un rollback.
            $table->json('space_catalog')->nullable()->after('legal_footer');
        });
    }
};