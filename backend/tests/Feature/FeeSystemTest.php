<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\SellerBalance;
use App\Models\Setting;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\FeeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 5: fee marketplace + saldo seller + withdraw.
 *
 * Rincian:
 *   buyer bayar  = (harga - diskon) + fee 11% + admin Rp1000/unit + ongkir
 *   seller dapat = (harga - diskon) - Rp500/unit  -> masuk saldo saat order PAID
 */
class FeeSystemTest extends TestCase
{
    use RefreshDatabase;

    private function merchant(array $overrides = []): Merchant
    {
        $user = User::factory()->create(['role' => 'merchant']);

        return Merchant::create(array_merge([
            'user_id' => $user->id, 'name' => 'Toko', 'slug' => 'toko-'.uniqid(), 'active' => true,
        ], $overrides));
    }

    private function product(Merchant $m, int $price = 10000, ?int $discount = null): Product
    {
        return Product::create([
            'merchant_id' => $m->id,
            'title' => 'Produk',
            'slug' => 'produk-'.uniqid(),
            'price' => $price,
            'discount_price' => $discount,
            'stock' => 10,
            'weight' => 500,
            'status' => 'active',
        ]);
    }

    /** Order PAID langsung (simulasi sudah lewat webhook) dengan 1 item. */
    private function paidOrder(Merchant $m, Product $p, int $qty = 1): Order
    {
        $price = $p->price;
        $discount = $p->discount_price;
        $f = (new FeeService)->line($price, $discount, $qty);

        $order = Order::create([
            'merchant_id' => $m->id,
            'order_no' => 'ORD-'.uniqid(),
            'buyer_name' => 'Budi',
            'buyer_phone' => '0812',
            'shipping_address' => 'Jl. Test',
            'shipping_cost' => 5000,
            'subtotal' => $price * $qty,
            'discount_total' => $f['discount'],
            'subtotal_sale' => $f['base_sale'],
            'buyer_fee' => $f['buyer_fee'],
            'buyer_admin_fee' => $f['buyer_admin_fee'],
            'seller_net' => $f['seller_net'],
            'total' => $f['buyer_line_total'] + 5000,
            'payment_status' => 'unpaid',
            'fulfillment_status' => 'pending',
        ]);

        $order->items()->create([
            'product_id' => $p->id,
            'title' => $p->title,
            'price' => $price,
            'discount_price' => $discount,
            'qty' => $qty,
            'line_total' => $f['base_sale'],
            'buyer_fee' => $f['buyer_fee'],
            'buyer_admin_fee' => $f['buyer_admin_fee'],
            'seller_net' => $f['seller_net'],
        ]);

        return $order;
    }

    public function test_fee_service_math(): void
    {
        $fee = new FeeService;

        // 10.000, tanpa diskon, qty 1: buyer 10.000+1.100+1.000; seller 9.500
        $l = $fee->line(10000, null, 1);
        $this->assertSame(10000, $l['base_sale']);
        $this->assertSame(1100, $l['buyer_fee']);
        $this->assertSame(1000, $l['buyer_admin_fee']);
        $this->assertSame(12100, $l['buyer_line_total']);
        $this->assertSame(9500, $l['seller_net']);

        // dengan diskon: 10.000 coret -> 8.000 jual: buyer 8.000+880+1.000
        $l = $fee->line(10000, 8000, 1);
        $this->assertSame(2000, $l['discount']);
        $this->assertSame(880, $l['buyer_fee']);
        $this->assertSame(7500, $l['seller_net']);

        // qty 3: admin fee 3.000, seller fee 1.500
        $l = $fee->line(10000, null, 3);
        $this->assertSame(30000, $l['base_sale']);
        $this->assertSame(3300, $l['buyer_fee']);
        $this->assertSame(3000, $l['buyer_admin_fee']);
        $this->assertSame(28500, $l['seller_net']);
    }

    public function test_checkout_preview_shows_fee_breakdown(): void
    {
        $m = $this->merchant();
        $p = $this->product($m);

        $res = $this->postJson('/api/v1/checkout/preview', [
            'merchant_slug' => $m->slug,
            'items' => [['product_id' => $p->id, 'qty' => 1]],
            'shipping_cost' => 5000,
        ])->assertOk()->json('data');

        $this->assertSame(10000, $res['subtotal']);
        $this->assertSame(0, $res['discount_total']);
        $this->assertSame(1100, $res['buyer_fee']);
        $this->assertSame(1000, $res['buyer_admin_fee']);
        $this->assertSame(5000, $res['shipping_cost']);
        $this->assertSame(17100, $res['total']);
    }

    public function test_checkout_store_computes_fees_and_seller_net(): void
    {
        $m = $this->merchant();
        $p = $this->product($m);

        $res = $this->postJson('/api/v1/checkout', [
            'merchant_slug' => $m->slug,
            'buyer_name' => 'Budi',
            'buyer_phone' => '0812',
            'shipping_address' => 'Jl. Test 1',
            'destination_city_id' => '114',
            'courier' => 'jne',
            'service' => 'REG',
            'shipping_cost' => 6000,
            'items' => [['product_id' => $p->id, 'qty' => 2]],
        ])->assertCreated()->json('data');

        $this->assertSame(20000, $res['rincian']['subtotal']);
        $this->assertSame(2200, $res['rincian']['buyer_fee']);
        $this->assertSame(2000, $res['rincian']['buyer_admin_fee']);
        $this->assertSame(30200, $res['total']); // 20.000+2.200+2.000+6.000

        $order = Order::where('order_no', $res['order_no'])->firstOrFail();
        $this->assertSame(19000, $order->seller_net); // (10.000-500)*2
        $this->assertSame(2, $order->items()->first()->qty);

        // stok terpotong
        $this->assertSame(8, $p->fresh()->stock);
    }

