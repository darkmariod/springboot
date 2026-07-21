<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Correo de respaldo para la factura: si el principal rebota (bandeja llena),
// el sistema puede enviar también a este. Pedido por el cliente en la reunión.
return new class extends Migration {
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $t) {
            $t->string('email2')->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $t) {
            $t->dropColumn('email2');
        });
    }
};
