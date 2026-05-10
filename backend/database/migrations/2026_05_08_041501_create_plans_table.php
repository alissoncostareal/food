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
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');          // Ex: 'Bronze', 'Prata', 'Ouro'
            $table->string('slug')->unique(); // Ex: 'plano-bronze'
            $table->decimal('price', 10, 2); // Valor da mensalidade
            $table->string('description')->nullable();

            // Exemplo de limites (se quiser cobrar por volume)
            $table->integer('max_products')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
