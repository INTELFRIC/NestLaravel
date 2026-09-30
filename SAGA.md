# Sagas

A **saga** is a business transaction that spans services. There is no distributed database transaction between
`orders`, `inventory` and `payments`, so a saga replaces "rollback" with **compensation**: every step that has a
side-effect declares how to undo it.

NestLaravel ships an **orchestrated** saga runner in `nestlaravel/kafka` (`NestLaravel\Kafka\Saga`). The orchestrator
lives inside one service (typically the one that owns the business process, e.g. `orders`), keeps its state in that
service's database and talks to the other services through events (via the transactional outbox).

```php
use NestLaravel\Kafka\Saga\Saga;

// AppServiceProvider::boot()
Saga::define('order_checkout')
    ->step(ReserveInventory::class)->compensate(ReleaseInventory::class)
    ->step(ProcessPayment::class)->compensate(RefundPayment::class)->retries(2)
    ->step(ConfirmOrder::class);

// From an Action, after the order row is created
Saga::start('order_checkout', ['order_id' => $order->id], correlationId: $order->id);
```

## Writing a step

```php
final class ProcessPayment implements SagaStep
{
    public function execute(SagaContext $ctx): StepResult
    {
        app(EventBus::class)->publish(new PaymentRequested($ctx->get('order_id'), $ctx->get('total')));

        // Pause until payments answers. Either failure event, or 300 s of silence, triggers compensation.
        return StepResult::waitFor(
            successEvent: 'payments.payment.completed',
            failureEvents: ['payments.payment.failed'],
            timeoutSeconds: 300,
        );
    }
}
```

| Result | Meaning |
|--------|---------|
| `StepResult::done($data)` | Finished synchronously; `$data` is merged into the saga context; continue. |
| `StepResult::waitFor($ok, [$fail…], $timeout)` | Remote work started; pause. |
| `StepResult::fail($reason)` | Business rejection: compensate now, **no retries**. |
| *throws* | Technical failure: retried up to `->retries(n)` (default 2), then compensate. |

A compensation implements `Compensation::compensate(SagaContext $ctx)`.

## Feeding events into the saga

Sagas resume from events. In the orchestrating service's Kafka consumer handler:

```php
public function handle(array $event): void
{
    app(SagaOrchestrator::class)->handleEvent($event['event_type'], $event['payload'], $event['correlation_id']);
}
```

Run `php artisan saga:recover` every minute (scheduler). It handles **timeouts** and **crashed runners**.

## What is guaranteed (each item has a test in `tests/Reliability/SagaTest.php`)

| Guarantee | Test |
|-----------|------|
| Happy path pauses for the event, then completes | `test_happy_path_pauses_for_the_payment_event_then_completes` |
| Failure event ⇒ completed steps compensated in **reverse** order | `test_payment_failed_compensates_completed_steps_in_reverse_order` |
| Duplicate `start()` and duplicate events do nothing extra | `test_duplicate_start_and_duplicate_events_are_idempotent` |
| Timeout ⇒ compensation | `test_timeout_triggers_compensation_via_recovery` |
| Throwing step is retried; exhausted retries compensate | `test_a_throwing_step_is_retried_then_succeeds`, `test_exhausted_retries_compensate_earlier_steps` |
| Business rejection never retries | `test_business_rejection_compensates_immediately_without_retries` |
| Failing compensation parks the saga in `failed` instead of guessing | `test_compensation_that_keeps_failing_parks_the_saga_for_a_human` |
| Step state and its outbox events commit atomically | `test_step_events_are_committed_atomically_with_saga_state` |
| State survives restarts; crashed runner is recovered | `test_state_survives_a_process_restart_and_a_crashed_runner_is_recovered` |
| Two workers cannot advance one saga at once | `test_two_workers_cannot_advance_the_same_saga_concurrently` |

## What is NOT guaranteed — read before relying on it

* **Steps run at-least-once.** A crash between "step ran" and "state saved" re-runs the step after recovery. Steps
  must be idempotent (use natural keys / the `Idempotency-Key` pattern, or `EventInbox` on the receiving side).
* **Compensations are best-effort with bounded retries** (3). If they still fail, the saga is `failed` and a log line
  at `critical` level plus the metric `nestlaravel_saga_total{result="failed"}` tell you a human must intervene.
  Alert on it.
* **No isolation.** Between step 1 and compensation, other requests can observe the intermediate state
  (e.g. reserved stock). Design compensations as *semantic* undo (release reservation), not as a database rollback.
* **The claim uses a `locked_at` column** (portable across PostgreSQL/MySQL/SQLite). A runner that hangs for longer than
  120 s loses its claim to `saga:recover`; a step slower than that risks running twice.
* Choreography (services reacting to each other's events with no orchestrator) is still available; use a saga when
  the business process has an owner and needs explicit failure handling.

## Operating

| Signal | Where |
|--------|-------|
| Instances and their history (every transition is recorded) | table `saga_instances`, column `history` |
| Stuck in `waiting` past `deadline_at` | `php artisan saga:recover` handles it; alert if `saga_instances` has `waiting` rows older than expected |
| Needs a human | `status = 'failed'` |
| Counters | `nestlaravel_saga_total{saga, result=started, completed, compensated, timed_out or failed}` |

Correlation: the saga's `correlation_id` is put in every log line of the step and is inherited as `causation`/`correlation`
by events the step publishes, so one order can be followed across services in logs and traces.
