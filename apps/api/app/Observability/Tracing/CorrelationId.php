<?php

namespace App\Observability\Tracing;

use Illuminate\Support\Str;

final class CorrelationId
{
    public const HEADER_CORRELATION = 'X-Correlation-ID';

    public const HEADER_REQUEST = 'X-Request-ID';

    private ?string $correlationId = null;

    private ?string $requestId = null;

    public function get(): string
    {
        return $this->correlationId ??= (string) Str::uuid();
    }

    public function requestId(): string
    {
        return $this->requestId ??= $this->get();
    }

    public function set(string $correlationId, ?string $requestId = null): void
    {
        $this->correlationId = $this->normalize($correlationId);
        $this->requestId = $this->normalize($requestId ?? $correlationId);
    }

    public function bindToContainer(): void
    {
        $correlationId = $this->get();
        $requestId = $this->requestId();

        app()->instance('correlation_id', $correlationId);
        app()->instance('request_id', $requestId);
        app()->instance(self::class, $this);
    }

    /**
     * @return array{correlation_id: string, request_id: string}
     */
    public function context(): array
    {
        return [
            'correlation_id' => $this->get(),
            'request_id' => $this->requestId(),
        ];
    }

    private function normalize(string $value): string
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            return (string) Str::uuid();
        }

        return $trimmed;
    }
}
