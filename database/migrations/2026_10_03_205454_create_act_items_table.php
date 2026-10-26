<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Un elemento inventariado dentro de un espacio del acta.
     *
     * El espacio es texto libre y no una tabla aparte porque el catalogo de
     * espacios vive en la configuracion de la aplicacion (AppSettings).
     */
    public function up(): void
    {
        Schema::create('act_items', function (Blueprint $table) {
            $table->id();

            $table->string('space');
            $table->string('name');
            $table->string('state')->default('bueno');
            $table->string('note')->nullable();
            $table->string('photo_path')->nullable();

            $table->timestamps();

            $table->foreignId('delivery_act_id')->constrained()->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('act_items');
    }
};