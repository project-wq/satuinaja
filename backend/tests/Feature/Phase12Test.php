<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PublishLog;
use App\Models\User;
use App\Services\ShopeeService;
use App\Services\StockSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Fase 12: sinkron varian ke Shopee (init_tier_variation + update_stock/
 * update_price per-model + pull get_model_list).
 */
class Phase12Test extends TestCase
{
    use RefreshDatabase;

    private function makeSetup(array $variants): array
    {
        $user = User::factory()->create(['role' => 'merchant']);
        $m = Merchant::create([
            'user_id' => $user->id, 'name' => 'Toko', 'slug' => 'toko-'.uniqid(), 'active' => true,
        ]);
        $p = Product::create([
            'merchant_id' => $m->id,
            'title' => 'Kaos', 'slug' => 'kaos-'.uniqid(),
            'price' => 50000, 'stock' => 0, 'weight' => 200,
            'status' => 'active',
        ]);
        $total = 0;
        foreach ($variants as $i => $v) {
            ProductVariant::create([
                'product_id' => $p->id,
                'name' => $v['name'], 'sku' => $v['sku'] ?? null,
                'price' => $v['price'] ?? null, 'stock' => $v['stock'],
                'position' => $i,
            ]);
            $total += $v['stock'];
        }
        $p->update(['stock' => $total]);

        $c = Channel::create([
            'merchant_id' => $m->id, 'platform' => 'shopee', 'label' => 'Shopee',
            'active' => true,
            'credentials' => [
                'partner_id' => '10001', 'partner_key' => 'rahasia',
                'shop_id' => '20002', 'access_token' => 'tok', 'sandbox' => '1',
            ],
        ]);

        return [$m, $p, $c];
    }

    private function publishSuccess(Product $p, Channel $c, string $itemId = '777'): void
    {
        PublishLog::create([
            'product_id' => $p->id, 'channel_id' => $c->id,
            'status' => 'success', 'external_id' => $itemId,
            'published_at' => now(),
        ]);
    }

    // ---------- publish + init_tier ----------

    public function test_publish_with_variants_calls_init_tier_and_maps_model_ids(): void
    {
        [, $p, $c] = $this->makeSetup([
            ['name' => 'M', 'sku' => 'KAOS-M', 'stock' => 4],
            ['name' => 'L', 'sku' => 'KAOS-L', 'stock' => 6, 'price' => 55000],
        ]);

        Http::fake([
            '*/api/v2/product/add_item*' => Http::response(['response' => ['item_id' => 777]], 200),
            '*/api/v2/product/init_tier_variation*' => Http::response(['response' => [
                'item_id' => 777,
                'model' => [
                    ['model_id' => 9001, 'tier_index' => [0]],
                    ['model_id' => 9002, 'tier_index' => [1]],
                ],
            ]], 200),
        ]);

        $r = app(ShopeeService::class)->publish($p, $c);

        $this->assertTrue($r['ok']);
        $this->assertSame('777', $r['external_id']);

        // Payload init_tier: 1 tier "Varian", 2 opsi, 2 model harga efektif.
        Http::assertSent(fn ($req) => str_contains($req->url(), '/api/v2/product/init_tier_variation')
            && data_get($req->data(), 'item_id') === 777
            && data_get($req->data(), 'tier_variation.0.name') === 'Varian'
            && data_get($req->data(), 'tier_variation.0.option_list') === [['option' => 'M'], ['option' => 'L']]
            && data_get($req->data(), 'model.0.tier_index') === [0]
            && data_get($req->data(), 'model.0.original_price') == 50000 // fallback harga produk
            && data_get($req->data(), 'model.1.original_price') == 55000 // harga varian
            && data_get($req->data(), 'model.0.seller_stock') === [['stock' => 4]]
            && data_get($req->data(), 'model.0.model_sku') === 'KAOS-M');

        // model_id terpetakan balik ke varian lokal.
        $this->assertSame('9001', $p->variants()->where('name', 'M')->first()->fresh()->shopee_model_id);
        $this->assertSame('9002', $p->variants()->where('name', 'L')->first()->fresh()->shopee_model_id);
    }

