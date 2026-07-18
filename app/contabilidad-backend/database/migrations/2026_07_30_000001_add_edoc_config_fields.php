<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('companies', function (Blueprint $t) {
            // Campos del formulario "Administración configuración e-documents" de KVS
            $t->string('sri_usuario')->nullable();        // RUC para el portal SRI
            $t->text('sri_clave')->nullable();            // encriptada
            $t->string('sri_url_produccion')->nullable();
            $t->string('sri_url_pruebas')->nullable();
            $t->string('cert_emitido_desde')->nullable(); // F. Emisión Firma
            $t->string('tipo_token')->nullable();         // ANF, Security Data, Uanataca...
            $t->unsignedInteger('tiempo_generar')->default(300000);
            $t->unsignedInteger('tiempo_firmar')->default(300000);
            $t->unsignedInteger('tiempo_enviar')->default(300000);
            $t->unsignedInteger('tiempo_autorizar')->default(300000);
            $t->boolean('smtp_ssl')->default(true);
            $t->string('edoc_estado')->default('ACTIVO');
            $t->boolean('modo_online')->default(false);
        });
    }
    public function down(): void {
        Schema::table('companies', fn(Blueprint $t) => $t->dropColumn([
            'sri_usuario','sri_clave','sri_url_produccion','sri_url_pruebas','cert_emitido_desde',
            'tipo_token','tiempo_generar','tiempo_firmar','tiempo_enviar','tiempo_autorizar',
            'smtp_ssl','edoc_estado','modo_online',
        ]));
    }
};
