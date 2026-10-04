<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherRedemption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 14: promo — voucher produk/toko/platform + gratis ongkir
 * di checkout, kuota/limit pembeli, CRUD seller & admin.
 */
class Phase14Test extends TestCase
{
    use RefreshDatabase;

    private ?Merchant $lastMerchant = null;

    private function shop(): array
    {
        $user = User::factory()->create(['role' => 'merchant']);
        $m = Merchant::create([
            'user_id' => $user->id, 'name' => 'Toko', 'slug' => 'toko-'.uniqid(), 'active' => true,
        ]);
        $p = Product::create([
            'merchant_id' => $m->id,
            'title' => 'Kaos', 'slug' => 'kaos-'.uniqid(),
            'price' => 100000, 'stock' => 10, 'weight' => 200,
            'status' => 'active',
        ]);
        $this->lastMerchant = $m;

        return [$user, $m, $p];
    }

    private function voucher(array $over = []): Voucher
    {
        return Voucher::create(array_merge([
            'scope' => 'shop', 'merchant_id' => $this->lastMerchant?->id,
            'code' => 'HEMAT10', 'name' => 'Hemat 10%',
            'type' => 'percent', 'value' => 10, 'max_discount' => 5000,
            'active' => true,
        ], $over));
    }

    private function checkoutPayload(Merchant $m, Product $p, ?string $code = null): array
    {
        return array_filter([
            'merchant_slug' => $m->slug,
            'buyer_name' => 'Budi', 'buyer_phone' => '0812000001',
            'shipping_address' => 'Jl. Melati 1', 'destination_city_id' => '3171',
            'courier' => 'jne', 'service' => 'REG', 'shipping_cost' => 20000,
            'items' => [['product_id' => $p->id, 'qty' => 2]],
            'voucher_code' => $code,
        ], fn ($v) => $v !== null);
    }

    // ---------- preview ----------

    public function test_preview_applies_percent_voucher_with_cap(): void
    {
        [, $m, $p] = $this->shop();
        $this->voucher(); // 10%, max 5000

        $res = $this->postJson('/api/v1/checkout/preview', $this->checkoutPayload($m, $p, 'hemat10'));

        $res->assertOk();
        $d = $res['data'];
        $this->assertSame(200000, $d['subtotal']);          // 2 x 100k
        $this->assertSame(5000, $d['voucher']['discount']);  // 10% = 20k → cap 5k
        $this->assertSame(20000, $d['shipping_cost']);
        // total = sale 200k + fee 11% + admin 2000 + ongkir 20k − voucher 5k
        $this->assertSame(200000 + 22000 + 2000 + 20000 - 5000, $d['total']);
    }

    public function test_preview_rejects_voucher_below_min_spend(): void
    {
        [, $m, $p] = $this->shop();
        $this->voucher(['min_spend' => 500000]);

        $res = $this->postJson('/api/v1/checkout/preview', $this->checkoutPayload($m, $p, 'HEMAT10'));

        $res->assertOk();
        $this->assertNotNull($res['data']['voucher_disallowed']);
        $this->assertSame(0, $res['data']['voucher']['discount']);
    }

    public function test_preview_free_shipping_voucher_zeroes_ongkir(): void
    {
        [, $m, $p] = $this->shop();
        $this->voucher(['type' => 'fixed', 'value' => 1, 'free_shipping' => true, 'max_discount' => null]);

        $res = $this->postJson('/api/v1/checkout/preview', $this->checkoutPayload($m, $p, 'HEMAT10'));

        $res->assertOk();
        $this->assertTrue($res['data']['voucher']['free_shipping']);
        $this->assertSame(0, $res['data']['shipping_cost']);
    }

    // ---------- checkout ----------

    public function test_checkout_redeems_voucher_and_discounts_total(): void
    {
        [, $m, $p] = $this->shop();
        $v = $this->voucher(); // 10% max 5000

        $res = $this->postJson('/api/v1/checkout', $this->checkoutPayload($m, $p, 'HEMAT10'));

        $res->assertCreated();
        $order = Order::where('order_no', $res['data']['order_no'])->firstOrFail();

        $this->assertSame(5000, $order->voucher_discount);
        $this->assertSame('HEMAT10', $order->voucher_code);
        $this->assertSame($v->id, $order->voucher_id);
        $this->assertSame(0, $order->shipping_discount); // HEMAT10 bukan gratis ongkir
        $this->assertSame(200000 + 22000 + 2000 + 20000 - 5000, $order->total);

        // Kuota terpakai + redemption tercatat.
        $this->assertSame(1, $v->fresh()->used);
        $this->assertDatabaseHas('voucher_redemptions', [
            'voucher_id' => $v->id, 'order_id' => $order->id, 'buyer_phone' => '0812000001',
        ]);
    }

    public function test_checkout_quota_exhausted_ignores_voucher(): void
    {
        [, $m, $p] = $this->shop();
        $v = $this->voucher(['quota' => 1, 'used' => 1]);

        $res = $this->postJson('/api/v1/checkout', $this->checkoutPayload($m, $p, 'HEMAT10'));

        $res->assertCreated();
        $order = Order::where('order_no', $res['data']['order_no'])->firstOrFail();

        $this->assertNull($order->voucher_id);
        $this->assertSame(0, $order->voucher_discount);
        $this->assertSame(1, $v->fresh()->used); // kuota tak bertambah
    }

