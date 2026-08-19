<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Datos que aparecen en el RIDE impreso y que no estaban en la empresa:
 * logo del cliente, teléfonos, resolución de agente de retención, sitio web
 * y la nota al pie del comprobante.
 */
return new class extends Migration {
    public function up(): void {
        Schema::table('companies', function (Blueprint $t) {
            $t->text('logo')->nullable();                        // data URI (base64)
            $t->string('telefonos')->nullable();
            $t->string('agente_retencion')->nullable();           // Nro. de resolución
            $t->string('contribuyente_especial')->nullable();     // Nro. de resolución
            $t->string('sitio_web')->nullable();
            $t->text('nota_pie')->nullable();
        });
    }
    public function down(): void {
        Schema::table('companies', function (Blueprint $t) {
            $t->dropColumn(['logo','telefonos','agente_retencion','contribuyente_especial','sitio_web','nota_pie']);
        });
    }
};
