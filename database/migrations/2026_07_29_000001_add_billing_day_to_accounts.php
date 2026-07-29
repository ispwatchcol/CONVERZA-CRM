<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    // CORRER EN AMBOS schemas: converza y converza_dev (override DB_SEARCH_PATH).
    //
    // `billing_day` = día del mes en que se le factura a ESTA cuenta (1-31). Es la
    // excepción por cliente: Chaguani pagó un 9, así que se le cobra los 9 de cada
    // mes. NULL = sin excepción, el founder pone las fechas a mano como hasta ahora.
    //
    // Si el mes no tiene ese día (31 en febrero) se recorta al último día del mes;
    // eso lo resuelve Account::nextBillingDate(), no la BD.
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->unsignedTinyInteger('billing_day')->nullable()->after('renewal_at');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn('billing_day');
        });
    }
};
