<?php

namespace NestLaravel\Kafka\Observability;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Monolog processor: stamps every log line with service, environment, request/correlation/causation/event/
 * tenant/trace ids and redacts secrets from the message and context.
 */
final class ContextProcessor implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        $ambient = LogContext::all();
        $extra = array_filter([
            'service' => config('app.name'),
            'environment' => config('app.env'),
            'request_id' => $this->bound('request_id'),
            'correlation_id' => $this->bound('correlation_id') ?? ($ambient['correlation_id'] ?? null),
            'causation_id' => $ambient['causation_id'] ?? null,
            'event_id' => $ambient['event_id'] ?? null,
            'tenant_id' => $this->bound('tenant_id') ?? ($ambient['tenant_id'] ?? null),
            'trace_id' => $ambient['trace_id'] ?? null,
            'span_id' => $ambient['span_id'] ?? null,
        ], static fn ($v) => $v !== null && $v !== '');

        return $record->with(
            message: Redactor::redactString($record->message),
            context: Redactor::redact($record->context),
            extra: Redactor::redact($record->extra + $extra),
        );
    }

    private function bound(string $key): ?string
    {
        if (! function_exists('app') || ! app()->bound($key)) {
            return null;
        }

        $value = app($key);

        return is_scalar($value) && $value !== '' ? (string) $value : null;
    }
}
