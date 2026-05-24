<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->string('type', 32)->default('text')->after('body');
            $table->string('media_id')->nullable()->after('type');
            $table->string('media_path')->nullable()->after('media_id');
            $table->string('media_mime', 128)->nullable()->after('media_path');
            $table->string('media_filename')->nullable()->after('media_mime');
            $table->text('caption')->nullable()->after('media_filename');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn(['type', 'media_id', 'media_path', 'media_mime', 'media_filename', 'caption']);
        });
    }
};
