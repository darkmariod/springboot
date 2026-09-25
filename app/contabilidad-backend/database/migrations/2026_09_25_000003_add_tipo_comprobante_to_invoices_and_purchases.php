<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Nota de Débito y Liquidación de Compra reusan las tablas de facturas y
 * compras, filtrando por este campo. Sin la columna, esas DOS PANTALLAS
 * no cargaban ni en el listado: fallaban con "no such column" al abrirlas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $t) {
            $t->string('tipo_comprobante', 30)->nullable()->after('numero');
            $t->string('numero_referencia', 60)->nullable()->after('tipo_comprobante');
        });
        Schema::table('purchases', function (Blueprint $t) {
            $t->string('tipo_comprobante', 30)->nullable()->after('numero');
        });

        // Lo que ya existía es factura/compra normal, no nota de débito
        // ni liquidación — si quedara en NULL, el filtro de las pantallas
        // normales (que también acepta NULL) lo seguiría mostrando bien,
        // pero dejarlo explícito evita sorpresas si mañana se endurece el filtro.
        DB::table('invoices')->whereNull('tipo_comprobante')->update(['tipo_comprobante' => 'factura']);
        DB::table('purchases')->whereNull('tipo_comprobante')->update(['tipo_comprobante' => 'compra']);
    }

    public function down(): void
    {
        Schema::table('invoices', fn (Blueprint $t) => $t->dropColumn(['tipo_comprobante', 'numero_referencia']));
        Schema::table('purchases', fn (Blueprint $t) => $t->dropColumn('tipo_comprobante'));
    }
};
