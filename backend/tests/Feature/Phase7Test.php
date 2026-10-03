<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Product;
use App\Models\PublishLog;
use App\Models\SellerBalance;
use App\Models\User;
use App\Services\MidtransService;
use App\Services\StockSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Fase 7: KYC payment gateway, two-way stock sync, webhook marketplace.
 */
class Phase7Test extends TestCase
{
    use RefreshDatabase;

    private function merchant(array $overrides = []): Merchant
    {
        $user = User::factory()->create(['role' => 'merchant']);

        return Merchant::create(array_merge([
            'user_id' => $user->id, 'name' => 'Toko', 'slug' => 'toko-'.uniqid(), 'active' => true,
        ], $overrides));
    }

    private function product(Merchant $m, int $stock = 10): Product
    {
        return Product::create([
            'merchant_id' => $m->id,
            'title' => 'Produk',
            'slug' => 'sku-'.uniqid(),
            'price' => 20000,
            'stock' => $stock,
            'weight' => 500,
            'status' => 'active',
        ]);
    }

    private function shopeeChannel(Merchant $m, array $creds = []): Channel
    {
        return Channel::create([
            'merchant_id' => $m->id,
            'platform' => 'shopee',
            'label' => 'Shopee',
            'active' => true,
            'credentials' => array_merge([
                'partner_id' => '10001',
                'partner_key' => 'rahasia-partner',
                'shop_id' => '20002',
                'access_token' => 'tok-abc',
                'sandbox' => '1',
            ], $creds),
        ]);
    }

    /** POST raw JSON + HMAC signature untuk webhook marketplace. */
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

    // ---------- 7.1 KYC payment gateway ----------

    public function test_admin_sees_payment_gateway_readiness(): void
    {
        config(['services.midtrans.server_key' => 'SB-Mid-server-K', 'services.midtrans.client_key' => 'SB-Mid-client-K']);
        Http::fake(['api.sandbox.midtrans.com/v2/channels' => Http::response([['id' => 'bca_va']], 200)]);

        $admin = User::factory()->create(['role' => 'admin']);

        $res = $this->actingAs($admin)->getJson('/api/v1/admin/payment-gateway');

        $res->assertOk()
            ->assertJsonPath('data.mode', 'sandbox')
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.configured', true)
            ->assertJsonPath('data.reachable', true);
    }

    public function test_non_admin_cannot_read_payment_gateway(): void
    {
        $user = User::factory()->create(['role' => 'merchant']);
        Merchant::create(['user_id' => $user->id, 'name' => 'T', 'slug' => 't', 'active' => true]);

        $this->actingAs($user)->getJson('/api/v1/admin/payment-gateway')->assertForbidden();
    }

    public function test_admin_can_switch_gateway_mode_to_production(): void
    {
        config(['services.midtrans.server_key' => 'SB-Mid-server-K', 'services.midtrans.client_key' => 'SB-Mid-client-K']);
        Http::fake(['api.midtrans.com/v2/channels' => Http::response([], 200)]);

        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->putJson('/api/v1/admin/payment-gateway', ['sandbox' => false])
            ->assertOk()
            ->assertJsonPath('data.mode', 'production');

        $this->assertSame('0', \App\Models\Setting::get('midtrans_sandbox'));
    }

    // ---------- 7.2 two-way stock sync (pull) ----------

    public function test_pull_stock_updates_local_product_from_shopee(): void
    {
        Http::fake([
            '*/api/v2/product/get_item_base_info*' => Http::response([
                'response' => ['item_list' => [[
                    'item_id' => 555,
                    'item_status' => 'NORMAL',
                    'stock_info_v2' => ['seller_stock' => [['stock' => 7], ['stock' => 3]]],
                ]]],
            ], 200),
        ]);

        $m = $this->merchant();
        $p = $this->product($m, 50);
        $c = $this->shopeeChannel($m);

        PublishLog::create([
            'product_id' => $p->id, 'channel_id' => $c->id,
            'status' => 'success', 'external_id' => '555', 'published_at' => now(),
        ]);

        $res = app(StockSyncService::class)->pull($p);

        $this->assertTrue($res['shopee']['ok']);
        $this->assertSame(10, $p->fresh()->stock); // 7 + 3
    }

