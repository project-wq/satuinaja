<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;

class ProductPolicy
{
    /**
     * Admin boleh semua; merchant hanya produk tokonya sendiri.
     */
    private function owns(User $user, Product $product): bool
    {
        return $user->role === 'admin'
            || $user->merchant?->id === $product->merchant_id;
    }

    public function view(User $user, Product $product): bool
    {
        return $this->owns($user, $product);
    }

    public function update(User $user, Product $product): bool
    {
        return $this->owns($user, $product);
    }

    public function delete(User $user, Product $product): bool
    {
        return $this->owns($user, $product);
    }
}
