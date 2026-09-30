<?php

namespace NestLaravel\Kafka\Tests\Reliability;

use NestLaravel\Kafka\Events\AbstractDomainEvent;

/** Shared test event (own file so any test file can run on its own). */
final class PaymentCompleted extends AbstractDomainEvent
{
    public function __construct(private readonly string $orderId)
    {
        parent::__construct();
    }

    public function eventType(): string { return 'payments.payment.completed'; }

    public function aggregateId(): string { return $this->orderId; }

    public function aggregateType(): string { return 'payment'; }

    public function payload(): array { return ['order_id' => $this->orderId]; }
}
