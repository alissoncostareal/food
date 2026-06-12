<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->boolean('whatsapp_bot_enabled')->default(true)->after('whatsapp_order_messages');
            $table->boolean('whatsapp_ai_enabled')->default(true)->after('whatsapp_bot_enabled');
            $table->text('whatsapp_bot_welcome')->nullable()->after('whatsapp_ai_enabled');
            $table->text('whatsapp_ai_faq')->nullable()->after('whatsapp_bot_welcome');
        });

        Schema::create('whatsapp_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('customer_phone', 20);
            $table->string('state', 32)->default('idle');
            $table->json('context')->nullable();
            $table->timestamp('human_mode_until')->nullable();
            $table->timestamp('last_inbound_at')->nullable();
            $table->timestamp('last_outbound_at')->nullable();
            $table->timestamps();

            $table->unique(['store_id', 'customer_phone']);
            $table->index(['store_id', 'updated_at']);
        });

        Schema::create('whatsapp_conversation_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('whatsapp_session_id')->constrained()->cascadeOnDelete();
            $table->string('direction', 16);
            $table->string('source', 16)->default('bot');
            $table->text('body');
            $table->timestamps();

            $table->index(['whatsapp_session_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_conversation_messages');
        Schema::dropIfExists('whatsapp_sessions');

        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn([
                'whatsapp_bot_enabled',
                'whatsapp_ai_enabled',
                'whatsapp_bot_welcome',
                'whatsapp_ai_faq',
            ]);
        });
    }
};
