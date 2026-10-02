<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Product;
use App\Models\Refund;
use App\Models\SellerBalance;
use App\Models\User;
use App\Services\BalanceService;
use App\Services\FeeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 6: laporan penjualan + notifikasi in-app + refund.
 */
class Phase6Test extends TestCase
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

    /** Order dengan 1 item, status pembayaran bisa diatur. */
    private function order(Merchant $m, Product $p, int $qty = 1, string $payment = 'paid'): Order
    {
        $f = (new FeeService)->line($p->price, $p->discount_price, $qty);

        $order = Order::withoutGlobalScope('merchant')->create([
            'merchant_id' => $m->id,
            'order_no' => 'ORD-'.uniqid(),
            'buyer_name' => 'Budi',
            'buyer_phone' => '0812',
            'shipping_address' => 'Jl. Test',
            'shipping_cost' => 5000,
            'subtotal' => $p->price * $qty,
            'discount_total' => $f['discount'],
            'subtotal_sale' => $f['base_sale'],
            'buyer_fee' => $f['buyer_fee'],
            'buyer_admin_fee' => $f['buyer_admin_fee'],
            'seller_net' => $f['seller_net'],
            'total' => $f['buyer_line_total'] + 5000,
            'payment_status' => $payment,
            'fulfillment_status' => 'pending',
        ]);

        $order->items()->create([
            'product_id' => $p->id,
            'title' => $p->title,
            'price' => $p->price,
            'discount_price' => $p->discount_price,
            'qty' => $qty,
            'line_total' => $f['base_sale'],
            'buyer_fee' => $f['buyer_fee'],
            'buyer_admin_fee' => $f['buyer_admin_fee'],
            'seller_net' => $f['seller_net'],
        ]);

        return $order;
    }

    // ---------- Laporan ----------

    public function test_sales_report_summary_and_daily(): void
    {
        $m = $this->merchant();
        $user = $m->user;
        $p = $this->product($m, 20000);

        $this->order($m, $p, 2);          // paid: total 20000*2+11%*40000+2000+5000 = 49400
        $this->order($m, $p, 1);          // paid
        $this->order($m, $p, 1, 'unpaid'); // belum dibayar -> tak dihitung omzet

        $res = $this->actingAs($user)
            ->getJson('/api/v1/reports/sales?period=30d')
            ->assertOk()
            ->json('data');

        $this->assertSame(2, $res['summary']['orders_paid']);
        $this->assertSame(3, $res['summary']['orders_all']);
        $this->assertSame(1, $res['summary']['orders_pending']);
        $this->assertSame(3, $res['summary']['items_sold']);

        // omzet = order1 (49400) + order2 (20000+2200+1000+5000=28200) = 77600
        $this->assertSame(77600, $res['summary']['revenue']);
        // seller_net = (20000-500)*3 = 58500
        $this->assertSame(58500, $res['summary']['seller_net']);

        $this->assertCount(30, $res['daily']);
        $this->assertNotEmpty($res['top_products']);
        $this->assertSame(3, $res['top_products'][0]['qty']);
    }

    public function test_report_export_csv(): void
    {
        $m = $this->merchant();
        $user = $m->user;
        $p = $this->product($m);
        $this->order($m, $p);

        $res = $this->actingAs($user)->get('/api/v1/reports/sales/export?period=today');
        $res->assertOk();
        $this->assertStringContainsString('text/csv', $res->headers->get('Content-Type'));

        $body = $res->streamedContent() ?: $res->getContent();
        $this->assertStringContainsString('tanggal,order,omzet,pendapatan_bersih', $body);
    }

    public function test_report_is_scoped_to_own_merchant(): void
    {
        $m1 = $this->merchant();
        $m2 = $this->merchant();
        $p1 = $this->product($m1);
        $p2 = $this->product($m2);
        $this->order($m1, $p1);
        $this->order($m2, $p2);
        $this->order($m2, $p2);

        $res = $this->actingAs($m1->user)->getJson('/api/v1/reports/sales?period=30d')->json('data');
        $this->assertSame(1, $res['summary']['orders_paid']);
    }

    // ---------- Notifikasi ----------

    public function test_checkout_creates_order_new_notification(): void
    {
        $m = $this->merchant();
        $p = $this->product($m);

        $this->postJson('/api/v1/checkout', [
            'merchant_slug' => $m->slug,
            'buyer_name' => 'Budi',
            'buyer_phone' => '0812',
            'shipping_address' => 'Jl. Test',
            'destination_city_id' => '114',
            'courier' => 'jne',
            'service' => 'REG',
            'shipping_cost' => 6000,
            'items' => [['product_id' => $p->id, 'qty' => 1]],
        ])->assertCreated();

        $n = Notification::where('merchant_id', $m->id)->where('type', 'order.new')->firstOrFail();
        $this->assertStringContainsString('Pesanan baru', $n->title);
        $this->assertNull($n->read_at);
    }

    public function test_webhook_paid_creates_settled_notification(): void
    {
        $m = $this->merchant();
        $p = $this->product($m);
        $order = $this->order($m, $p, 1, 'unpaid');

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

        $this->assertDatabaseHas('notifications', [
            'merchant_id' => $m->id, 'type' => 'payment.settled',
        ]);
    }

    public function test_notification_list_read_and_read_all(): void
    {
        $m = $this->merchant();
        $user = $m->user;
        $p = $this->product($m);

        // 3 order -> 3 notifikasi
        for ($i = 0; $i < 3; $i++) {
            $this->order($m, $p);
        }
        Notification::create(['merchant_id' => $m->id, 'type' => 'x', 'title' => 'A']);
        Notification::create(['merchant_id' => $m->id, 'type' => 'x', 'title' => 'B']);
        Notification::create(['merchant_id' => $m->id, 'type' => 'x', 'title' => 'C']);

        $res = $this->actingAs($user)->getJson('/api/v1/notifications')->assertOk()->json();
        $this->assertSame(6, $res['meta']['total']);
        $this->assertSame(6, $res['meta']['unread']);

        // baca satu
        $first = $res['data'][0]['id'];
        $this->actingAs($user)->putJson("/api/v1/notifications/{$first}/read")->assertOk();
        $this->assertSame(5, Notification::where('merchant_id', $m->id)->whereNull('read_at')->count());

        // read all
        $this->actingAs($user)->postJson('/api/v1/notifications/read-all')->assertOk();
        $this->assertSame(0, Notification::where('merchant_id', $m->id)->whereNull('read_at')->count());
    }

    public function test_notification_cannot_be_read_by_other_merchant(): void
    {
        $m1 = $this->merchant();
        $m2 = $this->merchant();
        $n = Notification::create(['merchant_id' => $m1->id, 'type' => 'x', 'title' => 'A']);

        $this->actingAs($m2->user)->putJson("/api/v1/notifications/{$n->id}/read")->assertForbidden();
        $this->actingAs($m2->user)->deleteJson("/api/v1/notifications/{$n->id}")->assertForbidden();
    }

    // ---------- Refund ----------

    public function test_refund_request_and_admin_approve_claws_back_balance(): void
    {
        $m = $this->merchant();
        $user = $m->user;
        $p = $this->product($m, 20000);
        $order = $this->order($m, $p, 1);
        app(BalanceService::class)->creditOrder($order); // saldo 19500

        $this->assertSame(19500, SellerBalance::where('merchant_id', $m->id)->first()->balance);

        // seller ajukan refund
        $res = $this->actingAs($user)->postJson('/api/v1/refunds', [
            'order_id' => $order->id,
            'reason' => 'Barang rusak',
        ])->assertCreated()->json('data');

        $this->assertSame('pending', $res['status']);
        $this->assertSame(19500, $res['amount']);

        // admin approve -> saldo ditarik
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->putJson("/api/v1/admin/refunds/{$res['id']}", [
            'decision' => 'approved',
        ])->assertOk();

        $this->assertSame(0, SellerBalance::where('merchant_id', $m->id)->fresh()->balance);
        $this->assertSame('approved', Refund::find($res['id'])->status);
        $this->assertDatabaseHas('balance_transactions', [
            'merchant_id' => $m->id, 'type' => 'refund_paid', 'amount' => -19500,
        ]);

        // notifikasi refund terkirim
        $this->assertDatabaseHas('notifications', [
            'merchant_id' => $m->id, 'type' => 'refund.status',
        ]);
    }

    public function test_refund_reject_keeps_balance(): void
    {
        $m = $this->merchant();
        $user = $m->user;
        $p = $this->product($m, 20000);
        $order = $this->order($m, $p, 1);
        app(BalanceService::class)->creditOrder($order);

        $res = $this->actingAs($user)->postJson('/api/v1/refunds', [
            'order_id' => $order->id, 'reason' => 'Salah beli',
        ])->assertCreated()->json('data');

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->putJson("/api/v1/admin/refunds/{$res['id']}", [
            'decision' => 'rejected', 'note' => 'Tidak memenuhi syarat',
        ])->assertOk();

        $this->assertSame(19500, SellerBalance::where('merchant_id', $m->id)->first()->balance);
        $this->assertSame('rejected', Refund::find($res['id'])->status);
        $this->assertDatabaseMissing('balance_transactions', ['type' => 'refund_paid']);
    }

    public function test_refund_only_for_paid_orders_and_no_double_pending(): void
    {
        $m = $this->merchant();
        $user = $m->user;
        $p = $this->product($m);
        $unpaid = $this->order($m, $p, 1, 'unpaid');

        $this->actingAs($user)->postJson('/api/v1/refunds', [
            'order_id' => $unpaid->id, 'reason' => 'x',
        ])->assertStatus(422);

        $paid = $this->order($m, $p, 1);
        $this->actingAs($user)->postJson('/api/v1/refunds', [
            'order_id' => $paid->id, 'reason' => 'x',
        ])->assertCreated();

        // pengajuan kedua untuk order sama -> ditolak
        $this->actingAs($user)->postJson('/api/v1/refunds', [
            'order_id' => $paid->id, 'reason' => 'y',
        ])->assertStatus(422);
    }

    public function test_refund_idempotent_no_double_clawback(): void
    {
        $m = $this->merchant();
        $user = $m->user;
        $p = $this->product($m, 20000);
        $order = $this->order($m, $p, 1);
        app(BalanceService::class)->creditOrder($order);

        $res = $this->actingAs($user)->postJson('/api/v1/refunds', [
            'order_id' => $order->id, 'reason' => 'x',
        ])->assertCreated()->json('data');

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->putJson("/api/v1/admin/refunds/{$res['id']}", [
            'decision' => 'approved',
        ])->assertOk();

        // approve ulang via service -> tidak dobel tarik
        app(BalanceService::class)->refundOrder($order->fresh());
        $this->assertSame(0, SellerBalance::where('merchant_id', $m->id)->first()->balance);
        $this->assertSame(1, \App\Models\BalanceTransaction::where('type', 'refund_paid')->count());
    }

    public function test_only_admin_can_process_refunds(): void
    {
        $m = $this->merchant();
        $user = $m->user;
        $p = $this->product($m);
        $order = $this->order($m, $p);
        $refund = Refund::create([
            'order_id' => $order->id, 'merchant_id' => $m->id, 'amount' => 9500, 'status' => 'pending',
        ]);

        $this->actingAs($user)->putJson("/api/v1/admin/refunds/{$refund->id}", [
            'decision' => 'approved',
        ])->assertForbidden();
    }
}