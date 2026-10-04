<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Fase 20: blokir staf dari menu keuangan.
 * Pasang pada: balance*, refund*, billing*.
 */
class NoStaffFinance
{
    public function handle(Request $request, Closure $next)
    {
        abort_if($request->user()?->isStaff(), 403, 'Akun staf tidak bisa akses menu keuangan.');

        return $next($request);
    }
}
