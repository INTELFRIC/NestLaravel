<?php

namespace NestLaravel\Tenancy\Tests;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Request;
use InvalidArgumentException;
use NestLaravel\Kafka\Consumers\MessageHandler;
use NestLaravel\Kafka\Events\AbstractDomainEvent;
use NestLaravel\Tenancy\Concerns\BelongsToTenant;
use NestLaravel\Tenancy\Exceptions\CrossTenantAccess;
use NestLaravel\Tenancy\Exceptions\TenantNotResolved;
use NestLaravel\Tenancy\Http\Middleware\IdentifyTenant;
use NestLaravel\Tenancy\Kafka\TenantAwareHandler;

final class Invoice extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id'];
}

final class RecordTenantJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public static ?string $seen = null;

    public function handle(): void
    {
        self::$seen = app(\NestLaravel\Tenancy\TenantContext::class)->id();
    }
}

final class InvoiceCreated extends AbstractDomainEvent
{
    public function eventType(): string
    {
        return 'billing.invoice.created';
    }

    public function aggregateId(): string
    {
        return '1';
    }

    public function aggregateType(): string
    {
        return 'invoice';
    }

    public function payload(): array
    {
        return [];
    }
}

class CrossTenantAccessTest extends TestCase
{
    private function seedTwoTenants(): array
    {
        $a = $this->tenants->run('tenant-a', fn () => Invoice::create(['number' => 'A-1']));
        $b = $this->tenants->run('tenant-b', fn () => Invoice::create(['number' => 'B-1']));

        return [$a, $b];
    }

    public function test_tenant_cannot_read_another_tenants_rows(): void
    {
        [$a, $b] = $this->seedTwoTenants();

        $this->tenants->run('tenant-a', function () use ($a, $b): void {
            $this->assertSame(['A-1'], Invoice::pluck('number')->all());
            $this->assertNull(Invoice::find($b->id), 'direct id lookup of a foreign row must miss');
            $this->assertSame(0, Invoice::where('number', 'B-1')->count());
            $this->assertNotNull(Invoice::find($a->id));
        });
    }

    public function test_tenant_cannot_update_or_delete_another_tenants_rows(): void
    {
        [, $b] = $this->seedTwoTenants();

        $this->tenants->run('tenant-a', function () use ($b): void {
            $this->assertSame(0, Invoice::where('id', $b->id)->update(['number' => 'HACKED']));
            $this->assertSame(0, Invoice::where('id', $b->id)->delete());
        });

        $this->assertSame('B-1', $this->tenants->withoutTenancy(fn () => Invoice::find($b->id))->number);
    }

    public function test_queries_without_a_tenant_fail_closed(): void
    {
        $this->seedTwoTenants();

        $this->expectException(TenantNotResolved::class);
        Invoice::all();
    }

    public function test_creating_a_row_for_another_tenant_is_rejected(): void
    {
        $this->tenants->run('tenant-a', function (): void {
            $this->expectException(CrossTenantAccess::class);
            Invoice::create(['number' => 'X', 'tenant_id' => 'tenant-b']);
        });
    }

    public function test_a_row_cannot_be_moved_to_another_tenant(): void
    {
        $this->tenants->run('tenant-a', function (): void {
            $invoice = Invoice::create(['number' => 'A-2']);
            $invoice->tenant_id = 'tenant-b';

            $this->expectException(CrossTenantAccess::class);
            $invoice->save();
        });
    }

    public function test_admin_bypass_sees_everything_but_is_explicit(): void
    {
        $this->seedTwoTenants();

        $this->assertCount(2, $this->tenants->withoutTenancy(fn () => Invoice::all()));
    }

    public function test_middleware_rejects_requests_without_a_signed_tenant_and_ignores_client_headers(): void
    {
        $middleware = new IdentifyTenant($this->tenants);
        $next = fn (Request $r) => response('ok');

        // A spoofed client header must not select a tenant.
        $request = Request::create('/x', 'GET', server: ['HTTP_X_TENANT_ID' => 'tenant-b']);
        $this->assertSame(403, $middleware->handle($request, $next)->getStatusCode());

        $request = Request::create('/x', 'GET');
        $request->attributes->set('gateway_tenant_id', 'tenant-a');
        $seen = null;
        $middleware->handle($request, function () use (&$seen) {
            $seen = $this->tenants->id();

            return response('ok');
        });

        $this->assertSame('tenant-a', $seen);
        $this->assertNull($this->tenants->id(), 'tenant must not leak past the request');
    }

    public function test_queued_jobs_run_in_the_tenant_that_dispatched_them(): void
    {
        RecordTenantJob::$seen = null;

        $this->tenants->run('tenant-a', function (): void {
            RecordTenantJob::dispatch();
            $this->assertSame('tenant-a', RecordTenantJob::$seen);
            $this->assertSame('tenant-a', $this->tenants->id(), 'previous tenant restored after the job');
        });
    }

    public function test_kafka_events_carry_the_tenant_and_consumers_run_inside_it(): void
    {
        $event = $this->tenants->run('tenant-a', fn () => (new InvoiceCreated)->toArray());
        $this->assertSame('tenant-a', $event['tenant_id']);

        $seen = null;
        $handler = new TenantAwareHandler(new class($this->tenants, $seen) implements MessageHandler
        {
            public function __construct(private $tenants, public &$seen) {}

            public function handle(array $event): void
            {
                $this->seen = $this->tenants->id();
            }
        }, $this->tenants);

        $handler->handle($event);
        $this->assertNull($this->tenants->id());

        $this->expectException(InvalidArgumentException::class);
        $handler->handle(['event_id' => 'x']);
    }

    public function test_cache_keys_and_storage_paths_are_tenant_scoped_and_traversal_safe(): void
    {
        $this->tenants->run('tenant-a', function (): void {
            $this->assertSame('t:tenant-a:reports', $this->tenants->cacheKey('reports'));
            $this->assertSame('tenants/tenant-a/uploads/a.png', $this->tenants->path('uploads/a.png'));
            $this->assertStringNotContainsString('..', $this->tenants->path('../tenant-b/secret'));
        });
    }
}
