<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Fase 16: integrasi Biteship (cek ongkir, resi otomatis, label),
 * alamat pickup seller saat registrasi, dan KYC approve/reject di admin.
 */
class Phase16Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['services.biteship.key' => 'biteship_test.unit']); // key test agar mode auto aktif
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function shop(array $over = []): array
    {
        $user = User::factory()->create(['role' => 'merchant']);
        $m = Merchant::create(array_merge([
            'user_id' => $user->id, 'name' => 'Toko', 'slug' => 'toko-'.uniqid(),
            'phone' => '0811000000', 'address' => 'Jl. Melati 1',
            'province' => 'DKI Jakarta', 'city_name' => 'Jakarta Selatan',
            'district' => 'Cilandak', 'postal_code' => '12430',
            'active' => true, 'kyc_status' => 'approved',
        ], $over));
        $p = Product::create([
            'merchant_id' => $m->id, 'title' => 'Kaos', 'slug' => 'kaos-'.uniqid(),
            'price' => 100000, 'stock' => 10, 'weight' => 200, 'status' => 'active',
        ]);

        return [$user, $m, $p];
    }

    private function orderPacked(Merchant $m, Product $p): Order
    {
        $f = app(\App\Services\FeeService::class)->line($p->price, null, 1);

        return Order::create([
            'merchant_id' => $m->id, 'order_no' => 'ORD-'.uniqid(),
            'buyer_name' => 'Budi', 'buyer_phone' => '0812000001',
            'shipping_address' => 'Jl. Kenanga 5',
            'destination_postal_code' => '12950',
            'courier' => 'jne', 'service' => 'reg', 'shipping_cost' => 20000,
            'subtotal' => $p->price, 'discount_total' => $f['discount'],
            'subtotal_sale' => $f['base_sale'], 'buyer_fee' => $f['buyer_fee'],
            'buyer_admin_fee' => $f['buyer_admin_fee'], 'seller_net' => $f['seller_net'],
            'total' => $f['base_sale'] + $f['buyer_fee'] + $f['buyer_admin_fee'] + 20000,
            'payment_status' => 'paid',
            'fulfillment_status' => 'packed', 'packed_at' => now(),
        ]);
    }

    // ---- Admin: simpan API key Biteship ----

    public function test_admin_can_store_biteship_api_key(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->putJson('/api/v1/admin/settings', [
            'biteship_api_key' => 'biteship_test.abc123',
        ])->assertOk();

        $this->assertSame(
            'biteship_test.abc123',
            $this->actingAs($admin)->getJson('/api/v1/admin/settings')->json('data.biteship_api_key'),
        );
    }

    // ---- Cek ongkir via Biteship ----

    public function test_shipping_cost_uses_biteship_rates(): void
    {
        Http::fake([
            'api.biteship.com/v1/rates/couriers' => Http::response([
                'success' => true,
                'pricing' => [[
                    'courier_code' => 'jne', 'courier_name' => 'JNE',
                    'service_name' => 'Reguler', 'service_type' => 'reg',
                    'price' => 18000, 'duration' => '2-3 days',
                ]],
            ]),
        ]);

        [, $m] = $this->shop();

        $res = $this->postJson('/api/v1/shipping/cost', [
            'merchant_slug' => $m->slug,
            'destination_postal_code' => '12950',
            'weight' => 500,
            'value' => 100000,
        ])->assertOk();

        $this->assertSame('jne', $res->json('data.services.0.courier'));
        $this->assertSame(18000, $res->json('data.services.0.cost'));
    }

    public function test_shipping_cost_requires_store_postal_code(): void
    {
        [, $m] = $this->shop(['postal_code' => null]);

        $this->postJson('/api/v1/shipping/cost', [
            'merchant_slug' => $m->slug,
            'destination_postal_code' => '12950',
            'weight' => 500,
        ])->assertStatus(422);
    }

    // ---- Ship otomatis: resi dari Biteship ----

    public function test_auto_ship_creates_biteship_order_and_resi(): void
    {
        Http::fake([
            'api.biteship.com/v1/orders' => Http::response([
                'success' => true,
                'id' => '65dd599ebdefcd4158eb8470b',
                'courier' => [
                    'waybill_id' => 'WYB-1112223333443',
                    'tracking_id' => '6de509ebdefgh4158ij3451c',
                    'company' => 'jne', 'type' => 'reg', 'routing_code' => 'CGK',
                ],
                'price' => 18000, 'status' => 'confirmed',
            ], 201),
        ]);

        [$user, $m, $p] = $this->shop();
        $order = $this->orderPacked($m, $p);

        $res = $this->actingAs($user)
            ->putJson("/api/v1/orders/{$order->id}/ship")
            ->assertOk();

        $this->assertSame('WYB-1112223333443', $res->json('data.tracking_no'));
        $this->assertSame('shipped', $res->json('data.fulfillment_status'));
        $this->assertSame('65dd599ebdefcd4158eb8470b', $res->json('data.biteship_order_id'));
        $this->assertSame('WYB-1112223333443', $res->json('label.waybill'));
        $this->assertSame('Toko', $res->json('label.sender.name'));
        $this->assertSame('Budi', $res->json('label.recipient.name'));

        // Request Biteship membawa data origin (alamat seller) & tujuan (buyer).
        $body = json_decode(Http::recorded()[0][0]->body(), true);
        $this->assertSame(12430, $body['origin_postal_code']);
        $this->assertSame(12950, $body['destination_postal_code']);
        $this->assertSame('jne', $body['courier_company']);
    }

    public function test_ship_without_api_key_returns_actionable_error(): void
    {
        config(['services.biteship.key' => '']); // pastikan kosong
        [$user, $m, $p] = $this->shop();
        $order = $this->orderPacked($m, $p);

        config(['services.biteship.key' => '']); // pastikan kosong

        $this->actingAs($user)
            ->putJson("/api/v1/orders/{$order->id}/ship")
            ->assertStatus(422)
            ->assertJsonPath('error', 'API key Biteship belum diatur. Setel di Admin → Pengaturan, atau kirim resi manual.');
    }

    public function test_manual_resi_still_works_as_fallback(): void
    {
        [$user, $m, $p] = $this->shop();
        $order = $this->orderPacked($m, $p);

        $this->actingAs($user)
            ->putJson("/api/v1/orders/{$order->id}/ship", ['tracking_no' => 'JNE999'])
            ->assertOk()
            ->assertJsonPath('data.tracking_no', 'JNE999')
            ->assertJsonPath('data.fulfillment_status', 'shipped');
    }

    // ---- KYC: registrasi pending → admin approve/reject ----

    private function register(array $over = []): mixed
    {
        return $this->post('/api/v1/auth/register', array_merge([
            'name' => 'Sari', 'email' => 'sari-'.uniqid().'@example.test',
            'password' => 'rahasia123', 'password_confirmation' => 'rahasia123',
            'store_name' => 'Toko Sari '.uniqid(),
            'phone' => '08120000002',
            'address' => 'Jl. Anggrek 2', 'province' => 'Jawa Barat',
            'city_name' => 'Bandung', 'district' => 'Coblong',
            'postal_code' => '40131', 'kyc_nik' => '3273010101010001',
            'kyc_ktp' => UploadedFile::fake()->image('ktp.jpg', 400, 250),
        ], $over), ['Accept' => 'application/json']);
    }

    public function test_kyc_pending_blocks_storefront_until_approved(): void
    {
        $this->register()->assertCreated();

        $slug = Merchant::latest('id')->first()->slug;
        $this->getJson("/api/v1/shops/{$slug}")->assertNotFound();
    }

    public function test_admin_can_approve_seller_kyc(): void
    {
        $this->register()->assertCreated();
        $merchant = Merchant::latest('id')->first();
        $admin = $this->admin();

        // Terdaftar: pending + belum aktif.
        $this->assertSame('pending', $merchant->kyc_status);
        $this->assertFalse($merchant->active);

        // Muncul di antrean KYC admin.
        $listed = $this->actingAs($admin)->getJson('/api/v1/admin/kyc?status=pending')
            ->assertOk()->json('data');
        $this->assertTrue(collect($listed)->contains('id', $merchant->id));
        $this->assertSame('3273010101010001', collect($listed)->firstWhere('id', $merchant->id)['kyc_nik']);

        // Approve → toko aktif.
        $this->actingAs($admin)->putJson("/api/v1/admin/kyc/{$merchant->id}", [
            'action' => 'approve',
        ])->assertOk()->assertJsonPath('data.kyc_status', 'approved')->assertJsonPath('data.active', true);

        $this->getJson('/api/v1/shops/'.$merchant->slug)->assertOk();
    }

    public function test_admin_can_reject_seller_kyc_with_reason(): void
    {
        $this->register()->assertCreated();
        $merchant = Merchant::latest('id')->first();
        $admin = $this->admin();

        $this->actingAs($admin)->putJson("/api/v1/admin/kyc/{$merchant->id}", [
            'action' => 'reject', 'reason' => 'Foto KTP buram.',
        ])->assertOk()->assertJsonPath('data.kyc_status', 'rejected')->assertJsonPath('data.active', false);

        $this->assertDatabaseHas('merchants', [
            'id' => $merchant->id, 'kyc_status' => 'rejected', 'kyc_reject_reason' => 'Foto KTP buram.',
        ]);
        $this->getJson('/api/v1/shops/'.$merchant->slug)->assertNotFound();
    }

    public function test_merchant_cannot_access_kyc_review(): void
    {
        [, $m] = $this->shop();
        $merchantUser = $m->user;

        $this->actingAs($merchantUser)->getJson('/api/v1/admin/kyc')->assertForbidden();
    }

    // ---- Status integrasi ----

    public function test_shipping_status_endpoint_reports_configuration(): void
    {
        $res = $this->getJson('/api/v1/shipping/status')->assertOk();
        $this->assertSame('biteship', $res->json('data.provider'));
        $this->assertArrayHasKey('configured', $res->json('data'));
    }
}
