<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T10.1: bitácora de los correos de comprobantes. Una fila por intento (enviado, error u omitido), con a quién, qué asunto,
 * qué adjuntos y, si falló o no se intentó, por qué (texto recortado y sin ningún secreto). El comprobante es el documento
 * de negocio (factura, nota, retención) al que pertenece el correo, no su fila de sri_documents.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('documentable_type');
            $t->unsignedBigInteger('documentable_id');
            $t->string('tipo_comprobante', 30);            // factura | nota_credito | nota_debito | retencion
            $t->json('destinatarios');
            $t->string('asunto', 255)->nullable();
            $t->string('estado', 12);                      // enviado | error | omitido
            $t->string('motivo', 500)->nullable();         // por qué falló u omitió (recortado y sin secretos)
            $t->boolean('automatico')->default(false);
            $t->json('adjuntos')->nullable();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamp('sent_at')->nullable();
            $t->timestamps();

            $t->index(['documentable_type', 'documentable_id']);
            $t->index(['company_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_logs');
    }
};
