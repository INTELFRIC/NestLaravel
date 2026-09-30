<?php

namespace App\Observability\Logging;

use App\Observability\Tracing\CorrelationId;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use Stringable;
use Throwable;

final class StructuredLogger
{
    public function __construct(
        private readonly ?CorrelationId $correlationId = null,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public function debug(string|Stringable $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function info(string|Stringable $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function notice(string|Stringable $message, array $context = []): void
    {
        $this->log('notice', $message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function warning(string|Stringable $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function error(string|Stringable $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function critical(string|Stringable $message, array $context = []): void
    {
        $this->log('critical', $message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function exception(Throwable $exception, array $context = []): void
    {
        $this->error($exception->getMessage(), array_merge($context, [
            'exception' => $exception::class,
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'code' => $exception->getCode(),
        ]));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function log(string $level, string|Stringable $message, array $context = []): void
    {
        $this->driver()->log($level, (string) $message, $this->enrich($context));
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function enrich(array $context): array
    {
        return array_merge($this->traceContext(), $context);
    }

    /**
     * @return array<string, mixed>
     */
    private function traceContext(): array
    {
        if ($this->correlationId !== null) {
            return $this->correlationId->context();
        }

        $context = [];

        if (app()->bound('correlation_id')) {
            $context['correlation_id'] = (string) app('correlation_id');
        }

        if (app()->bound('request_id')) {
            $context['request_id'] = (string) app('request_id');
        } elseif (isset($context['correlation_id'])) {
            $context['request_id'] = $context['correlation_id'];
        }

        return $context;
    }

    private function driver(): LoggerInterface
    {
        return $this->logger ?? Log::channel();
    }
}
