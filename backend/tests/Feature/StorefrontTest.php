<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StorefrontTest extends TestCase
{
    use RefreshDatabase;

    private Merchant $merchant;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create(['role' => 'merchant']);
        $this->merchant = Merchant::create([
            'user_id' => $user->id, 'name' => 'Toko Publik', 'slug' => 'toko-publik', 'active' => true,
        ]);
    }

    public function test_public_storefront_lists_only_active_products(): void
    {
        Product::create([
            'merchant_id' => $this->merchant->id, 'title' => 'Tampil', 'slug' => 'tampil',
            'price' => 1000, 'stock' => 5, 'weight' => 100, 'status' => 'active',
        ]);
        Product::create([
            'merchant_id' => $this->merchant->id, 'title' => 'Draft', 'slug' => 'draft',
            'price' => 1000, 'stock' => 5, 'weight' => 100, 'status' => 'draft',
        ]);

        $res = $this->getJson('/api/v1/shops/toko-publik')->assertOk();

        $titles = collect($res->json('data.products.data'))->pluck('title');

        $this->assertTrue($titles->contains('Tampil'));
        $this->assertFalse($titles->contains('Draft'), 'Produk draft bocor ke storefront.');
    }

    public function test_unknown_shop_returns_404(): void
    {
        $this->getJson('/api/v1/shops/toko-hantu')->assertNotFound();
    }

    public function test_checkout_creates_order_and_decrements_stock(): void
    {
        $product = Product::create([
            'merchant_id' => $this->merchant->id, 'title' => 'Kaos', 'slug' => 'kaos',
            'price' => 50000, 'stock' => 10, 'weight' => 500, 'status' => 'active',
        ]);

        $res = $this->postJson('/api/v1/checkout', [
            'merchant_slug' => 'toko-publik',
            'buyer_name' => 'Budi',
            'buyer_phone' => '0812345678',
            'shipping_address' => 'Jl. Mawar 1',
            'destination_city_id' => '152', 'destination_postal_code' => '12950',
            'courier' => 'jne',
            'service' => 'REG',
            'shipping_cost' => 18000,
            'items' => [['product_id' => $product->id, 'qty' => 3]],
        ])->assertCreated();

        $this->assertSame(50000 * 3 + (int) round(150000 * 0.11) + 3 * 1000 + 18000, $res->json('data.total'));
        $this->assertSame(7, $product->fresh()->stock, 'Stok tidak berkurang.');
        $this->assertDatabaseHas('orders', ['payment_status' => 'unpaid']);
        $this->assertDatabaseCount('order_items', 1);
    }

    public function test_checkout_rejects_quantity_above_stock(): void
    {
        $product = Product::create([
            'merchant_id' => $this->merchant->id, 'title' => 'Kaos', 'slug' => 'kaos2',
            'price' => 50000, 'stock' => 2, 'weight' => 500, 'status' => 'active',
        ]);

        $this->postJson('/api/v1/checkout', [
            'merchant_slug' => 'toko-publik',
            'buyer_name' => 'Budi',
            'buyer_phone' => '0812345678',
            'shipping_address' => 'Jl. Mawar 1',
            'destination_city_id' => '152', 'destination_postal_code' => '12950',
            'courier' => 'jne',
            'service' => 'REG',
            'shipping_cost' => 18000,
            'items' => [['product_id' => $product->id, 'qty' => 99]],
        ])->assertStatus(422);

        $this->assertSame(2, $product->fresh()->stock, 'Stok berubah padahal checkout gagal.');
    }

    public function test_order_can_be_tracked_publicly(): void
    {
        $product = Product::create([
            'merchant_id' => $this->merchant->id, 'title' => 'Kaos', 'slug' => 'kaos3',
            'price' => 50000, 'stock' => 10, 'weight' => 500, 'status' => 'active',
        ]);

        $orderNo = $this->postJson('/api/v1/checkout', [
            'merchant_slug' => 'toko-publik',
            'buyer_name' => 'Budi',
            'buyer_phone' => '0812345678',
            'shipping_address' => 'Jl. Mawar 1',
            'destination_city_id' => '152', 'destination_postal_code' => '12950',
            'courier' => 'jne',
            'service' => 'REG',
            'shipping_cost' => 18000,
            'items' => [['product_id' => $product->id, 'qty' => 1]],
        ])->json('data.order_no');

        $this->getJson("/api/v1/orders/track/{$orderNo}")
            ->assertOk()
            ->assertJsonPath('data.order_no', $orderNo)
            ->assertJsonPath('data.payment_status', 'unpaid');
    }
}
