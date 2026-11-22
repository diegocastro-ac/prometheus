<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_accesses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('document_type', 64);
            $table->string('reference')->nullable();
            $table->string('format', 16);
            $table->json('context')->nullable();
            $table->timestamp('accessed_at');
            $table->timestamps();

            $table->index(['user_id', 'accessed_at']);
            $table->index(['document_type', 'accessed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_accesses');
    }
};
