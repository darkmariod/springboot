<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Centros de costo: permiten saber cuánto vende y cuánto gasta cada área
 * del negocio (local, línea de producto, proyecto) sin abrir otra empresa.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('cost_centers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('codigo', 20);
            $t->string('nombre');
            $t->text('descripcion')->nullable();
            $t->boolean('activo')->default(true);
            $t->timestamps();
            $t->unique(['company_id', 'codigo']);
        });

        foreach (['invoices', 'purchases', 'journal_entries'] as $tabla) {
            if (Schema::hasTable($tabla)) {
                Schema::table($tabla, fn (Blueprint $t) =>
                    $t->foreignId('cost_center_id')->nullable()->constrained('cost_centers')->nullOnDelete());
            }
        }
    }

    public function down(): void
    {
        foreach (['invoices', 'purchases', 'journal_entries'] as $tabla) {
            if (Schema::hasTable($tabla)) {
                Schema::table($tabla, fn (Blueprint $t) => $t->dropConstrainedForeignId('cost_center_id'));
            }
        }
        Schema::dropIfExists('cost_centers');
    }
};
