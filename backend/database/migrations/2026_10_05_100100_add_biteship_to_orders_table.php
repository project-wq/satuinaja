<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Cek ongkir / create order Biteship butuh pos tujuan.
            $table->string('destination_postal_code', 10)->nullable()->after('destination_city_id');
            // Jejak order Biteship: dipakai untuk lacak (GET /v1/trackings/:id).
            $table->string('biteship_order_id', 64)->nullable()->after('tracking_no');
            $table->string('routing_code', 32)->nullable()->after('biteship_order_id');
            $table->unsignedBigInteger('biteship_price')->nullable()->after('shipping_cost');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'destination_postal_code', 'biteship_order_id', 'routing_code', 'biteship_price',
            ]);
        });
    }
};
