<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * T11.6: aislamiento entre empresas. El superadministrador es el operador de la plataforma (quien vende el sistema): el único que
 * ve todas las empresas, las crea y cambia su plan. Los demás usuarios con empresa quedan confinados a la suya.
 *
 * Compatible hacia atrás: los administradores que ya existen son las cuentas del dueño de la plataforma, así que todos quedan
 * como superadministrador y nada cambia para ellos. Los administradores de un cliente nuevo nacen con `false` (ver NuevaEmpresa).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'es_superadmin')) {
            Schema::table('users', function (Blueprint $table) {
                $table->boolean('es_superadmin')->default(false);
            });
        }

        DB::table('users')->where('rol', 'admin')->update(['es_superadmin' => true]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'es_superadmin')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('es_superadmin');
            });
        }
    }
};
