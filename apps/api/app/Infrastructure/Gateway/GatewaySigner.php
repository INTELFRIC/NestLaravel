<?php

namespace App\Infrastructure\Gateway;

use Illuminate\Support\Str;

/**
 * Signs gateway → microservice requests (SERVICE-TO-SERVICE trust).
 *
 * The signature covers method, path+query, body hash, timestamp, nonce and
 * the authenticated user id, so a captured request cannot be replayed, altered
 * or re-targeted. Verified by VerifyGatewaySignature in every microservice.
 */
final class GatewaySigner
{
    public const HEADER_TIMESTAMP = 'X-Gateway-Timestamp';

    public const HEADER_NONCE = 'X-Gateway-Nonce';

    public const HEADER_USER = 'X-Gateway-User';

    public const HEADER_TENANT = 'X-Gateway-Tenant';

    public const HEADER_SIGNATURE = 'X-Gateway-Signature';

    /**
     * @return array<string, string>
     */
    public function sign(string $secret, string $method, string $pathAndQuery, string $body, ?string $userId, ?int $timestamp = null, ?string $nonce = null, ?string $tenantId = null): array
    {
        $timestamp ??= time();
        $nonce ??= (string) Str::uuid();
        $userId ??= '';
        $tenantId ??= '';

        return [
            self::HEADER_TIMESTAMP => (string) $timestamp,
            self::HEADER_NONCE => $nonce,
            self::HEADER_USER => $userId,
            self::HEADER_TENANT => $tenantId,
            self::HEADER_SIGNATURE => self::signature($secret, $method, $pathAndQuery, $body, $userId, (string) $timestamp, $nonce, $tenantId),
        ];
    }

    public static function signature(string $secret, string $method, string $pathAndQuery, string $body, string $userId, string $timestamp, string $nonce, string $tenantId = ''): string
    {
        $canonical = implode("\n", [
            $timestamp,
            $nonce,
            strtoupper($method),
            $pathAndQuery,
            hash('sha256', $body),
            $userId,
            $tenantId,
        ]);

        return hash_hmac('sha256', $canonical, $secret);
    }
}
