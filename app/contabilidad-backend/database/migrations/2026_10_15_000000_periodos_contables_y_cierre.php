<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T4.3: períodos contables y cierre del ejercicio.
 *  - accounting_periods: un registro por empresa, año y mes. Un mes SIN registro está abierto; cerrarlo crea (o actualiza)
 *    su registro. Al reabrirlo el registro queda como 'abierto' con quién, cuándo y por qué se reabrió.
 *  - journal_entries.es_cierre: el asiento que cierra el ejercicio (31/12, ingresos y gastos contra el resultado del ejercicio).
 *    Es el único asiento que puede crearse en un mes cerrado y no cuenta en el estado de resultados del año que cierra.
 * Solo hacia adelante: no se toca ningún asiento existente (todos quedan con es_cierre = false) y todos los meses siguen abiertos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_periods', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('anio');
            $t->unsignedTinyInteger('mes');
            $t->string('estado', 10)->default('abierto');   // abierto | cerrado
            $t->timestamp('cerrado_at')->nullable();
            $t->unsignedBigInteger('cerrado_por')->nullable();
            $t->string('motivo_reapertura')->nullable();
            $t->timestamp('reabierto_at')->nullable();
            $t->unsignedBigInteger('reabierto_por')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'anio', 'mes']);
        });

        Schema::table('journal_entries', function (Blueprint $t) {
            $t->boolean('es_cierre')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $t) {
            $t->dropColumn('es_cierre');
        });
        Schema::dropIfExists('accounting_periods');
    }
};
