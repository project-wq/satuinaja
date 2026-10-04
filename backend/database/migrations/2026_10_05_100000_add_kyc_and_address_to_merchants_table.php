<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            // Alamat lengkap sesuai kebutuhan Biteship (origin pickup + cek ongkir).
            $table->string('province', 80)->nullable()->after('address');
            $table->string('city_name', 80)->nullable()->after('province');
            $table->string('district', 80)->nullable()->after('city_name');
            $table->string('postal_code', 10)->nullable()->after('district');
            $table->string('area_id', 64)->nullable()->after('postal_code');

            // KYC: seller baru pending sampai admin setujui.
            // Default 'approved' agar seller lama tetap aktif.
            $table->string('kyc_status', 16)->default('approved')->after('active');
            $table->string('kyc_nik', 20)->nullable()->after('kyc_status');
            $table->string('kyc_ktp_path')->nullable()->after('kyc_nik');
            $table->timestamp('kyc_submitted_at')->nullable()->after('kyc_ktp_path');
            $table->timestamp('kyc_reviewed_at')->nullable()->after('kyc_submitted_at');
            $table->string('kyc_reject_reason', 500)->nullable()->after('kyc_reviewed_at');
        });
    }

    public function down(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->dropColumn([
                'province', 'city_name', 'district', 'postal_code', 'area_id',
                'kyc_status', 'kyc_nik', 'kyc_ktp_path',
                'kyc_submitted_at', 'kyc_reviewed_at', 'kyc_reject_reason',
            ]);
        });
    }
};
