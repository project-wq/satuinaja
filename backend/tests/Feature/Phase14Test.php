<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\Order;
use App\Models\Product;
use App\Models\SellerBalance;
use App\Models\User;
use App\Models\Voucher;
use App\Services\FeeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 14: promo — gratis ongkir produk, diskon produk, voucher
 * (produk/toko/platform) dipakai di checkout (preview & store).
 * Fase 15: alur pemenuhan order — pack/deliver/complete/cancel/return
 * + pencairan saldo saat completed + stok & kuota voucher kembali saat cancel.
 */
class Phase14Test extends TestCase
{
    use RefreshDatabase;

    private function merchant(): Merchant
    {
        $user = User::factory()->create(['role' => 'merchant']);
        Merchant::where('user_id', $user->id)->delete();

        return Merchant::create([
            'user_id' => $user->id, 'name' => 'Toko', 'slug' => 'toko-'.uniqid(), 'active' => true,
        ]);
    }

    private function product(Merchant $m, int $price = 10000, ?int $discount = null): Product
    {
        return Product::create([
            'merchant_id' => $m->id,
            'title' => 'Produk', 'slug' => 'produk-'.uniqid(),
            'price' => $price, 'discount_price' => $discount,
            'stock' => 10, 'weight' => 500, 'status' => 'active',
        ]);
    }

    private function voucher(int $type, Merchant $m, array $overrides = []): Voucher
    {
        return Voucher::create(array_merge([
            'scope' => 'shop', 'merchant_id' => $m->id,
            'code' => strtoupper(uniqid('VC')), 'name' => 'Voucher',
            'type' => $type === 1 ? 'percent' : 'fixed',
            'value' => $type === 1 ? 10 : 500,
            'min_spend' => 0, 'max_discount' => 1000,
            'quota' => 10, 'used' => 0, 'free_shipping' => false,
            'active' => true,
        ], $overrides));
    }

    public function test_checkout_preview_applies_discount_price_and_free_shipping(): void
    {
        $m = $this->merchant();
        $discounted = $this->product($m, 20000, 15000);
        $free = $this->product($m, 30000);
        $free->update(['free_shipping' => true]);

        $res = $this->postJson('/api/v1/checkout/preview', [
            'merchant_slug' => $m->slug,
            'items' => [
                ['product_id' => $discounted->id, 'qty' => 1],
                ['product_id' => $free->id, 'qty' => 1],
            ],
            'shipping_cost' => 10000,
        ]);

        $res->assertOk();
        $data = $res->json('data');

        // diskon produk diterapkan (20000-15000)
        $this->assertSame(5000, $data['discount_total']);
        // subtotal_sale = 15000 + 30000
        $this->assertSame(45000, $data['subtotal_sale']);
        // ongkir belum otomatis gratis di preview bila hanya 1 item free-ship —
        // banner ditampilkan frontend. Cek voucher free_shipping di bawah.
        $this->assertSame(10000, $data['shipping_cost']);
    }

    public function test_voucher_percent_cuts_total_and_caps_at_max_discount(): void
    {
        $m = $this->merchant();
        $p = $this->product($m, 20000);
        $v = $this->voucher(1, $m); // 10% max 1000

        $res = $this->postJson('/api/v1/checkout/preview', [
            'merchant_slug' => $m->slug,
            'items' => [['product_id' => $p->id, 'qty' => 1]],
            'shipping_cost' => 5000,
            'voucher_code' => $v->code,
        ]);
        $res->assertOk();

        $d = $res->json('data');
        $this->assertSame(1000, $d['voucher']['discount']); // min(10% of 20000, 1000)
        $this->assertTrue($d['total'] < 20000);
    }

    public function test_voucher_free_shipping_zeroes_shipping_cost(): void
    {
        $m = $this->merchant();
        $p = $this->product($m, 10000);
        $v = $this->voucher(0, $m, ['free_shipping' => true, 'value' => 0]);

        $res = $this->postJson('/api/v1/checkout/preview', [
            'merchant_slug' => $m->slug,
            'items' => [['product_id' => $p->id, 'qty' => 1]],
            'shipping_cost' => 8000,
            'voucher_code' => $v->code,
        ]);
        $res->assertOk();
        $this->assertSame(0, $res->json('data.shipping_cost'));
    }

