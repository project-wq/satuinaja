<?php

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * Helper audit log terpusat.
 * Dipakai di controller & middleware agar jejak aksi sensitif selalu tercatat.
 */
class Audit
{
    public static function record(
        string $action,
        ?object $subject = null,
        array $payload = [],
        string $status = 'ok',
    ): void {
        $user = Auth::user();

        AuditLog::create([
            'merchant_id' => $user?->merchant?->id,
            'user_id' => $user?->id,
            'action' => $action,
            'subject_type' => $subject ? $subject::class : null,
            'subject_id' => $subject?->id,
            'ip' => Request::ip(),
            'user_agent' => substr((string) Request::userAgent(), 0, 500),
            'payload' => self::redact($payload),
            'status' => $status,
        ]);
    }

    /**
     * Buang kunci sensitif sebelum disimpan.
     */
    private static function redact(array $payload): array
    {
        $blocked = ['password', 'password_confirmation', 'token', 'access_token',
            'api_key', 'secret', 'client_secret', 'credentials', 'server_key'];

        $clean = [];
        foreach ($payload as $key => $value) {
            if (in_array(strtolower((string) $key), $blocked, true)) {
                $clean[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $clean[$key] = self::redact($value);
            } else {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }
}
