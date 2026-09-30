<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyGatewaySignature;
use Illuminate\Support\Facades\Route;
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
        $this->getJson('/api/v1/payments/health')->assertStatus(401);
    }

    public function test_valid_gateway_signature_is_accepted(): void
    {
        $this->withHeaders($this->signed('GET', '/api/v1/payments/health'))->get('/api/v1/payments/health', ['Accept' => 'application/json'])
            ->assertOk();
    }

    public function test_an_empty_nonce_store_setting_means_the_default_store(): void
    {
        // Generated .env files ship "INTERNAL_NONCE_STORE=" (empty). env() returns '', and Laravel treats '' as a store NAME.
        config(['internal.nonce_store' => '']);

        $this->withHeaders($this->signed('GET', '/api/v1/payments/health'))->get('/api/v1/payments/health', ['Accept' => 'application/json'])
            ->assertOk();
    }

    public function test_wrong_secret_is_rejected(): void
    {
        $this->withHeaders($this->signed('GET', '/api/v1/payments/health', secret: 'other'))->get('/api/v1/payments/health', ['Accept' => 'application/json'])
            ->assertStatus(401);
    }

    public function test_tampered_path_is_rejected(): void
    {
        $this->withHeaders($this->signed('GET', '/api/v1/payments/other'))->get('/api/v1/payments/health', ['Accept' => 'application/json'])
            ->assertStatus(401);
    }

    public function test_expired_signature_is_rejected(): void
    {
        $this->withHeaders($this->signed('GET', '/api/v1/payments/health', ts: time() - 3600))->get('/api/v1/payments/health', ['Accept' => 'application/json'])
            ->assertStatus(401);
    }

    public function test_replayed_nonce_is_rejected(): void
    {
        $headers = $this->signed('GET', '/api/v1/payments/health', nonce: 'replay-me');

        $this->withHeaders($headers)->get('/api/v1/payments/health', ['Accept' => 'application/json'])->assertOk();
        $this->withHeaders($headers)->get('/api/v1/payments/health', ['Accept' => 'application/json'])->assertStatus(401);
    }

    public function test_correlation_id_from_the_gateway_is_bound_and_echoed(): void
    {
        $headers = $this->signed('GET', '/api/v1/payments/health') + ['X-Correlation-ID' => 'corr-1234-abcd'];

        $this->withHeaders($headers)->get('/api/v1/payments/health', ['Accept' => 'application/json'])
            ->assertOk()
            ->assertHeader('X-Correlation-ID', 'corr-1234-abcd');

        $this->assertSame('corr-1234-abcd', app('correlation_id'));
    }

    public function test_secret_can_be_rotated_without_downtime(): void
    {
        $old = 'old-secret-old-secret-old-secret-00';
        $new = 'new-secret-new-secret-new-secret-11';

        // Step 1: service accepts the new secret AND the previous one while the gateway is being rolled.
        config(['internal.secret' => $new, 'internal.secret_previous' => $old]);

        $this->withHeaders($this->signed('GET', '/api/v1/payments/health', nonce: 'rot-1', secret: $old))
            ->get('/api/v1/payments/health', ['Accept' => 'application/json'])->assertOk();
        $this->withHeaders($this->signed('GET', '/api/v1/payments/health', nonce: 'rot-2', secret: $new))
            ->get('/api/v1/payments/health', ['Accept' => 'application/json'])->assertOk();

        // Step 2: rotation finished, previous secret removed → the old key stops working immediately.
        config(['internal.secret_previous' => '']);
        $this->withHeaders($this->signed('GET', '/api/v1/payments/health', nonce: 'rot-3', secret: $old))
            ->get('/api/v1/payments/health', ['Accept' => 'application/json'])->assertStatus(401);
    }

    public function test_tampering_with_any_signed_part_is_rejected(): void
    {
        $uri = '/api/v1/payments/health';
        $base = $this->signed('GET', $uri, nonce: 'tamper-base');

        // Different tenant / user than the one that was signed (privilege or cross-tenant escalation attempt).
        $this->withHeaders(['X-Gateway-Tenant' => 't-2'] + $base)->get($uri, ['Accept' => 'application/json'])->assertStatus(401);
        $this->withHeaders(['X-Gateway-User' => '1'] + $this->signed('GET', $uri, nonce: 'tamper-user'))->get($uri, ['Accept' => 'application/json'])->assertStatus(401);

        // A route that accepts any method, guarded by the same middleware.
        Route::any('/api/v1/payments/echo', fn () => 'ok')->middleware(['api', VerifyGatewaySignature::class]);
        $echo = '/api/v1/payments/echo';

        // Signature made for GET replayed as POST.
        $this->withHeaders($this->signed('GET', $echo, nonce: 'tamper-method'))->post($echo, [], ['Accept' => 'application/json'])->assertStatus(401);

        // Signature made for an empty body replayed with a payload.
        $this->withHeaders($this->signed('POST', $echo, '', nonce: 'tamper-body'))->postJson($echo, ['x' => 1])->assertStatus(401);
    }

    public function test_timestamps_from_the_future_are_rejected_too(): void
    {
        $this->withHeaders($this->signed('GET', '/api/v1/payments/health', ts: time() + 3600, nonce: 'future'))
            ->get('/api/v1/payments/health', ['Accept' => 'application/json'])->assertStatus(401);
    }

    public function test_requests_are_refused_when_replay_protection_cannot_be_guaranteed(): void
    {
        config(['cache.stores.broken' => ['driver' => 'redis', 'connection' => 'nope'], 'internal.nonce_store' => 'broken']);

        $this->withHeaders($this->signed('GET', '/api/v1/payments/health', nonce: 'no-store'))
            ->get('/api/v1/payments/health', ['Accept' => 'application/json'])->assertStatus(503);

        // Explicit opt-out trades replay protection for availability.
        config(['internal.replay_protection_required' => false]);
        $this->withHeaders($this->signed('GET', '/api/v1/payments/health', nonce: 'no-store-2'))
            ->get('/api/v1/payments/health', ['Accept' => 'application/json'])->assertOk();
    }

    public function test_service_fails_closed_without_configured_secret(): void
    {
        config(['internal.secret' => '']);

        $this->withHeaders($this->signed('GET', '/api/v1/payments/health'))->get('/api/v1/payments/health', ['Accept' => 'application/json'])
            ->assertStatus(503);
    }

    public function test_liveness_and_readiness_stay_open_for_orchestrators(): void
    {
        $this->get('/up')->assertOk();
        $this->getJson('/ready')->assertOk();
    }
}
