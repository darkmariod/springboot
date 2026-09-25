<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sin esto no hay forma de encontrar qué movimientos del kárdex vinieron de
 * qué compra, así que eliminar una compra no se podía revertir de verdad
 * (o se borraba a ciegas, o se dejaba el stock inflado para siempre).
 * Mismo patrón que invoice_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_movements', function (Blueprint $t) {
            $t->foreignId('purchase_id')->nullable()->after('invoice_id')
                ->constrained('purchases')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_movements', fn (Blueprint $t) => $t->dropConstrainedForeignId('purchase_id'));
    }
};
