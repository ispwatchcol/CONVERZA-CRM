<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    // CORRER EN AMBOS schemas: converza y converza_dev (override DB_SEARCH_PATH).
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('subject');
            $table->enum('status', ['open', 'pending', 'resolved', 'closed'])->default('open');
            $table->enum('priority', ['low', 'normal', 'high', 'urgent'])->default('normal');
            $table->string('category', 40)->nullable(); // technical|billing|onboarding|other
            $table->enum('product', ['ispwatch', 'converza'])->nullable();
            $table->enum('source', ['manual', 'whatsapp', 'email'])->default('manual');

            $table->timestamp('first_response_at')->nullable();
            $table->timestamp('resolved_at')->nullable();

            $table->timestamps();

            $table->index(['account_id', 'status']);
            $table->index(['status', 'priority']);
        });

        Schema::create('ticket_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->enum('type', ['message', 'note', 'status_change', 'assignment']);
            $table->text('body')->nullable();
            $table->json('meta')->nullable(); // {from:'open', to:'resolved'} etc.

            $table->timestamp('created_at')->useCurrent();

            $table->index('support_ticket_id');
        });

        Schema::create('account_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->text('body');
            $table->boolean('pinned')->default(false);

            $table->timestamps();

            $table->index(['account_id', 'pinned']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_notes');
        Schema::dropIfExists('ticket_events');
        Schema::dropIfExists('support_tickets');
    }
};