    public function test_publish_without_variants_skips_init_tier(): void
    {
        [, $p, $c] = $this->makeSetup([]);
        $p->update(['stock' => 5]); // produk tanpa varian

        Http::fake([
            '*/api/v2/product/add_item*' => Http::response(['response' => ['item_id' => 101]], 200),
        ]);

        $r = app(ShopeeService::class)->publish($p, $c);

        $this->assertTrue($r['ok']);
        Http::assertNotSent(fn ($req) => str_contains($req->url(), '/api/v2/product/init_tier_variation'));
    }

    public function test_publish_succeeds_even_when_init_tier_fails(): void
    {
        [, $p, $c] = $this->makeSetup([['name' => 'M', 'stock' => 3]]);

        Http::fake([
            '*/api/v2/product/add_item*' => Http::response(['response' => ['item_id' => 202]], 200),
            '*/api/v2/product/init_tier_variation*' => Http::response(['error' => 'tier_invalid', 'message' => 'bad'], 400),
        ]);

        // Item tetap tayang (tanpa tier); varian belum terpetakan.
        $r = app(ShopeeService::class)->publish($p, $c);

        $this->assertTrue($r['ok']);
        $this->assertSame('202', $r['external_id']);
        $this->assertArrayNotHasKey('variant', $r);
        $this->assertNull($p->variants()->first()->fresh()->shopee_model_id);
    }

    // ---------- sync push per-model ----------

    private function mappedSetup(): array
    {
        [$m, $p, $c] = $this->makeSetup([
            ['name' => 'M', 'sku' => 'KAOS-M', 'stock' => 4],
            ['name' => 'L', 'sku' => 'KAOS-L', 'stock' => 6, 'price' => 55000],
        ]);
        $p->variants()->where('name', 'M')->update(['shopee_model_id' => '9001']);
        $p->variants()->where('name', 'L')->update(['shopee_model_id' => '9002']);
        $this->publishSuccess($p, $c);

        return [$m, $p, $c];
    }

    public function test_sync_pushes_stock_and_price_per_model(): void
    {
        [, $p, $c] = $this->mappedSetup();

        Http::fake([
            '*/api/v2/product/update_stock*' => Http::response(['response' => ['success_list' => [['model_id' => 9001], ['model_id' => 9002]]]], 200),
            '*/api/v2/product/update_price*' => Http::response(['response' => ['success_list' => [['model_id' => 9001], ['model_id' => 9002]]]], 200),
        ]);

        $r = app(StockSyncService::class)->sync($p->fresh());

        $this->assertTrue($r['shopee']['ok']);

        Http::assertSent(fn ($req) => str_contains($req->url(), '/api/v2/product/update_stock')
            && data_get($req->data(), 'item_id') === 777
            && data_get($req->data(), 'stock_list') === [
                ['model_id' => 9001, 'seller_stock' => [['stock' => 4]]],
                ['model_id' => 9002, 'seller_stock' => [['stock' => 6]]],
            ]);
        Http::assertSent(fn ($req) => str_contains($req->url(), '/api/v2/product/update_price')
            && data_get($req->data(), 'price_list') === [
                ['model_id' => 9001, 'original_price' => 50000.0],
                ['model_id' => 9002, 'original_price' => 55000.0],
            ]);
        // Produk bervarian terpetakan tidak lagi memakai update_item agregat.
        Http::assertNotSent(fn ($req) => str_contains($req->url(), '/api/v2/product/update_item'));
    }

    public function test_sync_stock_only_skips_price_when_content_held(): void
    {
        [, $p] = $this->mappedSetup();

        Http::fake([
            '*/api/v2/product/update_stock*' => Http::response(['response' => ['success_list' => []]], 200),
        ]);

        // Anti-flap Fase 10: includeContent=false → stok saja.
        $r = app(StockSyncService::class)->sync($p->fresh(), false);

        $this->assertTrue($r['shopee']['ok']);
        Http::assertSent(fn ($req) => str_contains($req->url(), '/api/v2/product/update_stock'));
        Http::assertNotSent(fn ($req) => str_contains($req->url(), '/api/v2/product/update_price'));
    }

