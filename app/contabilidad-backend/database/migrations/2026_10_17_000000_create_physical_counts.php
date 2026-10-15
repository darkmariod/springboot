<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T5.2: toma física masiva de inventario (TF-000001, contador propio por empresa; NO usa el secuencial de las facturas).
 *  - physical_counts: la toma (bodega, estado borrador|aplicada, motivo, fecha) y, al aplicarla, el total de sobrantes y faltantes.
 *  - physical_count_lines: un producto por línea con el stock que tenía el sistema al abrir el borrador (foto), el costo
 *    de ese momento y lo contado (NULL = no contado: nunca ajusta). Al aplicar se anota contra qué stock se calculó,
 *    la diferencia, su valor y el movimiento del kárdex que nació.
 * Solo hacia adelante: nada de lo existente se reprocesa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('physical_counts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('warehouse_id')->constrained('warehouses');
            $t->string('numero', 20);                                   // TF-000001
            $t->string('estado', 20)->default('borrador');              // borrador | aplicada
            $t->string('motivo')->nullable();
            $t->date('fecha');
            $t->json('omitidos_series')->nullable();                    // productos con series que no entraron (se ajustan por serie)
            $t->decimal('valor_sobrantes', 14, 2)->default(0);          // se llenan al aplicar
            $t->decimal('valor_faltantes', 14, 2)->default(0);
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('applied_at')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'numero']);
            $t->index(['company_id', 'estado']);
        });

        Schema::create('physical_count_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('physical_count_id')->constrained('physical_counts')->cascadeOnDelete();
            $t->foreignId('product_id')->constrained('products');
            $t->decimal('stock_sistema', 14, 4)->default(0);            // el stock de la bodega al abrir el borrador
            $t->decimal('costo_promedio', 14, 4)->default(0);           // el costo de ese momento (solo para mostrar el valor del borrador)
            $t->decimal('contado', 14, 4)->nullable();                  // NULL = no contado
            $t->decimal('stock_al_aplicar', 14, 4)->nullable();         // el stock actual contra el que se calculó la diferencia
            $t->decimal('diferencia', 14, 4)->nullable();               // contado - stock_al_aplicar
            $t->decimal('valor', 14, 2)->nullable();                    // diferencia valorada (negativo = faltante)
            $t->foreignId('inventory_movement_id')->nullable()->constrained('inventory_movements')->nullOnDelete();
            $t->timestamps();
            $t->unique(['physical_count_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('physical_count_lines');
        Schema::dropIfExists('physical_counts');
    }
};
