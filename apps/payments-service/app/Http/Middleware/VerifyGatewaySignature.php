<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * SERVICE-TO-SERVICE guard. Only the API gateway holds INTERNAL_SERVICE_SECRET,
 * so a request that reaches this service directly (bypassing the gateway) is rejected.
 *
 * Verifies the HMAC produced by the gateway's GatewaySigner: timestamp freshness,
 * single-use nonce (replay protection) and signature over method/path/body/user.
 */
final class VerifyGatewaySignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('internal.secret', '');

        if ($secret === '') {
            // Fail closed: an unconfigured service must never be reachable.
            return $this->deny(503, 'Service authentication is not configured.');
        }

        $timestamp = (string) $request->header('X-Gateway-Timestamp', '');
        $nonce = (string) $request->header('X-Gateway-Nonce', '');
        $userId = (string) $request->header('X-Gateway-User', '');
        $tenantId = (string) $request->header('X-Gateway-Tenant', '');
        $signature = (string) $request->header('X-Gateway-Signature', '');

        if ($timestamp === '' || $nonce === '' || $signature === '' || ! ctype_digit($timestamp)) {
            return $this->deny(401, 'Missing gateway signature.');
        }

        $ttl = (int) config('internal.signature_ttl', 60);

        if (abs(time() - (int) $timestamp) > $ttl) {
            return $this->deny(401, 'Gateway signature expired.');
        }

        $expected = hash_hmac('sha256', implode("\n", [
            $timestamp,
            $nonce,
            strtoupper($request->method()),
            $request->getRequestUri(),
            hash('sha256', $request->getContent()),
            $userId,
            $tenantId,
        ]), $secret);

        if (! hash_equals($expected, $signature)) {
            return $this->deny(401, 'Invalid gateway signature.');
        }

        // Single use within the validity window (both clock directions).
        if (! Cache::add('gateway-nonce:'.$nonce, 1, $ttl * 2)) {
            return $this->deny(401, 'Gateway signature already used.');
        }

        // Signed identity: safe to trust downstream (tenancy package reads gateway_tenant_id).
        $request->attributes->set('gateway_user_id', $userId !== '' ? $userId : null);
        $request->attributes->set('gateway_tenant_id', $tenantId !== '' ? $tenantId : null);

        // Observability: one correlation id per request, shared with logs and Kafka events.
        $correlationId = (string) ($request->header('X-Correlation-ID') ?: $request->header('X-Request-ID') ?: '');
        if ($correlationId !== '' && preg_match('/^[A-Za-z0-9._-]{8,64}$/', $correlationId) === 1) {
            app()->instance('correlation_id', $correlationId);
            Log::shareContext(['correlation_id' => $correlationId, 'gateway_user_id' => $request->attributes->get('gateway_user_id')]);
        }

        $response = $next($request);

        if (isset($correlationId) && $correlationId !== '') {
            $response->headers->set('X-Correlation-ID', $correlationId);
        }

        return $response;
    }

    private function deny(int $status, string $message): Response
    {
        return response()->json(['success' => false, 'message' => $message], $status);
    }
}
