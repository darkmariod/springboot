<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T3.7: fraccionamiento y conversión de artículos dejan un documento propio (FRA-000001 / CONV-000001, contador por
 * empresa y por clase) en vez de gastar el secuencial de las facturas y crear un asiento vacío.
 *  - stock_transformations: el documento (producto que sale, producto que entra, cantidades, valor que se conserva, bodega).
 *  - inventory_movements.origen_type / origen_id: los dos lados del kárdex apuntan al documento que los originó
 *    (los movimientos de siempre quedan con NULL).
 * Solo hacia adelante: las conversiones y fraccionamientos anteriores no se reprocesan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_transformations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('tipo', 20);                                   // fraccionamiento | conversion
            $t->string('numero', 20);                                 // FRA-000001 | CONV-000001
            $t->foreignId('product_from_id')->constrained('products');
            $t->foreignId('product_to_id')->constrained('products');
            $t->decimal('cantidad_origen', 14, 4);
            $t->decimal('cantidad_destino', 14, 4);
            $t->decimal('valor_total', 14, 2)->default(0);           // el valor del inventario que sale y vuelve a entrar
            $t->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $t->string('motivo')->nullable();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->date('fecha');
            $t->timestamps();
            $t->unique(['company_id', 'numero']);
        });

        Schema::table('inventory_movements', function (Blueprint $t) {
            $t->nullableMorphs('origen');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_movements', function (Blueprint $t) {
            $t->dropMorphs('origen');
        });
        Schema::dropIfExists('stock_transformations');
    }
};
