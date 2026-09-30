<?php

namespace NestLaravel\Tenancy\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use NestLaravel\Kafka\Contracts\EventBus;
use NestLaravel\Kafka\Events\AbstractDomainEvent;
use NestLaravel\Kafka\Observability\LogContext;
use NestLaravel\Kafka\Outbox\OutboxMessage;
use NestLaravel\Tenancy\Exceptions\TenantNotResolved;

final class TenantInvoiceCreated extends AbstractDomainEvent
{
    public function eventType(): string { return 'billing.invoice.created'; }
    public function aggregateId(): string { return '1'; }
    public function aggregateType(): string { return 'invoice'; }
    public function payload(): array { return []; }
}

final class TenantNoopJob implements \Illuminate\Contracts\Queue\ShouldQueue
{
    use \Illuminate\Bus\Queueable, \Illuminate\Foundation\Bus\Dispatchable;

    public function handle(): void {}
}

class TenantPropagationTest extends TestCase
{
    public function test_log_lines_carry_the_active_tenant_and_it_is_removed_afterwards(): void
    {
        $this->tenants->run('tenant-a', function (): void {
            $this->assertSame('tenant-a', LogContext::all()['tenant_id']);
        });

        $this->assertArrayNotHasKey('tenant_id', LogContext::all());
    }

    public function test_strict_mode_refuses_to_dispatch_a_tenantless_job(): void
    {
        config(['tenancy.strict_jobs' => true]);

        // Dispatch through the bus directly: PendingDispatch defers the push to its destructor.
        $bus = $this->app->make(\Illuminate\Contracts\Bus\Dispatcher::class);

        $this->tenants->run('tenant-a', fn () => $bus->dispatch(new TenantNoopJob));   // tenant known: fine

        try {
            $bus->dispatch(new TenantNoopJob);
            $this->fail('a tenant-less dispatch must be refused');
        } catch (TenantNotResolved) {
            $this->addToAssertionCount(1);
        }

        // Explicit cross-tenant maintenance stays possible (and greppable).
        $this->tenants->withoutTenancy(fn () => $bus->dispatch(new TenantNoopJob));
        $this->addToAssertionCount(1);
    }

    public function test_events_published_inside_a_tenant_carry_it_through_the_outbox(): void
    {
        Schema::create('outbox_messages', function (Blueprint $t) {
            $t->id();
            $t->string('event_id')->unique();
            $t->string('event_type');
            $t->string('aggregate_id');
            $t->string('aggregate_type');
            $t->json('payload');
            $t->string('topic');
            $t->string('correlation_id')->nullable();
            $t->string('tenant_id')->nullable();
            $t->string('status')->default('pending');
            $t->unsignedInteger('attempts')->default(0);
            $t->timestamp('available_at')->nullable();
            $t->timestamp('locked_at')->nullable();
            $t->string('locked_by')->nullable();
            $t->text('last_error')->nullable();
            $t->timestamp('published_at')->nullable();
            $t->timestamps();
        });

        $this->tenants->run('tenant-a', fn () => $this->app->make(EventBus::class)->publish(new TenantInvoiceCreated));

        $row = OutboxMessage::firstOrFail();
        $this->assertSame('tenant-a', $row->tenant_id);
        $this->assertSame('tenant-a', $row->payload['tenant_id']);
    }
}
