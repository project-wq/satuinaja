<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Services\FacebookService;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ChannelController extends Controller
{
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
    public function verify(Request $request, Channel $channel, FacebookService $facebook): JsonResponse
    {
        abort_unless($channel->merchant_id === $request->user()->merchant->id, 403);

        $result = match ($channel->platform) {
            'facebook', 'instagram' => $facebook->verify($channel),
            default => ['ok' => false, 'error' => "Verifikasi {$channel->platform} belum tersedia."],
        };

        Audit::record('channel.verify', $channel, $result['data'] ?? [], $result['ok'] ? 'ok' : 'error');

        return response()->json($result, $result['ok'] ? 200 : 422);
    }

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
            'last_sync_at' => $channel->last_sync_at,
            'last_error' => $channel->last_error,
        ];
    }
}
