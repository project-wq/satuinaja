<?php

namespace App\Services;

use App\Models\Setting;

/**
 * Kalkulasi fee marketplace.
 *
 * Per unit:
 *   unit_sale = discount_price ?? price          (harga jual setelah diskon)
 *   buyer bayar per unit  = unit_sale + fee_buyer(11%) + admin_fee(Rp1000)
 *   seller dapat per unit = unit_sale - seller_fee(Rp500)   -> masuk saldo
 *
 * Ongkir TIDAK kena fee (diteruskan penuh ke seller? tidak — ongkir milik
 * kurir, tidak masuk saldo seller maupun fee platform; disimpan apa adanya).
 */
class FeeService
{
    /** Fee pembeli dalam persen (default 11). */
    public function buyerPercent(): int
    {
        return (int) Setting::get('fee_buyer_percent', '11');
    }

    /** Admin fee per unit untuk pembeli (default 1000). */
    public function adminFeePerItem(): int
    {
        return (int) Setting::get('admin_fee_per_item', '1000');
    }

    /** Potongan seller per unit terjual (default 500). */
    public function sellerFeePerItem(): int
    {
        return (int) Setting::get('seller_fee_per_item', '500');
    }

    /** Rincian 1 baris item (harga ASLI, diskon, qty) untuk pembeli & seller. */
    public function line(int $price, ?int $discountPrice, int $qty): array
    {
        $unitSale = $discountPrice ?? $price;
        $discount = max(0, ($price - $unitSale) * $qty);

        // Sisi pembeli.
        $baseSale = $unitSale * $qty;                                  // setelah diskon
        $buyerFee = (int) round($baseSale * $this->buyerPercent() / 100);
        $buyerAdmin = $this->adminFeePerItem() * $qty;

        // Sisi seller.
        $sellerFee = $this->sellerFeePerItem() * $qty;
        $sellerNet = $baseSale - $sellerFee;

        return [
            'unit_sale' => $unitSale,
            'discount' => $discount,
            'base_sale' => $baseSale,
            'buyer_fee' => $buyerFee,
            'buyer_admin_fee' => $buyerAdmin,
            'buyer_line_total' => $baseSale + $buyerFee + $buyerAdmin,
            'seller_net' => $sellerNet,
        ];
    }
}