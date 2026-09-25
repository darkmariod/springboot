<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El formulario de Registro de Compras siempre mandó "bodega" al guardar,
 * pero la columna nunca se creó: cada compra nueva desde esa pantalla
 * terminaba en un error 500, sin que nadie lo notara porque las pruebas
 * de esta sesión cargaban compras por script, no por la pantalla real.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $t) {
            $t->foreignId('warehouse_id')->nullable()->after('sustento_tributario')
                ->constrained('warehouses')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('purchases', fn (Blueprint $t) => $t->dropConstrainedForeignId('warehouse_id'));
    }
};
