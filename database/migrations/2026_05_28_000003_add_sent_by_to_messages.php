<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            // Usuario (asesor) que envió el mensaje saliente. Null para mensajes
            // entrantes del cliente o automáticos (plantillas, recordatorios).
            $table->foreignId('sent_by_user_id')->nullable()->after('caption')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sent_by_user_id');
        });
    }
};
