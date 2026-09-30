# Reference application: checkout

A complete, runnable example of the reliability features working together across three services. Source:
[`packages/laravel-kafka/tests/Reference/CheckoutApp.php`](packages/laravel-kafka/tests/Reference/CheckoutApp.php) (the services),
[`CheckoutSagaTest.php`](packages/laravel-kafka/tests/Reference/CheckoutSagaTest.php) (the scenarios).

```bash
cd packages/laravel-kafka
composer install
php vendor/bin/phpunit --filter CheckoutSagaTest
```

## What it models

```
                       ┌───────────────────────── Orders (owns the saga) ─────────────────────────┐
 POST /orders ────────▶│ Saga::start('order_checkout')                                             │
 (via the gateway)     │  1 CreateOrderRecord   ⟲ CancelOrder                                      │
                       │  2 ReserveStock        ⟲ ReleaseStock     ── inventory.reserve.requested ─┼──▶ Inventory
                       │       waits for inventory.stock.reserved   ◀── inventory.stock.reserved ──┼───   stock, reservations
                       │  3 ChargePayment       ⟲ RefundPayment    ── payments.charge.requested ───┼──▶ Payments
                       │       waits for payments.payment.completed ◀── payments.payment.completed ┼───   payments
                       │  4 ConfirmOrder                            ── orders.order.confirmed       │
                       └────────────────────────────────────────────────────────────────────────────┘
      ⟲ = compensation, run in reverse order when a later step fails, times out or is rejected
```

Every arrow is a Kafka event written to the sender's **outbox in the same database transaction** as its local change and
read by the receiver through its **inbox** (one transaction: dedup record + local change + reply event).

## The scenarios (all in `CheckoutSagaTest`, all passing)

| Scenario | Proven outcome |
|----------|----------------|
| Happy path | events in exactly this order: created → reserve requested → reserved → charge requested → completed → confirmed; order `confirmed`, stock −2, one payment, saga `completed` |
| Payment declined | `payments.payment.failed` → refund (no-op) → **stock released** → order **cancelled**; saga `compensated`; no payment row; cancelled event comes after the release |
| Out of stock | order cancelled; `payments.charge.requested` never emitted – no money is ever requested |
| **Every message delivered twice** to every consumer | one reservation, stock decremented once, **one payment**, one saga; the inbox counter shows duplicates were actually seen by all three services |
| **Payments crashes after its DB commit, before the offset commit** | the charge request is redelivered, skipped by the inbox, **exactly one payment** |
| Client starts the same checkout twice | one order, one charge, one stock decrement (idempotent `Saga::start` per correlation id + `insertOrIgnore`) |
| Broker outage loses the reply path | the step times out, the saga compensates, a late reserve request would be released again |

To make sure these tests can actually fail, the inbox's duplicate check was disabled by hand: the duplicate-delivery and
crash tests then fail with "2 payments instead of 1" (and pass again with the check restored).

## Mapping to a real deployment

* Each box is its own Laravel app generated with `nestlaravel generate service <name>`; each has **its own database**, so its
  outbox and inbox tables are its own (the example shares one database only to keep the test small).
* Replace `CheckoutEvent` with one class per event: `nestlaravel generate event orders.order.created --service orders --version 1`
  and fill in the schema; enable `KAFKA_SCHEMA_ENFORCE_PRODUCER/CONSUMER`.
* Each consumer is a `kafka:consume domain.events <Handler>` process with its own `KAFKA_GROUP_ID` (this is what namespaces the
  inbox); run `messaging:outbox-publish --daemon` and `saga:recover` (Orders only) next to it.
* The gateway exposes `POST /orders` → Orders; the client gets `202 Accepted` with the order id and polls (or is notified) –
  confirmation is asynchronous by design.

## What this example does not show

* Real Kafka: the broker is in memory, so partitioning, rebalancing and network faults are not exercised
  (`RdKafkaBrokerTest` covers real produce/consume/DLQ in CI).
* External side effects: `PaymentsHandler` writes a row instead of calling a card processor. A real charge must use the
  provider's idempotency key (`Idempotency-Key: order_id`) because it lives outside the database transaction.
* Separate databases: cross-service data is only ever exchanged through events here as well, but the example cannot show
  independent failure of two databases.
* Users and Notifications services: they follow the same pattern (subscribe to `orders.order.confirmed`, send a mail from an
  outbox-driven job) and add nothing new to prove.
