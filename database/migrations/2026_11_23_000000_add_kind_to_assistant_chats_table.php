<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assistant_chats', function (Blueprint $table) {
            // 'user' = conversacion normal; 'briefing' = el chat diario
            // protegido que llena el comando assistant:brief.
            $table->string('kind', 20)->default('user')->index()->after('title');
        });
    }

    public function down(): void
    {
        Schema::table('assistant_chats', function (Blueprint $table) {
            $table->dropIndex(['kind']);
            $table->dropColumn('kind');
        });
    }
};
