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
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('document');
            $table->string('name');
            $table->string('phone_number');
            $table->string('email')->nullable();
            $table->timestamps();

            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->unique(['user_id', 'document'], 'tenants_user_document_unique');
            $table->unique(['user_id', 'document'], 'tenants_user_phone_number_unique');
            $table->unique(['user_id', 'document'], 'tenants_user_email_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
