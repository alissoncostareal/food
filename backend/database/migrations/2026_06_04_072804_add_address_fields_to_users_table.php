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
        Schema::table('users', function (Blueprint $table) {
            $table->string('address')->nullable()->comment('Rua / Logradouro');
            $table->string('address_number')->nullable()->comment('Número');
            $table->string('district')->nullable()->comment('Bairro');
            $table->string('address_complement')->nullable()->comment('Complemento');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['address', 'address_number', 'district', 'address_complement']);
        });
    }
};
