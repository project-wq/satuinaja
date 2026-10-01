<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cek status resi pengiriman via BinderByte (butuh key API BinderByte).
 */
class ResiService
{
    public function track(string $courier, string $trackingNo): array
    {
        $key = (string) config('services.binderbyte.key');
        if ($key === '') {
            return ['ok' => false, 'error' => 'BINDERBYTE_API_KEY belum diset.'];
        }

        try {
            $res = Http::timeout(20)->get('https://api.binderbyte.com/v1/track', [
                'api_key' => $key,
                'courier' => strtolower($courier),
                'awb' => $trackingNo,
            ]);

            if (! $res->successful()) {
                return ['ok' => false, 'error' => 'BinderByte HTTP '.$res->status()];
            }

            $body = $res->json();
            if (data_get($body, 'status') !== 200) {
                return ['ok' => false, 'error' => data_get($body, 'message', 'Resi tidak ditemukan.')];
            }

            $summary = data_get($body, 'data.summary');
            $history = collect(data_get($body, 'data.history', []))
                ->map(fn ($h) => [
                    'time' => data_get($h, 'time'),
                    'date' => data_get($h, 'date'),
                    'desc' => data_get($h, 'desc'),
                ])->values()->all();

            return ['ok' => true, 'data' => [
                'awb' => $trackingNo,
                'courier' => $courier,
                'status' => data_get($summary, 'status'),
                'message' => data_get($body, 'data.delivery_status.description'),
                'receiver' => data_get($summary, 'receiver'),
                'history' => $history,
            ]];
        } catch (\Throwable $e) {
            Log::warning('resi.track.failed', ['error' => $e->getMessage()]);

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}