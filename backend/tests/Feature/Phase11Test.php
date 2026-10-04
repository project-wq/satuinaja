<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 11: varian produk (ukuran/warna) — stok & harga per varian.
 */
class Phase11Test extends TestCase
{
    use RefreshDatabase;

    private function shop(): array
    {
        $user = User::factory()->create(['role' => 'merchant']);
        $m = Merchant::create([
            'user_id' => $user->id, 'name' => 'Toko', 'slug' => 'toko-'.uniqid(), 'active' => true,
        ]);
        $p = Product::create([
            'merchant_id' => $m->id,
            'title' => 'Kaos Polos', 'slug' => 'kaos-'.uniqid(),
            'price' => 50000, 'stock' => 10, 'weight' => 200,
            'status' => 'active',
        ]);

        return [$user, $m, $p];
    }

    // ---------- CRUD varian ----------

    public function test_seller_syncs_variants_and_product_stock_becomes_aggregate(): void
    {
        [$user, , $p] = $this->shop();

        $r = $this->actingAs($user)->putJson("/api/v1/products/{$p->id}/variants", [
            'variants' => [
                ['name' => 'M', 'stock' => 4],
                ['name' => 'L', 'stock' => 6, 'price' => 55000],
                ['name' => 'XL', 'stock' => 2, 'sku' => 'KAOS-XL'],
            ],
        ]);

        $r->assertOk()->assertJsonCount(3, 'data');

        $p->refresh();
        $this->assertSame(12, $p->stock); // agregat, bukan input manual
        $this->assertSame(3, $p->variants()->count());
        $this->assertSame(55000, $p->variants()->where('name', 'L')->first()->price);
        $this->assertSame('KAOS-XL', $p->variants()->where('name', 'XL')->first()->sku);
    }

    public function test_seller_clears_variants_and_stock_back_to_zero(): void
    {
        [$user, , $p] = $this->shop();

        $this->actingAs($user)->putJson("/api/v1/products/{$p->id}/variants", [
            'variants' => [['name' => 'M', 'stock' => 5]],
        ])->assertOk();
        $this->assertSame(5, $p->fresh()->stock);

        // Kirim daftar kosong → hapus semua varian.
        $this->actingAs($user)->putJson("/api/v1/products/{$p->id}/variants", [
            'variants' => [],
        ])->assertOk();

        $p->refresh();
        $this->assertSame(0, $p->variants()->count());
        $this->assertSame(0, $p->stock);
    }

    public function test_seller_updates_single_variant_stock(): void
    {
        [$user, , $p] = $this->shop();
        $v = ProductVariant::create([
            'product_id' => $p->id, 'name' => 'M', 'stock' => 3, 'position' => 0,
        ]);
        $p->update(['stock' => 3]);

        $this->actingAs($user)
            ->putJson("/api/v1/products/{$p->id}/variants/{$v->id}", ['stock' => 9])
            ->assertOk()
            ->assertJsonPath('data.stock', 9);

        $this->assertSame(9, $p->fresh()->stock); // agregat ikut terjaga
    }

    public function test_other_merchant_cannot_touch_variants(): void
    {
        [, , $p] = $this->shop();
        $intruder = User::factory()->create(['role' => 'merchant']);
        Merchant::create([
            'user_id' => $intruder->id, 'name' => 'Lain', 'slug' => 'lain-'.uniqid(), 'active' => true,
        ]);

        $this->actingAs($intruder)
            ->putJson("/api/v1/products/{$p->id}/variants", ['variants' => []])
            ->assertNotFound(); // global scope merchant: produk tak terlihat
    }

    // ---------- Checkout dengan varian ----------

