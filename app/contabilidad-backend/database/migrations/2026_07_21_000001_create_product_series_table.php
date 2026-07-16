<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('products', fn(Blueprint $t) => $t->boolean('maneja_series')->default(false));
        Schema::create('product_series', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_id')->constrained()->cascadeOnDelete();
            $t->string('serie');
            $t->string('estado')->default('disponible');
            $t->foreignId('purchase_id')->nullable()->constrained();
            $t->foreignId('invoice_id')->nullable()->constrained();
            $t->timestamps();
            $t->unique(['company_id', 'serie']);
        });
    }
    public function down(): void {
        Schema::dropIfExists('product_series');
        Schema::table('products', fn(Blueprint $t) => $t->dropColumn('maneja_series'));
    }
};