    public function test_max_per_buyer_blocks_second_redemption(): void
    {
        [, $m, $p] = $this->shop();
        $v = $this->voucher(['max_per_buyer' => 1, 'quota' => 10]);

        $this->postJson('/api/v1/checkout', $this->checkoutPayload($m, $p, 'HEMAT10'))
            ->assertCreated();

        $payload = $this->checkoutPayload($m, $p, 'HEMAT10');
        $payload['buyer_phone'] = '0812000001'; // pembeli sama

        $res = $this->postJson('/api/v1/checkout', $payload);
        $res->assertCreated();

        $second = Order::latest('id')->firstOrFail();
        $this->assertNull($second->voucher_id);
        $this->assertSame(0, $second->voucher_discount);
    }

    public function test_voucher_product_scope_ignores_other_products(): void
    {
        [, $m, $p] = $this->shop();
        $other = Product::create([
            'merchant_id' => $m->id, 'title' => 'Celana', 'slug' => 'celana-'.uniqid(),
            'price' => 80000, 'stock' => 5, 'weight' => 300, 'status' => 'active',
        ]);
        $this->voucher(['scope' => 'product', 'product_id' => $other->id]);

        // Keranjang berisi Kaos saja → voucher produk Celana tak ditemukan.
        $res = $this->postJson('/api/v1/checkout/preview', $this->checkoutPayload($m, $p, 'HEMAT10'));
        $res->assertOk();
        $this->assertNull($res['data']['voucher']);
        $this->assertSame(200000 + 22000 + 2000 + 20000, $res['data']['total']);
    }

    // ---------- kuota / voucher ----------

    public function test_voucher_platform_redeems_across_merchants(): void
    {
        [, $m, $p] = $this->shop();
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->postJson('/api/v1/admin/vouchers', [
            'scope' => 'platform', 'code' => 'LINTAS', 'name' => 'Lintas',
            'type' => 'fixed', 'value' => 7000, 'max_discount' => null, 'active' => true,
        ])->assertStatus(201);

        $res = $this->postJson('/api/v1/checkout', $this->checkoutPayload($m, $p, 'LINTAS'));
        $res->assertCreated();

        $order = Order::where('order_no', $res['data']['order_no'])->firstOrFail();
        $this->assertSame(7000, $order->voucher_discount);
    }

    public function test_seller_cannot_create_platform_voucher(): void
    {
        [$user] = $this->shop();

        $this->actingAs($user)->postJson('/api/v1/vouchers', [
            'scope' => 'platform', 'code' => 'ABAL', 'name' => 'Abal',
            'type' => 'fixed', 'value' => 1000,
        ])->assertStatus(422);
    }

    public function test_seller_crud_scoped_to_own_vouchers(): void
    {
        [$user, $m] = $this->shop();
        $own = $this->voucher();

        // Voucher milik merchant lain.
        $otherUser = User::factory()->create(['role' => 'merchant']);
        $om = Merchant::create([
            'user_id' => $otherUser->id, 'name' => 'Lain',
            'slug' => 'lain-'.uniqid(), 'active' => true,
        ]);
        $other = Voucher::create([
            'scope' => 'shop', 'merchant_id' => $om->id, 'code' => 'ORANGLAIN',
            'name' => 'Orang Lain', 'type' => 'fixed', 'value' => 1000, 'active' => true,
        ]);

        $this->actingAs($user)->getJson("/api/v1/vouchers/{$own->id}")->assertOk();

        // Global scope merchant: voucher lain tak terjangkau.
        $this->actingAs($user)->getJson("/api/v1/vouchers/{$other->id}")->assertNotFound();

        $this->actingAs($user)
            ->putJson("/api/v1/vouchers/{$own->id}", ['name' => 'Nama Baru'])
            ->assertOk();
        $this->assertSame('Nama Baru', $own->fresh()->name);
    }

    public function test_public_voucher_list_hides_drafts(): void
    {
        [, $m, $p] = $this->shop();
        $this->voucher(['code' => 'TAYANG']);
        $this->voucher(['code' => 'MATI', 'active' => false]);

        $res = $this->getJson('/api/v1/vouchers/public?merchant_slug='.$m->slug);
        $res->assertOk();
        $codes = array_column($res['data'], 'code');

        $this->assertContains('TAYANG', $codes);
        $this->assertNotContains('MATI', $codes);
    }

    public function test_voucher_check_endpoint(): void
    {
        [, $m, $p] = $this->shop();
        $this->voucher();

        $ok = $this->postJson('/api/v1/vouchers/check', [
            'merchant_slug' => $m->slug, 'code' => 'hemat10',
            'items' => [['product_id' => $p->id, 'qty' => 2]],
            'shipping_cost' => 20000,
        ]);
        $ok->assertOk();
        $this->assertTrue($ok['data']['valid']);
        $this->assertSame(5000, $ok['data']['discount']);

        $bad = $this->postJson('/api/v1/vouchers/check', [
            'merchant_slug' => $m->slug, 'code' => 'TIDAKADA',
            'items' => [['product_id' => $p->id, 'qty' => 1]],
        ]);
        $bad->assertOk();
        $this->assertFalse($bad['data']['valid']);
    }
}
