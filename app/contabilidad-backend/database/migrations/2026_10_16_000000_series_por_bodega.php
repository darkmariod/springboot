<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T5.1: cada serie sabe en qué bodega está y, si volvió por una nota de crédito, cuál.
 *  - product_series.warehouse_id: la bodega donde está la unidad (o de la que salió, si ya se vendió o se dio de baja).
 *    La columna ya existía desde la migración de bodegas (2026_08_01_000001: nullable, con llave foránea) pero nada la escribía:
 *    todas las series de antes tienen NULL y se tratan como de la bodega principal de la empresa (la marcada por defecto), que es
 *    a donde entró su stock. No se reescribe ninguna fila. Esta migración solo la deja indexada (y la crea si faltara).
 *  - product_series.credit_note_id: la nota de crédito que devolvió la serie a la bodega (para el historial de la serie).
 * Solo hacia adelante: nada se rellena.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_series', function (Blueprint $t) {
            if (! Schema::hasColumn('product_series', 'warehouse_id')) {
                $t->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            }
            $t->foreignId('credit_note_id')->nullable()->constrained('credit_notes')->nullOnDelete();
            $t->index('warehouse_id');
            $t->index(['product_id', 'estado', 'warehouse_id']);
        });
    }

    public function down(): void
    {
        Schema::table('product_series', function (Blueprint $t) {
            $t->dropIndex(['product_id', 'estado', 'warehouse_id']);
            $t->dropIndex(['warehouse_id']);
            $t->dropConstrainedForeignId('credit_note_id');
        });
    }
};
