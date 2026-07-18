<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('withholdings', function (Blueprint $t) {
            $t->string('tipo', 20)->default('recibida'); // recibida | emitida
        });
    }

    public function down(): void
    {
        Schema::table('withholdings', function (Blueprint $t) {
            $t->dropColumn('tipo');
        });
    }
};
