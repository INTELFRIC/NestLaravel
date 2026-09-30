<?php

namespace NestLaravel\Kafka\Observability;

/**
 * Removes credentials from anything that is about to be logged.
 * Key-based (password, token, secret, authorization, api key, cookie, signature, private key, …) and
 * value-based (Bearer tokens, URL credentials, PEM private keys).
 */
final class Redactor
{
    public const MASK = '[REDACTED]';

    private const KEY_PATTERN = '/(pass(word|wd)?|secret|token|authorization|api[-_]?key|cookie|signature|private[-_]?key|credential|sasl|otp|cvv|card[-_]?number)/i';

    public static function redact(mixed $value, ?string $key = null, int $depth = 0): mixed
    {
        if ($key !== null && preg_match(self::KEY_PATTERN, $key) === 1) {
            return self::MASK;
        }

        if ($depth > 8) {
            return '[MAX-DEPTH]';
        }

        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = self::redact($v, is_string($k) ? $k : null, $depth + 1);
            }

            return $out;
        }

        if (is_string($value)) {
            return self::redactString($value);
        }

        if (is_object($value) && ! $value instanceof \Throwable && ! $value instanceof \Stringable) {
            return '['.get_class($value).']';
        }

        return $value;
    }

    public static function redactString(string $text): string
    {
        $text = preg_replace('/(Bearer\s+)[A-Za-z0-9._~+\/=-]{8,}/i', '$1'.self::MASK, $text) ?? $text;
        $text = preg_replace('#(://[^/\s:@]+:)[^@\s/]+@#', '$1'.self::MASK.'@', $text) ?? $text;
        $text = preg_replace('/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?-----END [A-Z ]*PRIVATE KEY-----/s', self::MASK, $text) ?? $text;

        return $text;
    }
}
