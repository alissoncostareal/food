<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade'); // Cliente
            $table->foreignId('store_id')->constrained()->onDelete('cascade'); // Loja

            $table->decimal('total_amount', 10, 2);
            $table->decimal('delivery_fee', 8, 2)->default(0.00);

            // Status do Pedido
            $table->string('status')->default('pending'); // pending, confirmed, preparing, out_for_delivery, delivered, cancelled

            // Diferencial do seu projeto: Tipo de transação
            $table->string('type')->default('sale'); // 'sale' (venda) ou 'rent' (aluguel)

            // Dados de entrega simplificados
            $table->string('address');
            $table->string('payment_method')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
