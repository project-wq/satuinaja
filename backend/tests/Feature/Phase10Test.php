<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Fase 10: deteksi perubahan produk (hash) + anti-flap sinkronisasi.
 */
class Phase10Test extends TestCase
{
    use RefreshDatabase;

    private function seedShop(): array
    {
        $user = User::factory()->create(['role' => 'merchant']);
        $m = Merchant::create([
            'user_id' => $user->id, 'name' => 'Toko', 'slug' => 'toko-'.uniqid(), 'active' => true,
        ]);
        $p = Product::create([
            'merchant_id' => $m->id,
            'title' => 'Produk', 'slug' => 'sku-'.uniqid(),
            'price' => 20000, 'stock' => 4, 'weight' => 500,
            'status' => 'active',
        ]);
        $c = Channel::create([
            'merchant_id' => $m->id, 'platform' => 'shopee',
            'label' => 'Shopee', 'active' => true,
            'credentials' => [
                'partner_id' => '10001', 'partner_key' => 'rahasia-partner',
                'shop_id' => '20002', 'access_token' => 'tok-abc', 'sandbox' => '1',
            ],
        ]);

        return [$m, $p, $c];
    }

    public function test_sync_stock_pushes_when_content_changed(): void
    {
        Http::fake([
            '*/api/v2/product/update_item*' => Http::response(['response' => ['success' => true]], 200),
        ]);

        [$m, $p, $c] = $this->seedShop();

        $this->artisan('satu:sync-stock')->assertExitCode(0);

        // Pertama kali: ada perubahan (hash null) → push dipanggil.
        Http::assertSentCount(1);

        // Hash tersimpan dengan struktur lengkap.
        $fresh = $c->fresh();
        $entry = $fresh->product_hashes[$p->id];
        $this->assertIsArray($entry);
        $this->assertNotEmpty($entry['hash']);
        $this->assertArrayHasKey('updated_at', $entry);

        // Tidak ada perubahan → run kedua tidak push lagi.
        $this->artisan('satu:sync-stock')->assertExitCode(0);
        Http::assertSentCount(1);
    }

    public function test_sync_stock_repushes_after_content_change(): void
        {
            Http::fake([
                '*/api/v2/product/update_item*' => Http::response(['response' => ['success' => true]], 200),
            ]);

            [, $p] = $this->seedShop();

            $this->artisan('satu:sync-stock')->assertExitCode(0);
            Http::assertSentCount(1);

            // Edit harga → hash beda → push lagi.
            $p->update(['price' => 21000]);
            $this->artisan('satu:sync-stock')->assertExitCode(0);
            Http::assertSentCount(2);
        }

        public function test_sync_stock_pushes_content_when_no_sync_window(): void
        {
            Http::fake([
                '*/api/v2/product/update_item*' => Http::response(['response' => ['success' => true]], 200),
            ]);

            [, $p, $c] = $this->seedShop();

            // Hash lama tersimpan tapi tanpa synced_at → tidak dalam
            // jendela flap → konten ikut terkirim penuh.
            $c->update(['product_hashes' => [$p->id => ['hash' => 'stale']]]);

            $this->artisan('satu:sync-stock')->assertExitCode(0);

            Http::assertSent(fn ($req) => str_contains($req->url(), '/api/v2/product/update_item')
                && data_get($req->data(), 'item_name') === 'Produk'
                && (float) data_get($req->data(), 'original_price') === 20000.0);
        }

        public function test_sync_stock_holds_content_within_flap_window(): void
        {
            Http::fake([
                '*/api/v2/product/update_item*' => Http::response(['response' => ['success' => true]], 200),
            ]);

            [, $p, $c] = $this->seedShop();

            $this->artisan('satu:sync-stock')->assertExitCode(0);
            Http::assertSentCount(1);

            // Edit → push pertama sukses → synced_at = now.
            $p->update(['title' => 'Edit Pertama']);
            $this->artisan('satu:sync-stock')->assertExitCode(0);
            Http::assertSentCount(2);

            // Edit lagi <10 menit → konten DITAHAN, tapi stok tetap di-push.
            $p->update(['title' => 'Edit Kedua', 'stock' => 9]);
            $this->artisan('satu:sync-stock')->assertExitCode(0);
            Http::assertSentCount(3);

            // Request terakhir: stok terkirim, konten TIDAK.
            $last = null;
            Http::recorded(function ($request) use (&$last) {
                if (str_contains($request->url(), '/api/v2/product/update_item')) {
                    $last = $request->data();
                }

                return true;
            });
            $this->assertSame(9, (int) $last['stock']);
            $this->assertArrayNotHasKey('item_name', $last);
            $this->assertArrayNotHasKey('original_price', $last);

            // Hash null → setelah jendela lewat, konten terkirim penuh.
            $hashes = $c->fresh()->product_hashes;
            $this->assertNull($hashes[$p->id]['hash']);
            $hashes[$p->id]['synced_at'] = now()->subMinutes(11)->toISOString();
            $c->update(['product_hashes' => $hashes]);

            $this->artisan('satu:sync-stock')->assertExitCode(0);
            Http::assertSentCount(4);
            Http::assertSent(fn ($req) => str_contains($req->url(), '/api/v2/product/update_item')
                && data_get($req->data(), 'item_name') === 'Edit Kedua');
        }

        public function test_sync_stock_legacy_string_hash_backcompat(): void
        {
            Http::fake([
                '*/api/v2/product/update_item*' => Http::response(['response' => ['success' => true]], 200),
            ]);

            [, $p, $c] = $this->seedShop();

            // Simulasi data lama: string md5 tanpa struct.
            $hashes = $c->product_hashes ?? [];
            $hashes[$p->id] = md5('x'); // format lama
            $c->update(['product_hashes' => $hashes]);

            $this->artisan('satu:sync-stock')->assertExitCode(0);

            // Hash string lama beda dengan hash baru → push terjadi tanpa crash.
            Http::assertSentCount(1);

            // Sekarang tersimpan dalam format struct.
            $this->assertIsArray($c->fresh()->product_hashes[$p->id]);
        }
}