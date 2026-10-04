<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Fase 23: threshold restock per produk + flag auto-hold. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedInteger('low_stock_at')->default(5)->after('stock');
            $table->boolean('low_stock_alerted')->default(false)->after('low_stock_at');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['low_stock_at', 'low_stock_alerted']);
        });
    }
};
