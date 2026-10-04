<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Jadwal berkala (butuh `php artisan schedule:run` tiap menit via cron)
|--------------------------------------------------------------------------
*/
Schedule::command('satu:billing-rotate')->dailyAt('00:05');
Schedule::command('satu:sync-stock')->everyFiveMinutes();
// Fase 7: two-way sync — tarik stok marketplace → lokal tiap 15 menit.
Schedule::command('satu:sync-stock --pull')->everyFifteenMinutes();
// Fase 17: tarik status kiriman Biteship (shipped→delivered otomatis).
Schedule::command('biteship:track')->everyTenMinutes();