<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Catalogo maestro de elementos por arrendador.
     *
     * El acta guarda una referencia a esta fila y conserva el nombre como
     * snapshot. Asi dos actas comparten el mismo elemento sin duplicar sus
     * datos, y renombrar el catalogo no reescribe actas ya firmadas.
     */
    public function up(): void
    {
        Schema::create('master_inventory_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();

            $table->unique(['user_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('master_inventory_items');
    }
};
