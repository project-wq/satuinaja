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
    protected $signature = 'satu:sync-stock';

    protected $description = 'Sinkronkan perubahan stok/harga produk ke channel aktif';

    public function handle(StockSyncService $sync): int
    {
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
}