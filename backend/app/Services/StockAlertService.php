<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * Fase 23: restock & low-stock alert.
 * Dipanggil setelah setiap mutasi stok (checkout, cancel/return restock,
 * update manual, sinkron varian). Idempoten via flag low_stock_alerted.
 */
class StockAlertService
{
    public function __construct(private NotificationService $notif)
    {
    }

    /**
     * Cek satu produk: stok 0 → auto-hold (status archived) + notif;
     * stok <= threshold → notif sekali (flag); stok pulih → reset flag.
     */
    public function check(Product $product): void
    {
        $product->refresh();
        $threshold = max(0, (int) $product->low_stock_at);

        // Stok pulih di atas threshold → reset flag, tanpa notif.
        if ($product->stock > $threshold) {
            if ($product->low_stock_alerted) {
                $product->update(['low_stock_alerted' => false]);
            }

            return;
        }

        // Stok habis → auto-hold listing + notif (sekali).
        if ($product->stock <= 0) {
            DB::transaction(function () use ($product) {
                if ($product->status === 'active') {
                    $product->update(['status' => 'archived']);
                }
                if (! $product->low_stock_alerted) {
                    $product->update(['low_stock_alerted' => true]);
                    $this->notif->push(
                        $product->merchant_id,
                        'stock.empty',
                        "Stok habis: {$product->title}",
                        'Listing otomatis diarsipkan. Tambah stok untuk tayang kembali.',
                        '/seller/products',
                    );
                }
            });

            return;
        }

        // Stok rendah (<= threshold) → notif sekali.
        if (! $product->low_stock_alerted) {
            $product->update(['low_stock_alerted' => true]);
            $this->notif->push(
                $product->merchant_id,
                'stock.low',
                "Stok menipis: {$product->title}",
                "Tersisa {$product->stock} (batas {$threshold}). Segera restock.",
                '/seller/products',
            );
        }
    }

    /** Daftar produk menipis/habis milik merchant (badge + halaman). */
    public function lowStock(int $merchantId)
    {
        return Product::withoutGlobalScope('merchant')
            ->where('merchant_id', $merchantId)
            ->whereColumn('stock', '<=', 'low_stock_at')
            ->orderBy('stock')
            ->get();
    }
}
