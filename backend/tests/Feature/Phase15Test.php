<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\Voucher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 15: alur pemenuhan order seller/admin —
 * pack → ship → deliver → complete (cair saldo), cancel (kembalikan stok +
 * kuota voucher), return (approve kembalikan stok / reject), admin index.
 */
class Phase15Test extends TestCase
{
    use RefreshDatabase;

    private function shop(): array
    {
        $user = User::factory()->create(['role' => 'merchant']);
        $m = Merchant::create([
            'user_id' => $user->id, 'name' => 'Toko', 'slug' => 'toko-'.uniqid(), 'active' => true,
        ]);
        $p = Product::create([
            'merchant_id' => $m->id, 'title' => 'Kaos', 'slug' => 'kaos-'.uniqid(),
            'price' => 100000, 'stock' => 10, 'weight' => 200, 'status' => 'active',
        ]);

        return [$user, $m, $p];
    }

    private function orderWithItems(Merchant $m, Product $p, int $qty = 1): Order
    {
        $f = app(\App\Services\FeeService::class)->line($p->price, $p->discount_price, $qty);
        $p->decrement('stock', $qty);

        $order = Order::create([
            'merchant_id' => $m->id,
            'order_no' => 'ORD-'.uniqid(),
            'buyer_name' => 'Budi', 'buyer_phone' => '0812000001',
            'shipping_address' => 'Jl. Melati 1', 'destination_city_id' => '3171',
            'courier' => 'jne', 'service' => 'REG', 'shipping_cost' => 20000,
            'subtotal' => $p->price * $qty,
            'discount_total' => $f['discount'],
            'subtotal_sale' => $f['base_sale'],
            'buyer_fee' => $f['buyer_fee'],
            'buyer_admin_fee' => $f['buyer_admin_fee'],
            'seller_net' => $f['seller_net'],
            'total' => $f['base_sale'] + $f['buyer_fee'] + $f['buyer_admin_fee'] + 20000,
            'payment_status' => 'paid',
            'fulfillment_status' => 'pending',
        ]);
        $order->items()->create([
            'product_id' => $p->id, 'title' => $p->title,
            'price' => $p->price, 'qty' => $qty, 'line_total' => $f['base_sale'],
            'buyer_fee' => $f['buyer_fee'], 'buyer_admin_fee' => $f['buyer_admin_fee'],
            'seller_net' => $f['seller_net'],
        ]);

        return $order;
    }

    public function test_full_flow_pack_ship_deliver_complete_credits_balance(): void
    {
        [$user, $m, $p] = $this->shop();
        $order = $this->orderWithItems($m, $p);

        $this->actingAs($user)->putJson("/api/v1/orders/{$order->id}/pack")->assertOk();
        $this->assertSame('packed', $order->fresh()->fulfillment_status);

        $this->actingAs($user)->putJson("/api/v1/orders/{$order->id}/ship", ['tracking_no' => 'JNE12345'])
            ->assertOk();
        $o = $order->fresh();
        $this->assertSame('shipped', $o->fulfillment_status);
        $this->assertSame('JNE12345', $o->tracking_no);

        $this->actingAs($user)->putJson("/api/v1/orders/{$order->id}/deliver")->assertOk();
        $this->assertSame('delivered', $order->fresh()->fulfillment_status);

        $saldoAwal = \App\Models\SellerBalance::where('merchant_id', $m->id)->first()?->balance ?? 0;
        $this->actingAs($user)->putJson("/api/v1/orders/{$order->id}/complete")->assertOk();

        $o = $order->fresh();
        $this->assertSame('completed', $o->fulfillment_status);
        $this->assertNotNull($o->completed_at);
        $this->assertSame($saldoAwal + $o->seller_net, \App\Models\SellerBalance::where('merchant_id', $m->id)->first()->balance);
    }

    public function test_pack_requires_paid(): void
    {
        [$user, $m, $p] = $this->shop();
        $order = $this->orderWithItems($m, $p);
        $order->update(['payment_status' => 'unpaid']);

        $this->actingAs($user)->putJson("/api/v1/orders/{$order->id}/pack")->assertStatus(422);
    }

