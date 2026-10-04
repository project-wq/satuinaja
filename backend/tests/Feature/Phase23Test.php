<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\Notification;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Fase 23: restock & low-stock alert. */
class Phase23Test extends TestCase
{
    use RefreshDatabase;

    private function shop(): array
    {
        $user = User::factory()->create(['role' => 'merchant']);
        $m = Merchant::create([
            'user_id' => $user->id, 'name' => 'Toko', 'slug' => 'toko-'.uniqid(), 'active' => true,
        ]);

        return [$user, $m];
    }

    private function product(Merchant $m, int $stock = 10, int $threshold = 5): Product
    {
        return Product::create([
            'merchant_id' => $m->id, 'title' => 'Produk', 'slug' => 'produk-'.uniqid(),
            'price' => 10000, 'stock' => $stock, 'low_stock_at' => $threshold,
            'weight' => 500, 'status' => 'active',
        ]);
    }

    public function test_update_to_low_stock_sends_one_notif(): void
    {
        [$user, $m] = $this->shop();
        $p = $this->product($m, 10, 5);

        $this->actingAs($user)->putJson("/api/v1/products/{$p->id}", ['stock' => 4])->assertOk();
        $this->assertTrue($p->fresh()->low_stock_alerted);
        $this->assertSame(1, Notification::where('merchant_id', $m->id)->where('type', 'stock.low')->count());

        // update lagi di bawah threshold → tidak dobel
        $this->actingAs($user)->putJson("/api/v1/products/{$p->id}", ['stock' => 3])->assertOk();
        $this->assertSame(1, Notification::where('merchant_id', $m->id)->where('type', 'stock.low')->count());
    }

    public function test_zero_stock_auto_archives(): void
    {
        [$user, $m] = $this->shop();
        $p = $this->product($m, 10, 5);

        $this->actingAs($user)->putJson("/api/v1/products/{$p->id}", ['stock' => 0])->assertOk();
        $fresh = $p->fresh();
        $this->assertSame('archived', $fresh->status);
        $this->assertSame(1, Notification::where('merchant_id', $m->id)->where('type', 'stock.empty')->count());
    }

    public function test_restock_resets_flag(): void
    {
        [$user, $m] = $this->shop();
        $p = $this->product($m, 10, 5);

        $this->actingAs($user)->putJson("/api/v1/products/{$p->id}", ['stock' => 2])->assertOk();
        $this->assertTrue($p->fresh()->low_stock_alerted);

        $this->actingAs($user)->putJson("/api/v1/products/{$p->id}", ['stock' => 20])->assertOk();
        $this->assertFalse($p->fresh()->low_stock_alerted);

        // turun lagi → notif kedua (siklus baru)
        $this->actingAs($user)->putJson("/api/v1/products/{$p->id}", ['stock' => 1])->assertOk();
        $this->assertSame(2, Notification::where('merchant_id', $m->id)->where('type', 'stock.low')->count());
    }

    public function test_low_stock_list_and_threshold_update(): void
    {
        [$user, $m] = $this->shop();
        $p1 = $this->product($m, 2, 5);
        $this->product($m, 50, 5);

        $res = $this->actingAs($user)->getJson('/api/v1/products-alerts/low-stock')->assertOk()->json();
        $this->assertSame(1, $res['count']);
        $this->assertSame($p1->id, $res['data'][0]['id']);

        // ubah threshold → produk aman ikut masuk daftar
        $p2 = Product::withoutGlobalScope('merchant')->where('merchant_id', $m->id)->where('stock', 50)->first();
        $this->actingAs($user)->putJson("/api/v1/products/{$p2->id}", ['low_stock_at' => 60])->assertOk();
        $res2 = $this->actingAs($user)->getJson('/api/v1/products-alerts/low-stock')->json();
        $this->assertSame(2, $res2['count']);
    }
}
