<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Los pagos dejan de llevar sus propios indicadores de servicio.
     *
     * Los cuatro booleanos se borran a proposito. Marcaban "este mes ya pagaste
     * el agua" sin decir cuanto se pago, asi que no permitian calcular nada:
     * ni saldo, ni mora, ni comprobante. Ahora un pago apunta a la factura que
     * esta cubriendo y el estado se deduce de ahi.
     *
     * Dato que se pierde y no es recuperable: si un pago tenia is_water_paid en
     * true, no existe en ninguna parte cuanto fue el valor del agua de ese mes.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn([
                'is_rent_paid',
                'is_water_paid',
                'is_energy_paid',
                'is_gas_paid',
            ]);

            // Nullable porque un pago puede quedar huerfano de una factura
            // emitida antes de esta migracion.
            $table->foreignId('invoice_id')
                ->nullable()
                ->after('amount')
                ->constrained()
                ->nullOnDelete();

            // Como se abona y con que referencia. Sin esto no habria forma de
            // saber si un pago fue efectivo, transferencia o consignacion.
            $table->string('method')->default('efectivo')->after('invoice_id');
            $table->string('reference')->nullable()->after('method');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['invoice_id']);
            $table->dropColumn('invoice_id');

            $table->boolean('is_rent_paid')->default(false);
            $table->boolean('is_water_paid')->default(false);
            $table->boolean('is_energy_paid')->default(false);
            $table->boolean('is_gas_paid')->default(false);
        });
    }
};
