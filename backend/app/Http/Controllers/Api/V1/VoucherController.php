<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\Voucher;
use App\Services\PromotionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Voucher (Fase 14) — dikelola seller (scope product/shop) & admin (platform).
 * Seller hanya bisa menyentuh voucher bertanda merchant_id-nya (global scope).
 */
class VoucherController extends Controller
{
    public function __construct(
        private PromotionService $promo,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $merchant = $request->user()->merchant;

        $vouchers = Voucher::query()
            ->when($request->string('scope')->toString(), fn ($q, $s) => $q->where('scope', $s))
            ->when($request->boolean('active_only'), fn ($q) => $q->where('active', true))
            ->with('product:id,title')
            ->latest()
            ->paginate(20);

        return response()->json($vouchers);
    }

    public function store(Request $request): JsonResponse
    {
        $merchant = $request->user()->merchant;
        $data = $this->validateData($request, $merchant, null);

        $voucher = Voucher::create($data + ['merchant_id' => $merchant->id]);

        return response()->json(['data' => $voucher], 201);
    }

    public function show(Request $request, Voucher $voucher): JsonResponse
    {
        $this->authorizeVoucher($request, $voucher);

        return response()->json(['data' => $voucher->load('product:id,title', 'redemptions')]);
    }

    public function update(Request $request, Voucher $voucher): JsonResponse
    {
        $this->authorizeVoucher($request, $voucher);
        $merchant = $request->user()->merchant;
        $data = $this->validateData($request, $merchant, $voucher);

        $voucher->update($data);

        return response()->json(['data' => $voucher->fresh()]);
    }

    public function destroy(Request $request, Voucher $voucher): JsonResponse
    {
        $this->authorizeVoucher($request, $voucher);
        $voucher->delete();

        return response()->json(['message' => 'Voucher dihapus.'], 200);
    }

    /** Cek cepat: apakah kode voucher ini bisa dipakai untuk keranjang ini? */
    public function check(Request $request): JsonResponse
    {
        $data = $request->validate([
            'merchant_slug' => ['required', 'string', 'exists:merchants,slug'],
            'code' => ['required', 'string', 'max:40'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.qty' => ['required', 'integer', 'min:1'],
            'shipping_cost' => ['nullable', 'integer', 'min:0'],
            'buyer_phone' => ['nullable', 'string', 'max:32'],
        ]);

        $merchant = Merchant::where('slug', $data['merchant_slug'])->where('active', true)->firstOrFail();
        $productIds = array_column($data['items'], 'product_id');
        $voucher = $this->promo->findUsable((string) $data['code'], $merchant, $productIds);

        if (! $voucher) {
            return response()->json(['data' => ['valid' => false, 'reason' => 'Kode voucher tidak ditemukan / tidak aktif / sudah habis.']]);
        }

        // Subtotal per item: harga efektif produk (tanpa varian — perkiraan).
        $lines = [];
        foreach ($data['items'] as $i) {
            $p = Product::withoutGlobalScope('merchant')->whereKey($i['product_id'])->first();
            if (! $p) {
                continue;
            }
            $lines[] = ['product_id' => $p->id, 'unit_sale' => $p->effectivePrice(), 'qty' => $i['qty']];
        }

        $result = $this->promo->apply($voucher, $lines, (int) ($data['shipping_cost'] ?? 0));

        if ($result['error']) {
            return response()->json(['data' => ['valid' => false, 'reason' => $result['error']]]);
        }

        if (! empty($data['buyer_phone']) && $this->promo->buyerLimitReached($voucher, (string) $data['buyer_phone'])) {
            return response()->json(['data' => ['valid' => false, 'reason' => 'Voucher ini sudah dipakai maksimal per pembeli.']]);
        }

        return response()->json(['data' => [
            'valid' => true,
            'code' => $voucher->code,
            'name' => $voucher->name,
            'discount' => $result['discount'],
            'free_shipping' => $result['free_shipping'],
            'shipping_discount' => $result['shipping_discount'] ?? 0,
        ]]);
    }

    /** Daftar voucher yang sedang tayang (storefront). */
    public function publicList(Request $request): JsonResponse
    {
        $data = $request->validate([
            'merchant_slug' => ['required', 'string', 'exists:merchants,slug'],
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
        ]);

        $merchant = Merchant::where('slug', $data['merchant_slug'])->where('active', true)->firstOrFail();
        $product = isset($data['product_id']) ? Product::withoutGlobalScope('merchant')->whereKey($data['product_id'])->first() : null;

        return response()->json(['data' => $this->promo->publicFor($merchant, $product)]);
    }

    // ---- helper ----

    private function validateData(Request $request, Merchant $merchant, ?Voucher $current): array
    {
        $data = $request->validate([
            'scope' => ['required', 'in:product,shop,platform'],
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'code' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/'],
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', 'in:percent,fixed'],
            'value' => ['required', 'integer', 'min:1', 'max:100000000'],
            'min_spend' => ['nullable', 'integer', 'min:0'],
            'max_discount' => ['nullable', 'integer', 'min:1'],
            'quota' => ['nullable', 'integer', 'min:1'],
            'max_per_buyer' => ['nullable', 'integer', 'min:1'],
            'free_shipping' => ['boolean'],
            'active' => ['boolean'],
            'start_at' => ['nullable', 'date'],
            'end_at' => ['nullable', 'date', 'after:start_at'],
        ]);

        // Seller tidak boleh bikin voucher platform (scope=platform hanya admin).
        if ($request->user()->role !== 'admin' && $data['scope'] === 'platform') {
            abort(422, 'Voucher platform hanya bisa dibuat admin.');
        }

        // scope=product wajib product_id milik merchant ini.
        if ($data['scope'] === 'product') {
            $pid = (int) ($data['product_id'] ?? 0);
            $owned = Product::withoutGlobalScope('merchant')
                ->where('merchant_id', $merchant->id)
                ->where('id', $pid)
                ->exists();
            if (! $owned) {
                abort(422, 'Produk untuk voucher ini tidak ditemukan.');
            }
        } else {
            $data['product_id'] = null;
        }

        if ($data['type'] === 'percent' && $data['value'] > 100) {
            abort(422, 'Persen voucher maksimal 100.');
        }
        if ($data['type'] === 'percent' && ! empty($data['max_discount']) && $data['max_discount'] < 1) {
            abort(422, 'Voucher persen wajib punya maksimal potongan.');
        }

        // Kode unik per scope.
        $q = Voucher::where('scope', $data['scope'])->where('code', strtoupper($data['code']));
        if ($current) {
            $q->where('id', '!=', $current->id);
        }
        if ($data['scope'] === 'product' && ! empty($data['product_id'])) {
            $q->where('product_id', (int) $data['product_id']);
        }
        if ($q->exists()) {
            abort(422, 'Kode voucher sudah dipakai.');
        }

        $data['code'] = strtoupper($data['code']);

        return $data;
    }

    private function authorizeVoucher(Request $request, Voucher $voucher): void
    {
        $user = $request->user();
        $isAdmin = $user->role === 'admin';
        $own = $voucher->merchant_id && $voucher->merchant_id === $user->merchant?->id;

        if (! $isAdmin && ! $own) {
            abort(403, 'Bukan voucher kamu.');
        }
    }
}
