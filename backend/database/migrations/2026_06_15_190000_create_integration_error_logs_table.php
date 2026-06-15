<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_error_logs', function (Blueprint $table) {
            $table->id();
            $table->string('error_ref', 12)->unique();
            $table->string('channel', 32);
            $table->string('action', 64);
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->string('public_message');
            $table->text('details');
            $table->json('context')->nullable();
            $table->timestamps();

            $table->index(['channel', 'created_at']);
            $table->index(['store_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_error_logs');
    }
};
