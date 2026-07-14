<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('contacts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->boolean('es_cliente')->default(true);
            $t->boolean('es_proveedor')->default(false);
            $t->string('tipo_identificacion', 2)->default('05');
            $t->string('identificacion');
            $t->string('razon_social');
            $t->string('nombre_comercial')->nullable();
            $t->string('direccion')->nullable();
            $t->string('telefono')->nullable();
            $t->string('email')->nullable();
            $t->boolean('parte_relacionada')->default(false);
            $t->timestamps();
            $t->unique(['company_id', 'identificacion']);
        });
    }
    public function down(): void { Schema::dropIfExists('contacts'); }
};
