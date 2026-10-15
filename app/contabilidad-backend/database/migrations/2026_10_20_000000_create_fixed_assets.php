<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T7.2: activos fijos con depreciación mensual en línea recta. Solo hacia adelante: solo crea tablas nuevas, no reescribe ninguna fila.
 *
 *  - fixed_assets: la ficha del activo (código por empresa, grupo, responsable, costo, valor residual, vida útil, inicio de la depreciación,
 *    estado y baja). `purchase_id` liga la ficha con la compra que la capitalizó; `journal_entry_alta_id` y `journal_entry_baja_id` guardan
 *    los asientos de alta y de baja (sin llave foránea: un asiento no se borra, se reversa).
 *  - fixed_asset_depreciations: una fila por activo y mes ya contabilizado (único por activo, año y mes) con el monto, lo acumulado
 *    después de ese mes y el asiento del mes. Es el cronograma real; lo que falta por depreciar se calcula de la ficha.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fixed_assets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('codigo', 30);
            $t->string('nombre', 150);
            $t->text('descripcion')->nullable();
            $t->string('grupo', 30);                       // muebles_enseres | equipos_computacion | maquinaria_equipo | vehiculos | edificios | otros
            $t->string('departamento', 100)->nullable();   // departamento o ubicación
            $t->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();   // responsable
            $t->date('fecha_adquisicion');
            $t->decimal('costo', 14, 2);
            $t->decimal('valor_residual', 14, 2)->default(0);
            $t->unsignedSmallInteger('vida_util_meses');
            $t->string('metodo', 20)->default('linea_recta');
            $t->date('fecha_inicio_depreciacion');          // siempre día 1: la depreciación se calcula por meses completos
            $t->string('estado', 20)->default('activo');    // activo | dado_de_baja
            $t->date('fecha_baja')->nullable();
            $t->string('motivo_baja')->nullable();
            $t->decimal('valor_venta', 14, 2)->nullable();
            $t->foreignId('purchase_id')->nullable()->constrained('purchases')->nullOnDelete();
            $t->string('serie', 80)->nullable();            // serie o placa
            $t->text('observacion')->nullable();
            $t->unsignedBigInteger('journal_entry_alta_id')->nullable();
            $t->unsignedBigInteger('journal_entry_baja_id')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'codigo']);
            $t->index(['company_id', 'estado']);
        });

        Schema::create('fixed_asset_depreciations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('fixed_asset_id')->constrained('fixed_assets')->cascadeOnDelete();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('anio');
            $t->unsignedTinyInteger('mes');
            $t->decimal('monto', 14, 2);
            $t->decimal('depreciacion_acumulada_despues', 14, 2);
            $t->unsignedBigInteger('journal_entry_id')->nullable();
            $t->timestamps();
            $t->unique(['fixed_asset_id', 'anio', 'mes']);
            $t->index(['company_id', 'anio', 'mes']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_asset_depreciations');
        Schema::dropIfExists('fixed_assets');
    }
};
