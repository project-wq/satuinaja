<?php

namespace App\Console\Commands;

use App\Models\Channel;
use App\Models\Merchant;
use App\Services\StockSyncService;
use Illuminate\Console\Command;

/**
 * Jalankan lewat scheduler (setiap 5 menit): deteksi perubahan produk
 * (stok/harga/deskripsi) lalu sinkronkan ke channel yang aktif.
 *
 * Hash disimpan di channels.product_hashes sebagai JSON:
 *   { "<product_id>": "<md5 judul|harga|stok|deskripsi>" }
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
            $products = $channel->merchant()->first()
                ? $channel->merchant()->first()->products()->where('status', 'active')->get()
                : collect();

            $hashes = (array) $channel->product_hashes;

            foreach ($products as $product) {
                $hash = md5(implode('|', [
                    $product->title, $product->price, $product->stock, $product->description,
                ]));

                if (($hashes[$product->id] ?? null) === $hash) {
                    continue; // tak ada perubahan
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
                    $hashes[$product->id] = $hash;
                    $channel->update(['product_hashes' => $hashes]);
                }
            }
        }

        return self::SUCCESS;
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