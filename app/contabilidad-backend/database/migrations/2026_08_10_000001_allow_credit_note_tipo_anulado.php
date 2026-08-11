<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        // Permite marcar una nota de crédito como anulada (antes el CHECK de la BD rechazaba 'anulado')
        Schema::table('credit_notes', function (Blueprint $t) {
            $t->enum('tipo', ['sri', 'interna', 'anulado'])->default('interna')->change();
        });
    }
    public function down(): void {
        Schema::table('credit_notes', function (Blueprint $t) {
            $t->enum('tipo', ['sri', 'interna'])->default('interna')->change();
        });
    }
};
