<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cabecera del acta de entrega y recepcion.
     *
     * Las secciones que se pueden dejar en blanco (lecturas, compromisos,
     * observaciones, firmas) guardan null. Esa ausencia es intencional: es la
     * forma de registrar que la seccion no existia en ese documento y no de
     * que se haya dejado sin diligenciar.
     */
    public function up(): void
    {
        Schema::create('delivery_acts', function (Blueprint $table) {
            $table->id();

            $table->string('type')->default('entrega');

            $table->string('landlord_name');
            $table->string('landlord_document')->nullable();
            $table->string('tenant_name');
            $table->string('tenant_document')->nullable();

            $table->date('occurred_at');
            $table->timestamp('scheduled_at')->nullable();

            // Lecturas de medidores. Null cuando no se midio ese servicio.
            $table->string('water_reading')->nullable();
            $table->string('energy_reading')->nullable();
            $table->string('gas_reading')->nullable();

            $table->text('commitments')->nullable();
            $table->text('observations')->nullable();

            $table->string('landlord_signature_path')->nullable();
            $table->string('tenant_signature_path')->nullable();
            $table->timestamp('signed_at')->nullable();

            $table->timestamps();

            $table->foreignId('rental_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('delivery_acts');
    }
};