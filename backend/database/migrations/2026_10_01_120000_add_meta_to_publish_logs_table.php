<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Menyimpan payload siap-pakai / caption dari publisher yang gagal
     * karena butuh aksi tambahan (TikTok wajib video, Tokopedia butuh kemitraan).
     */
    public function up(): void
    {
        Schema::table('publish_logs', function (Blueprint $table) {
            $table->json('meta')->nullable()->after('error');
        });
    }

    public function down(): void
    {
        Schema::table('publish_logs', function (Blueprint $table) {
            $table->dropColumn('meta');
        });
    }
};
