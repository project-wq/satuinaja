<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\FeeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Fase 21: analytics — conversion_rate + compare=prev. */
class Phase21Test extends TestCase
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

    private function order(Merchant $m, Product $p, string $payment = 'paid', ?string $created = null): Order
    {
        $f = (new FeeService)->line($p->price, $p->discount_price, 1);
        $attrs = [
            'merchant_id' => $m->id, 'order_no' => 'ORD-'.uniqid(),
            'buyer_name' => 'Budi', 'buyer_phone' => '0812', 'shipping_address' => 'Jl',
            'shipping_cost' => 5000, 'subtotal' => 10000,
            'discount_total' => $f['discount'], 'subtotal_sale' => $f['base_sale'],
            'buyer_fee' => $f['buyer_fee'], 'buyer_admin_fee' => $f['buyer_admin_fee'],
            'seller_net' => $f['seller_net'], 'total' => $f['buyer_line_total'] + 5000,
            'payment_status' => $payment, 'fulfillment_status' => 'pending',
        ];
        if ($created) {
            $attrs['created_at'] = $created;
            $attrs['updated_at'] = $created;
        }
        $o = Order::withoutGlobalScope('merchant')->create($attrs);
        if ($created) {
            // Eloquent create() overwrite created_at via freshTimestamp → update via query builder.
            Order::withoutGlobalScope('merchant')->where('id', $o->id)
                ->update(['created_at' => $created, 'updated_at' => $created]);
            $o = Order::withoutGlobalScope('merchant')->find($o->id);
        }
        $o->items()->create([
            'product_id' => $p->id, 'title' => $p->title, 'price' => $p->price,
            'qty' => 1, 'line_total' => $f['base_sale'],
            'buyer_fee' => $f['buyer_fee'], 'buyer_admin_fee' => $f['buyer_admin_fee'],
            'seller_net' => $f['seller_net'],
        ]);

        return $o;
    }

    public function test_summary_has_conversion_rate(): void
    {
        [$user, $m, $p] = $this->shop();
        $this->order($m, $p, 'paid');
        $this->order($m, $p, 'unpaid');

        $res = $this->actingAs($user)->getJson('/api/v1/reports/sales?period=30d')->json('data');
        // 1 paid dari 2 order = 50%
        $this->assertEquals(50, $res['summary']['conversion_rate']);
    }

    public function test_compare_prev_returns_delta(): void
    {
        [$user, $m, $p] = $this->shop();
        $this->order($m, $p, 'paid'); // hari ini
        $this->order($m, $p, 'paid', now()->subDays(40)->toDateTimeString()); // periode lalu

        $res = $this->actingAs($user)
            ->getJson('/api/v1/reports/sales?period=30d&compare=prev')
            ->json('data');

        $this->assertArrayHasKey('previous', $res);
        $this->assertArrayHasKey('delta', $res);
        // periode lalu ada 1 paid, sekarang 1 paid → delta 0
        $this->assertSame(0, $res['delta']['orders_paid']);
        $this->assertSame(1, $res['previous']['orders_paid']);
    }
}
