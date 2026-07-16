<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('users', function (Blueprint $t) {
            $t->string('rol')->default('admin');
            $t->foreignId('emission_point_id')->nullable()->constrained();
            $t->boolean('activo')->default(true);
        });
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained();
            $t->string('accion');
            $t->string('modelo');
            $t->unsignedBigInteger('modelo_id')->nullable();
            $t->string('descripcion')->nullable();
            $t->json('cambios')->nullable();
            $t->string('ip', 45)->nullable();
            $t->timestamps();
            $t->index(['modelo', 'modelo_id']);
        });
    }
    public function down(): void {
        Schema::dropIfExists('audit_logs');
        Schema::table('users', function (Blueprint $t) {
            $t->dropConstrainedForeignId('emission_point_id');
            $t->dropColumn(['rol', 'activo']);
        });
    }
};
