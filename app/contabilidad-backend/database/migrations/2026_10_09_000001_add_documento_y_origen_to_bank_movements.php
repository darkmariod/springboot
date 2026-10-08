<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Movimientos de banco que nacen de un pago o cobro (cheque, transferencia...) para conciliarlos:
 *  - documento: número del cheque o de la transferencia.
 *  - origen_type / origen_id: el documento que lo generó (compra, factura, anticipo o asiento de un pago múltiple).
 * Los movimientos manuales de siempre dejan los tres campos vacíos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_movements', function (Blueprint $t) {
            $t->string('documento')->nullable();
            $t->nullableMorphs('origen');
        });
    }

    public function down(): void
    {
        Schema::table('bank_movements', function (Blueprint $t) {
            $t->dropMorphs('origen');
            $t->dropColumn('documento');
        });
    }
};
