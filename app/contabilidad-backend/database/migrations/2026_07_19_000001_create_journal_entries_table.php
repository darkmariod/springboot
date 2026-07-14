<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('journal_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('numero');
            $t->date('fecha');
            $t->string('concepto');
            $t->nullableMorphs('origen'); // factura, compra, cobro...
            $t->decimal('total_debe', 14, 2);
            $t->decimal('total_haber', 14, 2);
            $t->enum('estado', ['pendiente','mayorizado'])->default('pendiente');
            $t->timestamps();
        });
        Schema::create('journal_entry_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('journal_entry_id')->constrained()->cascadeOnDelete();
            $t->foreignId('account_id')->constrained();
            $t->decimal('debe', 14, 2)->default(0);
            $t->decimal('haber', 14, 2)->default(0);
            $t->string('referencia')->nullable();
            $t->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('journal_entry_lines'); Schema::dropIfExists('journal_entries'); }
};
