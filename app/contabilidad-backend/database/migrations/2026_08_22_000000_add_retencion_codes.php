<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El formulario 103 agrupa las retenciones de renta por CÓDIGO del SRI
 * (303 honorarios, 304 intelecto, 307 mano de obra…). Sin ese código no se
 * puede armar la declaración, así que se guarda junto con la base y el valor.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('withholdings', function (Blueprint $t) {
            $t->string('codigo_retencion', 10)->nullable();
            $t->decimal('base_imponible', 12, 2)->nullable();
            $t->decimal('porcentaje', 6, 2)->nullable();
            $t->foreignId('purchase_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('withholdings', function (Blueprint $t) {
            $t->dropConstrainedForeignId('purchase_id');
            $t->dropConstrainedForeignId('contact_id');
            $t->dropColumn(['codigo_retencion', 'base_imponible', 'porcentaje']);
        });
    }
};
