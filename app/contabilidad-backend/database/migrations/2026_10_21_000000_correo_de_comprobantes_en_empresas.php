<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T10.1/T10.2: el correo de la empresa para enviar sus comprobantes electrónicos.
 *
 *  - smtp_host, smtp_port, smtp_user, smtp_password: el formulario "Servidor de correo" de e-documents los pedía y el modelo los
 *    leía, pero NINGUNA migración los había creado (y no estaban en $fillable), así que lo que se escribía ahí se descartaba y el
 *    botón "Probar correo" siempre decía que faltaba configurar. La clave va cifrada por el cast `encrypted` de Company.
 *    Cada columna se agrega solo si no existe, por si algún servidor ya la tenía.
 *  - enviar_comprobantes_automaticamente: interruptor del envío automático al autorizarse el comprobante (apagado por defecto).
 *
 * Solo agrega columnas: no reescribe ninguna fila.
 */
return new class extends Migration
{
    public function up(): void
    {
        $nuevas = [
            'smtp_host' => fn (Blueprint $t) => $t->string('smtp_host')->nullable(),
            'smtp_port' => fn (Blueprint $t) => $t->unsignedSmallInteger('smtp_port')->nullable(),
            'smtp_user' => fn (Blueprint $t) => $t->string('smtp_user')->nullable(),
            'smtp_password' => fn (Blueprint $t) => $t->text('smtp_password')->nullable(),
            'enviar_comprobantes_automaticamente' => fn (Blueprint $t) => $t->boolean('enviar_comprobantes_automaticamente')->default(false),
        ];

        foreach ($nuevas as $columna => $definir) {
            if (! Schema::hasColumn('companies', $columna)) {
                Schema::table('companies', fn (Blueprint $t) => $definir($t));
            }
        }
    }

    public function down(): void
    {
        foreach (['enviar_comprobantes_automaticamente', 'smtp_password', 'smtp_user', 'smtp_port', 'smtp_host'] as $columna) {
            if (Schema::hasColumn('companies', $columna)) {
                Schema::table('companies', fn (Blueprint $t) => $t->dropColumn($columna));
            }
        }
    }
};
