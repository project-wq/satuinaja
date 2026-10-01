<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerRateLimiters();
    }

    /**
     * Rate limit berlapis (semua pakai cache — tidak butuh Redis):
     *  - global    500/mnt per IP  (jaring pengaman API group)
     *  - api-public 60/mnt per IP  (endpoint tak terautentikasi)
     *  - api-auth   300/mnt per user (merchant login)
     *  - login      5/menit per email+IP (di-handle AuthController)
     */
    private function registerRateLimiters(): void
    {
        RateLimiter::for('global', function (Request $request) {
            return Limit::perMinute(500)->by($request->ip());
        });

        RateLimiter::for('api-public', function (Request $request) {
            return Limit::perMinute(60)->by($request->ip());
        });

        RateLimiter::for('api-auth', function (Request $request) {
            $user = $request->user() ?? $request->user('sanctum');

            return Limit::perMinute(300)->by($user?->id ?: 'guest:|'.$request->ip());
        });
    }
}
