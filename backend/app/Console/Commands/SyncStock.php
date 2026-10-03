<?php

namespace App\Console\Commands;

use App\Models\Channel;
use App\Models\Merchant;
use App\Services\StockSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Jalankan lewat scheduler (setiap 5 menit): deteksi perubahan produk
 * (stok/harga/deskripsi/gambar) lalu sinkronkan ke channel yang aktif.
 *
 * Hash disimpan di channels.product_hashes sebagai JSON:
 *   { "<product_id>": {"hash": "<md5>", "updated_at": "ISO", "flap_at": "ISO|null"} }
 * Versi lama string md5 dibaca kompatibel (tidak punya updated_at/flap_at).
 */
class SyncStock extends Command
{
    protected $signature = 'satu:sync-stock {--pull : Tarik stok dari marketplace ke lokal (two-way), bukan push lokal→channel}';

    protected $description = 'Sinkronkan perubahan stok/harga produk ke channel aktif (atau tarik stok dari channel)';

    public function handle(StockSyncService $sync): int
    {
        if ($this->option('pull')) {
            return $this->pull($sync);
        }

        $channels = Channel::where('active', true)->get();

        foreach ($channels as $channel) {
            $merchant = $channel->merchant()->first();
            if (! $merchant) {
                continue;
            }

            $products = $merchant->products()->where('status', 'active')->get();
            $hashes = (array) $channel->product_hashes;

            foreach ($products as $product) {
                $prev = is_array($hashes[$product->id] ?? null)
                    ? $hashes[$product->id]
                    : (isset($hashes[$product->id]) ? ['hash' => (string) $hashes[$product->id]] : null);
                $hash = $this->hashOf($product, $channel->platform);

                if ($prev && ($prev['hash'] ?? null) === $hash) {
                    continue; // tak ada perubahan
                }

                // Anti-flap: kalau sudah republish dalam 10 menit terakhir
                // dan hash masih berubah, tahan (produk sedang diedit).
                $flapAt = isset($prev['flap_at']) ? Carbon::parse($prev['flap_at']) : null;
                if ($flapAt && $flapAt->gt(now()->subMinutes(10))) {
                    $this->line(sprintf(
                        '[%s] %s DITAHAN (kemungkinan sedang diedit; coba lagi nanti)',
                        $channel->platform, $product->title,
                    ));
                    continue;
                }

                $result = $sync->sync($product)[$channel->platform] ?? ['ok' => false];
                $error = $result['error'] ?? ($result['skipped'] ? 'skip (platform tanpa update stok)' : 'ok');
                $this->line(sprintf(
                    '[%s] %s %s -> %s',
                    $channel->platform,
                    $product->title,
                    $result['ok'] ? 'OK' : 'GAGAL',
                    $error,
                ));

                if ($result['ok'] ?? false) {
                    $hashes[$product->id] = [
                        'hash' => $hash,
                        'updated_at' => now()->toISOString(),
                        'flap_at' => $flapAt ? now()->toISOString() : null,
                    ];
                    $channel->update(['product_hashes' => $hashes]);
                } elseif (! ($result['skipped'] ?? false)) {
                    // Gagal (bukan skip) → retry otomatis tiap 5 menit oleh scheduler.
                    $this->line('  └ retry berikutnya dalam 5 menit');
                }
            }
        }

        return self::SUCCESS;
    }

    /** md5 dari field yang menentukan konten produk di channel. */
    private function hashOf($product, string $platform): string
    {
        $images = is_array($product->images) ? implode('|', $product->images) : '';
        return md5(implode('|', [
            $product->title, $product->price, $product->stock,
            $product->description, $images, $product->weight,
        ]));
    }

    /**
     * Tarik stok dari marketplace → lokal untuk semua produk aktif yang
     * dipublish ke channel pendukung (Shopee). Dijalankan manual/terjadwal.
     */
    private function pull(StockSyncService $sync): int
    {
        $channels = Channel::where('active', true)
            ->where('platform', 'shopee')
            ->get();

        foreach ($channels as $channel) {
            $merchant = $channel->merchant()->first();
            if (! $merchant) {
                continue;
            }

            foreach ($merchant->products()->where('status', 'active')->get() as $product) {
                $result = $sync->pull($product)['shopee'] ?? ['ok' => false];
                $this->line(sprintf(
                    '[shopee-pull] %s %s -> %s',
                    $product->title,
                    ($result['ok'] ?? false) ? 'OK' : 'GAGAL',
                    $result['ok'] ?? false
                        ? 'stok lokal = '.$product->fresh()->stock
                        : ($result['error'] ?? 'tidak ada perubahan'),
                ));
            }
        }

        return self::SUCCESS;
    }
}