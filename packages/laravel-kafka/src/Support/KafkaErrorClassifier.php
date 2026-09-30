<?php

namespace NestLaravel\Kafka\Support;

use Throwable;

/**
 * Decides how a consumer/producer should react to a client-level Kafka error.
 *
 *  FATAL      credentials/TLS/ACL problems: retrying only hammers the broker → stop and let the supervisor alert.
 *  TRANSIENT  broker down, network flap, rebalance in progress, topic not created yet → back off and keep going.
 *
 * "Unknown topic" is transient at first (auto-creation / provisioning race) but escalated by the caller if it persists.
 */
final class KafkaErrorClassifier
{
    public const FATAL = 'fatal';

    public const TRANSIENT = 'transient';

    private const FATAL_PATTERNS = [
        '/authentication/i', '/sasl/i', '/ssl/i', '/tls/i', '/certificate/i', '/handshake/i',
        '/authorization failed/i', '/topic authorization/i', '/group authorization/i', '/cluster authorization/i',
        '/invalid (?:client|group)[ .-]?id/i', '/unsupported (?:sasl|protocol)/i',
    ];

    public static function classify(Throwable|string $error): string
    {
        $message = $error instanceof Throwable ? $error->getMessage() : $error;

        foreach (self::FATAL_PATTERNS as $pattern) {
            if (preg_match($pattern, $message) === 1) {
                return self::FATAL;
            }
        }

        return self::TRANSIENT;
    }

    /** Exponential backoff with a ceiling: 0.5s, 1s, 2s … capped at $maxMs. */
    public static function backoffMs(int $consecutiveFailures, int $baseMs = 500, int $maxMs = 30000): int
    {
        return (int) min($maxMs, $baseMs * (2 ** max(0, min($consecutiveFailures - 1, 10))));
    }
}
