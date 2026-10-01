<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_merchant_can_register_and_gets_a_store(): void
    {
        $res = $this->postJson('/api/v1/auth/register', [
            'name' => 'Siti',
            'email' => 'siti@example.test',
            'password' => 'rahasia123',
            'password_confirmation' => 'rahasia123',
            'store_name' => 'Toko Siti',
        ]);

        $res->assertCreated()
            ->assertJsonPath('data.email', 'siti@example.test')
            ->assertJsonPath('data.merchant.name', 'Toko Siti');

        $this->assertDatabaseHas('merchants', ['name' => 'Toko Siti']);
    }

    public function test_register_rejects_duplicate_email(): void
    {
        User::factory()->create(['email' => 'ada@example.test']);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'X',
            'email' => 'ada@example.test',
            'password' => 'rahasia123',
            'password_confirmation' => 'rahasia123',
            'store_name' => 'Toko X',
        ])->assertStatus(422);
    }

    public function test_login_succeeds_with_correct_password(): void
    {
        $user = User::factory()->create(['password' => 'rahasia123', 'role' => 'merchant']);
        Merchant::create([
            'user_id' => $user->id, 'name' => 'T', 'slug' => 't', 'active' => true,
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'rahasia123',
        ])->assertOk()->assertJsonPath('data.email', $user->email);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        $user = User::factory()->create(['password' => 'rahasia123']);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'salah-banget',
        ])->assertStatus(422);
    }

    public function test_login_is_rate_limited_after_five_attempts(): void
    {
        $user = User::factory()->create(['password' => 'rahasia123']);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => $user->email, 'password' => 'password-salah',
            ]);
        }

        $res = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => 'password-salah',
        ]);

        $res->assertStatus(422);
        $this->assertStringContainsString('Terlalu banyak', $res->json('errors.email.0', ''));
    }

    public function test_protected_endpoint_requires_auth(): void
    {
        $this->getJson('/api/v1/products')->assertUnauthorized();
    }

    public function test_failed_login_is_written_to_audit_log(): void
    {
        $user = User::factory()->create(['password' => 'rahasia123']);

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password-salah']);

        $this->assertDatabaseHas('audit_logs', ['action' => 'login.failed', 'status' => 'error']);
    }
}
