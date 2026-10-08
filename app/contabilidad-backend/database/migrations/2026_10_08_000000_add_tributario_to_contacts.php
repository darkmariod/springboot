<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Datos tributarios y contables del cliente/proveedor.
 *  - clase_contribuyente: rise | emprendedor | negocio_popular | otros (nulo = sin definir).
 *  - cuenta_contable_id: cuenta de gasto/costo por defecto del proveedor (de la misma empresa);
 *    los servicios y gastos de sus compras se asientan ahí en lugar de 5.1.01.
 * `parte_relacionada` ya existía; ahora cambia las cuentas por cobrar/pagar que se usan.
 * Solo hacia adelante: no se toca ningún asiento existente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $t) {
            $t->string('clase_contribuyente', 20)->nullable()->after('parte_relacionada');
            $t->foreignId('cuenta_contable_id')->nullable()->after('clase_contribuyente')
                ->constrained('accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $t) {
            $t->dropConstrainedForeignId('cuenta_contable_id');
            $t->dropColumn('clase_contribuyente');
        });
    }
};
