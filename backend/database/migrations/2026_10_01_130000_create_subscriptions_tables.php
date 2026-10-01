<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Langganan seller (plan) untuk monetisasi.
     *
     * Perlu dipikirkan: plan default Free (tanpa baris) → semua seller
     * memperoleh batas (produk, channel aktif, publish/bulan). Baris di
     * tabel ini = upgrade (Pro/Bisnis) dengan batas yang lebih tinggi.
     */
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();               // free | pro | bisnis
            $table->string('name');                          // "Gratis", "Pro", "Bisnis"
            $table->unsignedBigInteger('price_monthly')->default(0); // dalam Rupiah / bulan
            $table->unsignedInteger('max_products')->nullable();      // null = tak terbatas
            $table->unsignedInteger('max_channels')->nullable();
            $table->unsignedInteger('max_publishes_monthly')->nullable();
            $table->boolean('ai_caption')->default(false);
            $table->boolean('auto_publish')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->string('plan_code')->default('free');    // denormalisasi utk mudah query
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->string('payment_ref')->nullable();       // Midtrans order id langganan
            $table->string('status')->default('active');     // active | trialing | past_due | canceled
            $table->timestamps();

            $table->index(['merchant_id', 'status']);
        });

        Schema::table('merchants', function (Blueprint $table) {
            $table->string('plan_code')->default('free')->after('active');
            $table->unsignedInteger('publishes_this_month')->default(0)->after('plan_code');
            $table->timestamp('last_publish_reset_at')->nullable()->after('publishes_this_month');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('plans');

        Schema::table('merchants', function (Blueprint $table) {
            $table->dropColumn(['plan_code', 'publishes_this_month', 'last_publish_reset_at']);
        });
    }
};