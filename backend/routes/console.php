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