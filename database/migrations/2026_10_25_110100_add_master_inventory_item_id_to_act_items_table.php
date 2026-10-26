<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * La referencia es opcional para no romper las actas anteriores. El nombre
     * de `act_items` sigue siendo el snapshot que se imprime.
     */
    public function up(): void
    {
        Schema::table('act_items', function (Blueprint $table) {
            $table->foreignId('master_inventory_item_id')
                ->nullable()
                ->after('space_id')
                ->constrained()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('act_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('master_inventory_item_id');
        });
    }
};
