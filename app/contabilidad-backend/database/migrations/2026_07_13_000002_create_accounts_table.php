<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('codigo');
            $table->string('nombre');
            $table->enum('tipo', ['activo', 'pasivo', 'patrimonio', 'ingreso', 'gasto']);
            $table->foreignId('parent_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'codigo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
