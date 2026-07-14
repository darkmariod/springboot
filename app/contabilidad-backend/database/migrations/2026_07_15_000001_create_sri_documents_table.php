<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('sri_documents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->morphs('documentable');
            $t->string('tipo_comprobante', 4);
            $t->string('clave_acceso', 49)->nullable();
            $t->longText('xml')->nullable();
            $t->longText('xml_firmado')->nullable();
            $t->string('estado')->default('generado');
            $t->string('numero_autorizacion')->nullable();
            $t->tinyInteger('ambiente')->default(1);
            $t->json('empresa_data')->nullable();
            $t->json('mensajes')->nullable();
            $t->timestamp('fecha_emision')->nullable();
            $t->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('sri_documents'); }
};
