<?php

namespace NestLaravel\Kafka;

use Illuminate\Support\ServiceProvider;
use NestLaravel\Kafka\Consumers\ConsumerPipeline;
use NestLaravel\Kafka\Console\KafkaConsumeCommand;
use NestLaravel\Kafka\Console\OutboxPublishCommand;
use NestLaravel\Kafka\Contracts\EventBus;
use NestLaravel\Kafka\Contracts\IdempotencyStore;
use NestLaravel\Kafka\Outbox\OutboxPublisher;
use NestLaravel\Kafka\Producers\DomainEventProducer;
use NestLaravel\Kafka\Serializers\JsonEventSerializer;

/**
 * NestLaravel Kafka kit for Laravel microservices.
 *
 * Provides the standard event envelope, transactional outbox, hardened
 * producer/consumer (acks=all, idempotence, TLS/SASL, manual commits), retry +
 * dead-letter pipeline and idempotent consumption.
 */
final class KafkaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/kafka.php', 'kafka');

        $this->app->singleton(KafkaConfig::class, static fn (): KafkaConfig => KafkaConfig::fromConfig());
        $this->app->singleton(KafkaSerializer::class);
        $this->app->singleton(JsonEventSerializer::class);
        $this->app->singleton(KafkaTopic::class);
        $this->app->singleton(DomainEventProducer::class);
        $this->app->singleton(OutboxPublisher::class);
        $this->app->singleton(ConsumerPipeline::class);

        $this->app->singleton(IdempotencyStore::class, static fn (): IdempotencyStore => new CacheIdempotencyStore(
            (string) config('kafka.consumer_pipeline.idempotency_prefix', 'kafka:processed:'),
            config('kafka.consumer_pipeline.idempotency_store'),
        ));

        $this->app->singleton(KafkaProducer::class, function ($app): KafkaProducer {
            $config = $app->make(KafkaConfig::class);
            $useRdKafka = $config->enabled() && extension_loaded('rdkafka');

            return match ($config->producerDriver()) {
                'rdkafka' => new RdKafkaKafkaProducer($config),
                'log' => new LogKafkaProducer($config),
                'null' => new NullKafkaProducer,
                default => $useRdKafka
                    ? new RdKafkaKafkaProducer($config)
                    : ($config->enabled() ? new LogKafkaProducer($config) : new NullKafkaProducer),
            };
        });

        $this->app->singleton(KafkaConsumer::class, function ($app): KafkaConsumer {
            $config = $app->make(KafkaConfig::class);
            $serializer = $app->make(KafkaSerializer::class);
            $useRdKafka = $config->enabled() && extension_loaded('rdkafka');

            return match ($config->consumerDriver()) {
                'rdkafka' => new RdKafkaKafkaConsumer($config, $serializer),
                'log' => new LogKafkaConsumer($config, $serializer),
                'null' => new NullKafkaConsumer,
                default => $useRdKafka
                    ? new RdKafkaKafkaConsumer($config, $serializer)
                    : ($config->enabled() ? new LogKafkaConsumer($config, $serializer) : new NullKafkaConsumer),
            };
        });

        $this->app->singleton(EventBus::class, function ($app): EventBus {
            return config('kafka.use_outbox', true)
                ? new OutboxEventBus($app['events'], $app->make(KafkaTopic::class), $app->make(JsonEventSerializer::class))
                : new LaravelEventBus($app['events'], $app->make(DomainEventProducer::class));
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/kafka.php' => config_path('kafka.php')], 'nestlaravel-kafka-config');
            $this->commands([OutboxPublishCommand::class, KafkaConsumeCommand::class]);
        }
    }
}
