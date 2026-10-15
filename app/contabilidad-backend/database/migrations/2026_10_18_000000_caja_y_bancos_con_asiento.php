<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T5.3: caja y bancos con asiento y número. Solo hacia adelante: ninguna fila anterior se reescribe, los campos nuevos
 * quedan vacíos y esos movimientos siguen como estaban (sin número ni asiento propio).
 *
 *  - cash_movements: empresa, número CI-/CE- (por empresa), fecha, la cuenta de contrapartida elegida y el origen cuando el
 *    movimiento nace de otro documento (el depósito a banco deja su salida de caja en la sesión abierta).
 *  - bank_movements: número MB-/DP-/LQ-, clase (manual, deposito, liquidacion_tarjeta, sin_asiento), forma (cheque...),
 *    fecha de cobro del cheque posfechado y la contrapartida del asiento. Los movimientos de pagos y cobros siguen con
 *    todo vacío y su origen: su asiento es el del pago.
 *  - card_settlements: banco donde se acreditó, número LQ-, retenciones de IVA y renta que descuenta el procesador.
 * Los índices únicos (empresa, número) son la última red contra dos números iguales; los NULL no chocan entre sí.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_movements', function (Blueprint $t) {
            $t->unsignedBigInteger('company_id')->nullable();
            $t->string('numero', 20)->nullable();
            $t->date('fecha')->nullable();
            $t->unsignedBigInteger('cuenta_contrapartida_id')->nullable();
            $t->nullableMorphs('origen');
            $t->unique(['company_id', 'numero']);
        });

        Schema::table('bank_movements', function (Blueprint $t) {
            $t->string('numero', 20)->nullable();
            $t->string('clase', 30)->nullable();
            $t->string('forma', 20)->nullable();
            $t->date('fecha_cobro')->nullable();
            $t->unsignedBigInteger('cuenta_contrapartida_id')->nullable();
            $t->unique(['company_id', 'numero']);
        });

        Schema::table('card_settlements', function (Blueprint $t) {
            $t->unsignedBigInteger('bank_id')->nullable();
            $t->string('numero', 20)->nullable();
            $t->decimal('retencion_iva', 12, 2)->default(0);
            $t->decimal('retencion_renta', 12, 2)->default(0);
            $t->unique(['company_id', 'numero']);
        });
    }

    public function down(): void
    {
        Schema::table('card_settlements', function (Blueprint $t) {
            $t->dropUnique(['company_id', 'numero']);
            $t->dropColumn(['bank_id', 'numero', 'retencion_iva', 'retencion_renta']);
        });
        Schema::table('bank_movements', function (Blueprint $t) {
            $t->dropUnique(['company_id', 'numero']);
            $t->dropColumn(['numero', 'clase', 'forma', 'fecha_cobro', 'cuenta_contrapartida_id']);
        });
        Schema::table('cash_movements', function (Blueprint $t) {
            $t->dropUnique(['company_id', 'numero']);
            $t->dropMorphs('origen');
            $t->dropColumn(['company_id', 'numero', 'fecha', 'cuenta_contrapartida_id']);
        });
    }
};