    public function test_sync_reports_failure_list_from_shopee(): void
    {
        [, $p] = $this->mappedSetup();

        Http::fake([
            '*/api/v2/product/update_stock*' => Http::response(['response' => [
                'failure_list' => [['model_id' => 9002, 'failed_reason' => 'locked']],
            ]], 200),
        ]);

        $r = app(StockSyncService::class)->sync($p->fresh(), false);

        $this->assertFalse($r['shopee']['ok']);
        $this->assertStringContainsString('9002', $r['shopee']['error']);
    }

    public function test_sync_unmapped_variants_falls_back_to_update_item(): void
    {
        // Varian ada tapi belum terpetakan (publish gagal init_tier) → agregat.
        [, $p] = $this->makeSetup([['name' => 'M', 'stock' => 3]]);
        $c = Channel::where('platform', 'shopee')->first();
        $this->publishSuccess($p, $c);

        Http::fake([
            '*/api/v2/product/update_item*' => Http::response(['response' => ['success' => true]], 200),
        ]);

        $r = app(StockSyncService::class)->sync($p->fresh());

        $this->assertTrue($r['shopee']['ok']);
        Http::assertSent(fn ($req) => str_contains($req->url(), '/api/v2/product/update_item')
            && data_get($req->data(), 'stock') === 3);
    }

    // ---------- pull per-model ----------

    public function test_pull_updates_each_variant_stock_and_reaggregates(): void
    {
        [, $p, $c] = $this->mappedSetup();

        Http::fake([
            '*/api/v2/product/get_model_list*' => Http::response(['response' => [
                'tier_variation' => [['name' => 'Varian', 'option_list' => [['option' => 'M'], ['option' => 'L']]]],
                'model' => [
                    ['model_id' => 9001, 'tier_index' => [0], 'model_sku' => 'KAOS-M',
                        'stock_info_v2' => ['seller_stock' => [['stock' => 2]]]],
                    ['model_id' => 9002, 'tier_index' => [1], 'model_sku' => 'KAOS-L',
                        'stock_info_v2' => ['seller_stock' => [['stock' => 8]]]],
                ],
            ]], 200),
        ]);

        $r = app(StockSyncService::class)->pull($p->fresh());

        $this->assertTrue($r['shopee']['ok']);
        $this->assertSame(2, $r['shopee']['variants_updated']);
        $this->assertSame(2, $p->variants()->where('name', 'M')->first()->fresh()->stock);
        $this->assertSame(8, $p->variants()->where('name', 'L')->first()->fresh()->stock);
        $this->assertSame(10, $p->fresh()->stock); // agregat = 2 + 8
    }

    public function test_pull_maps_by_sku_when_model_id_missing(): void
    {
        [, $p] = $this->mappedSetup();
        // Hapus mapping model_id, biarkan sku → fallback by sku.
        $p->variants()->update(['shopee_model_id' => null]);
        $p->variants()->where('name', 'M')->update(['shopee_model_id' => '0000']);

        Http::fake([
            '*/api/v2/product/get_model_list*' => Http::response(['response' => [
                'model' => [
                    ['model_id' => 9001, 'model_sku' => 'KAOS-M',
                        'stock_info_v2' => ['seller_stock' => [['stock' => 5]]]],
                ],
            ]], 200),
        ]);

        $r = app(StockSyncService::class)->pull($p->fresh());

        $this->assertTrue($r['shopee']['ok']);
        $this->assertSame(1, $r['shopee']['variants_updated']);
        // M: 5 (dari Shopee via sku); L: tetap 6 (tak ada di respons).
        $this->assertSame(5, $p->variants()->where('name', 'M')->first()->fresh()->stock);
        $this->assertSame(6, $p->variants()->where('name', 'L')->first()->fresh()->stock);
    }
}