    public function test_checkout_with_variant_uses_variant_price_and_stocks(): void
    {
        [, $m, $p] = $this->shop();
        $v = ProductVariant::create([
            'product_id' => $p->id, 'name' => 'L', 'price' => 60000, 'stock' => 5, 'position' => 0,
        ]);
        $p->update(['stock' => 5]);

        $r = $this->postJson('/api/v1/checkout', [
            'merchant_slug' => $m->slug,
            'buyer_name' => 'Budi', 'buyer_phone' => '0812000000',
            'shipping_address' => 'Jl. Melati 1', 'destination_city_id' => '31', 'destination_postal_code' => '12950',
            'courier' => 'jne', 'service' => 'REG', 'shipping_cost' => 10000,
            'items' => [['product_id' => $p->id, 'variant_id' => $v->id, 'qty' => 2]],
        ]);

        $r->assertCreated();

        $this->assertSame(3, $v->fresh()->stock); // stok varian turun
        $this->assertSame(3, $p->fresh()->stock); // agregat ikut turun

        // Garis order memakai harga varian + suffix nama varian.
        $order = $m->orders()->latest('id')->first();
        $line = $order->items()->first();
        $this->assertSame(60000, $line->price);
        $this->assertStringContainsString('— L', $line->title);
        // Subtotal = 2 × 60000.
        $this->assertSame(120000, $order->subtotal);
    }

    public function test_checkout_variant_stock_insufficient_rejected(): void
    {
        [, $m, $p] = $this->shop();
        $v = ProductVariant::create([
            'product_id' => $p->id, 'name' => 'M', 'stock' => 1, 'position' => 0,
        ]);
        $p->update(['stock' => 10]); // agregat sengaja besar

        $this->postJson('/api/v1/checkout', [
            'merchant_slug' => $m->slug,
            'buyer_name' => 'Budi', 'buyer_phone' => '0812000000',
            'shipping_address' => 'Jl. Melati 1', 'destination_city_id' => '31', 'destination_postal_code' => '12950',
            'courier' => 'jne', 'service' => 'REG', 'shipping_cost' => 10000,
            'items' => [['product_id' => $p->id, 'variant_id' => $v->id, 'qty' => 3]],
        ])->assertStatus(422);

        $this->assertSame(1, $v->fresh()->stock); // tidak berubah
    }

    public function test_checkout_rejects_variant_of_another_product(): void
    {
        [, $m, $p] = $this->shop();
        $other = Product::create([
            'merchant_id' => $m->id, 'title' => 'Lain', 'slug' => 'lain-'.uniqid(),
            'price' => 1000, 'stock' => 5, 'weight' => 100, 'status' => 'active',
        ]);
        $v = ProductVariant::create([
            'product_id' => $other->id, 'name' => 'M', 'stock' => 5, 'position' => 0,
        ]);

        // variant_id valid (exists) tapi bukan milik produk tsb → 404 saat binding.
        $this->postJson('/api/v1/checkout', [
            'merchant_slug' => $m->slug,
            'buyer_name' => 'Budi', 'buyer_phone' => '0812000000',
            'shipping_address' => 'Jl. Melati 1', 'destination_city_id' => '31', 'destination_postal_code' => '12950',
            'courier' => 'jne', 'service' => 'REG', 'shipping_cost' => 0,
            'items' => [['product_id' => $p->id, 'variant_id' => $v->id, 'qty' => 1]],
        ])->assertNotFound();
    }

    // ---------- Preview ----------

    public function test_preview_uses_variant_price(): void
    {
        [, $m, $p] = $this->shop();
        $v = ProductVariant::create([
            'product_id' => $p->id, 'name' => 'XL', 'price' => 70000, 'stock' => 2, 'position' => 0,
        ]);

        $r = $this->postJson('/api/v1/checkout/preview', [
            'merchant_slug' => $m->slug,
            'items' => [['product_id' => $p->id, 'variant_id' => $v->id, 'qty' => 1]],
        ]);

        $r->assertOk();
        $data = $r->json('data');
        $this->assertSame(70000, $data['subtotal']);
        $this->assertStringContainsString('— XL', $data['items'][0]['title']);
    }

    // ---------- Storefront ----------

    public function test_storefront_product_exposes_variants(): void
    {
        [, $m, $p] = $this->shop();
        ProductVariant::create(['product_id' => $p->id, 'name' => 'S', 'stock' => 3, 'position' => 0]);

        $r = $this->getJson("/api/v1/shops/{$m->slug}/products/{$p->slug}");

        $r->assertOk()->assertJsonCount(1, 'data.product.variants');
        $r->assertJsonPath('data.product.variants.0.name', 'S');
    }

    public function test_seller_product_detail_exposes_variants(): void
    {
        [$user, , $p] = $this->shop();
        ProductVariant::create(['product_id' => $p->id, 'name' => 'S', 'stock' => 3, 'position' => 0]);

        $this->actingAs($user)
            ->getJson("/api/v1/products/{$p->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.variants');
    }
}
