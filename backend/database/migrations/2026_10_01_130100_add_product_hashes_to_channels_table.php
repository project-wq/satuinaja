<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kolom sinkronisasi stok: hash produk (untuk deteksi perubahan) dan
     * metadata stok yang dikirim ke channel (untuk update tanpa publish ulang).
     * Diupdate lewat job/stok saat produk berubah.
     */
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->json('product_hashes')->nullable()->after('credentials');
        });
    }

    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->dropColumn('product_hashes');
        });
    }
};