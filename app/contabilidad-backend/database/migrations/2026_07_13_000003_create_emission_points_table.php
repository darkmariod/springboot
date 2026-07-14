<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Puntos de emisión: cada caja/establecimiento con su propia secuencia.
        // Ej. estab 001, punto 901 (caja), 902 (farmacia). Deben existir en el SRI.
        Schema::create('emission_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('estab', 3);      // establecimiento (001)
            $table->string('punto', 3);      // punto de emisión (901)
            $table->string('nombre');        // "Caja", "Farmacia"
            $table->unsignedInteger('secuencial')->default(1);
            $table->timestamps();

            $table->unique(['company_id', 'estab', 'punto']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emission_points');
    }
};
