<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->float('amount');
            $table->boolean('is_rent_paid');
            $table->string('rent_voucher_path')->nullable();
            $table->boolean('is_water_paid');
            $table->string('water_voucher_path')->nullable();
            $table->boolean('is_energy_paid');
            $table->string('energy_voucher_path')->nullable();
            $table->boolean('is_gas_paid');
            $table->string('gas_voucher_path')->nullable();
            $table->timestamps();

            $table->foreignId('rental_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
