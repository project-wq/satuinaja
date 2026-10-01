<?php

namespace Tests\Feature\Billing;

use App\Models\Channel;
use App\Models\Merchant;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 4: langganan plan, batas produk/channel/publish, webhook settlement.
 * Semua hardening tanpa menyentuh jaringan luar (Midtrans di-fake via config).
 */
class BillingPlanTest extends TestCase
{
    use RefreshDatabase;

    private function user(): array
    {
        Plan::factory()->create(['code' => 'free', 'price_monthly' => 0, 'max_products' => 1, 'max_channels' => 1]);
        Plan::factory()->create(['code' => 'pro', 'price_monthly' => 99000, 'max_products' => 5, 'max_channels' => 3]);

        $user = User::factory()->create(['role' => 'merchant']);
        $merchant = Merchant::factory()->create([
            'user_id' => $user->id,
            'plan_code' => 'free',
            'active' => true,
        ]);

        return [$user, $merchant];
    }

    private function userWithPlans(string $freeCode, string $proCode): array
    {
        Plan::factory()->create(['code' => $freeCode, 'price_monthly' => 0, 'max_products' => 1, 'max_channels' => 1]);
        Plan::factory()->create(['code' => $proCode, 'price_monthly' => 99000, 'max_products' => 5, 'max_channels' => 3]);

        $user = User::factory()->create(['role' => 'merchant']);
        $merchant = Merchant::factory()->create([
            'user_id' => $user->id,
            'plan_code' => 'free',
            'active' => true,
        ]);

        return [$user, $merchant];
    }

    public function test_free_plan_limits_products(): void
    {
        [$user, $merchant] = $this->user();
        // 1 produk dulu (batas free = 1).
        Product::factory()->create(['merchant_id' => $merchant->id]);

        $this->actingAs($user)
            ->postJson('/api/v1/products', [
                'title' => 'Produk kedua',
                'price' => 50000,
                'stock' => 5,
                'weight' => 1000,
            ])
            ->assertStatus(403)
            ->assertJson(['error' => 'Batas produk plan free: maksimal 1. Upgrade di halaman Langganan.']);
    }

    public function test_free_plan_limits_active_channels(): void
    {
        [$user, $merchant] = $this->user();
        Channel::factory()->create(['merchant_id' => $merchant->id, 'platform' => 'facebook', 'active' => true]);

        // Channel aktif kedua ditolak (max_channels free = 1).
        $c = Channel::factory()->create(['merchant_id' => $merchant->id, 'platform' => 'instagram', 'active' => false]);

        $this->actingAs($user)
            ->putJson("/api/v1/channels/{$c->id}", ['active' => true])
            ->assertStatus(403)
            ->assertJson(['error' => 'Batas channel aktif plan free: maksimal 1. Upgrade di halaman Langganan.']);
    }

    public function test_subscribe_creates_pending_subscription_without_midtrans_key(): void
    {
        // Tanpa MIDTRANS_SERVER_KEY → error jelas, bukan 500.
        config(['services.midtrans.server_key' => '']);

        [$user, $merchant] = $this->user();

        $this->actingAs($user)
            ->postJson('/api/v1/billing/subscribe', ['plan_code' => 'pro'])
            ->assertStatus(422)
            ->assertJson(['error' => 'MIDTRANS_SERVER_KEY belum diset.']);

        $this->assertDatabaseHas('subscriptions', [
            'merchant_id' => $merchant->id,
            'plan_code' => 'pro',
            'status' => 'failed',
        ]);
    }

    public function test_midtrans_settlement_activates_subscription(): void
    {
        [$user, $merchant] = $this->user();

        $sub = Subscription::create([
            'merchant_id' => $merchant->id,
            'plan_code' => 'pro',
            'payment_ref' => 'ORD-SUB-123',
            'status' => 'pending',
        ]);

        // Signature valid (order_id.status_code.gross_amount.server_key).
        $payload = [
            'order_id' => 'ORD-SUB-123',
            'transaction_status' => 'settlement',
            'fraud_status' => 'accept',
            'status_code' => '200',
            'gross_amount' => '99000.00',
            'transaction_id' => 'trx-1',
            'payment_type' => 'credit_card',
        ];
        $key = 'SB-Mid-server-fake-key-1234567890';
        config([
            'services.midtrans.server_key' => $key,
            'services.midtrans.sandbox' => true,
        ]);
        $payload['signature_key'] = hash('sha512', 'ORD-SUB-123'.'200'.'99000.00'.$key);

        $this->postJson('/api/v1/webhooks/midtrans', $payload)->assertOk();

        $this->assertDatabaseHas('subscriptions', [
            'id' => $sub->id,
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('merchants', [
            'id' => $merchant->id,
            'plan_code' => 'pro',
        ]);
    }

    public function test_admin_panel_requires_admin_role(): void
    {
        [$user, $merchant] = $this->user();

        $this->actingAs($user)
            ->getJson('/api/v1/admin/stats')
            ->assertForbidden();
    }

    public function test_admin_can_change_plan(): void
    {
        [$user, $merchant] = $this->user();
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->putJson("/api/v1/admin/merchants/{$merchant->id}/plan", ['plan_code' => 'pro'])
            ->assertOk()
            ->assertJson(['data' => ['plan_code' => 'pro']]);

        $this->assertDatabaseHas('merchants', [
            'id' => $merchant->id,
            'plan_code' => 'pro',
        ]);
    }
}