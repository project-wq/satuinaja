<?php

namespace App\Console\Commands;

use App\Services\OrderTrackingService;
use Illuminate\Console\Command;

/**
 * Fase 17: tracking otomatis — polling Biteship tiap 10 menit.
 * Jalankan via cron: php artisan schedule:run
 */
class SyncTracking extends Command
{
    protected $signature = 'biteship:track {--limit=50 : Maks order per jalan}';

    protected $description = 'Sinkronkan status kiriman Biteship (shipped→delivered, riwayat timeline).';

    public function handle(OrderTrackingService $svc): int
    {
        $changed = $svc->syncPending((int) $this->option('limit'));
        $this->info("tracking tersinkron, {$changed} order berubah.");

        return self::SUCCESS;
    }
}
