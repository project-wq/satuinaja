<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Order yang lahir dari webhook marketplace (Shopee/Tokopedia), bukan checkout storefront.
            $table->foreignId('channel_id')->nullable()->after('merchant_id')
                ->constrained('channels')->nullOnDelete();
            $table->string('source', 24)->default('storefront')->after('channel_id'); // storefront|shopee|tokopedia
            $table->string('external_order_id', 128)->nullable()->after('order_no');

            $table->unique(['channel_id', 'external_order_id'], 'orders_channel_external_unique');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique('orders_channel_external_unique');
            $table->dropConstrainedForeignId('channel_id');
            $table->dropColumn(['source', 'external_order_id']);
        });
    }
};
