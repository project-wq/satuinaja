<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fase 5: sistem fee marketplace + saldo seller + withdraw.
     *
     * Rincian fee (per unit):
     *   Buyer bayar = (harga - diskon) + fee 11% + admin fee Rp1000
     *   Seller dapat = (harga - diskon) - potongan seller Rp500  -> masuk saldo
     *
     * Tabel:
     *   settings            — konfigurasi fee (admin bisa ubah dari panel)
     *   seller_balances     — saldo terkini per seller (denormalisasi dari ledger)
     *   balance_transactions— ledger mutasi saldo (source of truth)
     *   withdrawals         — permintaan penarikan saldo
     */
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();
            $table->text('value');
            $table->timestamps();
        });

        Schema::create('seller_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->unique()->constrained()->cascadeOnDelete();
            $table->bigInteger('balance')->default(0); // rupiah, bisa 0
            $table->bigInteger('held')->default(0);    // ditahan saat withdraw pending
            $table->timestamps();
        });

        Schema::create('withdrawals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('amount');
            $table->string('bank_name', 64);
            $table->string('bank_account_no', 64);
            $table->string('bank_account_holder', 128);
            $table->string('status', 24)->default('pending'); // pending|approved|rejected
            $table->text('admin_note')->nullable();
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        Schema::create('balance_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);   // sale|withdraw_hold|withdraw_paid|withdraw_refund|adjustment
            $table->bigInteger('amount'); // +/- rupiah terhadap balance
            $table->bigInteger('balance_after');
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('withdrawal_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index(['merchant_id', 'created_at']);
        });

        // Fee config default.
        $now = now();
        foreach ([
            ['fee_buyer_percent', '11'],
            ['admin_fee_per_item', '1000'],
            ['seller_fee_per_item', '500'],
        ] as [$k, $v]) {
            \DB::table('settings')->insertOrIgnore([
                'key' => $k, 'value' => $v, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('withdrawals');
        Schema::dropIfExists('balance_transactions');
        Schema::dropIfExists('seller_balances');
        Schema::dropIfExists('settings');
    }
};