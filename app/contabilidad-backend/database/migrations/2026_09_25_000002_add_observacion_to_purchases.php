<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mismo caso que warehouse_id: el formulario y el controlador siempre
 * mandaron "observación", pero la columna nunca se creó.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $t) {
            $t->string('observacion', 600)->nullable()->after('warehouse_id');
        });
    }

    public function down(): void
    {
        Schema::table('purchases', fn (Blueprint $t) => $t->dropColumn('observacion'));
    }
};
