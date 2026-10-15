<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * T4.1: asientos manuales, edición y borrador.
 *  - journal_entries.estado deja de ser un enum cerrado (pendiente, mayorizado): admite también 'borrador'
 *    (guardado sin contar en ningún reporte). En SQLite el enum es un CHECK, por eso se pasa a texto.
 *  - manual: el asiento lo escribió una persona (no lo generó un documento).
 *  - editado_manualmente / editado_at / editado_por: un asiento generado por un documento fue corregido a mano.
 *    Sirve para que el chequeo contable explique una diferencia de cartera o inventario.
 *  - fue_mayorizado: nunca vuelve a false. Un asiento manual que ya fue mayorizado no se elimina aunque se desmayorice.
 * Solo hacia adelante: no se reescribe ningún asiento; los mayorizados de hoy quedan marcados como "fue mayorizado".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $t) {
            $t->string('estado', 20)->default('pendiente')->change();
        });
        Schema::table('journal_entries', function (Blueprint $t) {
            $t->boolean('manual')->default(false);
            $t->boolean('editado_manualmente')->default(false);
            $t->timestamp('editado_at')->nullable();
            $t->unsignedBigInteger('editado_por')->nullable();
            $t->boolean('fue_mayorizado')->default(false);
        });
        DB::table('journal_entries')->where('estado', 'mayorizado')->update(['fue_mayorizado' => true]);
    }

    public function down(): void
    {
        // Los borradores no caben en el enum anterior: vuelven a pendiente antes de restaurarlo
        DB::table('journal_entries')->where('estado', 'borrador')->update(['estado' => 'pendiente']);
        Schema::table('journal_entries', function (Blueprint $t) {
            $t->dropColumn(['manual', 'editado_manualmente', 'editado_at', 'editado_por', 'fue_mayorizado']);
        });
        Schema::table('journal_entries', function (Blueprint $t) {
            $t->enum('estado', ['pendiente', 'mayorizado'])->default('pendiente')->change();
        });
    }
};
