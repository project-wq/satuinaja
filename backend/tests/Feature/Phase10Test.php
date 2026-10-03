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

        [$m, $p, $c] = $this->seedShop();

        $this->artisan('satu:sync-stock')->assertExitCode(0);
        Http::assertSentCount(1);

        // Edit harga → hash beda → push lagi.
        $p->update(['price' => 21000]);
        $this->artisan('satu:sync-stock')->assertExitCode(0);
        Http::assertSentCount(2);
    }

    public function test_sync_stock_holds_republish_within_flap_window(): void
    {
        Http::fake([
            '*/api/v2/product/update_item*' => Http::response(['response' => ['success' => true]], 200),
        ]);

        [$m, $p, $c] = $this->seedShop();

        $this->artisan('satu:sync-stock')->assertExitCode(0);
        Http::assertSentCount(1);

        // Edit → push berhasil → flap_at = now.
        $p->update(['title' => 'Produk Baru']);
        $this->artisan('satu:sync-stock')->assertExitCode(0);
        Http::assertSentCount(2);

        // Edit lagi dalam 10 menit → DITAHAN (anti-flap).
        $p->update(['title' => 'Produk Edit 2']);
        $this->artisan('satu:sync-stock')->assertExitCode(0);
        Http::assertSentCount(2); // tidak bertambah

        // Lewat 10 menit → lolos, push.
        $hashes = $c->fresh()->product_hashes;
        $hashes[$p->id]['flap_at'] = now()->subMinutes(11)->toISOString();
        $c->update(['product_hashes' => $hashes]);

        $p->update(['title' => 'Produk Edit 3']);
        $this->artisan('satu:sync-stock')->assertExitCode(0);
        Http::assertSentCount(3);
    }

    public function test_sync_stock_legacy_string_hash_backcompat(): void
    {
        Http::fake([
            '*/api/v2/product/update_item*' => Http::response(['response' => ['success' => true]], 200),
        ]);

        [$m, $p, $c] = $this->seedShop();

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