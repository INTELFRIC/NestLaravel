<?php

namespace NestLaravel\Kafka\Resilience;

/**
 * What may be retried, and how.
 *
 *  Error classes (from the HTTP status / transport failure):
 *    TRANSIENT   connection reset/refused, timeout, 408, 425, 429, 500*, 502, 503, 504   → retry (if the method is safe to)
 *    AUTH        401, 403                                                              → never (retrying cannot help; refresh credentials)
 *    VALIDATION  400, 422                                                              → never (the request itself is wrong)
 *    BUSINESS    409, 410, 412, 423 and other 4xx                                      → never (a legitimate business answer)
 *    OK          everything < 400
 *   (*) 500 is treated as transient only for idempotent requests.
 *
 *  Method safety: by default only GET/HEAD/OPTIONS are retried. POST/PUT/PATCH/DELETE are retried ONLY when the
 *  caller opts in (`retry_unsafe`) or sends an `Idempotency-Key` header — an automatic retry of a non-idempotent
 *  request can execute the business operation twice.
 */
final class RetryPolicy
{
    public const OK = 'ok';

    public const TRANSIENT = 'transient';

    public const AUTH = 'auth';

    public const VALIDATION = 'validation';

    public const BUSINESS = 'business';

    public function __construct(
        public readonly int $maxRetries = 2,
        public readonly int $baseDelayMs = 100,
        public readonly int $maxDelayMs = 2000,
        public readonly bool $retryUnsafe = false,
        public readonly int $budgetMs = 8000,
    ) {}

    public static function classifyStatus(int $status): string
    {
        return match (true) {
            $status < 400 => self::OK,
            in_array($status, [401, 403], true) => self::AUTH,
            in_array($status, [400, 422], true) => self::VALIDATION,
            in_array($status, [408, 425, 429, 500, 502, 503, 504], true) => self::TRANSIENT,
            $status >= 500 => self::TRANSIENT,
            default => self::BUSINESS,
        };
    }

    /** @param array<string, string> $headers */
    public function methodIsRetryable(string $method, array $headers = []): bool
    {
        if (in_array(strtoupper($method), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return true;
        }

        if ($this->retryUnsafe) {
            return true;
        }

        foreach ($headers as $name => $_) {
            if (strcasecmp((string) $name, 'Idempotency-Key') === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, string>  $headers
     * @param  int|null  $status  null = transport failure (timeout, connection refused, DNS)
     */
    public function shouldRetry(string $method, array $headers, ?int $status, int $attempt, int $elapsedMs = 0): bool
    {
        if ($attempt > $this->maxRetries || $elapsedMs >= $this->budgetMs) {
            return false;
        }

        if ($status !== null && self::classifyStatus($status) !== self::TRANSIENT) {
            return false;
        }

        // A 500 may have partially executed; only re-send it when the request is safe to repeat.
        if ($status === 500 && ! in_array(strtoupper($method), ['GET', 'HEAD', 'OPTIONS'], true) && ! $this->retryUnsafe) {
            return false;
        }

        return $this->methodIsRetryable($method, $headers);
    }

    /** Exponential backoff with full jitter: random(0 … min(max, base·2^(attempt-1))). */
    public function delayMs(int $attempt, ?int $retryAfterSeconds = null): int
    {
        if ($retryAfterSeconds !== null) {
            return min($this->maxDelayMs, $retryAfterSeconds * 1000);
        }

        $ceiling = min($this->maxDelayMs, $this->baseDelayMs * (2 ** max(0, $attempt - 1)));

        return random_int((int) ($ceiling / 2), max(1, $ceiling));
    }
}
