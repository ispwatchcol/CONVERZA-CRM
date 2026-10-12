<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    // CORRER EN AMBOS schemas: converza y converza_dev (override DB_SEARCH_PATH).
    //
    // Egresos del negocio (lo que cuesta mantener las apps), para ver el resultado
    // del mes al lado de lo que entra por cobros. Prefijo `brain_` porque no son de
    // ningún tenant: si un día los ISP llevan sus gastos en Converza, `expenses`
    // queda libre para ellos.
    public function up(): void
    {
        // Gastos fijos: la plantilla de lo que se paga todos los meses (o todos los
        // años) — el droplet, la base de datos, el dominio. No es plata gastada
        // todavía: cada período genera su fila en `brain_expenses`.
        Schema::create('brain_recurring_expenses', function (Blueprint $table) {
            $table->id();
            $table->string('concept', 120);
            $table->string('vendor', 80)->nullable();
            $table->string('category', 30);
            $table->string('app', 40);
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('USD');
            $table->enum('cycle', ['monthly', 'yearly'])->default('monthly');
            $table->unsignedTinyInteger('day_of_month')->default(1);
            // Solo para los anuales: en qué mes se paga.
            $table->unsignedTinyInteger('month_of_year')->nullable();
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->boolean('active')->default(true);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('brain_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recurring_expense_id')->nullable()
                ->constrained('brain_recurring_expenses')->nullOnDelete();
            $table->string('concept', 120);
            $table->string('vendor', 80)->nullable();
            $table->string('category', 30);
            $table->string('app', 40);
            $table->enum('kind', ['fixed', 'variable']);
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('USD');
            $table->date('spent_at');
            $table->string('method', 20)->nullable();
            $table->string('reference', 120)->nullable();
            $table->text('notes')->nullable();
            // 'YYYY-MM' (o 'YYYY' en los anuales) del período que generó la fila.
            // Junto al gasto fijo es el candado: un período, una fila.
            $table->string('period', 7)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['recurring_expense_id', 'period']);
            $table->index('spent_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brain_expenses');
        Schema::dropIfExists('brain_recurring_expenses');
    }
};
