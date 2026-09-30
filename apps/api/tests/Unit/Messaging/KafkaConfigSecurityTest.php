<?php

namespace Tests\Unit\Messaging;

use App\Infrastructure\Kafka\KafkaConfig;
use PHPUnit\Framework\TestCase;

class KafkaConfigSecurityTest extends TestCase
{
    public function test_producer_defaults_are_safe_delivery_settings(): void
    {
        $s = (new KafkaConfig(['brokers' => 'b1:9092']))->producerSettings();

        $this->assertSame('all', $s['acks']);
        $this->assertSame('true', $s['enable.idempotence']);
        $this->assertSame('plaintext', $s['security.protocol']);
        $this->assertArrayNotHasKey('sasl.password', $s);
    }

    public function test_sasl_ssl_settings_are_passed_through(): void
    {
        $s = (new KafkaConfig(['security' => [
            'protocol' => 'sasl_ssl',
            'sasl_mechanism' => 'SCRAM-SHA-512',
            'sasl_username' => 'svc-orders',
            'sasl_password' => 'pw',
            'ssl_ca_location' => '/etc/ssl/ca.pem',
        ]]))->connectionSettings();

        $this->assertSame('sasl_ssl', $s['security.protocol']);
        $this->assertSame('svc-orders', $s['sasl.username']);
        $this->assertSame('/etc/ssl/ca.pem', $s['ssl.ca.location']);
    }

    public function test_consumer_never_auto_commits(): void
    {
        $s = (new KafkaConfig)->consumerSettings();

        $this->assertSame('false', $s['enable.auto.commit']);
    }
}
