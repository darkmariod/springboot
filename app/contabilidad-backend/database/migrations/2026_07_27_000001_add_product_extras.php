<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('products', function (Blueprint $t) {
            $t->decimal('stock_minimo', 12, 2)->default(0);
            $t->decimal('stock_maximo', 12, 2)->default(0);
            $t->string('ubicacion')->nullable();
            $t->boolean('es_combo')->default(false);
        });
        Schema::create('product_components', function (Blueprint $t) {
            $t->id();
            $t->foreignId('product_id')->constrained()->cascadeOnDelete();
            $t->foreignId('component_id')->constrained('products');
            $t->decimal('cantidad', 12, 2)->default(1);
            $t->timestamps();
        });
        Schema::create('price_lists', function (Blueprint $t) {
            $t->id();
            $t->foreignId('product_id')->constrained()->cascadeOnDelete();
            $t->string('nombre');
            $t->decimal('precio', 12, 2);
            $t->timestamps();
        });
        Schema::create('product_codes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('product_id')->constrained()->cascadeOnDelete();
            $t->string('codigo');
            $t->timestamps();
            $t->index('codigo');
        });
    }
    public function down(): void {
        Schema::dropIfExists('product_codes');
        Schema::dropIfExists('price_lists');
        Schema::dropIfExists('product_components');
        Schema::table('products', fn(Blueprint $t) =>
            $t->dropColumn(['stock_minimo','stock_maximo','ubicacion','es_combo']));
    }
};
