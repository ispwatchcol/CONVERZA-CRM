<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    // CORRER EN AMBOS schemas: converza y converza_dev (override DB_SEARCH_PATH).
    public function up(): void
    {
        Schema::create('account_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('number', 40)->unique();
            $table->string('concept');
            $table->decimal('amount', 12, 2)->default(0);
            $table->decimal('tax', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->string('currency', 3)->default('COP');

            $table->enum('status', ['draft', 'sent', 'paid', 'partial', 'overdue', 'void'])
                  ->default('draft');

            $table->date('issued_at');
            $table->date('due_at')->nullable();
            $table->date('paid_at')->nullable();
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();

            $table->timestamps();

            $table->index(['account_id', 'status']);
            $table->index('due_at');
        });

        Schema::create('account_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('COP');
            $table->enum('method', ['transfer', 'cash', 'card', 'other'])->default('transfer');
            $table->string('reference', 120)->nullable();
            $table->date('paid_at');

            $table->timestamps();

            $table->index(['account_id', 'paid_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_payments');
        Schema::dropIfExists('account_invoices');
    }
};
