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
        Schema::table('app_settings', function (Blueprint $table) {
            $table->string('primary_color')->default('#3b82f6')->after('legal_footer');
            $table->string('secondary_color')->default('#6366f1')->after('primary_color');
            $table->string('font_family')->default('Inter')->after('secondary_color');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('app_settings', function (Blueprint $table) {
            $table->dropColumn(['primary_color', 'secondary_color', 'font_family']);
        });
    }
};
