<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\Product;
use App\Models\PublishLog;
use App\Services\PublisherService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Publish produk ke satu channel di background.
 * Retry 3x dengan backoff untuk antisipasi rate-limit platform.
 */
class PublishToChannel implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public int $productId,
        public int $channelId,
    ) {
    }

    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function handle(PublisherService $publisher): void
    {
        $product = Product::withoutGlobalScope('merchant')->find($this->productId);
        $channel = Channel::withoutGlobalScope('merchant')->find($this->channelId);

        if (! $product || ! $channel) {
            return;
        }

        $log = $publisher->publish($product, $channel);

        if ($log->status === 'failed' && ! $this->attemptsExhausted()) {
            $this->release($this->backoff()[$this->attempts()] ?? 600);
        }
    }

    private function attemptsExhausted(): bool
    {
        return $this->attempts() >= $this->tries;
    }

    public function failed(\Throwable $exception): void
    {
        PublishLog::where('product_id', $this->productId)
            ->where('channel_id', $this->channelId)
            ->update([
                'status' => 'failed',
                'error' => mb_substr('Job gagal: '.$exception->getMessage(), 0, 1000),
            ]);
    }
}
