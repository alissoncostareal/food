<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('food99_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id')->nullable()->index();
            $table->string('event_type', 80)->nullable()->index();
            $table->string('shop_id', 120)->nullable()->index();
            $table->string('order_id', 120)->nullable()->index();
            $table->string('status', 32)->default('received');
            $table->json('payload')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('food99_webhook_events');
    }
};
