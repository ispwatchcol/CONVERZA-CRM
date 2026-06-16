<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    // CORRER EN AMBOS schemas: converza y converza_dev (override DB_SEARCH_PATH).
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->string('slug')->unique();

            // Vínculos a los dos productos del founder.
            // null si el ISP todavía no tiene ese producto.
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('ispwatch_tenant_id')->nullable();

            $table->enum('status', ['prospect', 'active', 'past_due', 'suspended', 'churned'])
                  ->default('active');
            $table->enum('health', ['good', 'at_risk', 'critical'])->nullable();

            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('contact_name')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone', 30)->nullable();
            $table->string('country', 5)->nullable()->default('CO');

            $table->date('onboarding_at')->nullable();
            $table->date('renewal_at')->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('renewal_at');
        });

        Schema::create('account_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();

            $table->enum('product', ['ispwatch', 'converza']);
            $table->string('plan', 60)->nullable();
            $table->enum('status', ['active', 'paused', 'cancelled'])->default('active');
            $table->enum('billing_cycle', ['monthly', 'quarterly', 'yearly'])->default('monthly');

            $table->decimal('amount', 12, 2)->default(0);
            $table->string('currency', 3)->default('COP');

            $table->date('started_at')->nullable();
            $table->date('renews_at')->nullable();
            $table->date('cancelled_at')->nullable();

            $table->timestamps();

            $table->unique(['account_id', 'product']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_products');
        Schema::dropIfExists('accounts');
    }
};
