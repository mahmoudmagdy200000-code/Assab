<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MobileAuthDiagnosticLogger
{
    /**
     * Build normalized Saudi phone candidates from raw user input.
     *
     * Supports:
     * - `5XXXXXXXX` (9 digits)
     * - `05XXXXXXXX` (10 digits)
     * - `9665XXXXXXXX` (12 digits)
     * - `009665XXXXXXXX` (14 digits)
     * - `+9665XXXXXXXX` (13 chars)
     * Spaces and dashes are stripped before building candidate representations.
     *
     * @return string[]
     */
    public static function buildPhoneCandidates(string $identifier): array
    {
        $cleaned = preg_replace('/[\s\-]/', '', trim($identifier));
        if ($cleaned === '' || $cleaned === null) {
            return [];
        }

        $canonical = null;
        if (str_starts_with($cleaned, '00966')) {
            $canonical = '+966'.substr($cleaned, 5);
        } elseif (str_starts_with($cleaned, '966')) {
            $canonical = '+966'.substr($cleaned, 3);
        } elseif (str_starts_with($cleaned, '0')) {
            $canonical = '+966'.substr($cleaned, 1);
        } elseif (str_starts_with($cleaned, '5')) {
            $canonical = '+966'.$cleaned;
        } elseif (str_starts_with($cleaned, '+966')) {
            $canonical = $cleaned;
        }

        $candidates = [$identifier];
        if ($cleaned !== $identifier) {
            $candidates[] = $cleaned;
        }

        if ($canonical !== null && preg_match('/^\+9665\d{8}$/', $canonical)) {
            $subscriber = substr($canonical, 4);    // '5XXXXXXXX' (9 digits)
            $national = '0'.$subscriber;          // '05XXXXXXXX' (10 digits)
            $intlWithoutPlus = '966'.$subscriber; // '9665XXXXXXXX' (12 digits)
            $intlDoubleZero = '00966'.$subscriber; // '009665XXXXXXXX' (14 digits)

            $candidates[] = $canonical;         // '+9665XXXXXXXX'
            $candidates[] = $national;          // '05XXXXXXXX'
            $candidates[] = $subscriber;        // '5XXXXXXXX'
            $candidates[] = $intlWithoutPlus;   // '9665XXXXXXXX'
            $candidates[] = $intlDoubleZero;    // '009665XXXXXXXX'
        }

        return array_values(array_unique(array_filter($candidates)));
    }

    /**
     * Mask IPv4 address to /24 subnet or IPv6 to /48 prefix for privacy.
     */
    public static function maskIpToSubnet(?string $ip): string
    {
        if (empty($ip)) {
            return 'unknown';
        }

        if (str_contains($ip, ',')) {
            $ip = trim(explode(',', $ip)[0]);
        }

        $parts = explode('.', $ip);
        if (count($parts) === 4) {
            return "{$parts[0]}.{$parts[1]}.{$parts[2]}.0/24";
        }

        if (str_contains($ip, ':')) {
            $hextets = explode(':', $ip);
            $prefix = array_slice($hextets, 0, 3);

            return implode(':', $prefix).'::/48';
        }

        return 'unknown';
    }

    /**
     * Resolve request or trace ID from ingress headers or attributes.
     */
    public static function resolveRequestId(Request $request): string
    {
        return $request->header('X-Request-Id')
            ?? $request->header('X-Correlation-Id')
            ?? $request->header('X-Amzn-Trace-Id')
            ?? (string) ($request->attributes->get('request_id') ?? Str::uuid());
    }

    /**
     * Log structured auth failure to stderr.
     * Never logs identifiers, passwords, hashes, or tokens.
     *
     * @param  string  $surface  'branch_manager' | 'cashier'
     * @param  string  $reason  'no_user' | 'bad_password' | 'inactive' | 'first_login' | 'validation'
     */
    public static function logAuthFailed(string $surface, string $reason, Request $request): void
    {
        $allowedReasons = ['no_user', 'bad_password', 'inactive', 'first_login', 'validation'];
        $sanitizedReason = in_array($reason, $allowedReasons, true) ? $reason : 'no_user';

        $ip = $request->header('X-Real-Ip') ?? $request->ip();

        $data = [
            'surface' => $surface,
            'reason' => $sanitizedReason,
            'request_id' => self::resolveRequestId($request),
            'ip_prefix' => self::maskIpToSubnet($ip),
            'timestamp' => now('UTC')->toIso8601String(),
        ];

        try {
            Log::channel('stderr')->warning('mobile_auth_failed', $data);
        } catch (\Throwable) {
            @file_put_contents('php://stderr', json_encode(['event' => 'mobile_auth_failed'] + $data).PHP_EOL);
        }
    }

    /**
     * Log structured auth success to stderr.
     *
     * @param  string  $surface  'branch_manager' | 'cashier'
     */
    public static function logAuthSucceeded(string $surface, Request $request): void
    {
        $data = [
            'surface' => $surface,
            'request_id' => self::resolveRequestId($request),
        ];

        try {
            Log::channel('stderr')->info('mobile_auth_succeeded', $data);
        } catch (\Throwable) {
            @file_put_contents('php://stderr', json_encode(['event' => 'mobile_auth_succeeded'] + $data).PHP_EOL);
        }
    }
}
