<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_track_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('status', 40);
            $table->string('message', 255);
            $table->timestamp('occurred_at')->nullable();
            $table->string('provider', 20)->default('biteship');
            $table->timestamps();
            $table->index(['order_id', 'status']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('track_synced_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('track_synced_at');
        });
        Schema::dropIfExists('order_track_events');
    }
};
