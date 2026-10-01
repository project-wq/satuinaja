<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Channel;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Admin panel: hanya user role=admin.
 * Di LOKAL berbagai dari aplikasi (server ini), admin pertama dibuat
 * lewat tinker/kwery — dipakai Fase 4.
 */
class AdminController extends Controller
{
    protected function ensureAdmin(): void
    {
        abort_unless(request()->user()?->isAdmin(), 403, 'Hanya admin.');
    }

    /** Statistik platform (dashboard admin). */
    public function stats(): JsonResponse
    {
        $this->ensureAdmin();
        $now = now();

        return response()->json(['data' => [
            'users' => [
                'total' => User::count(),
                'merchants' => Merchant::count(),
            ],
            'products' => Product::count(),
            'channels' => Channel::count(),
            'orders' => [
                'total' => Order::count(),
                'revenue' => (int) Order::where('payment_status', 'paid')->sum('total'),
                'this_month' => Order::where('created_at', '>=', $now->startOfMonth())->count(),
            ],
            'publishes' => AuditLog::where('action', 'like', 'publish.%')->count(),
        ]]);
    }

    /** Daftar semua merchant (+ batas plan, cancella aksi). */
    public function merchants(Request $request): JsonResponse
    {
        $this->ensureAdmin();
        $q = Merchant::with('user')->latest();

        if ($search = $request->query('search')) {
            $q->whereAny([
                'name' => ['like', '%'.$search.'%'],
                'slug' => ['like', '%'.$search.'%'],
                'user.email' => ['like', '%'.$search.'%'],
            ]);
        }

        $page = $q->paginate(20, ['*'], 'page', (int) $request->query('page', 1));

        return response()->json([
            'data' => $page->map(fn ($m) => [
                'id' => $m->id,
                'name' => $m->name,
                'slug' => $m->slug,
                'email' => $m->user?->email,
                'plan_code' => $m->plan_code,
                'publishes_this_month' => $m->publishes_this_month,
                'active' => $m->active,
                'products_count' => $m->products()->count(),
                'orders_count' => $m->orders()->count(),
                'created_at' => $m->created_at,
            ]),
            'pagination' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    /** Ban / unban merchant (supayane usaha, spam, stok palsu). */
    public function setMerchantStatus(Request $request, Merchant $merchant): JsonResponse
    {
        $this->ensureAdmin();
        $data = $request->validate([
            'active' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        abort_unless($merchant->user?->role !== 'admin', 422, 'Tak bisa nonaktifkan akun admin.');

        $merchant->update(['active' => $data['active']]);

        Audit::record($data['active'] ? 'admin.merchant.activated' : 'admin.merchant.banned', $merchant, [
            'reason' => $data['reason'] ?? null,
        ]);

        return response()->json(['data' => ['id' => $merchant->id, 'active' => $merchant->fresh()->active]]);
    }

    /** Ganti plan merchant manual (disaranan, bukan dikotak otomatis). */
    public function setPlan(Request $request, Merchant $merchant): JsonResponse
    {
        $this->ensureAdmin();
        $data = $request->validate([
            'plan_code' => ['required', 'string', Rule::in(\App\Models\Plan::pluck('code'))],
            'ends_at' => ['sometimes', 'date'],
        ]);

        $merchant->update([
            'plan_code' => $data['plan_code'],
            'last_publish_reset_at' => $data['ends_at'] ?? now(),
        ]);

        Audit::record('admin.plan.changed', $merchant, ['plan' => $data['plan_code']]);

        return response()->json(['data' => ['id' => $merchant->id, 'plan_code' => $merchant->fresh()->plan_code]]);
    }
}