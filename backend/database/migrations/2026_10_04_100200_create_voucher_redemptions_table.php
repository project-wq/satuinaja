<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Pemakaian voucher per order (Fase 14) — dasar idempotensi & kuota. */
    public function up(): void
    {
        Schema::create('voucher_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('voucher_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('buyer_phone', 32)->nullable();
            $table->unsignedBigInteger('discount')->default(0);
            $table->timestamps();

            $table->unique(['voucher_id', 'order_id']);
            $table->index('buyer_phone');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voucher_redemptions');
    }
};
