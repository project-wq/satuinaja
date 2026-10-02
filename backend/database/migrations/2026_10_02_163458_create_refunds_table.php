<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Refund / pengembalian dana order (fase 6).
     * status: pending | approved | rejected
     */
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('amount');                    // keluar dari saldo seller (seller_net order)
            $table->string('reason', 255)->nullable();
            $table->string('status', 20)->default('pending');        // pending | approved | rejected
            $table->text('note')->nullable();                        // catatan admin/penolakan
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['merchant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
