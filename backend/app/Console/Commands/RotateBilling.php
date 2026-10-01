<?php

namespace App\Console\Commands;

use App\Models\Merchant;
use Illuminate\Console\Command;

/**
 * Reset penghitung publish bulanan + batal langganan kedaluwarsa.
 * Jalankan 1x/hari lewat scheduler.
 */
class RotateBilling extends Command
{
    protected $signature = 'satu:billing-rotate';

    protected $description = 'Reset batas bulanan & batal langganan kedaluwarsa';

    public function handle(): int
    {
        // 1. Reset penghitung per merchant yang last_publish_reset_at < bulan ini.
        $count = Merchant::where(function ($q) {
            $q->whereNull('last_publish_reset_at')
                ->orWhere('last_publish_reset_at', '<', now()->startOfMonth());
        })->update([
            'publishes_this_month' => 0,
            'last_publish_reset_at' => now(),
        ]);

        // 2. Langganan past_due — batal semua yang kedaluwarsa.
        $expired = \App\Models\Subscription::where('status', 'active')
            ->whereNotNull('ends_at')
            ->where('ends_at', '<', now())
            ->update(['status' => 'past_due']);

        // 3. Merchants dengan langganan past_due → kembali ke free.
        $downgraded = Merchant::where(function ($q) {
            $q->whereIn('id', \App\Models\Subscription::where('status', 'past_due')->pluck('merchant_id'));
        })->update([
            'plan_code' => 'free',
            'publishes_this_month' => 0,
        ]);

        $this->line(sprintf('Reset %d merchant, batal %d langganan, %d kembali free.', $count, $expired, $downgraded));

        return self::SUCCESS;
    }
}