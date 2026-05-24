<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Remove duplicate messages keeping the earliest record per wa_message_id
        DB::statement("
            DELETE FROM messages
            WHERE id NOT IN (
                SELECT MIN(id)
                FROM messages
                WHERE wa_message_id IS NOT NULL
                GROUP BY wa_message_id
            )
            AND wa_message_id IS NOT NULL
        ");

        Schema::table('messages', function (Blueprint $table) {
            $table->unique('wa_message_id');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropUnique(['wa_message_id']);
        });
    }
};
