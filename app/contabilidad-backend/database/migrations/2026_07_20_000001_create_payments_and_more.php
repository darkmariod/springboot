<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        // Cobros de clientes (cartera CxC)
        Schema::create('invoice_payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $t->date('fecha');
            $t->decimal('monto', 12, 2);
            $t->string('forma_pago', 20); // efectivo|transferencia|cheque|cruce
            $t->foreignId('bank_id')->nullable()->constrained();
            $t->string('nota')->nullable();
            $t->timestamps();
        });
        // Pagos a proveedores (cartera CxP)
        Schema::create('purchase_payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('purchase_id')->constrained()->cascadeOnDelete();
            $t->date('fecha');
            $t->decimal('monto', 12, 2);
            $t->string('forma_pago', 20); // efectivo|transferencia|cheque|cruce
            $t->foreignId('bank_id')->nullable()->constrained();
            $t->string('cheque_numero')->nullable();
            $t->timestamps();
        });
        // Retenciones recibidas (empate automático con factura)
        Schema::create('withholdings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('invoice_id')->nullable()->constrained();
            $t->string('numero')->nullable();
            $t->string('clave_acceso', 49)->nullable();
            $t->date('fecha')->nullable();
            $t->decimal('total_retenido', 12, 2)->default(0);
            $t->longText('xml')->nullable();
            $t->timestamps();
        });
        // Cotizaciones
        Schema::create('quotes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('contact_id')->constrained('contacts');
            $t->json('items');
            $t->decimal('total_sin_impuestos', 12, 2);
            $t->decimal('total_impuesto', 12, 2);
            $t->decimal('importe_total', 12, 2);
            $t->string('estado')->default('pendiente'); // pendiente|facturada
            $t->timestamps();
        });
        // Movimientos de banco + conciliación
        Schema::create('bank_movements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('bank_id')->constrained();
            $t->date('fecha');
            $t->enum('tipo', ['debito','credito']);
            $t->decimal('monto', 12, 2);
            $t->string('concepto');
            $t->boolean('conciliado')->default(false);
            $t->timestamps();
        });
        // Las compras llevan saldo pendiente (lo que le debés al proveedor)
        Schema::table('purchases', fn(Blueprint $t)=>$t->decimal('saldo_pendiente',12,2)->default(0));
    }
    public function down(): void {
        Schema::table('purchases', fn(Blueprint $t)=>$t->dropColumn('saldo_pendiente'));
        Schema::dropIfExists('bank_movements');
        Schema::dropIfExists('quotes');
        Schema::dropIfExists('withholdings');
        Schema::dropIfExists('purchase_payments');
        Schema::dropIfExists('invoice_payments');
    }
};
