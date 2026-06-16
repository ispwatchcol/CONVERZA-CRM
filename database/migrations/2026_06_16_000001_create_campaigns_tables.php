<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    // CORRER EN AMBOS schemas: converza y converza_dev (override DB_SEARCH_PATH).
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('template_id')->nullable()->constrained('templates')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('name');
            $table->enum('status', ['draft', 'scheduled', 'sending', 'paused', 'completed', 'cancelled', 'failed'])
                  ->default('draft');
            $table->enum('source_type', ['upload', 'crm', 'manual', 'sheet']);
            $table->json('source_meta')->nullable();
            $table->json('variable_mapping')->nullable();

            $table->unsignedInteger('throttle_per_minute')->default(60);
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->unsignedInteger('total_recipients')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('delivered_count')->default(0);
            $table->unsignedInteger('read_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->unsignedInteger('replied_count')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'status']);
        });

        Schema::create('campaign_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();

            $table->string('phone', 20);
            $table->string('name')->nullable();
            $table->json('variables')->nullable();

            $table->enum('status', ['pending', 'queued', 'sent', 'delivered', 'read', 'failed', 'skipped', 'opted_out'])
                  ->default('pending');
            $table->string('skip_reason')->nullable();
            $table->string('wa_message_id')->nullable();
            $table->text('error')->nullable();

            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('replied_at')->nullable();

            $table->timestamps();

            $table->unique(['campaign_id', 'phone']);
            $table->index('wa_message_id');
            $table->index(['campaign_id', 'status']);
        });

        Schema::create('campaign_opt_outs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained()->nullOnDelete();

            $table->string('phone', 20);
            $table->string('reason')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->unique(['tenant_id', 'phone']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_opt_outs');
        Schema::dropIfExists('campaign_recipients');
        Schema::dropIfExists('campaigns');
    }
};
