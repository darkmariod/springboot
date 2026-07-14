<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('products', fn(Blueprint $t)=>$t->decimal('costo_promedio',12,4)->default(0));
        Schema::create('inventory_movements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_id')->constrained()->cascadeOnDelete();
            $t->date('fecha');
            $t->enum('tipo', ['ingreso','egreso','ajuste']);
            $t->string('concepto');
            $t->decimal('cantidad', 14, 2);
            $t->decimal('costo_unitario', 12, 4);
            $t->decimal('saldo_cantidad', 14, 2);
            $t->decimal('saldo_costo_promedio', 12, 4);
            $t->decimal('saldo_valor', 14, 2);
            $t->timestamps();
        });
    }
    public function down(): void {
        Schema::dropIfExists('inventory_movements');
        Schema::table('products', fn(Blueprint $t)=>$t->dropColumn('costo_promedio'));
    }
};
