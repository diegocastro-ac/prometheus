<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * La factura es la fuente de verdad de lo que se debe. Un pago ya no dice
     * "el canon esta pagado": dice "se abonaron estos pesos contra esta factura".
     *
     * Esta tabla no es una factura electronica autorizada por la DIAN. Es el
     * respaldo en PDF que el sistema genera.
     */
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();

            $table->string('number');
            $table->string('concept');
            $table->float('amount');

            // El mes al que corresponde la factura, en formato YYYY-MM. Sin el,
            // dos facturas de canon del mismo mes solo se distinguen por su
            // fecha de emision, y esa depende de cuando se arreglo la.
            $table->string('period', 7)->nullable();

            $table->date('issued_at');
            $table->date('due_at');
            $table->string('status')->default('emitida');

            $table->timestamp('paid_at')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->foreignId('rental_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // El consecutivo es unico por arrendador, no global: dos
            // arrendadores pueden tener FV-2026-0001 sin chocar.
            $table->unique(['user_id', 'number']);
            $table->index(['status', 'due_at']);
        });

        // Consecutivo separado de la configuracion. AppSettings es inmutable y
        // solo lectura, asi que un contador que sube con cada factura no puede
        // vivir alli.
        Schema::create('invoice_sequences', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('last_number')->default(0);
            $table->unsignedSmallInteger('year');
            $table->timestamps();

            // Unico por arrendador y ano: el consecutivo se reinicia cada
            // enero, asi que hace falta el ano para no reiniciar de mas.
            $table->unique(['user_id', 'year']);
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoice_sequences');
        Schema::dropIfExists('invoices');
    }
};
