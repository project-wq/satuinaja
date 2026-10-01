<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->string('order_no', 32)->unique();
            $table->string('buyer_name');
            $table->string('buyer_phone', 32);
            $table->string('buyer_email')->nullable();
            $table->text('shipping_address');
            $table->string('destination_city_id', 16)->nullable();
            $table->string('courier', 32)->nullable();
            $table->string('service', 64)->nullable();
            $table->unsignedBigInteger('shipping_cost')->default(0);
            $table->unsignedBigInteger('subtotal')->default(0);
            $table->unsignedBigInteger('total')->default(0);
            $table->string('tracking_no', 64)->nullable();
            $table->string('payment_status', 24)->default('unpaid'); // unpaid|paid|expired|refunded
            $table->string('payment_ref')->nullable();
            $table->string('payment_url', 512)->nullable();
            $table->string('fulfillment_status', 24)->default('pending'); // pending|shipped|delivered|cancelled
            $table->timestamps();

            $table->index(['merchant_id', 'payment_status']);
            $table->index(['merchant_id', 'fulfillment_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
