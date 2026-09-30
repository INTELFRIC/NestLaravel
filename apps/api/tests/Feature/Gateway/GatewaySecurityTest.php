<?php

namespace Tests\Feature\Gateway;

use App\Infrastructure\Gateway\GatewayProxy;
use App\Infrastructure\Gateway\GatewaySigner;
use App\Infrastructure\Gateway\ServiceRegistry;
use App\Models\User;
use App\Providers\GatewayServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GatewaySecurityTest extends TestCase
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
        ]);

        // Routes are registered at boot; re-boot the provider with the test config.
        $this->refreshApplicationWithGateway();
    }

    private function refreshApplicationWithGateway(): void
    {
        $this->app->forgetInstance(ServiceRegistry::class);
        $this->app->forgetInstance(GatewayProxy::class);
        (new GatewayServiceProvider($this->app))->boot();
        $this->app['router']->getRoutes()->refreshNameLookups();
        $this->app['router']->getRoutes()->refreshActionLookups();
    }

    public function test_anonymous_callers_cannot_reach_internal_services(): void
    {
        Http::fake();

        $this->getJson('/api/v1/orders/anything')->assertStatus(401);

        Http::assertNothingSent();
    }

    public function test_authenticated_request_is_signed_for_the_downstream_service(): void
    {
        Http::fake(['orders.internal/*' => Http::response(['ok' => true], 200)]);
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/orders/42?expand=items')->assertOk();

        Http::assertSent(function ($request) use ($user) {
            $expected = GatewaySigner::signature(
                'gateway-orders-secret',
                'GET',
                '/api/v1/orders/42?expand=items',
                $request->body(),
                (string) $user->getKey(),
                $request->header(GatewaySigner::HEADER_TIMESTAMP)[0],
                $request->header(GatewaySigner::HEADER_NONCE)[0],
            );

            return $request->url() === 'http://orders.internal/api/v1/orders/42?expand=items'
                && $request->header(GatewaySigner::HEADER_SIGNATURE)[0] === $expected
                && ! $request->hasHeader('Authorization');
        });
    }

    public function test_path_traversal_is_blocked_before_reaching_the_service(): void
    {
        Http::fake();
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/orders/%2e%2e/admin')->assertStatus(400);
        $this->getJson('/api/v1/orders/a/%2e%2e/admin')->assertStatus(400);

        Http::assertNothingSent();
    }

    public function test_service_registry_endpoint_requires_auth_and_hides_internal_urls(): void
    {
        $this->getJson('/api/gateway/services')->assertStatus(401);

        Sanctum::actingAs(User::factory()->create());
        $response = $this->getJson('/api/gateway/services')->assertOk();

        $this->assertStringNotContainsString('orders.internal', $response->getContent());
    }
}
