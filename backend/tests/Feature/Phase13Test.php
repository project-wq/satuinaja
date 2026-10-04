<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 13: order marketplace bervarian — webhook Shopee membawa
 * model_id/model_sku → dicocokkan ke varian lokal (order_items.variant_id,
 * harga & fee per varian, potong stok varian + agregat produk).
 */
class Phase13Test extends TestCase
{
    use RefreshDatabase;

    /** POST raw JSON + HMAC signature (pola Phase7Test). */
    private function postSigned(string $url, array $payload, string $secret, ?string $signature = null): \Illuminate\Testing\TestResponse
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $sig = $signature ?? hash_hmac('sha256', $body, $secret);

        return $this->call(
            'POST', $url, [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_MARKETPLACE_SIGNATURE' => $sig],
            $body,
        );
    }

    /** Produk + varian M (stok 4) & L (harga 55000, stok 6) + channel Shopee. */
    private function shop(): array
    {
        $user = User::factory()->create(['role' => 'merchant']);
        $m = Merchant::create([
            'user_id' => $user->id, 'name' => 'Toko', 'slug' => 'toko-'.uniqid(), 'active' => true,
        ]);
        $p = Product::create([
            'merchant_id' => $m->id,
            'title' => 'Kaos', 'slug' => 'kaos-'.uniqid(),
            'price' => 50000, 'stock' => 10, 'weight' => 200,
            'status' => 'active',
        ]);
        ProductVariant::create(['product_id' => $p->id, 'name' => 'M', 'sku' => 'KAOS-M', 'stock' => 4, 'position' => 0]);
        ProductVariant::create(['product_id' => $p->id, 'name' => 'L', 'sku' => 'KAOS-L', 'price' => 55000, 'stock' => 6, 'position' => 1]);
        $p->update(['stock' => 10]);

        $c = Channel::create([
            'merchant_id' => $m->id, 'platform' => 'shopee', 'label' => 'Shopee', 'active' => true,
            'credentials' => [
                'partner_id' => '10001', 'partner_key' => 'rahasia-partner',
                'shop_id' => '20002', 'access_token' => 'tok', 'sandbox' => '1',
            ],
        ]);

        return [$m, $p, $c];
    }

    // ---------- 13.1 webhook Shopee dengan model varian ----------

    public function test_shopee_webhook_resolves_variant_by_model_sku(): void
    {
        [, $p, $c] = $this->shop();
        $variantL = $p->variants()->where('name', 'L')->firstOrFail();

        $res = $this->postSigned("/api/v1/webhooks/shopee/{$c->id}", [
            'ordersn' => 'SP-VAR-1',
            'buyer_username' => 'Budi',
            'item_list' => [
                ['item_sku' => $p->slug, 'model_sku' => 'KAOS-L', 'model_id' => 9002, 'model_quantity_purchased' => 2],
            ],
        ], 'rahasia-partner');

        $res->assertCreated();

        $order = Order::withoutGlobalScope('merchant')->firstOrFail();
        $item = $order->items()->first();

        // Baris order terpetakan ke varian L, harga varian (bukan harga produk).
        $this->assertSame($variantL->id, $item->variant_id);
        $this->assertSame(55000, $item->price);
        $this->assertNull($item->discount_price);
        $this->assertStringContainsString(' — L', $item->title);
        // Fee dihitung dari harga varian: 110000 + 11% + 1000×2.
        $this->assertSame(110000, $item->line_total);
        $this->assertSame(12100, $item->buyer_fee);
        $this->assertSame(2000, $item->buyer_admin_fee);
        $this->assertSame(110000 - 1000, $item->seller_net);
        $this->assertSame(110000, $order->subtotal);

        // Stok terpotong di varian L + agregat produk.
        $this->assertSame(4, $variantL->fresh()->stock);
        $this->assertSame(4, $p->variants()->where('name', 'M')->firstOrFail()->fresh()->stock);
        $this->assertSame(8, $p->fresh()->stock);
    }

