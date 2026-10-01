<?php

namespace Database\Seeders;

use App\Models\Channel;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::factory()->create([
            'name' => 'Demo Merchant',
            'email' => 'demo@satuinaja.test',
            'role' => 'merchant',
        ]);

        $merchant = Merchant::create([
            'user_id' => $user->id,
            'name' => 'Toko Demo',
            'slug' => 'toko-demo',
            'description' => 'Toko contoh untuk uji coba storefront.',
            'phone' => '081234567890',
            'address' => 'Jl. Contoh No. 1, Jakarta',
            'city_id' => 152,
            'active' => true,
        ]);

        foreach ([
            ['facebook', 'Facebook Page Toko Demo'],
            ['instagram', 'IG @tokodemo'],
            ['shopee', 'Shopee Toko Demo'],
            ['tiktok', 'TikTok Shop Toko Demo'],
        ] as [$platform, $label]) {
            Channel::create([
                'merchant_id' => $merchant->id,
                'platform' => $platform,
                'label' => $label,
                'credentials' => ['placeholder' => true],
                'active' => false,
            ]);
        }

        foreach ([
            ['Kaos Polos Cotton Combed 30s', 65000, 120],
            ['Hoodie Fleece Premium', 185000, 45],
            ['Kemeja Flanel Lengan Panjang', 145000, 60],
            ['Celana Chino Slim Fit', 175000, 30],
            ['Topi Baseball Custom', 55000, 200],
        ] as $i => [$title, $price, $stock]) {
            Product::create([
                'merchant_id' => $merchant->id,
                'title' => $title,
                'slug' => Str::slug($title),
                'description' => "{$title} — bahan berkualitas, jahitan rapi, ready stock.",
                'price' => $price,
                'stock' => $stock,
                'weight' => 500,
                'images' => [],
                'status' => 'active',
            ]);
        }
    }
}
