<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Tabela de Produtos (Cardápio)
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->onDelete('cascade');
            $table->string('name');
            $table->string('description')->nullable();
            $table->decimal('price', 8, 2);
            $table->string('image_url')->nullable(); // Campo para imagem do produto
            $table->boolean('is_available')->default(true); // Se está ativo ou pausado
            $table->timestamps();
        });

        // 2. Grupos de Opções (ex: "Bordas", "Adicionais")
        Schema::create('option_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->onDelete('cascade');
            $table->string('name'); // ex: "Escolha o sabor da borda"
            $table->integer('min_selected')->default(0); // 0 se for opcional
            $table->integer('max_selected')->default(1); // 1 para rádio, >1 para checkbox
            $table->timestamps();
        });

        // 3. Itens das Opções (ex: "Catupiry", "Cheddar")
        Schema::create('option_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('option_group_id')->constrained('option_groups')->onDelete('cascade');
            $table->string('name'); // ex: "Borda de Catupiry"
            $table->decimal('price', 8, 2)->default(0.00); // Acréscimo (pode ser 0.00 como "Sem cebola")
            $table->string('image_url')->nullable(); // Algumas opções podem ter foto ilustrativa
            $table->boolean('is_available')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('option_items');
        Schema::dropIfExists('option_groups');
        Schema::dropIfExists('products');
    }
};
