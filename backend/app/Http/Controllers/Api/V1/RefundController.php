<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Refund;
use App\Services\NotificationService;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Refund / pengembalian dana (fase 6).
 * - Seller mengajukan refund untuk order yang sudah dibayar.
 * - Admin menyetujui (tarik seller_net dari saldo) atau menolak.
 */
class RefundController extends Controller
{
    public function __construct(private NotificationService $notif)
    {
    }

    /** Seller: daftar refund milik merchant. */
    public function index(Request $request): JsonResponse
    {
        $merchant = $request->user()->effectiveMerchant();

        return response()->json(
            Refund::where('merchant_id', $merchant->id)
                ->with('order:id,order_no,total,buyer_name')
                ->latest()->paginate(20),
        );
    }

    /** Seller: ajukan refund untuk sebuah order. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order_id' => ['required', 'integer', 'exists:orders,id'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $merchant = $request->user()->effectiveMerchant();

        $order = Order::withoutGlobalScope('merchant')
            ->where('merchant_id', $merchant->id)
            ->findOrFail($data['order_id']);

        if ($order->payment_status !== 'paid') {
            return response()->json(['error' => 'Hanya order yang sudah dibayar bisa direfund.'], 422);
        }

        if (Refund::where('order_id', $order->id)->where('status', 'pending')->exists()) {
            return response()->json(['error' => 'Sudah ada permintaan refund yang menunggu untuk order ini.'], 422);
        }

        $refund = Refund::create([
            'order_id' => $order->id,
            'merchant_id' => $merchant->id,
            'amount' => $order->seller_net,
            'reason' => $data['reason'],
            'status' => 'pending',
        ]);

        $this->notif->push(
            $merchant,
            'refund.requested',
            'Pengajuan refund dikirim',
            "Refund untuk order {$order->order_no} menunggu persetujuan admin.",
            '/seller/refunds',
        );

        Audit::record('refund.requested', $refund, ['order_no' => $order->order_no, 'amount' => $refund->amount]);

        return response()->json(['data' => $refund], 201);
    }

    /** Admin: daftar semua refund. */
    public function adminIndex(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isAdmin(), 403, 'Hanya admin.');

        return response()->json(
            Refund::with('order:id,order_no,total,buyer_name', 'merchant:id,name,slug')
                ->when($request->string('status')->toString(), fn ($q, $s) => $q->where('status', $s))
                ->latest()->paginate(20),
        );
    }

    /** Admin: setujui / tolak refund. */
    public function process(Request $request, Refund $refund): JsonResponse
    {
        abort_unless($request->user()?->isAdmin(), 403, 'Hanya admin.');

        $data = $request->validate([
            'decision' => ['required', 'in:approved,rejected'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $data['decision'] === 'approved'
                ? $refund->approve($request->user()->id, $data['note'] ?? null)
                : $refund->reject($request->user()->id, $data['note'] ?? null);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        $refund->load('order', 'merchant');

        $this->notif->push(
            $refund->merchant,
            'refund.status',
            $data['decision'] === 'approved' ? 'Refund disetujui' : 'Refund ditolak',
            $data['decision'] === 'approved'
                ? "Refund order {$refund->order->order_no} disetujui. Rp" . number_format($refund->amount, 0, ',', '.') . ' ditarik dari saldo.'
                : "Refund order {$refund->order->order_no} ditolak." . ($data['note'] ? " Alasan: {$data['note']}" : ''),
            '/seller/refunds',
        );

        Audit::record('refund.' . $data['decision'], $refund, ['order_no' => $refund->order->order_no]);

        return response()->json(['data' => $refund->fresh('order')]);
    }
}
