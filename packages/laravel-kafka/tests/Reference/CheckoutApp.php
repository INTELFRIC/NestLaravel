<?php

/**
 * REFERENCE APPLICATION — "checkout" across Orders, Inventory and Payments.
 *
 * Read this file top to bottom together with REFERENCE-APP.md. In a real deployment every service is its own Laravel
 * app with its own database, outbox and inbox; here they share one in-memory database and one in-memory broker so the
 * whole flow (and every failure) can run inside a test, deterministically.
 *
 *   happy path      orders.order.created → inventory.stock.reserved → payments.payment.completed → orders.order.confirmed
 *   payment fails   … → payments.payment.failed → inventory.release.requested → orders.order.cancelled
 *
 * Who owns what:
 *   Orders     owns the saga (the process), the `orders` table, and the decision to confirm/cancel.
 *   Inventory  owns `stock` and `reservations`; reserves and releases on request; knows nothing about payments.
 *   Payments   owns `payments`; charges and refunds on request; knows nothing about inventory.
 * Services only talk through events. Every reply is written to the outbox in the SAME transaction as the local change.
 */

namespace NestLaravel\Kafka\Tests\Reference;

use Illuminate\Support\Facades\DB;
use NestLaravel\Kafka\Consumers\MessageHandler;
use NestLaravel\Kafka\Contracts\EventBus;
use NestLaravel\Kafka\Events\AbstractDomainEvent;
use NestLaravel\Kafka\Saga\Compensation;
use NestLaravel\Kafka\Saga\Saga;
use NestLaravel\Kafka\Saga\SagaContext;
use NestLaravel\Kafka\Saga\SagaOrchestrator;
use NestLaravel\Kafka\Saga\SagaStep;
use NestLaravel\Kafka\Saga\StepResult;

/** One class for every event of the example (real services would have one class + schema per event; see `generate event`). */
final class CheckoutEvent extends AbstractDomainEvent
{
    /** @param array<string, mixed> $payload */
    public function __construct(private readonly string $type, private readonly string $orderId, private readonly array $payload = [])
    {
        parent::__construct();
    }

    public function eventType(): string { return $this->type; }

    public function aggregateId(): string { return $this->orderId; }

    public function aggregateType(): string { return 'checkout'; }

    public function payload(): array { return ['order_id' => $this->orderId] + $this->payload; }
}

function emit(string $type, string $orderId, array $payload = []): void
{
    app(EventBus::class)->publish(new CheckoutEvent($type, $orderId, $payload));
}

// ---------------------------------------------------------------------------------------------------------------
// ORDERS — saga steps (each runs in one DB transaction together with the events it publishes)
// ---------------------------------------------------------------------------------------------------------------

final class CreateOrderRecord implements SagaStep
{
    public function execute(SagaContext $c): StepResult
    {
        DB::table('orders')->insertOrIgnore([
            'order_id' => $c->get('order_id'), 'sku' => $c->get('sku'), 'qty' => $c->get('qty'), 'amount' => $c->get('amount'), 'status' => 'pending',
        ]);
        emit('orders.order.created', $c->get('order_id'), ['sku' => $c->get('sku'), 'qty' => $c->get('qty'), 'amount' => $c->get('amount')]);

        return StepResult::done();
    }
}

final class CancelOrder implements Compensation
{
    public function compensate(SagaContext $c): void
    {
        DB::table('orders')->where('order_id', $c->get('order_id'))->update(['status' => 'cancelled']);
        emit('orders.order.cancelled', $c->get('order_id'), ['reason' => 'checkout failed']);
    }
}

final class ReserveStock implements SagaStep
{
    public function execute(SagaContext $c): StepResult
    {
        emit('inventory.reserve.requested', $c->get('order_id'), ['sku' => $c->get('sku'), 'qty' => $c->get('qty')]);

        return StepResult::waitFor('inventory.stock.reserved', ['inventory.stock.rejected'], timeoutSeconds: 60);
    }
}

final class ReleaseStock implements Compensation
{
    public function compensate(SagaContext $c): void
    {
        emit('inventory.release.requested', $c->get('order_id'));   // idempotent on the inventory side
    }
}

