<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Fase 11: varian produk (ukuran/warna) — stok & harga per varian.
 * products.stock dijaga sebagai AGREGAT jumlah stok varian agar
 * fitur lama (checkout tanpa varian, laporan, sync) tetap akurat.
 */
class VariantController extends Controller
{
    /** Simpan/replace seluruh varian produk (bulk, mode form). */
    public function sync(Request $request, Product $product): JsonResponse
    {
        $this->authorize('update', $product);

        $data = $request->validate([
            'variants' => ['nullable', 'array', 'max:50'],
            'variants.*.name' => ['required', 'string', 'max:120'],
            'variants.*.sku' => ['nullable', 'string', 'max:64'],
            'variants.*.price' => ['nullable', 'integer', 'min:0', 'max:999999999'],
            'variants.*.stock' => ['required', 'integer', 'min:0', 'max:1000000'],
        ]);

        $saved = DB::transaction(function () use ($product, $data) {
            $product->variants()->delete();

            foreach (array_values($data['variants'] ?? []) as $i => $v) {
                ProductVariant::create([
                    'product_id' => $product->id,
                    'name' => $v['name'],
                    'sku' => $v['sku'] ?? null,
                    'price' => $v['price'] ?? null,
                    'stock' => $v['stock'],
                    'position' => $i,
                ]);
            }

            // Stok produk = agregat varian (tanpa varian → tetap manual).
            $total = $product->variants()->sum('stock');
            $product->update(['stock' => (int) $total]);

            return $product->fresh()->variants;
        });

        Audit::record('product.variants.synced', $product, ['count' => $saved->count()]);

        return response()->json(['data' => $saved]);
    }

    /** Update cepat satu varian (mis. restock dari panel seller). */
    public function update(Request $request, Product $product, ProductVariant $variant): JsonResponse
    {
        $this->authorize('update', $product);
        abort_unless($variant->product_id === $product->id, 404);

        $data = $request->validate([
            'stock' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'price' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:999999999'],
        ]);

        DB::transaction(function () use ($product, $variant, $data) {
            $variant->update($data);
            $product->update(['stock' => (int) $product->variants()->sum('stock')]);
        });

        return response()->json(['data' => $variant->fresh()]);
    }

    public function destroy(Product $product, ProductVariant $variant): JsonResponse
    {
        $this->authorize('delete', $product);
        abort_unless($variant->product_id === $product->id, 404);

        DB::transaction(function () use ($product, $variant) {
            $variant->delete();
            $product->update(['stock' => (int) $product->variants()->sum('stock')]);
        });

        Audit::record('product.variant.deleted', $product, ['variant' => $variant->name]);

        return response()->json(['message' => 'Varian dihapus.']);
    }
}