    public function test_webhook_paid_credits_seller_balance_once(): void
    {
        $m = $this->merchant();
        $p = $this->product($m);
        $order = $this->paidOrder($m, $p);
        config(['services.midtrans.server_key' => 'SB-Mid-server-TESTKEY']);
        $gross = (string) $order->total.'.00';
        $sig = hash('sha512', $order->order_no.'200'.$gross.'SB-Mid-server-TESTKEY');

        $this->postJson('/api/v1/webhooks/midtrans', [
            'order_id' => $order->order_no,
            'status_code' => '200',
            'gross_amount' => $gross,
            'signature_key' => $sig,
            'transaction_status' => 'settlement',
        ])->assertOk();

        $bal = SellerBalance::where('merchant_id', $m->id)->firstOrFail();
        $this->assertSame(9500, $bal->balance);

        // kirim ulang webhook yang sama -> tidak double credit
        $this->postJson('/api/v1/webhooks/midtrans', [
            'order_id' => $order->order_no,
            'status_code' => '200',
            'gross_amount' => $order->total.'.00',
            'signature_key' => $sig,
            'transaction_status' => 'settlement',
        ])->assertOk();

        $this->assertSame(9500, $bal->fresh()->balance);
        $this->assertSame(1, $bal->transactions()->where('type', 'sale')->count());
    }

    public function test_withdraw_flow_hold_approve_reject(): void
    {
        $m = $this->merchant();
        $user = $m->user;
        $p = $this->product($m, 20000);
        $order = $this->paidOrder($m, $p);
        app(\App\Services\BalanceService::class)->creditOrder($order); // saldo 19500

        // 1) request withdraw 19000 (saldo 19500)
        $this->actingAs($user)->postJson('/api/v1/balance/withdraw', [
            'amount' => 19000,
            'bank_name' => 'BCA',
            'bank_account_no' => '123456',
            'bank_account_holder' => 'Toko',
        ])->assertCreated();

        $bal = SellerBalance::where('merchant_id', $m->id)->firstOrFail();
        $this->assertSame(500, $bal->balance);
        $this->assertSame(19000, $bal->held);

        $wd = Withdrawal::firstOrFail();
        $this->assertSame('pending', $wd->status);

        // 2) withdraw lebih dari saldo -> 422
        $this->actingAs($user)->postJson('/api/v1/balance/withdraw', [
            'amount' => 6000,
            'bank_name' => 'BCA',
            'bank_account_no' => '123456',
            'bank_account_holder' => 'Toko',
        ])->assertStatus(422);

        $admin = User::factory()->create(['role' => 'admin']);

        // 3) admin reject -> saldo balik
        $this->actingAs($admin)->putJson("/api/v1/admin/withdrawals/{$wd->id}", [
            'decision' => 'rejected', 'note' => 'Rekening tak valid',
        ])->assertOk();

        $bal->refresh();
        $this->assertSame(19500, $bal->balance);
        $this->assertSame(0, $bal->held);
        $this->assertSame('rejected', $wd->fresh()->status);

        // 4) withdraw ulang 10000 lalu admin approve -> held nol, uang "keluar"
        $this->actingAs($user)->postJson('/api/v1/balance/withdraw', [
            'amount' => 10000,
            'bank_name' => 'BCA',
            'bank_account_no' => '123456',
            'bank_account_holder' => 'Toko',
        ])->assertCreated();

        $wd2 = Withdrawal::where('status', 'pending')->firstOrFail();
        $this->actingAs($admin)->putJson("/api/v1/admin/withdrawals/{$wd2->id}", [
            'decision' => 'approved',
        ])->assertOk();

        $bal->refresh();
        $this->assertSame(9500, $bal->balance);
        $this->assertSame(0, $bal->held);
        $this->assertSame('approved', $wd2->fresh()->status);
    }

    public function test_only_admin_can_process_withdraw_and_change_settings(): void
    {
        $m = $this->merchant();
        $user = $m->user;

        $this->actingAs($user)->getJson('/api/v1/admin/withdrawals')->assertForbidden();
        $this->actingAs($user)->putJson('/api/v1/admin/settings', [
            'fee_buyer_percent' => 5,
        ])->assertForbidden();

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->putJson('/api/v1/admin/settings', [
            'fee_buyer_percent' => 9,
            'seller_fee_per_item' => 750,
        ])->assertOk();

        $this->assertSame('9', Setting::get('fee_buyer_percent'));
        $this->assertSame('750', Setting::get('seller_fee_per_item'));

        // fee baru dipakai checkout berikutnya
        $p = $this->product($m);
        $res = $this->postJson('/api/v1/checkout/preview', [
            'merchant_slug' => $m->slug,
            'items' => [['product_id' => $p->id, 'qty' => 1]],
            'shipping_cost' => 0,
        ])->assertOk()->json('data');
        $this->assertSame(900, $res['buyer_fee']); // 9% dari 10.000
    }
}