final class ChargePayment implements SagaStep
{
    public function execute(SagaContext $c): StepResult
    {
        emit('payments.charge.requested', $c->get('order_id'), ['amount' => $c->get('amount'), 'card' => $c->get('card')]);

        return StepResult::waitFor('payments.payment.completed', ['payments.payment.failed'], timeoutSeconds: 60);
    }
}

final class RefundPayment implements Compensation
{
    public function compensate(SagaContext $c): void
    {
        emit('payments.refund.requested', $c->get('order_id'));     // idempotent on the payments side
    }
}

final class ConfirmOrder implements SagaStep
{
    public function execute(SagaContext $c): StepResult
    {
        DB::table('orders')->where('order_id', $c->get('order_id'))->update(['status' => 'confirmed']);
        emit('orders.order.confirmed', $c->get('order_id'));

        return StepResult::done();
    }
}

// ---------------------------------------------------------------------------------------------------------------
// ORDERS — consumer: feeds events to the saga. INVENTORY / PAYMENTS — consumers: do their local work and reply.
// ---------------------------------------------------------------------------------------------------------------

final class OrdersHandler implements MessageHandler
{
    public function handle(array $event): void
    {
        if (str_starts_with($event['event_type'], 'inventory.stock.') || str_starts_with($event['event_type'], 'payments.payment.')) {
            app(SagaOrchestrator::class)->handleEvent($event['event_type'], $event['payload'], $event['correlation_id']);
        }
    }
}

final class InventoryHandler implements MessageHandler
{
    public function handle(array $event): void
    {
        $p = $event['payload'];

        match ($event['event_type']) {
            'inventory.reserve.requested' => $this->reserve($p),
            'inventory.release.requested' => $this->release($p),
            default => null,
        };
    }

    private function reserve(array $p): void
    {
        $available = (int) DB::table('stock')->where('sku', $p['sku'])->value('qty');

        if ($available < $p['qty']) {
            emit('inventory.stock.rejected', $p['order_id'], ['reason' => 'out of stock']);

            return;
        }

        DB::table('stock')->where('sku', $p['sku'])->decrement('qty', $p['qty']);
        DB::table('reservations')->insert(['order_id' => $p['order_id'], 'sku' => $p['sku'], 'qty' => $p['qty'], 'released' => false]);
        emit('inventory.stock.reserved', $p['order_id']);
    }

    private function release(array $p): void
    {
        $reservation = DB::table('reservations')->where('order_id', $p['order_id'])->where('released', false)->first();

        if ($reservation === null) {
            return;                                   // nothing reserved, or already released: compensation is idempotent
        }

        DB::table('reservations')->where('order_id', $p['order_id'])->update(['released' => true]);
        DB::table('stock')->where('sku', $reservation->sku)->increment('qty', $reservation->qty);
    }
}

final class PaymentsHandler implements MessageHandler
{
    public function handle(array $event): void
    {
        $p = $event['payload'];

        if ($event['event_type'] === 'payments.charge.requested') {
            if (($p['card'] ?? '') === 'declined') {
                emit('payments.payment.failed', $p['order_id'], ['reason' => 'card declined']);

                return;
            }
            DB::table('payments')->insert(['order_id' => $p['order_id'], 'amount' => $p['amount']]);
            emit('payments.payment.completed', $p['order_id'], ['transaction' => 'tx-'.$p['order_id']]);
        }

        if ($event['event_type'] === 'payments.refund.requested') {
            DB::table('payments')->where('order_id', $p['order_id'])->delete();
        }
    }
}

final class CheckoutSaga
{
    public const NAME = 'order_checkout';

    /** Registered once at boot of the Orders service. */
    public static function define(): void
    {
        Saga::define(self::NAME)
            ->step(CreateOrderRecord::class)->compensate(CancelOrder::class)
            ->step(ReserveStock::class)->compensate(ReleaseStock::class)
            ->step(ChargePayment::class)->compensate(RefundPayment::class)
            ->step(ConfirmOrder::class);
    }
}
