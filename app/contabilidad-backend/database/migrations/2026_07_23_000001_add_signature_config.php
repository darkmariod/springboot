<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('companies', function (Blueprint $t) {
            $t->string('email_envio')->nullable();       // correo desde el que se envían las facturas
            $t->string('cert_sujeto')->nullable();        // titular del certificado (informativo)
            $t->date('cert_valido_hasta')->nullable();    // vencimiento del .p12
        });
    }
    public function down(): void {
        Schema::table('companies', fn(Blueprint $t)=>$t->dropColumn(['email_envio','cert_sujeto','cert_valido_hasta']));
    }
};