    public function test_shopee_webhook_resolves_variant_by_model_id_only(): void
    {
        [, $p, $c] = $this->shop();
        $p->variants()->where('name', 'M')->update(['shopee_model_id' => '9001']);

        $res = $this->postSigned("/api/v1/webhooks/shopee/{$c->id}", [
            'ordersn' => 'SP-VAR-2',
            'buyer_username' => 'Ani',
            'item_list' => [
                // Tanpa sku sama sekali — hanya model_id Shopee.
                ['model_id' => 9001, 'quantity' => 1],
            ],
        ], 'rahasia-partner');

        $res->assertCreated();

        $item = Order::withoutGlobalScope('merchant')->firstOrFail()->items()->first();
        $variantM = $p->variants()->where('name', 'M')->firstOrFail();

        $this->assertSame($variantM->id, $item->variant_id);
        // Varian M tanpa harga sendiri → harga produk (50000).
        $this->assertSame(50000, $item->price);
        $this->assertSame(3, $variantM->fresh()->stock);
        $this->assertSame(9, $p->fresh()->stock);
    }

    public function test_shopee_webhook_rejects_when_no_variant_matches(): void
    {
        [, $p, $c] = $this->shop();

        $this->postSigned("/api/v1/webhooks/shopee/{$c->id}", [
            'ordersn' => 'SP-VAR-X',
            'buyer_username' => 'Budi',
            'item_list' => [
                // Model tak dikenal & sku tak cocok apa-apa.
                ['model_sku' => 'TIDAK-ADA', 'model_id' => 9999, 'quantity' => 1],
            ],
        ], 'rahasia-partner')->assertStatus(422);

        $this->assertSame(0, Order::withoutGlobalScope('merchant')->count());
        // Stok tidak berubah.
        $this->assertSame(10, $p->fresh()->stock);
    }

    public function test_idempotency_still_holds_for_variant_orders(): void
    {
        [, $p, $c] = $this->shop();

        $payload = [
            'ordersn' => 'SP-VAR-DUP',
            'buyer_username' => 'Budi',
            'item_list' => [['model_sku' => 'KAOS-L', 'quantity' => 1]],
        ];

        $this->postSigned("/api/v1/webhooks/shopee/{$c->id}", $payload, 'rahasia-partner')->assertCreated();
        $this->postSigned("/api/v1/webhooks/shopee/{$c->id}", $payload, 'rahasia-partner')->assertOk();

        $this->assertSame(1, Order::withoutGlobalScope('merchant')->count());
        // Stok varian hanya berkurang sekali (6 → 5).
        $this->assertSame(5, $p->variants()->where('name', 'L')->firstOrFail()->fresh()->stock);
    }

    // ---------- 13.2 produk tanpa varian tetap jalan (backcompat) ----------

    public function test_non_variant_product_still_ingests_without_variant_id(): void
    {
        $user = User::factory()->create(['role' => 'merchant']);
        $m = Merchant::create(['user_id' => $user->id, 'name' => 'Toko2', 'slug' => 'toko2-'.uniqid(), 'active' => true]);
        $p = Product::create([
            'merchant_id' => $m->id, 'title' => 'Single', 'slug' => 'single-'.uniqid(),
            'price' => 20000, 'stock' => 5, 'weight' => 100, 'status' => 'active',
        ]);
        $c = Channel::create([
            'merchant_id' => $m->id, 'platform' => 'shopee', 'label' => 'Shopee', 'active' => true,
            'credentials' => [
                'partner_id' => '10001', 'partner_key' => 'rahasia-partner',
                'shop_id' => '20002', 'access_token' => 'tok', 'sandbox' => '1',
            ],
        ]);

        $this->postSigned("/api/v1/webhooks/shopee/{$c->id}", [
            'ordersn' => 'SP-SINGLE-1',
            'buyer_username' => 'Budi',
            'item_list' => [['item_sku' => $p->slug, 'quantity' => 2]],
        ], 'rahasia-partner')->assertCreated();

        $item = Order::withoutGlobalScope('merchant')->firstOrFail()->items()->first();

        $this->assertNull($item->variant_id);
        $this->assertSame(20000, $item->price);
        $this->assertSame(3, $p->fresh()->stock);
    }
}
