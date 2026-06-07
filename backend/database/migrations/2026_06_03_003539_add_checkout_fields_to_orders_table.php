<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->enum('fulfillment_type', ['delivery', 'pickup'])
                ->default('delivery')
                ->after('type');

            $table->timestamp('scheduled_at')
                ->nullable()
                ->after('fulfillment_type');

            $table->string('customer_name')
                ->nullable()
                ->after('scheduled_at');

            $table->string('customer_phone')
                ->nullable()
                ->after('customer_name');

            $table->string('address_number')
                ->nullable()
                ->after('address');

            $table->string('address_complement')
                ->nullable()
                ->after('address_number');

            $table->string('district')
                ->nullable()
                ->after('address_complement');

            $table->decimal('latitude', 10, 7)
                ->nullable()
                ->after('district');

            $table->decimal('longitude', 10, 7)
                ->nullable()
                ->after('latitude');

            $table->decimal('change_for', 10, 2)
                ->nullable()
                ->after('payment_method');

            $table->string('whatsapp_url', 2048)
                ->nullable()
                ->after('change_for');

            $table->timestamp('sent_to_whatsapp_at')
                ->nullable()
                ->after('whatsapp_url');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'fulfillment_type',
                'scheduled_at',
                'customer_name',
                'customer_phone',
                'address_number',
                'address_complement',
                'district',
                'latitude',
                'longitude',
                'change_for',
                'whatsapp_url',
                'sent_to_whatsapp_at',
            ]);
        });
    }
};
