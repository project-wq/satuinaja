<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Integrasi Midtrans Snap (sandbox/production).
 * Seller cukup isi server_key + client_key di pengaturan.
 */
class MidtransService
{
    public function isEnabled(): bool
    {
        return (string) config('services.midtrans.server_key') !== '';
    }

    /**
     * Buat transaksi Snap dari payload generik (dipakai langganan plan).
     * $payload: order_no, total, buyer_name, buyer_email, buyer_phone, items[].
     */
    public function createSnapGeneric(array $payload): array
    {
        $serverKey = (string) config('services.midtrans.server_key');
        if ($serverKey === '') {
            return ['ok' => false, 'error' => 'MIDTRANS_SERVER_KEY belum diset.'];
        }

        $isSandbox = (bool) config('services.midtrans.sandbox', true);
        $baseUrl = $isSandbox
            ? 'https://app.sandbox.midtrans.com/snap/v1'
            : 'https://app.midtrans.com/snap/v1';

        try {
            $res = Http::withBasicAuth($serverKey, '')
                ->timeout(30)
                ->acceptJson()
                ->post("{$baseUrl}/transactions", [
                    'transaction_details' => [
                        'order_id' => $payload['order_no'],
                        'gross_amount' => $payload['total'],
                    ],
                    'item_details' => array_map(fn ($i) => [
                        'id' => (string) $i['product_id'],
                        'price' => $i['price'],
                        'quantity' => $i['qty'],
                        'name' => mb_substr($i['title'], 0, 50),
                    ], $payload['items']),
                    'customer_details' => [
                        'first_name' => $payload['buyer_name'],
                        'email' => $payload['buyer_email'] ?? null,
                        'phone' => $payload['buyer_phone'] ?? null,
                    ],
                    'expiry' => ['unit' => 'hours', 'duration' => 24],
                ]);

            if (! $res->successful()) {
                return [
                    'ok' => false,
                    'error' => data_get($res->json(), 'error_messages.0', 'Midtrans HTTP '.$res->status()),
                ];
            }

            return [
                'ok' => true,
                'data' => [
                    'token' => data_get($res->json(), 'token'),
                    'redirect_url' => data_get($res->json(), 'redirect_url'),
                ],
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Buat transaksi Snap → menghasilkan redirect URL pembayaran.
     */
    public function createSnap(Order $order): array
    {
        $serverKey = (string) config('services.midtrans.server_key');
        if ($serverKey === '') {
            return ['ok' => false, 'error' => 'MIDTRANS_SERVER_KEY belum diset.'];
        }

        $isSandbox = (bool) config('services.midtrans.sandbox', true);
        $baseUrl = $isSandbox
            ? 'https://app.sandbox.midtrans.com/snap/v1'
            : 'https://app.midtrans.com/snap/v1';

        $items = $order->items->map(fn ($i) => [
            'id' => (string) $i->product_id,
            'price' => $i->price,
            'quantity' => $i->qty,
            'name' => mb_substr($i->title, 0, 50),
        ])->values()->all();

        try {
            $res = Http::withBasicAuth($serverKey, '')
                ->timeout(30)
                ->acceptJson()
                ->post("{$baseUrl}/transactions", [
                    'transaction_details' => [
                        'order_id' => $order->order_no,
                        'gross_amount' => $order->total,
                    ],
                    'item_details' => $items,
                    'customer_details' => [
                        'first_name' => $order->buyer_name,
                        'email' => $order->buyer_email ?? null,
                        'phone' => $order->buyer_phone,
                    ],
                    'expiry' => ['unit' => 'hours', 'duration' => 24],
                ]);

            if (! $res->successful()) {
                return [
                    'ok' => false,
                    'error' => data_get($res->json(), 'error_messages.0', 'Midtrans HTTP '.$res->status()),
                ];
            }

            $snapToken = data_get($res->json(), 'token');
            $redirectUrl = data_get($res->json(), 'redirect_url');

            $order->update([
                'payment_ref' => $snapToken,
                'payment_url' => $redirectUrl,
            ]);

            return ['ok' => true, 'data' => [
                'token' => $snapToken,
                'redirect_url' => $redirectUrl,
            ]];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Validasi signature callback Midtrans (server-side, wajib).
     */
    public function isValidSignature(array $payload): bool
    {
        $orderId = (string) ($payload['order_id'] ?? '');
        $statusCode = (string) ($payload['status_code'] ?? '');
        $grossAmount = (string) ($payload['gross_amount'] ?? '');
        $serverKey = (string) config('services.midtrans.server_key');

        if ($serverKey === '' || $orderId === '') {
            return false;
        }

        $expected = hash('sha512', $orderId.$statusCode.$grossAmount.$serverKey);

        return hash_equals($expected, (string) ($payload['signature_key'] ?? ''));
    }

    public function generateOrderNo(): string
    {
        return 'ORD-'.now()->format('ymd').'-'.Str::upper(Str::random(6));
    }

    /**
     * Status kesiapan produksi (Fase 7).
     * Kembalikan: mode, kredensial lengkap?, + hasil ping live ke Midtrans.
     */
    public function readiness(): array
    {
        $serverKey = (string) config('services.midtrans.server_key');
        $clientKey = (string) config('services.midtrans.client_key');
        $sandbox = (bool) config('services.midtrans.sandbox', true);

        $configured = $serverKey !== '' && $clientKey !== '';

        // Cek live: GET /v2/channels (dokumented, basic auth server_key).
        // Kunci salah → 401; kunci benar → 200 daftar payment channel aktif.
        $reachable = null;
        $note = null;
        if ($serverKey !== '') {
            $base = $sandbox
                ? 'https://api.sandbox.midtrans.com'
                : 'https://api.midtrans.com';
            try {
                $res = Http::withBasicAuth($serverKey, '')
                    ->timeout(15)
                    ->acceptJson()
                    ->get("{$base}/v2/channels");
                $reachable = $res->status() !== 401;
                $note = $reachable
                    ? 'Kredensial Midtrans valid.'
                    : 'Kredensial ditolak Midtrans (HTTP 401).';
            } catch (\Throwable $e) {
                $reachable = false;
                $note = 'Tidak bisa menghubungi Midtrans: '.$e->getMessage();
            }
        }

        return [
            'mode' => $sandbox ? 'sandbox' : 'production',
            'enabled' => $serverKey !== '',
            'configured' => $configured,
            'has_client_key' => $clientKey !== '',
            'reachable' => $reachable,
            'note' => $note ?? 'MIDTRANS_SERVER_KEY belum diset.',
        ];
    }
}