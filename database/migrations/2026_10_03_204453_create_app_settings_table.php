<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * La tabla que respalda al singleton AppSettings.
     *
     * Se guarda una unica fila (la de id 1). Los contadores que cambian con
     * el uso, como el consecutivo de facturas, NO viven aqui: eso es estado
     * mutable y se lleva en su propia tabla en una migracion posterior.
     */
    public function up(): void
    {
        Schema::create('app_settings', function (Blueprint $table) {
            $table->id();

            $table->string('business_name');
            $table->string('tax_id');
            $table->string('address')->default('');
            $table->string('currency', 8)->default('COP');
            $table->string('logo_path')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();

            $table->unsignedSmallInteger('invoice_due_days')->default(5);
            $table->text('legal_footer')->nullable();
            $table->json('space_catalog')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('app_settings');
    }
};