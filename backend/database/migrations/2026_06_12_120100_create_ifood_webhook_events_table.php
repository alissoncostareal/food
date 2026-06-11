<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ifood_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id')->unique();
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 64)->nullable();
            $table->uuid('ifood_order_id')->nullable();
            $table->string('status', 32)->default('received');
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['ifood_order_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ifood_webhook_events');
    }
};