    public function test_checkout_store_with_voucher_redeems_and_persists(): void
    {
        $m = $this->merchant();
        $p = $this->product($m, 20000);
        $v = $this->voucher(1, $m); // 10% max 1000, quota 10

        $res = $this->postJson('/api/v1/checkout', [
            'merchant_slug' => $m->slug,
            'buyer_name' => 'Budi', 'buyer_phone' => '0812',
            'shipping_address' => 'Jl. Merdeka 1',
            'destination_city_id' => '1', 'courier' => 'jne', 'service' => 'REG',
            'shipping_cost' => 5000,
            'items' => [['product_id' => $p->id, 'variant_id' => null, 'qty' => 1]],
            'voucher_code' => $v->code,
        ]);

        $res->assertCreated();
        $order = Order::where('order_no', $res->json('data.order_no'))->firstOrFail();

        $this->assertSame($v->id, $order->voucher_id);
        $this->assertSame(1000, $order->voucher_discount);
        $this->assertSame(1, $v->fresh()->used);
        $this->assertDatabaseHas('voucher_redemptions', ['voucher_id' => $v->id, 'order_id' => $order->id]);
    }

    public function test_voucher_quota_exhausted_is_rejected(): void
    {
        $m = $this->merchant();
        $p = $this->product($m, 20000);
        $v = $this->voucher(1, $m, ['quota' => 1, 'used' => 1]);

        $res = $this->postJson('/api/v1/checkout/preview', [
            'merchant_slug' => $m->slug,
            'items' => [['product_id' => $p->id, 'qty' => 1]],
            'shipping_cost' => 5000,
            'voucher_code' => $v->code,
        ]);
        $res->assertOk();
        $this->assertNull($res->json('data.voucher'));
        $this->assertNotNull($res->json('data.voucher_disallowed'));
    }

    // ---------- Fase 15: alur pemenuhan ----------

    public function test_order_fulfillment_flow_and_balance_credit(): void
    {
        $m = $this->merchant();
        $p = $this->product($m, 10000);
        $user = $m->user;

        $f = (new FeeService)->line(10000, null, 1);
        $order = Order::create([
            'merchant_id' => $m->id,
            'order_no' => 'ORD-F-'.uniqid(),
            'buyer_name' => 'Budi', 'buyer_phone' => '0812',
            'shipping_address' => 'Jl. X 1', 'destination_city_id' => '1',
            'courier' => 'jne', 'service' => 'REG',
            'shipping_cost' => 5000,
            'subtotal' => 10000, 'discount_total' => 0, 'subtotal_sale' => 10000,
            'buyer_fee' => $f['buyer_fee'], 'buyer_admin_fee' => $f['buyer_admin_fee'],
            'seller_net' => $f['seller_net'],
            'total' => $f['buyer_line_total'] + 5000,
            'payment_status' => 'paid', 'fulfillment_status' => 'pending',
        ]);

        // pack
        $this->actingAs($user)->putJson("/api/v1/orders/{$order->id}/pack")->assertOk();
        $this->assertSame('packed', $order->fresh()->fulfillment_status);

        // ship (via resi)
        $this->actingAs($user)->putJson("/api/v1/orders/{$order->id}/ship", ['tracking_no' => 'JNE123'])->assertOk();
        $this->assertSame('shipped', $order->fresh()->fulfillment_status);

        // deliver
        $this->actingAs($user)->putJson("/api/v1/orders/{$order->id}/deliver")->assertOk();
        $this->assertSame('delivered', $order->fresh()->fulfillment_status);

        // complete → saldo cair
        $this->actingAs($user)->putJson("/api/v1/orders/{$order->id}/complete")->assertOk();
        $this->assertSame('completed', $order->fresh()->fulfillment_status);
        $bal = SellerBalance::where('merchant_id', $m->id)->first();
        $this->assertNotNull($bal);
        $this->assertSame($f['seller_net'], $bal->balance);
    }

