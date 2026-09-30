<?php

namespace NestLaravel\Kafka\Observability;

use Monolog\Formatter\JsonFormatter;
use Monolog\LogRecord;

/**
 * One JSON object per line:
 * { timestamp, level, message, service, environment, request_id, correlation_id, causation_id, event_id,
 *   tenant_id, trace_id, span_id, context: {...}, exception: {class,message,file,line,trace} }
 */
final class JsonLogFormatter extends JsonFormatter
{
    public function __construct()
    {
        parent::__construct(self::BATCH_MODE_NEWLINES, false, true, false);
        $this->includeStacktraces(false);
    }

    public function format(LogRecord $record): string
    {
        $context = $record->context;
        $exception = null;

        if (($context['exception'] ?? null) instanceof \Throwable) {
            $e = $context['exception'];
            $exception = [
                'class' => $e::class,
                'message' => Redactor::redactString($e->getMessage()),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => array_slice(explode("\n", $e->getTraceAsString()), 0, 12),
            ];
            unset($context['exception']);
        }

        $line = array_filter([
            'timestamp' => $record->datetime->format('Y-m-d\TH:i:s.vP'),
            'level' => strtolower($record->level->getName()),
            'message' => $record->message,
            'channel' => $record->channel,
        ] + $record->extra + [
            'context' => $context ?: null,
            'exception' => $exception,
        ], static fn ($v) => $v !== null);

        return $this->toJson($this->normalize($line), true)."\n";
    }
}
