<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T7.1: movimientos de empleado (anticipos, préstamos, descuentos e ingresos extra), consumos facturados al empleado y
 * permisos y vacaciones. Solo hacia adelante: ningún rol ni asiento anterior se reescribe; las columnas nuevas de
 * `payroll_lines` quedan en cero y `detalle` en NULL, así que un rol generado antes de esta migración se cierra como siempre.
 *
 *  - employees.contact_id: el cliente al que se le facturan los consumos del empleado (se descuentan del rol).
 *  - employee_movements: lo que se registra mes a mes por empleado; `saldo` baja cuando el rol se CIERRA.
 *  - employee_leaves: permisos, vacaciones, enfermedad y otros, con sus días.
 *  - payroll_lines: otros ingresos (bonos), descuentos, consumos, permisos no remunerados, lo diferido por el tope del neto y
 *    el detalle (JSON) de cada movimiento, consumo y permiso que entró en la línea.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $t) {
            $t->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
        });

        Schema::create('employee_movements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('employee_id')->constrained();
            $t->string('tipo', 20);                      // anticipo | prestamo | descuento | ingreso_extra
            $t->string('subtipo', 20)->nullable();       // ingreso_extra: horas_extra | comisiones | bono
            $t->string('concepto');
            $t->date('fecha');
            $t->date('aplicar_desde');                   // primer mes (día 1) en que entra a un rol
            $t->decimal('monto', 12, 2);
            $t->unsignedSmallInteger('cuotas')->default(1);
            $t->decimal('saldo', 12, 2);
            $t->string('estado', 20)->default('pendiente'); // pendiente | aplicado | cancelado
            $t->boolean('entregado')->default(false);    // la empresa entregó el dinero desde caja o banco (hay asiento de entrega)
            $t->string('forma_entrega', 20)->nullable();
            $t->foreignId('bank_id')->nullable()->constrained();
            $t->string('documento')->nullable();
            $t->timestamp('cancelado_at')->nullable();
            $t->string('motivo_cancelacion')->nullable();
            $t->timestamps();
            $t->index(['company_id', 'estado']);
            $t->index(['employee_id', 'estado']);
        });

        Schema::create('employee_leaves', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('employee_id')->constrained();
            $t->string('tipo', 20);                      // permiso | vacaciones | enfermedad | otro
            $t->date('fecha_desde');
            $t->date('fecha_hasta');
            $t->decimal('dias', 6, 2);
            $t->boolean('remunerado')->default(true);
            $t->text('observacion')->nullable();
            $t->string('estado', 20)->default('aprobado'); // pendiente | aprobado | rechazado | cancelado
            $t->timestamps();
            $t->index(['employee_id', 'estado']);
        });

        Schema::table('payroll_lines', function (Blueprint $t) {
            $t->decimal('otros_ingresos', 12, 2)->default(0);
            $t->decimal('descuentos', 12, 2)->default(0);
            $t->decimal('consumos', 12, 2)->default(0);
            $t->decimal('permisos', 12, 2)->default(0);
            $t->decimal('diferido', 12, 2)->default(0);
            $t->text('detalle')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payroll_lines', function (Blueprint $t) {
            $t->dropColumn(['otros_ingresos', 'descuentos', 'consumos', 'permisos', 'diferido', 'detalle']);
        });
        Schema::dropIfExists('employee_leaves');
        Schema::dropIfExists('employee_movements');
        Schema::table('employees', function (Blueprint $t) {
            $t->dropConstrainedForeignId('contact_id');
        });
    }
};
