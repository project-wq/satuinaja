<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\FeeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Fase 22: chat buyer↔seller per order. */
class Phase22Test extends TestCase
{
    use RefreshDatabase;

    private function shop(): array
    {
        $user = User::factory()->create(['role' => 'merchant']);
        $m = Merchant::create([
            'user_id' => $user->id, 'name' => 'Toko', 'slug' => 'toko-'.uniqid(), 'active' => true,
        ]);
        $p = Product::create([
            'merchant_id' => $m->id, 'title' => 'Produk', 'slug' => 'produk-'.uniqid(),
            'price' => 10000, 'stock' => 10, 'weight' => 500, 'status' => 'active',
        ]);

        return [$user, $m, $p];
    }

    private function order(Merchant $m, Product $p): Order
    {
        $f = (new FeeService)->line($p->price, $p->discount_price, 1);
        $o = Order::withoutGlobalScope('merchant')->create([
            'merchant_id' => $m->id, 'order_no' => 'ORD-'.uniqid(),
            'buyer_name' => 'Budi', 'buyer_phone' => '0812345678', 'shipping_address' => 'Jl',
            'shipping_cost' => 5000, 'subtotal' => 10000,
            'discount_total' => $f['discount'], 'subtotal_sale' => $f['base_sale'],
            'buyer_fee' => $f['buyer_fee'], 'buyer_admin_fee' => $f['buyer_admin_fee'],
            'seller_net' => $f['seller_net'], 'total' => $f['buyer_line_total'] + 5000,
            'payment_status' => 'paid', 'fulfillment_status' => 'pending',
        ]);
        $o->items()->create([
            'product_id' => $p->id, 'title' => $p->title, 'price' => $p->price,
            'qty' => 1, 'line_total' => $f['base_sale'],
            'buyer_fee' => $f['buyer_fee'], 'buyer_admin_fee' => $f['buyer_admin_fee'],
            'seller_net' => $f['seller_net'],
        ]);

        return $o;
    }

    public function test_buyer_can_send_and_read(): void
    {
        [$user, $m, $p] = $this->shop();
        $o = $this->order($m, $p);

        $this->postJson("/api/v1/orders/track/{$o->order_no}/messages", [
            'buyer_phone' => '0812345678', 'body' => 'Kak, ready warna hitam?',
        ])->assertCreated();

        // HP salah → 403
        $this->postJson("/api/v1/orders/track/{$o->order_no}/messages", [
            'buyer_phone' => '0899', 'body' => 'x',
        ])->assertForbidden();

        $res = $this->getJson("/api/v1/orders/track/{$o->order_no}/messages?phone=0812345678")
            ->assertOk()->json('data');
        $this->assertCount(1, $res);
        $this->assertSame('buyer', $res[0]['sender']);
    }

    public function test_seller_reply_and_unread_badge(): void
    {
        [$user, $m, $p] = $this->shop();
        $o = $this->order($m, $p);

        $this->postJson("/api/v1/orders/track/{$o->order_no}/messages", [
            'buyer_phone' => '0812345678', 'body' => 'Halo kak',
        ])->assertCreated();

        // badge unread = 1
        $badge = $this->actingAs($user)->getJson('/api/v1/messages/unread')->json('data');
        $this->assertSame(1, $badge['unread']);

        // seller balas
        $this->actingAs($user)->postJson("/api/v1/orders/{$o->id}/messages", [
            'body' => 'Halo, ready ya',
        ])->assertCreated();

        // sellerIndex tandai dibaca → badge 0
        $list = $this->actingAs($user)->getJson("/api/v1/orders/{$o->id}/messages")
            ->assertOk()->json();
        $this->assertCount(2, $list['data']);
        $this->assertSame(1, $list['unread_buyer']);
        $badge2 = $this->actingAs($user)->getJson('/api/v1/messages/unread')->json('data');
        $this->assertSame(0, $badge2['unread']);

        // buyer lihat balasan
        $buyer = $this->getJson("/api/v1/orders/track/{$o->order_no}/messages?phone=0812345678")
            ->json('data');
        $this->assertCount(2, $buyer);
        $this->assertSame('seller', $buyer[1]['sender']);
    }

    public function test_other_merchant_cannot_read_chat(): void
    {
        [$user, $m, $p] = $this->shop();
        $o = $this->order($m, $p);

        // order milik toko lain → global scope = 404
        $u2 = User::factory()->create(['role' => 'merchant']);
        Merchant::create(['user_id' => $u2->id, 'name' => 'T2', 'slug' => 't2-'.uniqid(), 'active' => true]);
        $this->actingAs($u2)->getJson("/api/v1/orders/{$o->id}/messages")->assertNotFound();
    }

    public function test_chat_closed_on_cancelled_order(): void
    {
        [$user, $m, $p] = $this->shop();
        $o = $this->order($m, $p);
        $o->update(['fulfillment_status' => 'cancelled']);

        $this->postJson("/api/v1/orders/track/{$o->order_no}/messages", [
            'buyer_phone' => '0812345678', 'body' => 'x',
        ])->assertStatus(422);
        $this->actingAs($user)->postJson("/api/v1/orders/{$o->id}/messages", [
            'body' => 'x',
        ])->assertStatus(422);
    }
}
