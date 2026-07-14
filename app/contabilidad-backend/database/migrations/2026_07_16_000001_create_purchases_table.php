<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('purchases', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('contact_id')->constrained('contacts'); // proveedor
            $t->string('numero')->nullable();
            $t->string('clave_acceso', 49)->nullable();
            $t->date('fecha_emision');
            $t->json('items');
            $t->decimal('total_sin_impuestos', 12, 2);
            $t->decimal('total_impuesto', 12, 2);   // IVA = crédito tributario
            $t->decimal('importe_total', 12, 2);
            $t->longText('xml')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'clave_acceso']);
        });
    }
    public function down(): void { Schema::dropIfExists('purchases'); }
};
