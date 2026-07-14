<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('ruc', 13)->unique();
            $table->string('razon_social');
            $table->string('nombre_comercial')->nullable();
            $table->string('dir_matriz');
            $table->string('estab', 3)->default('001');
            $table->string('pto_emi', 3)->default('001');
            $table->unsignedInteger('secuencial')->default(1);
            $table->string('regimen', 2)->nullable();
            $table->boolean('obligado_contabilidad')->default(false);
            $table->tinyInteger('ambiente')->default(1); // 1 pruebas, 2 producción
            // Certificado de firma (.p12) por empresa — encriptado
            $table->binary('certificado_p12')->nullable();
            $table->text('certificado_clave')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
