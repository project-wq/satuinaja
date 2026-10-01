<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Merchant $merchant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['role' => 'merchant']);
        $this->merchant = Merchant::create([
            'user_id' => $this->user->id,
            'name' => 'Toko A',
            'slug' => 'toko-a',
            'active' => true,
        ]);
    }

    public function test_merchant_can_create_product(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/products', [
                'title' => 'Kaos Polos',
                'price' => 65000,
                'stock' => 10,
                'weight' => 500,
                'status' => 'active',
            ])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Kaos Polos');

        $this->assertDatabaseHas('products', ['title' => 'Kaos Polos', 'merchant_id' => $this->merchant->id]);
    }

    public function test_product_requires_valid_payload(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/products', ['title' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['title', 'price', 'stock', 'weight']);
    }

    public function test_merchant_only_sees_own_products(): void
    {
        $other = User::factory()->create(['role' => 'merchant']);
        $otherMerchant = Merchant::create([
            'user_id' => $other->id, 'name' => 'Toko B', 'slug' => 'toko-b', 'active' => true,
        ]);

        Product::create([
            'merchant_id' => $this->merchant->id, 'title' => 'Punya A', 'slug' => 'punya-a',
            'price' => 1000, 'stock' => 1, 'weight' => 100, 'status' => 'active',
        ]);
        Product::create([
            'merchant_id' => $otherMerchant->id, 'title' => 'Punya B', 'slug' => 'punya-b',
            'price' => 2000, 'stock' => 1, 'weight' => 100, 'status' => 'active',
        ]);

        $titles = collect(
            $this->actingAs($this->user)->getJson('/api/v1/products')->json('data')
        )->pluck('title');

        $this->assertTrue($titles->contains('Punya A'));
        $this->assertFalse($titles->contains('Punya B'), 'Tenant isolation bocor.');
    }

    /**
     * Global scope merchant menyembunyikan produk milik merchant lain, sehingga
     * route model binding gagal dan Laravel membalas 404 (bukan 403). Ini lebih
     * aman: penyerang tidak bisa membedakan "tidak ada" vs "ada tapi bukan milikmu".
     */
    public function test_merchant_cannot_update_another_merchants_product(): void
    {
        $other = User::factory()->create(['role' => 'merchant']);
        $otherMerchant = Merchant::create([
            'user_id' => $other->id, 'name' => 'Toko B', 'slug' => 'toko-b', 'active' => true,
        ]);
        $foreign = Product::create([
            'merchant_id' => $otherMerchant->id, 'title' => 'Rahasia', 'slug' => 'rahasia',
            'price' => 5000, 'stock' => 1, 'weight' => 100, 'status' => 'active',
        ]);

        $this->actingAs($this->user)
            ->putJson("/api/v1/products/{$foreign->id}", ['price' => 1])
            ->assertNotFound();

        $this->assertSame(5000, $foreign->fresh()->price, 'Produk merchant lain berubah!');
    }

    public function test_publish_without_active_channel_returns_422(): void
    {
        $product = Product::create([
            'merchant_id' => $this->merchant->id, 'title' => 'P', 'slug' => 'p',
            'price' => 1000, 'stock' => 1, 'weight' => 100, 'status' => 'active',
        ]);

        $this->actingAs($this->user)
            ->postJson("/api/v1/products/{$product->id}/publish")
            ->assertStatus(422);
    }

    public function test_publish_queues_job_for_active_channel(): void
    {
        \Illuminate\Support\Facades\Queue::fake();

        $product = Product::create([
            'merchant_id' => $this->merchant->id, 'title' => 'P', 'slug' => 'p',
            'price' => 1000, 'stock' => 1, 'weight' => 100, 'status' => 'active',
        ]);

        Channel::create([
            'merchant_id' => $this->merchant->id, 'platform' => 'facebook',
            'label' => 'FB', 'credentials' => ['page_id' => '1', 'page_token' => 't'],
            'active' => true,
        ]);

        $this->actingAs($this->user)
            ->postJson("/api/v1/products/{$product->id}/publish")
            ->assertStatus(202);

        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\PublishToChannel::class);
    }

    public function test_product_slug_is_unique_per_merchant(): void
    {
        foreach ([1, 2] as $i) {
            $this->actingAs($this->user)->postJson('/api/v1/products', [
                'title' => 'Produk Sama',
                'price' => 1000,
                'stock' => 5,
                'weight' => 100,
                'status' => 'draft',
            ])->assertCreated();
        }

        $slugs = Product::pluck('slug')->all();

        $this->assertCount(2, array_unique($slugs), 'Slug duplikat dalam satu merchant.');
    }
}
