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
        Schema::table('products', function (Blueprint $table) {
        // Aumentando o tamanho da descrição
        $table->text('description')->nullable()->change();

        // Garantindo que o slug seja único para buscas rápidas e seguras
        $table->string('slug')->unique()->change();

        // Adicionando o preço promocional se quiser
        $table->decimal('sale_price', 8, 2)->nullable()->after('price');
    });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
       Schema::table('products', function (Blueprint $table) {

        });
    }
};
