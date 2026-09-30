<?php

namespace App\Security\RateLimiting;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

final class RateLimiterRegistry
{
    public static function register(): void
    {
        // Brute-force protection: limit per IP *and* per targeted account,
        // so a distributed attack on one email is throttled as well.
        RateLimiter::for('auth', function (Request $request) {
            $email = strtolower((string) $request->input('email', ''));

            return array_values(array_filter([
                Limit::perMinute(10)->by('ip:'.$request->ip()),
                $email !== '' ? Limit::perMinute(5)->by('email:'.$email.'|'.$request->ip()) : null,
                $email !== '' ? Limit::perHour(30)->by('email:'.$email) : null,
            ]));
        });

        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->getAuthIdentifier() ?: $request->ip());
        });

        RateLimiter::for('mcp', function (Request $request) {
            return Limit::perMinute(30)->by($request->user()?->getAuthIdentifier() ?: $request->ip());
        });
    }
}
