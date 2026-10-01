<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('publish_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->string('status', 24)->default('pending'); // pending|success|failed
            $table->string('external_id')->nullable();
            $table->string('external_url', 512)->nullable();
            $table->string('error', 1024)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'channel_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('publish_logs');
    }
};
