<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kolom fee di order + diskon di produk.
     *
     * orders:
     *   subtotal          = total harga ASLI (price x qty)
     *   discount_total    = total potongan diskon produk
     *   subtotal_sale     = harga setelah diskon (basis fee)
     *   buyer_fee         = fee pembeli 11% (dibebankan ke pembeli)
     *   buyer_admin_fee   = admin fee Rp1000 x jumlah unit
     *   seller_net        = pendapatan seller ((harga-diskon-500) x qty)
     *   total             = subtotal_sale + buyer_fee + buyer_admin_fee + shipping
     *
     * order_items: rincian per baris (semua angka x qty kecuali unit_*)
     * products: discount_price = harga setelah diskon (null = tanpa diskon)
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->bigInteger('discount_total')->default(0)->after('subtotal');
            $table->bigInteger('subtotal_sale')->default(0)->after('discount_total');
            $table->bigInteger('buyer_fee')->default(0)->after('subtotal_sale');
            $table->bigInteger('buyer_admin_fee')->default(0)->after('buyer_fee');
            $table->bigInteger('seller_net')->default(0)->after('buyer_admin_fee');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->integer('discount_price')->nullable()->after('price'); // harga setelah diskon (per unit)
            $table->bigInteger('buyer_fee')->default(0)->after('line_total');
            $table->bigInteger('buyer_admin_fee')->default(0)->after('buyer_fee');
            $table->bigInteger('seller_net')->default(0)->after('buyer_admin_fee');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->integer('discount_price')->nullable()->after('price'); // null = tanpa diskon
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['discount_total', 'subtotal_sale', 'buyer_fee', 'buyer_admin_fee', 'seller_net']);
        });
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['discount_price', 'buyer_fee', 'buyer_admin_fee', 'seller_net']);
        });
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('discount_price');
        });
    }
};