    public function test_cancel_returns_stock_and_voucher_quota(): void
    {
        [$user, $m, $p] = $this->shop();
        $order = $this->orderWithItems($m, $p, 2);
        $this->assertSame(8, $p->fresh()->stock);

        $v = Voucher::create([
            'scope' => 'shop', 'merchant_id' => $m->id, 'code' => 'HEMAT',
            'name' => 'Hemat', 'type' => 'fixed', 'value' => 5000,
            'used' => 1, 'active' => true,
        ]);
        $order->update(['voucher_id' => $v->id, 'voucher_discount' => 5000, 'voucher_code' => 'HEMAT']);
        \App\Models\VoucherRedemption::create([
            'voucher_id' => $v->id, 'order_id' => $order->id,
            'buyer_phone' => '0812000001', 'discount' => 5000,
        ]);

        $this->actingAs($user)
            ->putJson("/api/v1/orders/{$order->id}/cancel", ['reason' => 'Stok habis'])
            ->assertOk();

        $o = $order->fresh();
        $this->assertSame('cancelled', $o->fulfillment_status);
        $this->assertSame(10, $p->fresh()->stock);        // stok kembali
        $this->assertSame(0, $v->fresh()->used);          // kuota kembali
        $this->assertDatabaseMissing('voucher_redemptions', ['order_id' => $order->id]);
    }

    public function test_cancel_after_ship_rejected(): void
    {
        [$user, $m, $p] = $this->shop();
        $order = $this->orderWithItems($m, $p);
        $order->update(['fulfillment_status' => 'shipped']);

        $this->actingAs($user)->putJson("/api/v1/orders/{$order->id}/cancel")->assertStatus(422);
    }

    public function test_return_approve_returns_stock(): void
    {
        [$user, $m, $p] = $this->shop();
        $order = $this->orderWithItems($m, $p);

        $this->actingAs($user)->postJson("/api/v1/orders/{$order->id}/return", ['reason' => 'Size kekecilan'])
            ->assertStatus(422); // belum dikirim → ditolak

        $order->update(['fulfillment_status' => 'delivered']);
        $this->actingAs($user)->postJson("/api/v1/orders/{$order->id}/return", ['reason' => 'Size kekecilan'])
            ->assertOk();
        $this->assertSame('requested', $order->fresh()->return_status);

        $this->actingAs($user)->putJson("/api/v1/orders/{$order->id}/return", ['decision' => 'approved'])
            ->assertOk();

        $o = $order->fresh();
        $this->assertSame('approved', $o->return_status);
        $this->assertSame('returned', $o->fulfillment_status);
        $this->assertSame(10, $p->fresh()->stock);
    }

    public function test_return_reject_keeps_flow(): void
    {
        [$user, $m, $p] = $this->shop();
        $order = $this->orderWithItems($m, $p);
        $order->update(['fulfillment_status' => 'delivered', 'return_status' => 'requested']);

        $this->actingAs($user)->putJson("/api/v1/orders/{$order->id}/return", ['decision' => 'rejected', 'note' => 'Bukti kurang'])
            ->assertOk();

        $o = $order->fresh();
        $this->assertSame('rejected', $o->return_status);
        $this->assertSame('delivered', $o->fulfillment_status);
        $this->assertSame(9, $p->fresh()->stock);
    }

    public function test_other_merchant_cannot_touch_order(): void
    {
        [, $m, $p] = $this->shop();
        $order = $this->orderWithItems($m, $p);

        $intruder = User::factory()->create(['role' => 'merchant']);
        Merchant::create([
            'user_id' => $intruder->id, 'name' => 'Asing',
            'slug' => 'asing-'.uniqid(), 'active' => true,
        ]);

        // Tenant scope: order merchant lain tak terjangkau (404, bukan 403 —
        // tidak membocorkan keberadaan order).
        $this->actingAs($intruder)->putJson("/api/v1/orders/{$order->id}/pack")->assertStatus(404);
    }

    public function test_admin_sees_all_orders_and_can_cancel(): void
    {
        [$user, $m, $p] = $this->shop();
        $order = $this->orderWithItems($m, $p);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->getJson('/api/v1/admin/orders?fulfillment_status=pending')
            ->assertOk();

        $this->actingAs($admin)->putJson("/api/v1/admin/orders/{$order->id}/cancel", ['by' => 'admin'])
            ->assertOk();
        $this->assertSame('cancelled', $order->fresh()->fulfillment_status);

        // Seller tak boleh akses endpoint admin.
        $this->actingAs($user)->getJson('/api/v1/admin/orders')->assertStatus(403);
    }
}