    public function test_pull_stock_reports_missing_external_id(): void
    {
        $m = $this->merchant();
        $p = $this->product($m);
        $this->shopeeChannel($m);

        $res = app(StockSyncService::class)->pull($p);

        $this->assertFalse($res['shopee']['ok']);
        $this->assertStringContainsString('belum pernah dipublish', $res['shopee']['error']);
    }

    // ---------- 7.3 webhook marketplace ----------

    public function test_marketplace_webhook_rejects_bad_signature(): void
    {
        $m = $this->merchant();
        $this->product($m);
        $c = $this->shopeeChannel($m);

        $this->postSigned("/api/v1/webhooks/shopee/{$c->id}", [
            'ordersn' => 'SP-1', 'buyer_username' => 'Budi',
            'item_list' => [['item_sku' => 'x', 'quantity' => 1]],
        ], 'rahasia-partner', 'sig-palsu')->assertForbidden();

        $this->assertSame(0, Order::withoutGlobalScope('merchant')->count());
    }

    public function test_marketplace_webhook_creates_paid_order_and_credits_balance(): void
    {
        $m = $this->merchant();
        $p = $this->product($m, 10);
        $c = $this->shopeeChannel($m);

        $res = $this->postSigned("/api/v1/webhooks/shopee/{$c->id}", [
            'ordersn' => 'SP-OK-1',
            'buyer_username' => 'Budi',
            'item_list' => [['item_sku' => $p->slug, 'quantity' => 2]],
        ], 'rahasia-partner');

        $res->assertCreated()->assertJsonPath('order_no', fn ($v) => is_string($v));

        $order = Order::withoutGlobalScope('merchant')->firstOrFail();
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame('shopee', $order->source);
        $this->assertSame('SP-OK-1', $order->external_order_id);
        $this->assertSame(2, $order->items()->first()->qty);

        // Stok lokal berkurang 2.
        $this->assertSame(8, $p->fresh()->stock);

        // Saldo seller terisi seller_net.
        $bal = SellerBalance::where('merchant_id', $m->id)->first();
        $this->assertNotNull($bal);
        $this->assertSame($order->seller_net, $bal->balance);
    }

    public function test_marketplace_webhook_is_idempotent(): void
    {
        $m = $this->merchant();
        $p = $this->product($m, 10);
        $c = $this->shopeeChannel($m);

        $payload = [
            'ordersn' => 'SP-DUP-1',
            'buyer_username' => 'Budi',
            'item_list' => [['item_sku' => $p->slug, 'quantity' => 1]],
        ];

        $this->postSigned("/api/v1/webhooks/shopee/{$c->id}", $payload, 'rahasia-partner')->assertCreated();
        $this->postSigned("/api/v1/webhooks/shopee/{$c->id}", $payload, 'rahasia-partner')->assertOk();

        $this->assertSame(1, Order::withoutGlobalScope('merchant')->count());
        // Stok hanya berkurang sekali.
        $this->assertSame(9, $p->fresh()->stock);
    }

    public function test_marketplace_webhook_rejects_platform_mismatch(): void
    {
        $m = $this->merchant();
        $c = $this->shopeeChannel($m);

        $this->postSigned("/api/v1/webhooks/tokopedia/{$c->id}", [
            'order_id' => 'TK-1', 'products' => [],
        ], 'rahasia-partner')->assertStatus(422);
    }

    // ---------- 7.1b readiness tanpa kredensial ----------

    public function test_readiness_reports_disabled_without_key(): void
    {
        config(['services.midtrans.server_key' => '', 'services.midtrans.client_key' => '']);

        $out = app(MidtransService::class)->readiness();

        $this->assertFalse($out['enabled']);
        $this->assertFalse($out['configured']);
        $this->assertNull($out['reachable']);
    }
}
