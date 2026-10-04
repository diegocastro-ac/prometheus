<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reajuste del canon que el arrendador.le propone al inquilino.
 *
 * Se guarda aunque todavia no se haya comunicado, porque la carta que lo
 * informa es la prueba de que el arrendador cumplio con el deber de
 * comunicacion del articulo 20 de la Ley 820 de 2003.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rent_adjustments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('rental_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ipc_rate_id')->nullable()->constrained()->nullOnDelete();

            $table->float('previous_rent');
            $table->float('new_rent');

            // Se congelan los valores del IPC con el que se calculo. Si la tabla
            // ipc_rates se corrige mas adelante, la carta ya emitida debe seguir
            // diciendo con que dato se hizo.
            $table->unsignedSmallInteger('ipc_year')->nullable();
            $table->decimal('ipc_percentage', 6, 2)->nullable();
            $table->float('legal_cap_rent')->nullable();

            // Cuanto del incremento pedido supera el tope legal. Cero cuando el
            // reajuste esta dentro del IPC.
            $table->float('excess_over_cap')->default(0);

            $table->boolean('requires_written_agreement')->default(false);

            $table->date('effective_from')->nullable();
            $table->date('notified_on')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['rental_id', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rent_adjustments');
    }
};
