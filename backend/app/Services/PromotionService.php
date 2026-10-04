<?php

namespace App\Services;

use App\Models\Merchant;
use App\Models\Product;
use App\Models\Voucher;
use App\Models\VoucherRedemption;

/**
 * Promosi (Fase 14): voucher produk / toko / platform + gratis ongkir.
 *
 * Aturan pemakaian:
 *  - Satu order hanya boleh memakai 1 voucher.
 *  - scope `product` hanya berlaku untuk produk tersebut (subtotal = nilai item).
 *  - scope `shop` berlaku untuk semua produk merchant tsb.
 *  - scope `platform` berlaku lintas merchant (dibuat admin).
 *  - `min_spend` diukur terhadap subtotal barang (sebelum ongkir & fee).
 *  - `free_shipping` memotong ongkir penuh.
 */
class PromotionService
{
    /**
     * Cari voucher yang bisa dipakai.
     * Urutan: kode produk → kode toko → kode platform.
     */
    public function findUsable(string $code, Merchant $merchant, array $cartProductIds): ?Voucher
    {
        $code = strtoupper(trim($code));
        if ($code === '') {
            return null;
        }

        return Voucher::withoutGlobalScope('merchant')
            ->where('code', $code)
            ->where('active', true)
            ->where(function ($q) use ($merchant) {
                // Produk/stok milik merchant ini, atau voucher platform.
                $q->where('merchant_id', $merchant->id)->orWhere('scope', 'platform');
            })
            ->where(function ($q) {
                $now = now();
                $q->whereNull('start_at')->orWhere('start_at', '<=', $now);
            })
            ->where(function ($q) {
                $now = now();
                $q->whereNull('end_at')->orWhere('end_at', '>=', $now);
            })
            ->where(function ($q) {
                $q->whereNull('quota')->orWhereColumn('used', '<', 'quota');
            })
            ->where(function ($q) use ($cartProductIds) {
                // Voucher produk hanya jika produknya ada di keranjang.
                $q->whereIn('scope', ['shop', 'platform'])
                    ->orWhere(fn ($sub) => $sub->where('scope', 'product')->whereIn('product_id', $cartProductIds ?: [0]));
            })
            ->first();
    }

    /**
     * Hitung dampak voucher pada keranjang.
     *
     * @param  array<int, array{product_id:int, unit_sale:int, qty:int}>  $lines
     * @return array{subtotal:int, discount:int, free_shipping:bool, error:?string}
     */
    public function apply(Voucher $voucher, array $lines, int $shippingCost): array
    {
        // Subtotal yang jadi dasar voucher: produk terpilih (scope product)
        // atau seluruh keranjang (shop/platform).
        $eligible = 0;
        foreach ($lines as $l) {
            if ($voucher->scope === 'product' && (int) $voucher->product_id !== (int) $l['product_id']) {
                continue;
            }
            // store() baris memakai 'price'; preview memakai 'unit_sale' — dua-duanya didukung.
            $unit = (int) ($l['unit_sale'] ?? $l['price'] ?? 0);
            $eligible += $unit * (int) $l['qty'];
        }
        }

        if ($eligible <= 0) {
            return ['subtotal' => 0, 'discount' => 0, 'free_shipping' => false, 'error' => 'Voucher tidak berlaku untuk produk di keranjang.'];
        }

        if ($eligible < $voucher->min_spend) {
            $kurang = number_format($voucher->min_spend - $eligible, 0, ',', '.');

            return ['subtotal' => $eligible, 'discount' => 0, 'free_shipping' => false, 'error' => "Minimal belanja Rp{$kurang} belum terpenuhi."];
        }

        $d = $voucher->discountFor($eligible);

        return [
            'subtotal' => $eligible,
            'discount' => $d['discount'],
            'free_shipping' => $d['free_shipping'],
            'shipping_discount' => $d['free_shipping'] ? $shippingCost : 0,
            'error' => null,
        ];
    }

    /**
     * Tandai voucher terpakai (dipanggil di dalam transaksi checkout).
     * Idempotent per (voucher, order).
     */
    public function redeem(Voucher $voucher, int $orderId, int $discount, ?string $buyerPhone): void
    {
        VoucherRedemption::firstOrCreate(
            ['voucher_id' => $voucher->id, 'order_id' => $orderId],
            ['buyer_phone' => $buyerPhone, 'discount' => $discount],
        );

        $voucher->increment('used');
    }

    /** Batas pemakaian per pembeli sudah tercapai? (cek sebelum redeem) */
    public function buyerLimitReached(Voucher $voucher, ?string $buyerPhone): bool
    {
        if ($voucher->max_per_buyer <= 0 || ! $buyerPhone) {
            return false;
        }

        $count = VoucherRedemption::where('voucher_id', $voucher->id)
            ->where('buyer_phone', $buyerPhone)
            ->count();

        return $count >= $voucher->max_per_buyer;
    }

    /**
     * Voucher aktif yang tayang di storefront untuk produk/toko.
     *
     * @return array<int, array>
     */
    public function publicFor(Merchant $merchant, ?Product $product = null): array
    {
        $now = now();

        $q = Voucher::withoutGlobalScope('merchant')
            ->where('active', true)
            ->where(function ($w) use ($merchant) {
                $w->where('merchant_id', $merchant->id)->orWhere('scope', 'platform');
            })
            ->where(fn ($w) => $w->whereNull('start_at')->orWhere('start_at', '<=', $now))
            ->where(fn ($w) => $w->whereNull('end_at')->orWhere('end_at', '>=', $now))
            ->where(fn ($w) => $w->whereNull('quota')->orWhereColumn('used', '<', 'quota'));

        if ($product) {
            $q->where(function ($w) use ($product) {
                $w->whereIn('scope', ['shop', 'platform'])
                    ->orWhere(fn ($sub) => $sub->where('scope', 'product')->where('product_id', $product->id));
            });
        }

        return $q->orderByDesc('created_at')->limit(20)->get()->map(fn (Voucher $v) => [
            'code' => $v->code,
            'name' => $v->name,
            'type' => $v->type,
            'value' => $v->value,
            'min_spend' => $v->min_spend,
            'max_discount' => $v->max_discount,
            'free_shipping' => $v->free_shipping,
            'scope' => $v->scope,
            'end_at' => $v->end_at?->toISOString(),
            'sisa' => $v->quota === null ? null : max(0, $v->quota - $v->used),
        ])->all();
    }
}
