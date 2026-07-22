<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_reservations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->foreignId('product_id')->constrained();
            $t->foreignId('contact_id')->nullable()->constrained();
            $t->decimal('quantity', 12, 2);
            $t->string('motivo')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->enum('estado', ['activa', 'cancelada', 'cumplida'])->default('activa');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_reservations');
    }
};
