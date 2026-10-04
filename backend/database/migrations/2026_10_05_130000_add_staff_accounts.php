<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fase 20: sub-akun seller (staf toko).
     * - staff: user bisa lihat/edit katalog + orders, TANPA saldo/withdraw/pengaturan.
     * - staff_permissions: daftar JSON key ('products','orders','vouchers','channels','reviews')
     *   default penuh minus keuangan.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('owner_merchant_id')->nullable()->after('role');
        });

        Schema::table('merchants', function (Blueprint $table) {
            $table->json('staff_permissions')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->dropColumn('staff_permissions');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('owner_merchant_id');
        });
    }
};
