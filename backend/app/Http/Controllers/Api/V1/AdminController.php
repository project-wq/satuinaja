<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Channel;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\BalanceService;
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

    // ---- Fase 5: konfigurasi fee + kelola withdraw ----

    /** Baca konfigurasi fee (dipakai halaman pengaturan admin). */
    public function settings(): JsonResponse
    {
        $this->ensureAdmin();

        return response()->json(['data' => [
            'fee_buyer_percent' => (int) Setting::get('fee_buyer_percent', '11'),
            'admin_fee_per_item' => (int) Setting::get('admin_fee_per_item', '1000'),
            'seller_fee_per_item' => (int) Setting::get('seller_fee_per_item', '500'),
        ]]);
    }

    /** Ubah konfigurasi fee. */
    public function updateSettings(Request $request): JsonResponse
    {
        $this->ensureAdmin();
        $data = $request->validate([
            'fee_buyer_percent' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'admin_fee_per_item' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'seller_fee_per_item' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
        ]);

        foreach ($data as $key => $value) {
            Setting::set($key, (string) $value);
        }

        Audit::record('admin.settings.updated', null, $data);

        return response()->json(['data' => $this->settings()->getData(true)['data']]);
    }

    /** Daftar semua permintaan withdraw (filter status opsional). */
    public function withdrawals(Request $request): JsonResponse
    {
        $this->ensureAdmin();

        $q = Withdrawal::with('merchant.user')
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->latest();

        $page = $q->paginate(20, ['*'], 'page', (int) $request->query('page', 1));

        return response()->json([
            'data' => $page->map(fn ($w) => [
                'id' => $w->id,
                'merchant' => $w->merchant?->name,
                'amount' => $w->amount,
                'bank_name' => $w->bank_name,
                'bank_account_no' => $w->bank_account_no,
                'bank_account_holder' => $w->bank_account_holder,
                'status' => $w->status,
                'admin_note' => $w->admin_note,
                'created_at' => $w->created_at,
                'processed_at' => $w->processed_at,
            ]),
            'pagination' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    /** Approve (uang keluar) / reject (saldo dikembalikan). */
    public function processWithdrawal(Request $request, Withdrawal $withdrawal, BalanceService $balance): JsonResponse
    {
        $this->ensureAdmin();
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approved', 'rejected'])],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $wd = $balance->processWithdraw(
                $withdrawal, $data['decision'], $request->user()->id, $data['note'] ?? null,
            );
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        Audit::record('admin.withdrawal.'.$wd->status, $wd, [
            'amount' => $wd->amount, 'note' => $data['note'] ?? null,
        ]);

        return response()->json(['data' => ['id' => $wd->id, 'status' => $wd->status]]);
    }
}