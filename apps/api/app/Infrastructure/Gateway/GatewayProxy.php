<?php

namespace App\Infrastructure\Gateway;

use App\Core\Exceptions\ExternalServiceException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class GatewayProxy
{
    public function __construct(
        private readonly ServiceRegistry $registry,
        private readonly GatewaySigner $signer,
    ) {}

    public function forward(string $serviceName, string $path, Request $request): SymfonyResponse
    {
        $service = $this->registry->find($serviceName);

        if ($service === null || ! $service->enabled) {
            throw new ExternalServiceException("Gateway service [{$serviceName}] is not registered or disabled.");
        }

        if ($service->baseUrl === '') {
            throw new ExternalServiceException("Gateway service [{$serviceName}] has no base_url configured.");
        }

        // Keep the public API shape on the downstream service:
        // GET /api/v1/orders/123 → http://orders-service/api/v1/orders/123
        $suffix = trim($path, '/');

        // Path traversal / control characters must never reach an internal service.
        if ($suffix !== '' && (
            preg_match('/[\p{Cc}\\\\]/u', $suffix) === 1
            || in_array('..', explode('/', rawurldecode($suffix)), true)
            || str_contains(strtolower($suffix), '%2e%2e')
            || str_contains(strtolower($suffix), '%2f')
        )) {
            throw new HttpException(400, 'Invalid gateway path.');
        }

        $targetPath = '/api/v1/'.$service->prefix.($suffix !== '' ? '/'.$suffix : '');
        $url = $service->baseUrl.$targetPath;

        $pathAndQuery = $targetPath;
        if ($query = $request->getQueryString()) {
            $url .= '?'.$query;
            $pathAndQuery .= '?'.$query;
        }

        if ($service->secret === '') {
            throw new ExternalServiceException("Gateway service [{$serviceName}] has no signing secret configured.");
        }

        $body = $request->getContent();

        try {
            /** @var Response $response */
            $response = Http::withHeaders($this->headers($service, $request) + $this->signer->sign(
                $service->secret,
                $request->method(),
                $pathAndQuery,
                $body,
                $request->user()?->getAuthIdentifier() !== null ? (string) $request->user()->getAuthIdentifier() : null,
                tenantId: $this->tenantOf($request),
            ))
                ->withoutRedirecting()
                ->timeout($service->timeout)
                ->withBody($body, $request->header('Content-Type', 'application/json'))
                ->send($request->method(), $url);
        } catch (ConnectionException $e) {
            throw new ExternalServiceException(
                "Gateway could not reach [{$serviceName}] at {$service->baseUrl}: {$e->getMessage()}",
                previous: $e,
            );
        }

        return response($response->body(), $response->status())
            ->withHeaders($this->responseHeaders($response));
    }

    /**
     * Tenant comes from the authenticated user only (never from a client header),
     * so a caller cannot choose another tenant. Null when tenancy is not installed.
     */
    private function tenantOf(Request $request): ?string
    {
        $tenant = $request->user()?->tenant_id ?? null;

        return $tenant !== null && $tenant !== '' ? (string) $tenant : null;
    }

    /**
     * @return array<string, string>
     */
    private function headers(ServiceDefinition $service, Request $request): array
    {
        $headers = [];

        foreach (config('gateway.forward_headers', []) as $header) {
            $value = $request->headers->get($header);
            if ($value !== null && $value !== '') {
                $headers[$header] = $value;
            }
        }

        // Opt-in only: downstream services trust the signed gateway identity, not the user token.
        if ($service->forwardAuth && $request->bearerToken()) {
            $headers['Authorization'] = 'Bearer '.$request->bearerToken();
        }

        if (app()->bound('correlation_id')) {
            $headers['X-Correlation-ID'] = (string) app('correlation_id');
        }

        if (app()->bound('request_id')) {
            $headers['X-Request-ID'] = (string) app('request_id');
        }

        $headers['X-Forwarded-By'] = 'platform-api-gateway';
        $headers['Accept'] = $headers['Accept'] ?? 'application/json';

        return $headers;
    }

    /**
     * @return array<string, string>
     */
    private function responseHeaders(Response $response): array
    {
        $allowed = ['Content-Type', 'X-Request-ID', 'X-Correlation-ID'];
        $headers = [];

        foreach ($allowed as $header) {
            $value = $response->header($header);
            if (is_string($value) && $value !== '') {
                $headers[$header] = $value;
            }
        }

        return $headers;
    }
}
