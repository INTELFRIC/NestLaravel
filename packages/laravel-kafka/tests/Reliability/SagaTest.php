<?php

namespace NestLaravel\Kafka\Tests\Reliability;

use Illuminate\Support\Facades\DB;
use NestLaravel\Kafka\Contracts\EventBus;
use NestLaravel\Kafka\Outbox\OutboxMessage;
use NestLaravel\Kafka\Saga\Compensation;
use NestLaravel\Kafka\Saga\Saga;
use NestLaravel\Kafka\Saga\SagaContext;
use NestLaravel\Kafka\Saga\SagaInstance;
use NestLaravel\Kafka\Saga\SagaOrchestrator;
use NestLaravel\Kafka\Saga\SagaStep;
use NestLaravel\Kafka\Saga\StepResult;
use NestLaravel\Kafka\Tests\ReliabilityTestCase;
use RuntimeException;

/** Shared call log so tests can assert forward/compensation ORDER. */
final class SagaLog
{
    /** @var list<string> */
    public static array $calls = [];

    public static int $flakyFailures = 0;

    public static bool $refundFails = false;
}

final class ReserveInventory implements SagaStep
{
    public function execute(SagaContext $c): StepResult
    {
        SagaLog::$calls[] = 'reserve';
        // Outbox event written in the same transaction as the saga state.
        app(EventBus::class)->publish(new PaymentCompleted($c->get('order_id')));

        return StepResult::done(['reserved' => true]);
    }
}

final class ReleaseInventory implements Compensation
{
    public function compensate(SagaContext $c): void
    {
        SagaLog::$calls[] = 'release';
    }
}

final class RequestPayment implements SagaStep
{
    public function execute(SagaContext $c): StepResult
    {
        SagaLog::$calls[] = 'request_payment';

        return StepResult::waitFor('payments.payment.completed', ['payments.payment.failed'], timeoutSeconds: 60);
    }
}

final class RefundPayment implements Compensation
{
    public function compensate(SagaContext $c): void
    {
        if (SagaLog::$refundFails) {
            throw new RuntimeException('payment provider down');
        }
        SagaLog::$calls[] = 'refund';
    }
}

final class ConfirmOrder implements SagaStep
{
    public function execute(SagaContext $c): StepResult
    {
        SagaLog::$calls[] = 'confirm:'.($c->event['transaction'] ?? '-');

        return StepResult::done(['confirmed' => true]);
    }
}

final class FlakyStep implements SagaStep
{
    public function execute(SagaContext $c): StepResult
    {
        SagaLog::$calls[] = 'flaky';
        if (SagaLog::$flakyFailures-- > 0) {
            throw new RuntimeException('temporary');
        }

        return StepResult::done();
    }
}

final class RejectingStep implements SagaStep
{
    public function execute(SagaContext $c): StepResult
    {
        return StepResult::fail('fraud suspected');
    }
}

