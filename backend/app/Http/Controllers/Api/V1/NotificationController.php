<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Notifikasi in-app merchant (fase 6).
 */
class NotificationController extends Controller
{
    public function __construct(private NotificationService $notif)
    {
    }

    /** Daftar notifikasi + jumlah belum dibaca. */
    public function index(Request $request): JsonResponse
    {
        $merchant = $request->user()->effectiveMerchant();

        $items = Notification::where('merchant_id', $merchant->id)
            ->when($request->boolean('unread_only'), fn ($q) => $q->whereNull('read_at'))
            ->latest()
            ->paginate(20);

        return response()->json([
            'data' => $items->items(),
            'meta' => [
                'current_page' => $items->currentPage(),
                'last_page' => $items->lastPage(),
                'total' => $items->total(),
                'unread' => $this->notif->unreadCount($merchant),
            ],
        ]);
    }

    /** Tandai satu notifikasi sudah dibaca. */
    public function read(Request $request, Notification $notification): JsonResponse
    {
        abort_unless($notification->merchant_id === $request->user()->effectiveMerchant()->id, 403);
        $notification->update(['read_at' => now()]);

        return response()->json(['data' => $notification->fresh()]);
    }

    /** Tandai semua notifikasi sudah dibaca. */
    public function readAll(Request $request): JsonResponse
    {
        $count = $this->notif->markRead($request->user()->effectiveMerchant());

        return response()->json(['data' => ['marked' => $count]]);
    }

    /** Hapus notifikasi. */
    public function destroy(Request $request, Notification $notification): JsonResponse
    {
        abort_unless($notification->merchant_id === $request->user()->effectiveMerchant()->id, 403);
        $notification->delete();

        return response()->json(['message' => 'Dihapus.']);
    }
}
