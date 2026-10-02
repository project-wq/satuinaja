<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Notifikasi in-app untuk merchant (fase 6).
     */
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->string('type', 64);                   // order.new | payment.settled | order.shipped | plan.expiring | withdrawal.status | refund.requested
            $table->string('title', 160);
            $table->text('body')->nullable();
            $table->string('link', 255)->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['merchant_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};