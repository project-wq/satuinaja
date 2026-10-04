<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'role', 'owner_merchant_id', 'two_factor_secret'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function merchant(): HasOne
    {
        return $this->hasOne(Merchant::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /** Merchant bawahan staf (bila role=staff). */
    public function ownerMerchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class, 'owner_merchant_id');
    }

    /**
     * Merchant efektif: pemilik toko sendiri, atau toko majikan staf.
     * Semua controller seller pakai helper ini (ganti $user->merchant).
     */
    public function effectiveMerchant(): ?Merchant
    {
        if ($this->role === 'staff') {
            return $this->ownerMerchant()->first();
        }

        return $this->merchant()->first();
    }

    /** Staf dilarang akses keuangan (saldo/withdraw/refund/billing). */
    public function isStaff(): bool
    {
        return $this->role === 'staff';
    }
}
