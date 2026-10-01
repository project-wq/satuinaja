<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\PublishLog;
use App\Services\PublisherService;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ChannelController extends Controller
{
    public function __construct(private PublisherService $publisher)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $channels = $request->user()->merchant->channels()->latest()->get()
            ->map(fn (Channel $c) => $this->present($c));

        return response()->json(['data' => $channels]);
    }

    /**
     * Simpan kredensial channel seller. Credentials dienkripsi di DB
     * (cast encrypted:array) & tidak pernah dikembalikan ke frontend.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'platform' => ['required', Rule::in(Channel::PLATFORMS)],
            'label' => ['nullable', 'string', 'max:80'],
            'credentials' => ['required', 'array'],
            'credentials.*' => ['nullable', 'string', 'max:2000'],
        ]);

        $channel = Channel::updateOrCreate(
            [
                'merchant_id' => $request->user()->merchant->id,
                'platform' => $data['platform'],
            ],
            [
                'label' => $data['label'] ?? null,
                'credentials' => $data['credentials'],
                'active' => false,
                'last_error' => null,
            ],
        );

        Audit::record('channel.saved', $channel, [
            'platform' => $channel->platform,
            'keys' => array_keys($data['credentials']),
        ]);

        return response()->json(['data' => $this->present($channel)], 201);
    }

    public function update(Request $request, Channel $channel): JsonResponse
    {
        abort_unless($channel->merchant_id === $request->user()->merchant->id, 403);

        $data = $request->validate([
            'label' => ['nullable', 'string', 'max:80'],
            'active' => ['sometimes', 'boolean'],
            'credentials' => ['sometimes', 'array'],
            'credentials.*' => ['nullable', 'string', 'max:2000'],
        ]);

        if (array_key_exists('credentials', $data)) {
            $channel->credentials = array_merge((array) $channel->credentials, $data['credentials']);
        }
        if (array_key_exists('label', $data)) {
            $channel->label = $data['label'];
        }
        if (array_key_exists('active', $data)) {
            $channel->active = $data['active'];
        }
        $channel->save();

        Audit::record('channel.updated', $channel, ['active' => $channel->active]);

        return response()->json(['data' => $this->present($channel->fresh())]);
    }

    public function destroy(Request $request, Channel $channel): JsonResponse
    {
        abort_unless($channel->merchant_id === $request->user()->merchant->id, 403);

        Audit::record('channel.deleted', $channel, ['platform' => $channel->platform]);
        $channel->delete();

        return response()->json(['message' => 'Channel dihapus.']);
    }

    /**
     * Uji koneksi kredensial tanpa publish produk.
     */
    public function verify(Request $request, Channel $channel): JsonResponse
    {
        abort_unless($channel->merchant_id === $request->user()->merchant->id, 403);

        $result = $this->publisher->verify($channel);

        Audit::record('channel.verify', $channel, $result['data'] ?? [], $result['ok'] ? 'ok' : 'error');

        return response()->json($result, $result['ok'] ? 200 : 422);
    }

    /**
     * Riwayat publish channel ini + payload siap-pakai bila gagal.
     */
    public function logs(Request $request, Channel $channel): JsonResponse
    {
        abort_unless($channel->merchant_id === $request->user()->merchant->id, 403);

        $logs = PublishLog::with('product:id,title,slug')
            ->where('channel_id', $channel->id)
            ->latest()
            ->limit(50)
            ->get();

        return response()->json(['data' => $logs]);
    }

    /**
     * Daftar platform yang didukung + field kredensial yang dibutuhkan.
     * Dipakai frontend agar form channel selalu sinkron dengan backend.
     */
    public function platforms(): JsonResponse
    {
        return response()->json(['data' => self::PLATFORM_SPECS]);
    }

    public const PLATFORM_SPECS = [
        [
            'key' => 'facebook',
            'name' => 'Facebook Page',
            'supported' => true,
            'note' => 'Butuh Meta App + Page Access Token long-lived.',
            'fields' => [
                ['key' => 'page_id', 'label' => 'Page ID', 'secret' => false, 'placeholder' => '1234567890'],
                ['key' => 'page_token', 'label' => 'Page Access Token', 'secret' => true, 'placeholder' => 'EAAG...'],
            ],
        ],
        [
            'key' => 'instagram',
            'name' => 'Instagram Business',
            'supported' => true,
            'note' => 'IG wajib terhubung ke Facebook Page. Posting butuh URL gambar publik HTTPS.',
            'fields' => [
                ['key' => 'ig_user_id', 'label' => 'IG Business Account ID', 'secret' => false, 'placeholder' => '178414...'],
                ['key' => 'access_token', 'label' => 'Access Token', 'secret' => true, 'placeholder' => 'EAAG...'],
                ['key' => 'image_url', 'label' => 'URL gambar publik (opsional)', 'secret' => false, 'placeholder' => 'https://...'],
            ],
        ],
        [
            'key' => 'tiktok',
            'name' => 'TikTok',
            'supported' => true,
            'note' => 'TikTok wajib video. Isi video_url, atau salin caption yang disiapkan sistem.',
            'fields' => [
                ['key' => 'access_token', 'label' => 'Access Token', 'secret' => true, 'placeholder' => 'act...'],
                ['key' => 'video_url', 'label' => 'URL video HTTPS', 'secret' => false, 'placeholder' => 'https://...'],
                ['key' => 'privacy_level', 'label' => 'Privacy (SELF_ONLY / PUBLIC_TO_EVERYONE)', 'secret' => false, 'placeholder' => 'SELF_ONLY'],
            ],
        ],
        [
            'key' => 'shopee',
            'name' => 'Shopee',
            'supported' => true,
            'note' => 'Butuh daftar Shopee Open Platform. Isi sandbox=1 untuk uji coba.',
            'fields' => [
                ['key' => 'partner_id', 'label' => 'Partner ID', 'secret' => false, 'placeholder' => '2001234'],
                ['key' => 'partner_key', 'label' => 'Partner Key', 'secret' => true, 'placeholder' => ''],
                ['key' => 'shop_id', 'label' => 'Shop ID', 'secret' => false, 'placeholder' => '987654'],
                ['key' => 'access_token', 'label' => 'Access Token', 'secret' => true, 'placeholder' => ''],
                ['key' => 'sandbox', 'label' => 'Sandbox (1/0)', 'secret' => false, 'placeholder' => '1'],
            ],
        ],
        [
            'key' => 'tokopedia',
            'name' => 'Tokopedia',
            'supported' => true,
            'note' => 'API hanya untuk mitra resmi. Tanpa kemitraan, sistem beri payload siap-tempel.',
            'fields' => [
                ['key' => 'api_base', 'label' => 'API Base (dari kontrak mitra)', 'secret' => false, 'placeholder' => 'https://...'],
                ['key' => 'endpoint', 'label' => 'Endpoint tambah produk', 'secret' => false, 'placeholder' => '/v1/products'],
                ['key' => 'client_id', 'label' => 'Client ID', 'secret' => false, 'placeholder' => ''],
                ['key' => 'client_secret', 'label' => 'Client Secret', 'secret' => true, 'placeholder' => ''],
                ['key' => 'fs_id', 'label' => 'FS ID (ID toko)', 'secret' => false, 'placeholder' => ''],
            ],
        ],
    ];

    /**
     * Jangan pernah kirim credentials mentah ke frontend — hanya keys-nya.
     */
    private function present(Channel $channel): array
    {
        return [
            'id' => $channel->id,
            'platform' => $channel->platform,
            'label' => $channel->label,
            'active' => $channel->active,
            'credential_keys' => array_keys((array) $channel->credentials),
            'supported' => in_array($channel->platform, PublisherService::SUPPORTED, true),
            'last_sync_at' => $channel->last_sync_at,
            'last_error' => $channel->last_error,
        ];
    }
}
