<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('companies', function (Blueprint $t) {
            $t->string('plan')->default('corporativo');
            $t->date('plan_vence')->nullable();
        });
    }
    public function down(): void {
        Schema::table('companies', fn(Blueprint $t) => $t->dropColumn(['plan', 'plan_vence']));
    }
};
