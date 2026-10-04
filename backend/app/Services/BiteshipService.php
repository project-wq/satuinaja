<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Integrasi Biteship (multi-kurir): cek ongkir, create order + resi, tracking.
 * Docs: https://biteship.com/id/docs/intro
 * Base: https://api.biteship.com — auth: HTTP header `authorization: <API key>`
 * (token prefix biteship_live. / biteship_test.).
 *
 * API key disimpan admin panel (Setting: biteship_api_key) dengan fallback
 * config('services.biteship.key').
 */
class BiteshipService
{
    private const BASE = 'https://api.biteship.com';

    /** Kurir reguler default yang ditawarkan ke pembeli. */
    public const DEFAULT_COURIERS = 'jne,sicepat,jnt,anteraja,pos,tiki,ninja,lion,wahana,sap,ide,idexpress';

    public function apiKey(): string
    {
        $key = Setting::get('biteship_api_key', '');
        if ($key === '') {
            $key = (string) config('services.biteship.key', '');
        }

        return trim($key);
    }

    public function enabled(): bool
    {
        return $this->apiKey() !== '';
    }

    /** Cek ongkir. Lokasi: kode pos (origin dari toko, tujuan dari pembeli). */
    public function rates(int $originPostal, int $destinationPostal, int $weightGram, string $couriers, int $value = 0): array
    {
        if (! $this->enabled()) {
            return ['ok' => false, 'error' => 'API key Biteship belum diatur. Setel di Admin → Pengaturan.'];
        }

        try {
            $res = $this->client()->post(self::BASE.'/v1/rates/couriers', [
                'origin_postal_code' => $originPostal,
                'destination_postal_code' => $destinationPostal,
                'couriers' => $couriers !== '' ? $couriers : self::DEFAULT_COURIERS,
                'items' => [[
                    'name' => 'Paket',
                    'value' => max($value, 1),
                    'quantity' => 1,
                    'weight' => max($weightGram, 1),
                ]],
            ]);

            if (! $res->successful()) {
                return ['ok' => false, 'error' => 'Biteship HTTP '.$res->status().': '.substr((string) $res->body(), 0, 300)];
            }

            $body = $res->json();
            if (data_get($body, 'success') !== true) {
                return ['ok' => false, 'error' => data_get($body, 'error', 'Gagal mengambil ongkir Biteship.')];
            }

            $services = collect(data_get($body, 'pricing', []))->map(fn ($p) => [
                'courier' => data_get($p, 'courier_code'),
                'service' => data_get($p, 'service_type') ?? data_get($p, 'service_name'),
                'name' => data_get($p, 'courier_name'),
                'description' => data_get($p, 'service_name') ?? data_get($p, 'courier_name'),
                'cost' => (int) data_get($p, 'price', 0),
                'etd' => data_get($p, 'duration') ? (string) data_get($p, 'duration') : (string) data_get($p, 'shipping_fee.etd', ''),
                'insured' => (bool) data_get($p, 'is_insurance_available', false),
            ])->values()->all();

            return ['ok' => true, 'data' => [
                'services' => $services,
                'service' => null,
                'courier_name' => null,
                'available_collection_method' => data_get($body, 'available_collection_method', ['pickup']),
            ]];
        } catch (\Throwable $e) {
            Log::warning('biteship.rates.failed', ['error' => $e->getMessage()]);

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Buat order pengiriman → langsung dapat nomor resi (waybill).
     * $payload: origin_* + destination_* + courier_company/courier_type + items.
     */
    public function createOrder(array $payload): array
    {
        if (! $this->enabled()) {
            return ['ok' => false, 'error' => 'API key Biteship belum diatur. Setel di Admin → Pengaturan, atau kirim resi manual.'];
        }

        try {
            $res = $this->client()->post(self::BASE.'/v1/orders', $payload);

            $body = $res->json();
            if (! $res->successful() || data_get($body, 'success') !== true) {
                return ['ok' => false, 'error' => data_get($body, 'error', 'Biteship HTTP '.$res->status())];
            }

            return ['ok' => true, 'data' => [
                'id' => data_get($body, 'id'),
                'waybill_id' => data_get($body, 'courier.waybill_id'),
                'tracking_id' => data_get($body, 'courier.tracking_id'),
                'routing_code' => data_get($body, 'courier.routing_code'),
                'courier_company' => data_get($body, 'courier.company'),
                'courier_type' => data_get($body, 'courier.type'),
                'price' => (int) data_get($body, 'price', 0),
                'status' => data_get($body, 'status'),
            ]];
        } catch (\Throwable $e) {
            Log::warning('biteship.createOrder.failed', ['error' => $e->getMessage()]);

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** Lacak status pengiriman berdasarkan order id Biteship. */
    public function track(string $biteshipOrderId): array
    {
        if (! $this->enabled()) {
            return ['ok' => false, 'error' => 'API key Biteship belum diatur.'];
        }

        try {
            $res = $this->client()->get(self::BASE.'/v1/trackings/'.urlencode($biteshipOrderId));
            $body = $res->json();

            if (! $res->successful() || data_get($body, 'success') !== true) {
                return ['ok' => false, 'error' => data_get($body, 'error', 'Biteship HTTP '.$res->status())];
            }

            $history = collect(data_get($body, 'history', []))->map(fn ($h) => [
                'date' => data_get($h, 'created_at'),
                'desc' => data_get($h, 'message'),
                'status' => data_get($h, 'status'),
            ])->values()->all();

            return ['ok' => true, 'data' => [
                'id' => data_get($body, 'id'),
                'status' => data_get($body, 'status'),
                'message' => data_get($body, 'message'),
                'courier' => data_get($body, 'courier_name'),
                'waybill' => data_get($body, 'waybill_id') ?? data_get($body, 'courier.waybill_id'),
                'history' => $history,
            ]];
        } catch (\Throwable $e) {
            Log::warning('biteship.track.failed', ['error' => $e->getMessage()]);

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** Tes koneksi: hit endpoint publik couriers dengan key admin. */
    public function ping(): array
    {
        if (! $this->enabled()) {
            return ['ok' => false, 'error' => 'API key kosong.'];
        }

        try {
            $res = $this->client()->get(self::BASE.'/v1/couriers');
            if ($res->successful()) {
                return ['ok' => true, 'message' => 'Koneksi berhasil.'];
            }

            return ['ok' => false, 'error' => 'Biteship HTTP '.$res->status()];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private function client()
    {
        return Http::withHeaders([
            'authorization' => $this->apiKey(),
            'content-type' => 'application/json',
        ])->timeout(30);
    }
}
