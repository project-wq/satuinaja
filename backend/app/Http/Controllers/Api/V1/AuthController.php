<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Login → set HttpOnly session cookie (Sanctum SPA mode).
     * Tidak mengembalikan token ke JS.
     */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            // Login tidak menuntut panjang minimum: password lama pengguna bisa
            // lebih pendek. Aturan kekuatan hanya berlaku saat registrasi.
            'password' => ['required', 'string'],
        ]);

        $key = 'login:'.Str::lower($data['email']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            Audit::record('login.throttled', payload: ['email' => $data['email']], status: 'error');

            throw ValidationException::withMessages([
                'email' => 'Terlalu banyak percobaan. Coba lagi dalam '.RateLimiter::availableIn($key).' detik.',
            ]);
        }

        if (! Auth::attempt($data, false)) {
            RateLimiter::hit($key, 60);
            Audit::record('login.failed', payload: ['email' => $data['email']], status: 'error');

            throw ValidationException::withMessages(['email' => 'Email atau password salah.']);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();

        Audit::record('login.success', $request->user());

        return response()->json(['data' => $this->profile($request->user())]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->profile($request->user())]);
    }

    public function logout(Request $request): JsonResponse
    {
        Audit::record('logout', $request->user());

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Logged out.']);
    }

    /**
     * Registrasi merchant baru + toko default.
     *
     * Wajib: alamat pickup lengkap (dipakai Biteship sebagai origin cek
     * ongkir & penjemputan kurir) + identitas KYC (NIK + foto KTP).
     * Toko baru berstatus KYC pending → menunggu persetujuan admin.
     */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'store_name' => ['required', 'string', 'max:80'],
            'phone' => ['nullable', 'string', 'max:32'],
            'address' => ['required', 'string', 'max:500'],
            'province' => ['required', 'string', 'max:80'],
            'city_name' => ['required', 'string', 'max:80'],
            'district' => ['required', 'string', 'max:80'],
            'postal_code' => ['required', 'string', 'regex:/^\d{5}$/'],
            'kyc_nik' => ['required', 'string', 'regex:/^\d{16}$/'],
            'kyc_ktp' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ]);

        $ktpPath = $request->file('kyc_ktp')->store('kyc', 'local');

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'role' => 'merchant',
        ]);

        Merchant::create([
            'user_id' => $user->id,
            'name' => $data['store_name'],
            'slug' => $this->uniqueSlug($data['store_name']),
            'phone' => $data['phone'] ?? null,
            'address' => $data['address'],
            'province' => $data['province'],
            'city_name' => $data['city_name'],
            'district' => $data['district'],
            'postal_code' => $data['postal_code'],
            'kyc_status' => 'pending',
            'kyc_nik' => $data['kyc_nik'],
            'kyc_ktp_path' => $ktpPath,
            'kyc_submitted_at' => now(),
            'active' => false,
        ]);

        Audit::record('merchant.registered', $user);

        return response()->json(['data' => $this->profile($user->fresh())], 201);
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'toko';
        $slug = $base;
        $i = 1;
        while (Merchant::where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$i);
        }

        return $slug;
    }

    private function profile(User $user): array
    {
        $eff = $user->effectiveMerchant();

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'merchant' => $eff ? [
                'id' => $eff->id,
                'name' => $eff->name,
                'slug' => $eff->slug,
                'kyc_status' => $eff->kyc_status,
                'postal_code' => $eff->postal_code,
            ] : null,
        ];
    }
}
