<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Jobs\PublishToChannel;
use App\Models\Product;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $products = Product::query()
            ->when($request->string('q')->toString(), fn ($q, $term) => $q->where('title', 'like', "%{$term}%"))
            ->when($request->string('status')->toString(), fn ($q, $s) => $q->where('status', $s))
            ->latest()
            ->paginate(min((int) $request->integer('per_page', 20), 100));

        return response()->json($products);
    }

    public function store(Request $request): JsonResponse
    {
        $merchant = $request->user()->merchant;

        // Batas plan: max produk per merchant.
        $limits = BillingController::limits($merchant);
        if ($limits['max_products'] !== null
            && $merchant->products()->count() >= $limits['max_products']) {
            return response()->json([
                'error' => "Batas produk plan {$merchant->plan_code}: maksimal {$limits['max_products']}. "
                    .'Upgrade di halaman Langganan.',
            ], 403);
        }

        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:5000'],
            'price' => ['required', 'integer', 'min:0', 'max:999999999'],
            'stock' => ['required', 'integer', 'min:0', 'max:1000000'],
            'weight' => ['required', 'integer', 'min:1', 'max:100000'],
            'status' => ['nullable', Rule::in(['draft', 'active', 'archived'])],
            'images' => ['nullable', 'array', 'max:8'],
            'images.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ]);

        $images = collect($request->file('images', []))
            ->map(fn ($file) => Storage::disk('public')->putFile("products/{$merchant->id}", $file))
            ->values()
            ->all();

        $product = Product::create([
            'merchant_id' => $merchant->id,
            'title' => $data['title'],
            'slug' => $this->uniqueSlug($merchant->id, $data['title']),
            'description' => $data['description'] ?? null,
            'price' => $data['price'],
            'stock' => $data['stock'],
            'weight' => $data['weight'],
            'images' => $images,
            'status' => $data['status'] ?? 'draft',
        ]);

        Audit::record('product.created', $product, ['title' => $product->title]);

        return response()->json(['data' => $product], 201);
    }

    public function show(Product $product): JsonResponse
    {
        $this->authorize('view', $product);

        return response()->json(['data' => $product->load('publishLogs.channel')]);
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        $this->authorize('update', $product);

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:5000'],
            'price' => ['sometimes', 'integer', 'min:0', 'max:999999999'],
            'stock' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'weight' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'status' => ['sometimes', Rule::in(['draft', 'active', 'archived'])],
        ]);

        $product->update($data);

        Audit::record('product.updated', $product, $data);

        return response()->json(['data' => $product->fresh()]);
    }

    public function destroy(Product $product): JsonResponse
    {
        $this->authorize('delete', $product);

        Audit::record('product.deleted', $product, ['title' => $product->title]);
        $product->delete();

        return response()->json(['message' => 'Produk dihapus.']);
    }

    /**
     * Publish ke semua channel aktif (atau channel tertentu).
     * Async via queue → request tetap cepat.
     */
    public function publish(Request $request, Product $product): JsonResponse
    {
        $this->authorize('update', $product);

        $data = $request->validate([
            'channel_ids' => ['nullable', 'array'],
            'channel_ids.*' => ['integer', 'exists:channels,id'],
        ]);

        $channels = $request->user()->merchant->channels()
            ->where('active', true)
            ->when($data['channel_ids'] ?? null, fn ($q, $ids) => $q->whereIn('id', $ids))
            ->get();

        if ($channels->isEmpty()) {
            return response()->json([
                'message' => 'Tidak ada channel aktif. Hubungkan & aktifkan channel dulu.',
            ], 422);
        }

        foreach ($channels as $channel) {
            PublishToChannel::dispatch($product->id, $channel->id);
        }

        Audit::record('product.publish.queued', $product, ['channels' => $channels->pluck('platform')->all()]);

        return response()->json([
            'message' => 'Publish dijadwalkan ke '.$channels->count().' channel.',
            'data' => ['channels' => $channels->pluck('platform')],
        ], 202);
    }

    private function uniqueSlug(int $merchantId, string $title): string
    {
        $base = Str::slug($title) ?: 'produk';
        $slug = $base;
        $i = 1;
        while (Product::withoutGlobalScope('merchant')
            ->where('merchant_id', $merchantId)->where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$i);
        }

        return $slug;
    }
}
