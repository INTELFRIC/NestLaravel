<?php

namespace NestLaravel\Kafka\Observability;

/**
 * W3C Trace Context (https://www.w3.org/TR/trace-context/) propagation across HTTP and Kafka.
 * `traceparent: 00-<trace-id 32 hex>-<parent-id 16 hex>-<flags 2 hex>`
 *
 * Works without any OpenTelemetry package: ids are generated/propagated here so logs, events and
 * (optionally) exported spans always share the same trace_id.
 */
final class TraceContext
{
    public function __construct(
        public readonly string $traceId,
        public readonly string $spanId,
        public readonly ?string $parentSpanId = null,
        public readonly bool $sampled = true,
    ) {}

    public static function parse(?string $traceparent): ?self
    {
        if ($traceparent === null || preg_match('/^00-([0-9a-f]{32})-([0-9a-f]{16})-([0-9a-f]{2})$/', trim($traceparent), $m) !== 1) {
            return null;
        }

        // All-zero ids are invalid per spec.
        if ($m[1] === str_repeat('0', 32) || $m[2] === str_repeat('0', 16)) {
            return null;
        }

        return new self($m[1], $m[2], null, (hexdec($m[3]) & 1) === 1);
    }

    /** Start a new local span, continuing the incoming trace when there is one. */
    public static function continueFrom(?string $traceparent): self
    {
        $incoming = self::parse($traceparent);

        if ($incoming === null) {
            return new self(bin2hex(random_bytes(16)), bin2hex(random_bytes(8)), null, true);
        }

        return new self($incoming->traceId, bin2hex(random_bytes(8)), $incoming->spanId, $incoming->sampled);
    }

    /** A child of this context (new span id, same trace). */
    public function child(): self
    {
        return new self($this->traceId, bin2hex(random_bytes(8)), $this->spanId, $this->sampled);
    }

    public function traceparent(): string
    {
        return sprintf('00-%s-%s-%s', $this->traceId, $this->spanId, $this->sampled ? '01' : '00');
    }

    // --- ambient (per-process) current context -----------------------------------------------------------

    private static ?self $current = null;

    public static function current(): ?self
    {
        return self::$current;
    }

    public static function activate(?self $context): void
    {
        self::$current = $context;

        if ($context !== null) {
            LogContext::set(['trace_id' => $context->traceId, 'span_id' => $context->spanId]);
        } else {
            LogContext::forget('trace_id', 'span_id');
        }
    }

    /** Headers to attach to an outgoing HTTP call or Kafka message. */
    public static function outgoingHeaders(): array
    {
        return self::$current !== null ? ['traceparent' => self::$current->child()->traceparent()] : [];
    }
}
