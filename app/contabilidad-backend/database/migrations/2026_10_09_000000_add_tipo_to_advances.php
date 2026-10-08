<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Anticipos a proveedores. Hasta ahora todos los anticipos eran de clientes: `tipo` los distingue
 * ('cliente' por defecto, así cada anticipo existente sigue siendo de cliente; 'proveedor' es el
 * dinero entregado a un proveedor antes de que facture). Solo hacia adelante: no se toca ningún asiento.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('advances', function (Blueprint $t) {
            $t->string('tipo', 12)->default('cliente');
        });
    }

    public function down(): void
    {
        Schema::table('advances', function (Blueprint $t) {
            $t->dropColumn('tipo');
        });
    }
};
