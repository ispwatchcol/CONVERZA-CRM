<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    // CORRER EN AMBOS schemas: converza y converza_dev (override DB_SEARCH_PATH).
    //
    // Marca los pagos que NO son plata nueva entrando, sino saldo a favor que ya
    // estaba en la cuenta y se aplica como descuento a una factura nueva.
    //
    // Es la pieza que mantiene coherentes las dos vistas del dinero:
    //   - saldo de la cuenta = Σ pagos REALES − Σ facturas no anuladas
    //     (excluye estas filas: ese dinero ya se contó cuando entró)
    //   - saldo de una factura = total − Σ TODOS sus pagos
    //     (las incluye: para la factura sí es plata que la abona)
    // Sin la marca, aplicar un saldo a favor lo contaría dos veces como ingreso.
    public function up(): void
    {
        Schema::table('account_payments', function (Blueprint $table) {
            $table->boolean('is_credit_application')->default(false)->after('method');
        });
    }

    public function down(): void
    {
        Schema::table('account_payments', function (Blueprint $table) {
            $table->dropColumn('is_credit_application');
        });
    }
};