class SagaTest extends ReliabilityTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        SagaLog::$calls = [];
        SagaLog::$flakyFailures = 0;
        SagaLog::$refundFails = false;
        Saga::flush();

        Saga::define('order_checkout')
            ->step(ReserveInventory::class)->compensate(ReleaseInventory::class)
            ->step(RequestPayment::class)->compensate(RefundPayment::class)
            ->step(ConfirmOrder::class);
    }

    private function orchestrator(): SagaOrchestrator
    {
        return $this->app->make(SagaOrchestrator::class);
    }

    public function test_happy_path_pauses_for_the_payment_event_then_completes(): void
    {
        $saga = Saga::start('order_checkout', ['order_id' => 'o-1'], 'corr-1');

        $this->assertSame(SagaInstance::WAITING, $saga->status);
        $this->assertSame('payments.payment.completed', $saga->waiting_for);
        $this->assertSame(['reserve', 'request_payment'], SagaLog::$calls);

        $this->assertSame(1, $this->orchestrator()->handleEvent('payments.payment.completed', ['transaction' => 'tx-9'], 'corr-1'));

        $saga->refresh();
        $this->assertSame(SagaInstance::COMPLETED, $saga->status);
        $this->assertSame(['reserve', 'request_payment', 'confirm:tx-9'], SagaLog::$calls);
        $this->assertTrue($saga->context['confirmed']);
    }

    public function test_payment_failed_compensates_completed_steps_in_reverse_order(): void
    {
        Saga::start('order_checkout', ['order_id' => 'o-2'], 'corr-2');

        $this->orchestrator()->handleEvent('payments.payment.failed', ['reason' => 'card declined'], 'corr-2');

        $saga = SagaInstance::firstWhere('correlation_id', 'corr-2');
        $this->assertSame(SagaInstance::COMPENSATED, $saga->status);
        $this->assertSame(['reserve', 'request_payment', 'refund', 'release'], SagaLog::$calls, 'payment (latest) is undone before inventory');
        $this->assertNotContains('confirm:-', SagaLog::$calls);
        $this->assertStringContainsString('payments.payment.failed', $saga->last_error);
    }

    public function test_duplicate_start_and_duplicate_events_are_idempotent(): void
    {
        Saga::start('order_checkout', ['order_id' => 'o-3'], 'corr-3');
        Saga::start('order_checkout', ['order_id' => 'o-3'], 'corr-3');   // duplicate command

        $this->assertSame(1, SagaInstance::count());
        $this->assertSame(['reserve', 'request_payment'], SagaLog::$calls, 'steps did not run twice');

        $this->assertSame(1, $this->orchestrator()->handleEvent('payments.payment.completed', [], 'corr-3'));
        $this->assertSame(0, $this->orchestrator()->handleEvent('payments.payment.completed', [], 'corr-3'), 'duplicate event ignored');
        $this->assertSame(1, collect(SagaLog::$calls)->filter(fn ($c) => str_starts_with($c, 'confirm'))->count());
    }

    public function test_timeout_triggers_compensation_via_recovery(): void
    {
        Saga::start('order_checkout', ['order_id' => 'o-4'], 'corr-4');

        $this->assertSame(0, $this->orchestrator()->recoverOverdue(), 'not overdue yet');

        $this->travel(61)->seconds();
        $this->assertSame(1, $this->orchestrator()->recoverOverdue());

        $saga = SagaInstance::firstWhere('correlation_id', 'corr-4');
        $this->assertSame(SagaInstance::COMPENSATED, $saga->status);
        $this->assertStringContainsString('Timed out', $saga->last_error);
        $this->assertSame(['reserve', 'request_payment', 'refund', 'release'], SagaLog::$calls);
    }

    public function test_a_throwing_step_is_retried_then_succeeds(): void
    {
        Saga::flush();
        Saga::define('retrying')->step(FlakyStep::class)->retries(3);
        SagaLog::$flakyFailures = 2;

        $saga = Saga::start('retrying', [], 'corr-5');

        $this->assertSame(SagaInstance::COMPLETED, $saga->status);
        $this->assertSame(['flaky', 'flaky', 'flaky'], SagaLog::$calls);
    }

    public function test_exhausted_retries_compensate_earlier_steps(): void
    {
        Saga::flush();
        Saga::define('failing')->step(ReserveInventory::class)->compensate(ReleaseInventory::class)->step(FlakyStep::class)->retries(1);
        SagaLog::$flakyFailures = 99;

        $saga = Saga::start('failing', ['order_id' => 'o-6'], 'corr-6');

        $this->assertSame(SagaInstance::COMPENSATED, $saga->status);
        $this->assertSame(['reserve', 'flaky', 'flaky', 'release'], SagaLog::$calls);
    }

    public function test_business_rejection_compensates_immediately_without_retries(): void
    {
        Saga::flush();
        Saga::define('screened')->step(ReserveInventory::class)->compensate(ReleaseInventory::class)->step(RejectingStep::class);

        $saga = Saga::start('screened', ['order_id' => 'o-7'], 'corr-7');

        $this->assertSame(SagaInstance::COMPENSATED, $saga->status);
        $this->assertSame(['reserve', 'release'], SagaLog::$calls);
        $this->assertStringContainsString('fraud suspected', $saga->last_error);
    }

    public function test_compensation_that_keeps_failing_parks_the_saga_for_a_human(): void
    {
        Saga::start('order_checkout', ['order_id' => 'o-8'], 'corr-8');
        SagaLog::$refundFails = true;

        $this->orchestrator()->handleEvent('payments.payment.failed', [], 'corr-8');

        $saga = SagaInstance::firstWhere('correlation_id', 'corr-8');
        $this->assertSame(SagaInstance::FAILED, $saga->status);
        $this->assertStringContainsString('payment provider down', $saga->last_error);
        $this->assertNotContains('release', SagaLog::$calls, 'later compensations do not run past a failed one');
    }

    public function test_step_events_are_committed_atomically_with_saga_state(): void
    {
        Saga::start('order_checkout', ['order_id' => 'o-9'], 'corr-9');

        $this->assertSame(1, OutboxMessage::count(), 'the step published through the outbox in the same transaction');
    }

    public function test_state_survives_a_process_restart_and_a_crashed_runner_is_recovered(): void
    {
        Saga::flush();
        Saga::define('recoverable')->step(FlakyStep::class)->step(ConfirmOrder::class);

        // Simulate a runner that claimed the saga, executed nothing durable, and died.
        $id = (string) \Illuminate\Support\Str::uuid();
        SagaInstance::query()->insert([
            'id' => $id, 'name' => 'recoverable', 'correlation_id' => 'corr-10', 'status' => 'running', 'step_index' => 0, 'attempts' => 0,
            'context' => '{}', 'history' => '[]', 'locked_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame(0, $this->orchestrator()->recoverOverdue(), 'lock is fresh: the runner may still be alive');

        $this->travel(121)->seconds();
        $fresh = new SagaOrchestrator;   // a different process
        $this->assertSame(1, $fresh->recoverOverdue());

        $this->assertSame(SagaInstance::COMPLETED, SagaInstance::find($id)->status);
    }

    public function test_two_workers_cannot_advance_the_same_saga_concurrently(): void
    {
        Saga::start('order_checkout', ['order_id' => 'o-11'], 'corr-11');
        $saga = SagaInstance::firstWhere('correlation_id', 'corr-11');

        // Worker A holds the claim (mid-processing).
        SagaInstance::query()->whereKey($saga->id)->update(['locked_at' => now()]);

        $this->assertSame(0, $this->orchestrator()->handleEvent('payments.payment.completed', [], 'corr-11'), 'worker B backs off');
        $this->assertSame(SagaInstance::WAITING, $saga->refresh()->status);
    }

    public function test_unknown_saga_is_a_clear_error(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Saga::start('does_not_exist');
    }
}
