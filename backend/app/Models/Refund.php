<?php

namespace App\Models;

use App\Services\BalanceService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Refund / pengembalian dana order (fase 6).
 * status: pending | approved | rejected
 *
 * Approve = tarik kembali seller_net dari saldo seller (clawback).
 * Bila seller sudah menarik dana, saldo bisa negatif (jadi utang ke platform).
 */
class Refund extends Model
{
    protected $fillable = [
        'order_id', 'merchant_id', 'amount', 'reason', 'status',
        'note', 'processed_at', 'processed_by',
    ];

    protected $casts = [
        'amount' => 'integer',
        'processed_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /** Setujui refund: tarik seller_net dari saldo seller (idempotent). */
    public function approve(?int $adminId = null, ?string $note = null): self
    {
        if (! $this->isPending()) {
            throw new \RuntimeException('Refund sudah diproses.');
        }

        $this->loadMissing('order');
        app(BalanceService::class)->refundOrder($this->order, $note);

        $this->update([
            'status' => 'approved',
            'note' => $note,
            'processed_by' => $adminId,
            'processed_at' => now(),
        ]);

        return $this;
    }

    /** Tolak refund: tidak ada mutasi saldo. */
    public function reject(?int $adminId = null, ?string $note = null): self
    {
        if (! $this->isPending()) {
            throw new \RuntimeException('Refund sudah diproses.');
        }

        $this->update([
            'status' => 'rejected',
            'note' => $note,
            'processed_by' => $adminId,
            'processed_at' => now(),
        ]);

        return $this;
    }
}
