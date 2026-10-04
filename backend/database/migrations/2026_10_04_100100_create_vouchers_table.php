<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Voucher (Fase 14): dipakai seller maupun admin platform.
     *  scope = product  → voucher khusus 1 produk (product_id wajib)
     *  scope = shop     → voucher toko (merchant_id wajib, produk bebas)
     *  scope = platform → voucher platform (dibuat admin, merchant_id null)
     *  type  = percent  → value = persen (1-100), max_discount = cap
     *  type  = fixed    → value = rupiah potongan langsung
     */
    public function up(): void
    {
        Schema::create('vouchers', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 16);                        // product | shop | platform
            $table->foreignId('merchant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('name');
            $table->string('type', 8)->default('percent');      // percent | fixed
            $table->unsignedInteger('value');
            $table->unsignedBigInteger('min_spend')->default(0);
            $table->unsignedBigInteger('max_discount')->nullable();
            $table->unsignedInteger('quota')->nullable();       // null = tak terbatas
            $table->unsignedInteger('used')->default(0);
            $table->unsignedInteger('max_per_buyer')->default(1);
            $table->boolean('free_shipping')->default(false);   // voucher gratis ongkir
            $table->boolean('active')->default(true);
            $table->timestamp('start_at')->nullable();
            $table->timestamp('end_at')->nullable();
            $table->timestamps();

            $table->unique(['scope', 'code']);
            $table->index(['scope', 'active', 'start_at', 'end_at']);
            $table->index('merchant_id');
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vouchers');
    }
};
