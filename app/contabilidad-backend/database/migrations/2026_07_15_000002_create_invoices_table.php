<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('invoices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('contact_id')->constrained('contacts');
            $t->string('numero')->nullable();
            $t->json('items');
            $t->decimal('total_sin_impuestos', 12, 2);
            $t->decimal('total_impuesto', 12, 2);
            $t->decimal('importe_total', 12, 2);
            $t->string('forma_pago', 20)->default('efectivo');
            $t->decimal('saldo_pendiente', 12, 2)->default(0);
            $t->string('estado')->default('emitida');
            $t->timestamp('fecha_emision')->nullable();
            $t->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('invoices'); }
};
