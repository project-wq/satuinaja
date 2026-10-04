<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    /** Payload registrasi lengkap (alamat Biteship + KYC). */
    private function registerPayload(array $over = []): array
    {
        return array_merge([
            'name' => 'Siti',
            'email' => 'siti@example.test',
            'password' => 'rahasia123',
            'password_confirmation' => 'rahasia123',
            'store_name' => 'Toko Siti',
            'phone' => '08120000001',
            'address' => 'Jl. Melati No. 1',
            'province' => 'DKI Jakarta',
            'city_name' => 'Jakarta Selatan',
            'district' => 'Cilandak',
            'postal_code' => '12430',
            'kyc_nik' => '3171010101010001',
            'kyc_ktp' => UploadedFile::fake()->image('ktp.jpg', 400, 250),
        ], $over);
    }

    public function test_merchant_can_register_and_gets_a_store(): void
    {
        $res = $this->post('/api/v1/auth/register', $this->registerPayload(), ['Accept' => 'application/json']);
        $res->assertCreated()
            ->assertJsonPath('data.email', 'siti@example.test')
            ->assertJsonPath('data.merchant.name', 'Toko Siti')
            ->assertJsonPath('data.merchant.kyc_status', 'pending');

        // Toko baru menunggu persetujuan KYC admin → belum aktif.
        $this->assertDatabaseHas('merchants', [
            'name' => 'Toko Siti',
            'kyc_status' => 'pending',
            'postal_code' => '12430',
            'active' => false,
        ]);
    }

    public function test_register_rejects_missing_address_or_kyc(): void
    {
        $this->post('/api/v1/auth/register', $this->registerPayload([
            'postal_code' => '123',
            'kyc_nik' => '123',
        ]), ['Accept' => 'application/json'])->assertStatus(422);
    }

    public function test_register_rejects_duplicate_email(): void
    {
        User::factory()->create(['email' => 'ada@example.test']);

        $this->post('/api/v1/auth/register', $this->registerPayload([
            'name' => 'X',
            'email' => 'ada@example.test',
            'store_name' => 'Toko X',
        ]), ['Accept' => 'application/json'])->assertStatus(422);
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
