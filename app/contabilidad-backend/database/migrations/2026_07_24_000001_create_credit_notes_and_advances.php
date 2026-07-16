<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('credit_notes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('contact_id')->constrained('contacts');
            $t->foreignId('invoice_id')->nullable()->constrained();
            $t->enum('tipo', ['sri', 'interna'])->default('interna');
            $t->string('numero')->nullable();
            $t->date('fecha');
            $t->string('motivo');
            $t->json('items')->nullable();
            $t->decimal('total_sin_impuestos', 12, 2)->default(0);
            $t->decimal('total_impuesto', 12, 2)->default(0);
            $t->decimal('importe_total', 12, 2);
            $t->decimal('saldo_disponible', 12, 2)->default(0);
            $t->timestamps();
        });
        Schema::create('advances', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('contact_id')->constrained('contacts');
            $t->date('fecha');
            $t->decimal('monto', 12, 2);
            $t->decimal('saldo', 12, 2);
            $t->string('forma_pago', 20);
            $t->foreignId('bank_id')->nullable()->constrained();
            $t->string('nota')->nullable();
            $t->timestamps();
        });
        Schema::create('credit_applications', function (Blueprint $t) {
            $t->id();
            $t->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $t->nullableMorphs('origen');
            $t->decimal('monto', 12, 2);
            $t->date('fecha');
            $t->timestamps();
        });
    }
    public function down(): void {
        Schema::dropIfExists('credit_applications');
        Schema::dropIfExists('advances');
        Schema::dropIfExists('credit_notes');
    }
};
