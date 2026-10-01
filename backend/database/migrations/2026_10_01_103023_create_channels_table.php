<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 32); // facebook|instagram|tiktok|shopee|tokopedia
            $table->string('label')->nullable();
            // credentials disimpan terenkripsi (Laravel Crypt), cast: encrypted:array
            $table->text('credentials')->nullable();
            $table->boolean('active')->default(false);
            $table->timestamp('last_sync_at')->nullable();
            $table->string('last_error')->nullable();
            $table->timestamps();

            $table->unique(['merchant_id', 'platform']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channels');
    }
};
