<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('cash_sessions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('cash_register_id')->constrained();
            $t->date('fecha');
            $t->decimal('saldo_inicial', 12, 2)->default(0);
            $t->decimal('ingresos', 12, 2)->default(0);
            $t->decimal('egresos', 12, 2)->default(0);
            $t->decimal('saldo_final_contado', 12, 2)->nullable();
            $t->enum('estado', ['abierta','cerrada'])->default('abierta');
            $t->timestamps();
        });
        Schema::create('cash_movements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('cash_session_id')->constrained()->cascadeOnDelete();
            $t->enum('tipo', ['ingreso','egreso']);
            $t->decimal('monto', 12, 2);
            $t->string('concepto');
            $t->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('cash_movements'); Schema::dropIfExists('cash_sessions'); }
};
