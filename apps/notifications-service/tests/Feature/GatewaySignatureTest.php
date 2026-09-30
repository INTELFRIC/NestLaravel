<?php

namespace Tests\Feature;

use Tests\TestCase;

class GatewaySignatureTest extends TestCase
{
    private const SECRET = 'test-secret-test-secret-test-secret';

    protected function setUp(): void
    {
        parent::setUp();
        config(['internal.secret' => self::SECRET]);
    }

    /**
     * @return array<string, string>
     */
    private function signed(string $method, string $uri, string $body = '', ?int $ts = null, string $nonce = 'nonce-1', string $secret = self::SECRET): array
    {
        $ts ??= time();
        $sig = hash_hmac('sha256', implode("\n", [
            (string) $ts, $nonce, $method, $uri, hash('sha256', $body), '42', 't-1',
        ]), $secret);

        return [
            'X-Gateway-Timestamp' => (string) $ts,
            'X-Gateway-Nonce' => $nonce,
            'X-Gateway-User' => '42',
            'X-Gateway-Tenant' => 't-1',
            'X-Gateway-Signature' => $sig,
        ];
    }

    public function test_direct_request_without_signature_is_rejected(): void
    {
        $this->getJson('/api/v1/notifications/health')->assertStatus(401);
    }

    public function test_valid_gateway_signature_is_accepted(): void
    {
        $this->withHeaders($this->signed('GET', '/api/v1/notifications/health'))->get('/api/v1/notifications/health', ['Accept' => 'application/json'])
            ->assertOk();
    }

    public function test_wrong_secret_is_rejected(): void
    {
        $this->withHeaders($this->signed('GET', '/api/v1/notifications/health', secret: 'other'))->get('/api/v1/notifications/health', ['Accept' => 'application/json'])
            ->assertStatus(401);
    }

    public function test_tampered_path_is_rejected(): void
    {
        $this->withHeaders($this->signed('GET', '/api/v1/notifications/other'))->get('/api/v1/notifications/health', ['Accept' => 'application/json'])
            ->assertStatus(401);
    }

    public function test_expired_signature_is_rejected(): void
    {
        $this->withHeaders($this->signed('GET', '/api/v1/notifications/health', ts: time() - 3600))->get('/api/v1/notifications/health', ['Accept' => 'application/json'])
            ->assertStatus(401);
    }

    public function test_replayed_nonce_is_rejected(): void
    {
        $headers = $this->signed('GET', '/api/v1/notifications/health', nonce: 'replay-me');

        $this->withHeaders($headers)->get('/api/v1/notifications/health', ['Accept' => 'application/json'])->assertOk();
        $this->withHeaders($headers)->get('/api/v1/notifications/health', ['Accept' => 'application/json'])->assertStatus(401);
    }

    public function test_correlation_id_from_the_gateway_is_bound_and_echoed(): void
    {
        $headers = $this->signed('GET', '/api/v1/notifications/health') + ['X-Correlation-ID' => 'corr-1234-abcd'];

        $this->withHeaders($headers)->get('/api/v1/notifications/health', ['Accept' => 'application/json'])
            ->assertOk()
            ->assertHeader('X-Correlation-ID', 'corr-1234-abcd');

        $this->assertSame('corr-1234-abcd', app('correlation_id'));
    }

    public function test_service_fails_closed_without_configured_secret(): void
    {
        config(['internal.secret' => '']);

        $this->withHeaders($this->signed('GET', '/api/v1/notifications/health'))->get('/api/v1/notifications/health', ['Accept' => 'application/json'])
            ->assertStatus(503);
    }

    public function test_liveness_and_readiness_stay_open_for_orchestrators(): void
    {
        $this->get('/up')->assertOk();
        $this->getJson('/ready')->assertOk();
    }
}
