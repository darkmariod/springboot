<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('warehouses', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('codigo', 10);
            $t->string('nombre');
            $t->boolean('por_defecto')->default(false);
            $t->boolean('activa')->default(true);
            $t->timestamps();
            $t->unique(['company_id', 'codigo']);
        });

        Schema::create('warehouse_stocks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_id')->constrained()->cascadeOnDelete();
            $t->decimal('stock', 14, 2)->default(0);
            $t->timestamps();
            $t->unique(['warehouse_id', 'product_id']);
        });

        Schema::table('inventory_movements', function (Blueprint $t) {
            $t->foreignId('warehouse_id')->nullable()->constrained();
        });

        Schema::table('product_series', function (Blueprint $t) {
            $t->foreignId('warehouse_id')->nullable()->constrained();
        });
    }

    public function down(): void
    {
        Schema::table('product_series', fn(Blueprint $t) => $t->dropConstrainedForeignId('warehouse_id'));
        Schema::table('inventory_movements', fn(Blueprint $t) => $t->dropConstrainedForeignId('warehouse_id'));
        Schema::dropIfExists('warehouse_stocks');
        Schema::dropIfExists('warehouses');
    }
};
