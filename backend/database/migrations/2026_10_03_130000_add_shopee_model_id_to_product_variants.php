<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 12: pemetaan varian lokal -> model Shopee.
 *
 * Setelah publish + init_tier_variation sukses, Shopee mengembalikan
 * model_id per tier_index; kita simpan agar update_stock/update_price
 * per-varian dan pull (get_model_list) bisa dipetakan balik.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->string('shopee_model_id')->nullable()->after('sku');
        });
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn('shopee_model_id');
        });
    }
};
