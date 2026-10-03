<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\PublishLog;
use App\Models\User;
use App\Services\StockSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Fase 9: fix update_item item_id + product_hashes cast array.
 */
class Phase9Test extends TestCase
{
    use RefreshDatabase;

    private function merchant(): Merchant
    {
        $user = User::factory()->create(['role' => 'merchant']);

        return Merchant::create([
            'user_id' => $user->id, 'name' => 'Toko', 'slug' => 'toko-'.uniqid(), 'active' => true,
        ]);
    }

    private function product(Merchant $m): Product
    {
        return Product::create([
            'merchant_id' => $m->id,
            'title' => 'Produk',
            'slug' => 'sku-'.uniqid(),
            'price' => 20000,
            'stock' => 4,
            'weight' => 500,
            'status' => 'active',
        ]);
    }

    private function channel(Merchant $m): Channel
    {
        return Channel::create([
            'merchant_id' => $m->id,
            'platform' => 'shopee',
            'label' => 'Shopee',
            'active' => true,
            'credentials' => [
                'partner_id' => '10001',
                'partner_key' => 'rahasia-partner',
                'shop_id' => '20002',
                'access_token' => 'tok-abc',
                'sandbox' => '1',
            ],
            'product_hashes' => ['7' => 'abc123'],
        ]);
    }

    public function test_sync_sends_real_item_id_from_publish_log(): void
    {
        Http::fake([
            '*/api/v2/product/update_item*' => Http::response(['response' => ['success' => true]], 200),
        ]);

        $m = $this->merchant();
        $p = $this->product($m);
        $c = $this->channel($m);

        // Produk sudah pernah publish → external_id di log.
        PublishLog::create([
            'product_id' => $p->id, 'channel_id' => $c->id,
            'status' => 'success', 'external_id' => '777', 'published_at' => now(),
        ]);

        $r = app(StockSyncService::class)->sync($p);

        $this->assertTrue($r['shopee']['ok']);

        Http::assertSent(fn ($req) => str_contains($req->url(), '/api/v2/product/update_item')
            && (int) data_get($req->data(), 'item_id') === 777
            && (int) data_get($req->data(), 'stock') === 4);
    }

    public function test_sync_falls_back_to_zero_when_never_published(): void
    {
        Http::fake([
            '*/api/v2/product/update_item*' => Http::response(['response' => ['success' => true]], 200),
        ]);

        $m = $this->merchant();
        $p = $this->product($m);
        $this->channel($m);

        app(StockSyncService::class)->sync($p);

        Http::assertSent(fn ($req) => str_contains($req->url(), '/api/v2/product/update_item')
            && (int) data_get($req->data(), 'item_id') === 0);
    }

    public function test_channel_product_hashes_cast_to_array(): void
    {
        $m = $this->merchant();
        $c = $this->channel($m);

        $this->assertIsArray($c->fresh()->product_hashes);
        $this->assertSame('abc123', $c->fresh()->product_hashes['7']);
    }
}