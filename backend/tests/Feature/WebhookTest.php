<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\User;
use App\Services\MidtransService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebhookTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrder(): Order
    {
        $user = User::factory()->create(['role' => 'merchant']);
        $merchant = Merchant::create([
            'user_id' => $user->id, 'name' => 'Toko', 'slug' => 'toko', 'active' => true,
        ]);

        return Order::create([
            'merchant_id' => $merchant->id,
            'order_no' => 'ORD-TEST-1',
            'buyer_name' => 'Budi',
            'buyer_phone' => '08123',
            'shipping_address' => 'Jl. Test',
            'subtotal' => 100000,
            'total' => 100000,
            'payment_status' => 'unpaid',
            'fulfillment_status' => 'pending',
        ]);
    }

    public function test_webhook_rejects_request_without_valid_signature(): void
    {
        $this->makeOrder();
        config(['services.midtrans.server_key' => 'SB-Mid-server-TESTKEY']);

        $this->postJson('/api/v1/webhooks/midtrans', [
            'order_id' => 'ORD-TEST-1',
            'status_code' => '200',
            'gross_amount' => '100000.00',
            'signature_key' => 'palsu',
            'transaction_status' => 'settlement',
        ])->assertForbidden();
    }

    public function test_webhook_accepts_valid_signature_and_marks_paid(): void
    {
        $order = $this->makeOrder();
        $serverKey = 'SB-Mid-server-TESTKEY';
        config(['services.midtrans.server_key' => $serverKey]);

        $signature = hash('sha512', $order->order_no.'200'.'100000.00'.$serverKey);

        $this->postJson('/api/v1/webhooks/midtrans', [
            'order_id' => $order->order_no,
            'status_code' => '200',
            'gross_amount' => '100000.00',
            'signature_key' => $signature,
            'transaction_status' => 'settlement',
        ])->assertOk();

        $this->assertSame('paid', $order->fresh()->payment_status);
    }

    public function test_expired_transaction_marks_order_expired(): void
    {
        $order = $this->makeOrder();
        $serverKey = 'SB-Mid-server-TESTKEY';
        config(['services.midtrans.server_key' => $serverKey]);

        $signature = hash('sha512', $order->order_no.'200'.'100000.00'.$serverKey);

        $this->postJson('/api/v1/webhooks/midtrans', [
            'order_id' => $order->order_no,
            'status_code' => '200',
            'gross_amount' => '100000.00',
            'signature_key' => $signature,
            'transaction_status' => 'expire',
        ])->assertOk();

        $this->assertSame('expired', $order->fresh()->payment_status);
    }

    public function test_signature_of_another_key_is_rejected(): void
    {
        $order = $this->makeOrder();
        config(['services.midtrans.server_key' => 'SB-Mid-server-TESTKEY']);

        $wrong = hash('sha512', $order->order_no.'200'.'100000.00'.'KEY-PALSU');

        $this->postJson('/api/v1/webhooks/midtrans', [
            'order_id' => $order->order_no,
            'status_code' => '200',
            'gross_amount' => '100000.00',
            'signature_key' => $wrong,
            'transaction_status' => 'settlement',
        ])->assertForbidden();
    }
}
