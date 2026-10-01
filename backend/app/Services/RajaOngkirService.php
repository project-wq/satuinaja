<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cek ongkir via RajaOngkir.
 * Mode 'starter' (gratis): cukup API key tanpa akun berbayar.
 */
class RajaOngkirService
{
    public function costs(string $origin, string $destiny, int $weight, string $courier): array
    {
        $key = (string) config('services.rajaongkir.key');
        if ($key === '') {
            return ['ok' => false, 'error' => 'RAJAONGKIR_API_KEY belum diset.'];
        }

        try {
            $res = Http::withHeaders(['key' => $key])
                ->timeout(20)
                ->asForm()
                ->post('https://api.rajaongkir.com/starter/cost', [
                    'origin' => $origin,
                    'destination' => $destiny,
                    'weight' => $weight,
                    'courier' => $courier,
                ]);

            if (! $res->successful()) {
                return ['ok' => false, 'error' => 'RajaOngkir HTTP '.$res->status()];
            }

            $body = $res->json();
            $results = data_get($body, 'rajaongkir.results', []);

            $services = collect($results)
                ->flatMap(fn ($r) => collect(data_get($r, 'costs', []))->map(
                    fn ($c) => [
                        'service' => data_get($c, 'service'),
                        'description' => data_get($c, 'description'),
                        'cost' => data_get($c, 'cost.0.value'),
                        'etd' => data_get($c, 'cost.0.etd'),
                    ]
                ))
                ->values()
                ->all();

            return ['ok' => true, 'data' => ['services' => $services]];
        } catch (\Throwable $e) {
            Log::warning('rajaongkir.failed', ['error' => $e->getMessage()]);

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public function cities(): array
    {
        $key = (string) config('services.rajaongkir.key');
        if ($key === '') {
            return ['ok' => false, 'error' => 'RAJAONGKIR_API_KEY belum diset.'];
        }

        $res = Http::withHeaders(['key' => $key])
            ->timeout(20)
            ->get('https://api.rajaongkir.com/starter/city');

        if (! $res->successful()) {
            return ['ok' => false, 'error' => 'RajaOngkir HTTP '.$res->status()];
        }

        $rows = data_get($res->json(), 'rajaongkir.results', []);

        return ['ok' => true, 'data' => [
            'cities' => collect($rows)->map(fn ($c) => [
                'id' => (string) data_get($c, 'city_id'),
                'name' => data_get($c, 'city_name'),
                'type' => data_get($c, 'type'),
                'province' => data_get($c, 'province'),
            ])->values()->all(),
        ]];
    }
}