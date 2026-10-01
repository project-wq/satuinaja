<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoint publik untuk storefront tiap seller: /toko/{slug}
 * Tidak butuh login. Hanya menampilkan produk status active.
 */
class StorefrontController extends Controller
{
    public function show(string $slug): JsonResponse
    {
        $merchant = Merchant::where('slug', $slug)->where('active', true)->firstOrFail();

        $products = Product::withoutGlobalScope('merchant')
            ->where('merchant_id', $merchant->id)
            ->public()
            ->latest()
            ->paginate(24);

        return response()->json([
            'data' => [
                'merchant' => [
                    'id' => $merchant->id,
                    'name' => $merchant->name,
                    'slug' => $merchant->slug,
                    'description' => $merchant->description,
                    'logo_path' => $merchant->logo_path,
                    'city_id' => $merchant->city_id,
                ],
                'products' => $products,
            ],
        ]);
    }

    public function product(string $slug, string $productSlug): JsonResponse
    {
        $merchant = Merchant::where('slug', $slug)->where('active', true)->firstOrFail();

        $product = Product::withoutGlobalScope('merchant')
            ->where('merchant_id', $merchant->id)
            ->where('slug', $productSlug)
            ->public()
            ->firstOrFail();

        return response()->json(['data' => [
            'merchant' => ['name' => $merchant->name, 'slug' => $merchant->slug, 'city_id' => $merchant->city_id],
            'product' => $product,
        ]]);
    }

    /**
     * Daftar toko publik (opsional halaman katalog global).
     */
    public function index(Request $request): JsonResponse
    {
        $merchants = Merchant::where('active', true)
            ->when($request->string('q')->toString(), fn ($q, $t) => $q->where('name', 'like', "%{$t}%"))
            ->withCount(['products' => fn ($q) => $q->where('status', 'active')])
            ->paginate(24);

        return response()->json($merchants);
    }
}
