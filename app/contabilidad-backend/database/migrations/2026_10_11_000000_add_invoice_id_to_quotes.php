<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T3.5: una factura puede salir de varias cotizaciones, así que cada cotización guarda la factura en que terminó.
 * Solo hacia adelante: las cotizaciones ya facturadas quedan con NULL y siguen mostrándose como facturadas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $t) {
            $t->foreignId('invoice_id')->nullable()->after('estado')->constrained('invoices')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('quotes', fn (Blueprint $t) => $t->dropConstrainedForeignId('invoice_id'));
    }
};
