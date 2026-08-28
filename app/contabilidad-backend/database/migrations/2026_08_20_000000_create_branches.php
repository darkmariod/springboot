<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sucursales (multisede).
 * Cada sucursal es un establecimiento ante el SRI: tiene su propio código
 * (001 la matriz, 002 en adelante las demás), su dirección y su numeración.
 * Las bodegas, los puntos de emisión y las facturas pasan a pertenecer a una.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('estab', 3);                 // 001, 002, 003…
            $t->string('nombre');
            $t->string('direccion')->nullable();
            $t->string('telefono')->nullable();
            $t->boolean('es_matriz')->default(false);
            $t->boolean('activa')->default(true);
            $t->timestamps();
            $t->unique(['company_id', 'estab']);
        });

        foreach (['warehouses', 'emission_points', 'invoices'] as $tabla) {
            Schema::table($tabla, function (Blueprint $t) {
                $t->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['warehouses', 'emission_points', 'invoices'] as $tabla) {
            Schema::table($tabla, fn (Blueprint $t) => $t->dropConstrainedForeignId('branch_id'));
        }
        Schema::dropIfExists('branches');
    }
};
