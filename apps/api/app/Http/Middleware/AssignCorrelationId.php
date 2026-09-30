<?php

namespace App\Http\Middleware;

use App\Observability\Tracing\CorrelationId;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class AssignCorrelationId
{
    public function __construct(
        private readonly CorrelationId $correlationId,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $incoming = $request->headers->get(CorrelationId::HEADER_REQUEST)
            ?? $request->headers->get(CorrelationId::HEADER_CORRELATION)
            ?? (string) Str::uuid();

        $this->correlationId->set($incoming);
        $this->correlationId->bindToContainer();

        /** @var Response $response */
        $response = $next($request);

        $response->headers->set(
            CorrelationId::HEADER_CORRELATION,
            $this->correlationId->get(),
        );
        $response->headers->set(
            CorrelationId::HEADER_REQUEST,
            $this->correlationId->requestId(),
        );

        return $response;
    }
}
