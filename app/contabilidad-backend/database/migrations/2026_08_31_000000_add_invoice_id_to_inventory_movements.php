<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sin esta columna una línea del kárdex no se puede rastrear hasta su factura,
 * y al anular una venta no hay cómo encontrar el movimiento que la descontó.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_movements', function (Blueprint $t) {
            $t->foreignId('invoice_id')->nullable()->after('warehouse_id')
                ->constrained('invoices')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_movements', fn (Blueprint $t) => $t->dropConstrainedForeignId('invoice_id'));
    }
};
