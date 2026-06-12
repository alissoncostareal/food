<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $defaultMethods = ['pix', 'cash', 'debit_card', 'credit_card'];

    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->json('accepted_payment_methods')
                ->nullable()
                ->after('delivery_fee');
        });

        DB::table('stores')->update([
            'accepted_payment_methods' => json_encode($this->defaultMethods),
        ]);
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn('accepted_payment_methods');
        });
    }
};
