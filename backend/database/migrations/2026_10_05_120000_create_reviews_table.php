<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->tinyInteger('rating')->unsigned()->default(5);
            $table->text('comment')->nullable();
            $table->string('reviewer_name', 120);
            $table->text('seller_reply')->nullable();
            $table->timestamp('replied_at')->nullable();
            $table->boolean('visible')->default(true);
            $table->timestamps();
            $table->unique(['order_id', 'product_id'], 'reviews_order_product_unique');
            $table->index(['merchant_id', 'visible']);
        });

        Schema::table('merchants', function (Blueprint $table) {
            $table->decimal('rating_avg', 3, 2)->default(0);
            $table->unsignedInteger('rating_count')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->dropColumn(['rating_avg', 'rating_count']);
        });
        Schema::dropIfExists('reviews');
    }
};
