<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catalogo de geografias con IPC.
 *
 * Existe para que la aplicacion no tenga ningun codigo de ciudad escrito en el
 * codigo. Las opciones del selector de salida de aqui, y de la sincronizacion.
 *
 * La columna has_ipc es la que hace util la tabla: el DIVIPOLA tiene 1.122
 * municipios y solo 23 tienen IPC, asi que importar el catalogo completo sirve
 * para mostrar nombres y departamentos, pero nunca para ofrecer un filtro de
 * ciudad que el DANE no publica.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('geographies', function (Blueprint $table) {
            $table->id();

            // '50' es el total nacional. Los demas son DIVIPOLA de cinco digitos.
            $table->string('code', 5)->unique();

            $table->string('name');

            // Departamento del DIVIPOLA: los dos primeros digitos. Es lo que
            // permite agrupar el selector por region sin repetir el catalogo.
            $table->string('department_code', 2)->nullable();
            $table->string('department')->nullable();

            // Si el DANE publica IPC para esta geografia.
            $table->boolean('has_ipc')->default(false);

            // De donde salio la fila: 'cda' si vino de qryCiudad, 'divipola' si
            // se enriquecio desde el catalogo oficial de municipios.
            $table->string('source', 20)->default('cda');

            $table->timestamps();

            $table->index(['has_ipc', 'name'], 'geographies_ipc_name_index');
            $table->index('department', 'geographies_department_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('geographies');
    }
};