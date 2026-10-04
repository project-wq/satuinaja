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
use Illuminate\Support\Facades\Storage;
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
            'biteship_api_key' => Setting::get('biteship_api_key', (string) config('services.biteship.key', '')),
        ]]);
    }

    // ---- Fase 7: status payment gateway (KYC Midtrans produksi) ----

    /**
     * Status kesiapan payment gateway + opsi mode. live ping ke Midtrans.
     */
    public function paymentGateway(\App\Services\MidtransService $midtrans): JsonResponse
    {
        $this->ensureAdmin();

        return response()->json(['data' => $midtrans->readiness()]);
    }

    /** Ubah mode sandbox/production (overlay Setting, tak mengubah .env). */
    public function updatePaymentGateway(Request $request, \App\Services\MidtransService $midtrans): JsonResponse
    {
        $this->ensureAdmin();
        $data = $request->validate([
            'sandbox' => ['required', 'boolean'],
        ]);

        Setting::set('midtrans_sandbox', $data['sandbox'] ? '1' : '0');
        Audit::record('admin.payment_gateway.updated', null, ['sandbox' => $data['sandbox']]);

        return response()->json(['data' => $midtrans->readiness()]);
    }

    /** Ubah konfigurasi fee. */
    /**
     * Fase 16: review KYC seller baru (approve → toko aktif, reject → tolak).
     * Body: { action: approve|reject, reason?: string }
     */
    public function kycIndex(Request $request): JsonResponse
    {
        $this->ensureAdmin();

        $status = $request->string('status')->toString();
        $q = Merchant::with('user')->latest();
        if (in_array($status, ['pending', 'approved', 'rejected'], true)) {
            $q->where('kyc_status', $status);
        }

        $page = $q->paginate(20, ['*'], 'page', (int) $request->query('page', 1));

        return response()->json([
            'data' => $page->map(fn ($m) => [
                'id' => $m->id,
                'name' => $m->name,
                'slug' => $m->slug,
                'email' => $m->user?->email,
                'phone' => $m->phone,
                'kyc_status' => $m->kyc_status,
                'kyc_nik' => $m->kyc_nik,
                'has_ktp' => (bool) $m->kyc_ktp_path,
                'kyc_submitted_at' => $m->kyc_submitted_at,
                'kyc_reviewed_at' => $m->kyc_reviewed_at,
                'kyc_reject_reason' => $m->kyc_reject_reason,
                'address' => $m->address,
                'province' => $m->province,
                'city_name' => $m->city_name,
                'district' => $m->district,
                'postal_code' => $m->postal_code,
                'active' => $m->active,
                'created_at' => $m->created_at,
            ]),
            'pagination' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    /** Setujui/tolak pendaftaran seller. */
    public function kycReview(Request $request, Merchant $merchant): JsonResponse
    {
        $this->ensureAdmin();
        $data = $request->validate([
            'action' => ['required', Rule::in(['approve', 'reject'])],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        abort_unless($merchant->kyc_status !== 'approved' || $data['action'] !== 'approve', 422, 'Sudah disetujui.');

        $approve = $data['action'] === 'approve';
        $merchant->update([
            'kyc_status' => $approve ? 'approved' : 'rejected',
            'kyc_reviewed_at' => now(),
            'kyc_reject_reason' => $approve ? null : ($data['reason'] ?? 'Tidak memenuhi syarat.'),
            'active' => $approve,
        ]);

        Audit::record($approve ? 'admin.kyc.approved' : 'admin.kyc.rejected', $merchant, [
            'reason' => $data['reason'] ?? null,
        ]);

        return response()->json(['data' => [
            'id' => $merchant->id,
            'kyc_status' => $merchant->fresh()->kyc_status,
            'active' => $merchant->fresh()->active,
        ]]);
    }

    /** Foto KTP seller (privat, hanya admin). */
    public function kycKtp(Merchant $merchant): mixed
    {
        $this->ensureAdmin();

        abort_unless($merchant->kyc_ktp_path, 404, 'KTP tidak ada.');
        abort_unless(Storage::disk('local')->exists($merchant->kyc_ktp_path), 404, 'File KTP hilang.');

        return response()->file(Storage::disk('local')->path($merchant->kyc_ktp_path));
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $this->ensureAdmin();
        $data = $request->validate([
            'fee_buyer_percent' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'admin_fee_per_item' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'seller_fee_per_item' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            // API key Biteship (jasa kirim): simpan utuh, tampil utuh hanya ke admin.
            'biteship_api_key' => ['sometimes', 'string', 'max:256'],
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
    public function processWithdrawal(Request $request, Withdrawal $withdrawal, BalanceService $balance, \App\Services\NotificationService $notif): JsonResponse
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

        $notif->push(
            $wd->merchant_id,
            'withdrawal.status',
            $data['decision'] === 'approved' ? 'Penarikan disetujui' : 'Penarikan ditolak',
            $data['decision'] === 'approved'
                ? 'Penarikan Rp'.number_format($wd->amount, 0, ',', '.').' disetujui, dana akan ditransfer.'
                : 'Penarikan Rp'.number_format($wd->amount, 0, ',', '.').' ditolak, saldo dikembalikan.'.($data['note'] ? " Alasan: {$data['note']}" : ''),
            '/seller/balance',
        );

        return response()->json(['data' => ['id' => $wd->id, 'status' => $wd->status]]);
    }
}