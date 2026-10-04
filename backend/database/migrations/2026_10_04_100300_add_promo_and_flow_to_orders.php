<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fase 14/15: voucher di order + alur pemenuhan lengkap
     * (pending → packed → shipped → delivered → completed, plus cancel/return).
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Promo (Fase 14).
            $table->foreignId('voucher_id')->nullable()->after('discount_total')->constrained('vouchers')->nullOnDelete();
            $table->unsignedBigInteger('voucher_discount')->default(0)->after('voucher_id');
            $table->string('voucher_code', 40)->nullable()->after('voucher_discount');
            $table->unsignedBigInteger('shipping_discount')->default(0)->after('voucher_code'); // gratis ongkir

            // Alur pemenuhan (Fase 15).
            $table->timestamp('packed_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancelled_by', 16)->nullable();   // buyer | seller | admin
            $table->string('cancel_reason')->nullable();
            $table->string('return_status', 16)->nullable();  // requested | approved | rejected | received | refunded
            $table->timestamp('return_requested_at')->nullable();
            $table->string('return_reason')->nullable();
            $table->text('seller_note')->nullable();
            $table->string('courier_tracking_url')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('voucher_id');
            $table->dropColumn([
                'voucher_discount', 'voucher_code', 'shipping_discount',
                'packed_at', 'shipped_at', 'delivered_at', 'completed_at',
                'cancelled_at', 'cancelled_by', 'cancel_reason',
                'return_status', 'return_requested_at', 'return_reason',
                'seller_note', 'courier_tracking_url',
            ]);
        });
    }
};
