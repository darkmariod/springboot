<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('employees', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('cedula');
            $t->string('nombres');
            $t->string('cargo')->nullable();
            $t->date('fecha_ingreso');
            $t->date('fecha_salida')->nullable();
            $t->decimal('sueldo', 12, 2);
            $t->boolean('fondos_reserva')->default(false);
            $t->boolean('activo')->default(true);
            $t->timestamps();
            $t->unique(['company_id', 'cedula']);
        });
        Schema::create('payrolls', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('anio');
            $t->unsignedTinyInteger('mes');
            $t->decimal('total_ingresos', 12, 2)->default(0);
            $t->decimal('total_egresos', 12, 2)->default(0);
            $t->decimal('total_neto', 12, 2)->default(0);
            $t->decimal('total_provisiones', 12, 2)->default(0);
            $t->enum('estado', ['abierto', 'cerrado'])->default('abierto');
            $t->timestamps();
            $t->unique(['company_id', 'anio', 'mes']);
        });
        Schema::create('payroll_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('payroll_id')->constrained()->cascadeOnDelete();
            $t->foreignId('employee_id')->constrained();
            $t->decimal('sueldo', 12, 2)->default(0);
            $t->decimal('horas_extra', 12, 2)->default(0);
            $t->decimal('comisiones', 12, 2)->default(0);
            $t->decimal('aporte_personal', 12, 2)->default(0);
            $t->decimal('prestamos', 12, 2)->default(0);
            $t->decimal('anticipos', 12, 2)->default(0);
            $t->decimal('neto', 12, 2)->default(0);
            $t->decimal('aporte_patronal', 12, 2)->default(0);
            $t->decimal('decimo_tercero', 12, 2)->default(0);
            $t->decimal('decimo_cuarto', 12, 2)->default(0);
            $t->decimal('fondos_reserva', 12, 2)->default(0);
            $t->decimal('vacaciones', 12, 2)->default(0);
            $t->timestamps();
        });
        Schema::table('companies', fn(Blueprint $t) => $t->decimal('sbu', 10, 2)->default(470));
    }
    public function down(): void {
        Schema::table('companies', fn(Blueprint $t) => $t->dropColumn('sbu'));
        Schema::dropIfExists('payroll_lines');
        Schema::dropIfExists('payrolls');
        Schema::dropIfExists('employees');
    }
};
