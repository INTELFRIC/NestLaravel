<?php

namespace Tests\Feature\Gateway;

use App\Infrastructure\Gateway\GatewayProxy;
use App\Infrastructure\Gateway\ServiceRegistry;
use App\Models\User;
use App\Providers\GatewayServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GatewayResilienceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'gateway.enabled' => true,
            'gateway.services.orders.enabled' => true,
            'gateway.services.orders.base_url' => 'http://orders.internal',
            'gateway.services.orders.secret' => 'gateway-orders-secret',
            'gateway.resilience.retry_base_ms' => 1,
            'gateway.resilience.retry_max_ms' => 2,
            'gateway.resilience.retries' => 2,
            'gateway.resilience.breaker.threshold' => 3,
            'gateway.resilience.breaker.open' => 20,
            'cache.default' => 'array',
        ]);
        Cache::flush();

        $this->app->forgetInstance(ServiceRegistry::class);
        $this->app->forgetInstance(GatewayProxy::class);
        (new GatewayServiceProvider($this->app))->boot();
        $this->app['router']->getRoutes()->refreshNameLookups();
        $this->app['router']->getRoutes()->refreshActionLookups();

        Sanctum::actingAs(User::factory()->create());
    }

    public function test_safe_requests_are_retried_with_a_fresh_signature_each_time(): void
    {
        Http::fake(['orders.internal/*' => Http::sequence()->push('', 503)->push('{"ok":true}', 200)]);

        $this->getJson('/api/v1/orders/1')->assertOk();

        $nonces = [];
        Http::assertSent(function (Request $r) use (&$nonces) {
            $nonces[] = $r->header('X-Gateway-Nonce')[0];

            return true;
        });
        $this->assertCount(2, $nonces);
        $this->assertCount(2, array_unique($nonces), 'a retry must never reuse a single-use nonce');
    }

    public function test_non_idempotent_requests_are_not_retried_unless_the_client_sends_an_idempotency_key(): void
    {
        Http::fake(['orders.internal/*' => Http::sequence()->push('', 503)->push('', 503)->push('{"id":1}', 201)]);

        $this->postJson('/api/v1/orders', ['sku' => 'a'])->assertStatus(503);
        Http::assertSentCount(1);

        $this->withHeaders(['Idempotency-Key' => 'checkout-12345'])->postJson('/api/v1/orders', ['sku' => 'a'])->assertStatus(201);
        Http::assertSentCount(3);
        Http::assertSent(fn (Request $r) => $r->hasHeader('Idempotency-Key') && $r->header('Idempotency-Key')[0] === 'checkout-12345');
    }

    public function test_unreachable_service_returns_502_or_504_without_leaking_internal_addresses(): void
    {
        Http::fake(['orders.internal/*' => fn () => throw new ConnectionException('cURL error 7: Failed to connect to orders.internal port 80')]);
        $refused = $this->getJson('/api/v1/orders/1')->assertStatus(502);
        $this->assertStringNotContainsString('orders.internal', $refused->getContent());
        $this->assertStringNotContainsString('curl', strtolower($refused->getContent()));
    }

    public function test_a_slow_service_yields_504(): void
    {
        Http::fake(['orders.internal/*' => fn () => throw new ConnectionException('cURL error 28: Operation timed out after 10001 milliseconds')]);
        $this->getJson('/api/v1/orders/1')->assertStatus(504);
    }

    public function test_open_circuit_fails_fast_with_503_and_retry_after_and_stops_hitting_the_service(): void
    {
        Http::fake(['orders.internal/*' => Http::response('', 500)]);

        // GET /1 is retried: 3 attempts → threshold reached within the first request.
        $this->getJson('/api/v1/orders/1');
        $sent = count(Http::recorded());

        $response = $this->getJson('/api/v1/orders/2')->assertStatus(503);
        $this->assertNotEmpty($response->headers->get('Retry-After'));
        $this->assertCount($sent, Http::recorded(), 'open circuit must not touch the service');
    }

    public function test_client_errors_from_the_service_pass_through_and_never_trip_the_circuit(): void
    {
        Http::fake(['orders.internal/*' => Http::response('{"message":"Not found"}', 404)]);

        for ($i = 0; $i < 6; $i++) {
            $this->getJson('/api/v1/orders/'.$i)->assertStatus(404);
        }

        Http::assertSentCount(6);
    }
}
