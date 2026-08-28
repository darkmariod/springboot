<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conciliación de tarjetas.
 *
 * Cuando se cobra con tarjeta el dinero no entra el mismo día: el procesador
 * (Datafast, Medianet, el banco) deposita después, en lotes y descontando su
 * comisión. Conciliar es cruzar cada voucher de venta contra ese depósito.
 *
 *   card_transactions -> un cobro con tarjeta, nace pendiente
 *   card_settlements  -> la liquidación que deposita el procesador
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('card_settlements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->date('fecha');
            $t->string('procesador');                       // Datafast, Medianet, Banco…
            $t->string('lote')->nullable();                 // número de lote del procesador
            $t->decimal('monto_bruto', 12, 2)->default(0);  // suma de los vouchers
            $t->decimal('comision', 12, 2)->default(0);
            $t->decimal('monto_neto', 12, 2)->default(0);   // lo que realmente se depositó
            $t->string('estado')->default('abierta');       // abierta | conciliada
            $t->text('notas')->nullable();
            $t->timestamps();
        });

        Schema::create('card_transactions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('card_settlement_id')->nullable()->constrained()->nullOnDelete();
            $t->date('fecha');
            $t->string('tarjeta')->nullable();              // Visa, Mastercard, Diners…
            $t->string('voucher')->nullable();
            $t->decimal('monto', 12, 2);
            $t->timestamps();
            $t->index(['company_id', 'card_settlement_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('card_transactions');
        Schema::dropIfExists('card_settlements');
    }
};
