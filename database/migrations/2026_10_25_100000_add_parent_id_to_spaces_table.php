<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spaces', function (Blueprint $table) {
            $table->dropUnique(['name', 'user_id']);

            $table->foreignId('parent_id')
                ->nullable()
                ->after('id')
                ->constrained('spaces')
                ->nullOnDelete();

            $table->unique(['user_id', 'parent_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::table('spaces', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'parent_id', 'name']);
            $table->dropConstrainedForeignId('parent_id');

            $table->unique(['name', 'user_id']);
        });
    }
};
