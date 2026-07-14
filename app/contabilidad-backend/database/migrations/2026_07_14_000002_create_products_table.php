<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('products', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('codigo');
            $t->string('descripcion');
            $t->enum('tipo', ['bien', 'servicio'])->default('bien');
            $t->string('imagen')->nullable();
            $t->decimal('precio', 12, 2)->default(0);
            $t->decimal('tarifa_iva', 5, 2)->default(15);
            $t->decimal('stock', 14, 2)->default(0);
            $t->timestamps();
            $t->unique(['company_id', 'codigo']);
        });
    }
    public function down(): void { Schema::dropIfExists('products'); }
};
