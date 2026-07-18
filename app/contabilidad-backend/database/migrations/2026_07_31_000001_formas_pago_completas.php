<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Sustento tributario del SRI (obligatorio para la declaración de IVA)
        Schema::table('purchases', function (Blueprint $t) {
            $t->string('sustento_tributario', 2)->default('01');
            $t->string('establecimiento', 3)->nullable();
            $t->string('punto_emision', 3)->nullable();
            $t->string('autorizacion')->nullable();
            $t->date('fecha_caducidad')->nullable();
        });

        // Un pago puede repartirse en varias formas (efectivo + cheque + tarjeta)
        Schema::create('payment_splits', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->nullableMorphs('pagable');           // Invoice o Purchase
            $t->string('tipo', 30);                   // ver config/formas_pago.php
            $t->date('fecha');
            $t->decimal('valor', 12, 2);
            $t->foreignId('bank_id')->nullable()->constrained();
            $t->foreignId('cash_register_id')->nullable()->constrained();
            $t->string('documento')->nullable();      // n° de cheque / transferencia
            $t->string('detalle')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_splits');
        Schema::table('purchases', fn(Blueprint $t) => $t->dropColumn([
            'sustento_tributario', 'establecimiento', 'punto_emision', 'autorizacion', 'fecha_caducidad',
        ]));
    }
};
