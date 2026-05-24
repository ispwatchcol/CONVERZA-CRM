<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();

            $table->string('ispwatch_tenant_id')->nullable()->unique();

            $table->string('wa_phone_number_id')->nullable();
            $table->string('wa_business_account_id')->nullable();
            $table->text('wa_access_token')->nullable();
            $table->text('wa_app_secret')->nullable();
            $table->string('wa_verify_token')->nullable();
            $table->string('wa_status', 32)->default('disconnected');

            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('tenant_id')->nullable()->after('id')->constrained()->nullOnDelete();
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->dropUnique(['phone']);
        });
        Schema::table('contacts', function (Blueprint $table) {
            $table->foreignId('tenant_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->string('external_id')->nullable()->after('avatar');
            $table->unique(['tenant_id', 'phone']);
            $table->index(['tenant_id', 'external_id']);
        });

        Schema::table('labels', function (Blueprint $table) {
            $table->foreignId('tenant_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });

        Schema::table('teams', function (Blueprint $table) {
            $table->foreignId('tenant_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });

        Schema::table('staff_members', function (Blueprint $table) {
            $table->foreignId('tenant_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->foreignId('tenant_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->foreignId('tenant_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->index(['tenant_id', 'wa_message_id']);
        });

        Schema::table('quick_replies', function (Blueprint $table) {
            $table->foreignId('tenant_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });

        Schema::table('closing_notes', function (Blueprint $table) {
            $table->foreignId('tenant_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });

        Schema::table('templates', function (Blueprint $table) {
            $table->foreignId('tenant_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        foreach ([
            'templates', 'closing_notes', 'quick_replies', 'messages',
            'conversations', 'staff_members', 'teams', 'labels',
        ] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropConstrainedForeignId('tenant_id');
            });
        }

        Schema::table('contacts', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'phone']);
            $table->dropIndex(['tenant_id', 'external_id']);
            $table->dropConstrainedForeignId('tenant_id');
            $table->dropColumn('external_id');
            $table->unique('phone');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tenant_id');
        });

        Schema::dropIfExists('tenants');
    }
};