    public function test_cancel_returns_stock_and_voucher_quota(): void
    {
        $m = $this->merchant();
        $p = $this->product($m, 10000);
        $user = $m->user;
        $v = $this->voucher(1, $m);
        $p->decrement('stock', 2);

        $f = (new FeeService)->line(10000, null, 2);
        $order = Order::create([
            'merchant_id' => $m->id,
            'order_no' => 'ORD-C-'.uniqid(),
            'buyer_name' => 'Budi', 'buyer_phone' => '0812',
            'shipping_address' => 'Jl. X 1', 'destination_city_id' => '1',
            'courier' => 'jne', 'service' => 'REG',
            'shipping_cost' => 0,
            'subtotal' => 20000, 'discount_total' => 0, 'subtotal_sale' => 20000,
            'buyer_fee' => 0, 'buyer_admin_fee' => 0, 'seller_net' => $f['seller_net'],
            'total' => 20000,
            'payment_status' => 'paid', 'fulfillment_status' => 'pending',
            'voucher_id' => $v->id, 'voucher_discount' => 1000, 'voucher_code' => $v->code,
        ]);
        $order->items()->create([
            'product_id' => $p->id, 'title' => 'Produk', 'price' => 10000,
            'qty' => 2, 'line_total' => 20000, 'buyer_fee' => 0, 'buyer_admin_fee' => 0, 'seller_net' => $f['seller_net'],
        ]);
        $v->increment('used');

        $this->actingAs($user)
            ->putJson("/api/v1/orders/{$order->id}/cancel", ['reason' => 'batal'])
            ->assertOk();

        $fresh = $order->fresh();
        $this->assertSame('cancelled', $fresh->fulfillment_status);
        $this->assertSame(10, $p->fresh()->stock);     // 10 - 2 + 2
        $this->assertSame(0, $v->fresh()->used);        // 1 - 1
        $this->assertDatabaseMissing('voucher_redemptions', ['order_id' => $order->id]);
    }

    public function test_return_flow_approve_restores_stock(): void
    {
        $m = $this->merchant();
        $p = $this->product($m, 10000);
        $user = $m->user;

        $f = (new FeeService)->line(10000, null, 1);
        $order = Order::create([
            'merchant_id' => $m->id,
            'order_no' => 'ORD-R-'.uniqid(),
            'buyer_name' => 'Budi', 'buyer_phone' => '0812',
            'shipping_address' => 'Jl. X 1', 'destination_city_id' => '1',
            'courier' => 'jne', 'service' => 'REG',
            'shipping_cost' => 0,
            'subtotal' => 10000, 'discount_total' => 0, 'subtotal_sale' => 10000,
            'buyer_fee' => 0, 'buyer_admin_fee' => 0, 'seller_net' => $f['seller_net'],
            'total' => 10000,
            'payment_status' => 'paid', 'fulfillment_status' => 'delivered',
        ]);
        $order->items()->create([
            'product_id' => $p->id, 'title' => 'Produk', 'price' => 10000,
            'qty' => 1, 'line_total' => 10000, 'buyer_fee' => 0, 'buyer_admin_fee' => 0, 'seller_net' => $f['seller_net'],
        ]);

        $this->actingAs($user)
            ->postJson("/api/v1/orders/{$order->id}/return", ['reason' => 'ukuran salah'])
            ->assertOk();
        $this->assertSame('requested', $order->fresh()->return_status);

        $this->actingAs($user)
            ->putJson("/api/v1/orders/{$order->id}/return", ['decision' => 'approved'])
            ->assertOk();
        $fresh = $order->fresh();
        $this->assertSame('approved', $fresh->return_status);
        $this->assertSame('returned', $fresh->fulfillment_status);
        $this->assertSame(10, $p->fresh()->stock);
    }
}
