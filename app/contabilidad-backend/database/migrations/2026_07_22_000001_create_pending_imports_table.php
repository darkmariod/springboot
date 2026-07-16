<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('pending_imports', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('clave_acceso', 49)->unique();
            $t->string('ruc_emisor')->nullable();
            $t->string('razon_social')->nullable();
            $t->date('fecha')->nullable();
            $t->string('estado')->default('pendiente');
            $t->text('error')->nullable();
            $t->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('pending_imports'); }
};
