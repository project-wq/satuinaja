<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Fase 20: kelola sub-akun staf toko.
 * Hanya pemilik toko (role=merchant). Staf login seperti biasa, izin per-modul
 * dari staff_permissions milik toko. Staf TIDAK bisa: saldo/withdraw/refund,
 * langganan, pengaturan toko, kelola staf lain.
 */
class StaffController extends Controller
{
    public const MODULES = ['products', 'orders', 'vouchers', 'channels', 'reviews'];

    private function owner(Request $request): Merchant
    {
        abort_unless($request->user()->role === 'merchant', 403, 'Hanya pemilik toko.');
        $m = $request->user()->effectiveMerchant();
        abort_unless($m, 403);

        return $m;
    }

    /** Daftar staf toko. */
    public function index(Request $request): JsonResponse
    {
        $m = $this->owner($request);

        return response()->json([
            'data' => User::where('owner_merchant_id', $m->id)
                ->where('role', 'staff')
                ->get(['id', 'name', 'email', 'created_at'])
                ->map(fn ($u) => [
                    'id' => $u->id,
                    'name' => $u->name,
                    'email' => $u->email,
                    'permissions' => $m->staff_permissions[$u->id] ?? self::MODULES,
                    'created_at' => $u->created_at,
                ]),
            'modules' => self::MODULES,
        ]);
    }

    /** Undang staf (buat akun). */
    public function store(Request $request): JsonResponse
    {
        $m = $this->owner($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'max:72'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', 'in:'.implode(',', self::MODULES)],
        ]);

        $staff = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'role' => 'staff',
            'owner_merchant_id' => $m->id,
        ]);

        $perms = $m->staff_permissions ?? [];
        $perms[$staff->id] = $data['permissions'] ?? self::MODULES;
        $m->update(['staff_permissions' => $perms]);

        return response()->json(['data' => ['id' => $staff->id, 'email' => $staff->email]], 201);
    }

    /** Ubah izin staf / reset password. */
    public function update(Request $request, User $staff): JsonResponse
    {
        $m = $this->owner($request);
        abort_unless($staff->role === 'staff' && $staff->owner_merchant_id === $m->id, 404);

        $data = $request->validate([
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', 'in:'.implode(',', self::MODULES)],
            'password' => ['nullable', 'string', 'min:8', 'max:72'],
        ]);

        if (array_key_exists('permissions', $data)) {
            $perms = $m->staff_permissions ?? [];
            $perms[$staff->id] = $data['permissions'] ?? self::MODULES;
            $m->update(['staff_permissions' => $perms]);
        }
        if (! empty($data['password'])) {
            $staff->update(['password' => $data['password']]);
        }

        return response()->json(['data' => ['id' => $staff->id]]);
    }

    /** Cabut akses staf (hapus akun). */
    public function destroy(Request $request, User $staff): JsonResponse
    {
        $m = $this->owner($request);
        abort_unless($staff->role === 'staff' && $staff->owner_merchant_id === $m->id, 404);

        $perms = $m->staff_permissions ?? [];
        unset($perms[$staff->id]);
        $m->update(['staff_permissions' => $perms]);
        $staff->delete();

        return response()->json(['message' => 'Akses staf dicabut.']);
    }
}
