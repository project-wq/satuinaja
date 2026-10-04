<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Promosi tingkat produk (Fase 14).
            $table->boolean('free_shipping')->default(false)->after('discount_price');
            $table->boolean('voucher_enabled')->default(true)->after('free_shipping');
            $table->unsignedBigInteger('sold_count')->default(0)->after('voucher_enabled');
            $table->unsignedTinyInteger('rating_avg')->nullable()->after('sold_count'); // 0-50 (bintang*10)
            $table->unsignedInteger('rating_count')->default(0)->after('rating_avg');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['free_shipping', 'voucher_enabled', 'sold_count', 'rating_avg', 'rating_count']);
        });
    }
};
