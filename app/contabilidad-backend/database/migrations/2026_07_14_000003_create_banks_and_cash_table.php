<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('banks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('nombre');
            $t->string('numero_cuenta')->nullable();
            $t->string('cuenta_contable')->nullable();
            $t->timestamps();
        });
        Schema::create('cash_registers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('nombre');
            $t->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('cash_registers'); Schema::dropIfExists('banks'); }
};
