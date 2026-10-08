<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T3.2: notas de crédito y de débito ligadas a su factura.
 *  - credit_notes.aplicado_factura: cuánto de la nota bajó el saldo de SU factura al emitirse. Lo que sobra queda
 *    como saldo_disponible (saldo a favor). Sirve para devolver el saldo exacto si la nota se anula.
 *  - invoices.factura_referencia_id: la factura que corrige una nota de débito (hasta hoy solo guardaba su número).
 * Solo hacia adelante: las notas que ya existen quedan con 0 / NULL y siguen funcionando como antes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_notes', function (Blueprint $t) {
            $t->decimal('aplicado_factura', 12, 2)->default(0)->after('saldo_disponible');
        });
        Schema::table('invoices', function (Blueprint $t) {
            $t->unsignedBigInteger('factura_referencia_id')->nullable()->index()->after('numero_referencia');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', fn (Blueprint $t) => $t->dropIndex(['factura_referencia_id']));
        Schema::table('invoices', fn (Blueprint $t) => $t->dropColumn('factura_referencia_id'));
        Schema::table('credit_notes', fn (Blueprint $t) => $t->dropColumn('aplicado_factura'));
    }
};
