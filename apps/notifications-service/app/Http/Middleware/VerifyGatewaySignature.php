<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use NestLaravel\Kafka\Observability\LogContext;
use NestLaravel\Kafka\Observability\Metrics;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * SERVICE-TO-SERVICE guard. Only the API gateway holds the signing secret, so a request that reaches this
 * service directly (bypassing the gateway) is rejected.
 *
 * Verifies the HMAC produced by the gateway's GatewaySigner:
 *   • freshness       – |now − timestamp| ≤ INTERNAL_SIGNATURE_TTL (default 60 s)
 *   • integrity       – signature covers method, path+query, body hash, user id, tenant id, nonce, timestamp
 *   • replay          – nonce is single-use, stored in the SHARED cache (Redis in production) so replicas agree;
 *                       if that store is down the request is refused (fail closed) unless you explicitly opt out
 *   • key rotation    – INTERNAL_SERVICE_SECRET (current) and INTERNAL_SERVICE_SECRET_PREVIOUS (optional) are both
 *                       accepted, so the secret can be rotated without downtime (see SECURITY.md)
 */
final class VerifyGatewaySignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secrets = array_filter([
            'current' => (string) config('internal.secret', ''),
            'previous' => (string) config('internal.secret_previous', ''),
        ], static fn (string $s) => $s !== '');

        if ($secrets === []) {
            // Fail closed: an unconfigured service must never be reachable.
            return $this->deny(503, 'Service authentication is not configured.', 'unconfigured');
        }

        $timestamp = (string) $request->header('X-Gateway-Timestamp', '');
        $nonce = (string) $request->header('X-Gateway-Nonce', '');
        $userId = (string) $request->header('X-Gateway-User', '');
        $tenantId = (string) $request->header('X-Gateway-Tenant', '');
        $signature = (string) $request->header('X-Gateway-Signature', '');

        if ($timestamp === '' || $nonce === '' || $signature === '' || ! ctype_digit($timestamp) || strlen($nonce) > 128) {
            return $this->deny(401, 'Missing gateway signature.', 'missing');
        }

        $ttl = (int) config('internal.signature_ttl', 60);

        if (abs(time() - (int) $timestamp) > $ttl) {
            return $this->deny(401, 'Gateway signature expired.', 'expired');
        }

        $canonical = implode("\n", [
            $timestamp,
            $nonce,
            strtoupper($request->method()),
            $request->getRequestUri(),
            hash('sha256', $request->getContent()),
            $userId,
            $tenantId,
        ]);

        $matched = null;
        foreach ($secrets as $name => $secret) {
            if (hash_equals(hash_hmac('sha256', $canonical, $secret), $signature)) {
                $matched = $name;
                break;
            }
        }

        if ($matched === null) {
            return $this->deny(401, 'Invalid gateway signature.', 'invalid');
        }

        // Single use within the validity window (both clock directions).
        try {
            $fresh = Cache::store(config('internal.nonce_store'))->add('gateway-nonce:'.$nonce, 1, $ttl * 2);
        } catch (Throwable $e) {
            Log::error('Replay-protection store unavailable', ['error' => $e->getMessage()]);

            if (config('internal.replay_protection_required', true)) {
                return $this->deny(503, 'Replay protection unavailable.', 'replay_store_down');
            }

            $fresh = true; // explicit opt-out: availability over replay protection
        }

        if (! $fresh) {
            return $this->deny(401, 'Gateway signature already used.', 'replayed');
        }

        if ($matched === 'previous') {
            Log::warning('Request signed with the PREVIOUS gateway secret; finish the rotation and drop INTERNAL_SERVICE_SECRET_PREVIOUS');
        }
        Metrics::inc('nestlaravel_gateway_signature_total', ['result' => 'ok', 'key' => $matched], help: 'Gateway signature verifications');

        // Signed identity: safe to trust downstream (tenancy package reads gateway_tenant_id).
        $request->attributes->set('gateway_user_id', $userId !== '' ? $userId : null);
        $request->attributes->set('gateway_tenant_id', $tenantId !== '' ? $tenantId : null);
        LogContext::set(array_filter(['tenant_id' => $tenantId, 'user_id' => $userId], static fn ($v) => $v !== ''));

        return $next($request);
    }

    private function deny(int $status, string $message, string $reason): Response
    {
        Metrics::inc('nestlaravel_gateway_signature_total', ['result' => 'denied', 'reason' => $reason], help: 'Gateway signature verifications');

        return response()->json(['success' => false, 'message' => $message], $status);
    }
}
