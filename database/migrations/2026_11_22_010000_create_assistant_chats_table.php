<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_chats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'updated_at']);
        });

        Schema::table('assistant_messages', function (Blueprint $table) {
            $table->foreignId('chat_id')
                ->nullable()
                ->after('user_id')
                ->constrained('assistant_chats')
                ->cascadeOnDelete();
        });

        // Backfill: cada usuario con mensajes previos recibe un chat cuyo
        // titulo sale de su primer mensaje, y los mensajes se reasignan.
        DB::table('assistant_messages')
            ->whereNull('chat_id')
            ->select('user_id')
            ->distinct()
            ->pluck('user_id')
            ->each(function (int $userId): void {
                $first = DB::table('assistant_messages')
                    ->where('user_id', $userId)
                    ->whereNull('chat_id')
                    ->orderBy('id')
                    ->value('text');

                $chatId = DB::table('assistant_chats')->insertGetId([
                    'user_id' => $userId,
                    'title' => Str::limit((string) $first, 60),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('assistant_messages')
                    ->where('user_id', $userId)
                    ->whereNull('chat_id')
                    ->update(['chat_id' => $chatId]);
            });
    }

    public function down(): void
    {
        Schema::table('assistant_messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('chat_id');
        });

        Schema::dropIfExists('assistant_chats');
    }
};
