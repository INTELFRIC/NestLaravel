<?php

namespace App\Providers;

use App\Console\Commands\KafkaConsumeCommand;
use App\Console\Commands\OutboxPublishCommand;
use App\Core\Contracts\CacheStore;
use App\Core\Contracts\DistributedLock;
use App\Core\Contracts\EventBus;
use App\Core\Contracts\IdempotencyStore;
use App\Infrastructure\Kafka\KafkaConfig;
use App\Infrastructure\Kafka\KafkaConsumer;
use App\Infrastructure\Kafka\KafkaProducer;
use App\Infrastructure\Kafka\KafkaSerializer;
use App\Infrastructure\Kafka\KafkaTopic;
use App\Infrastructure\Kafka\LaravelEventBus;
use App\Infrastructure\Kafka\LogKafkaConsumer;
use App\Infrastructure\Kafka\LogKafkaProducer;
use App\Infrastructure\Kafka\NullKafkaConsumer;
use App\Infrastructure\Kafka\NullKafkaProducer;
use App\Infrastructure\Kafka\Outbox\OutboxPublisher;
use App\Infrastructure\Kafka\OutboxEventBus;
use App\Infrastructure\Kafka\RdKafkaKafkaConsumer;
use App\Infrastructure\Kafka\RdKafkaKafkaProducer;
use App\Infrastructure\Redis\LaravelCacheStore;
use App\Infrastructure\Redis\RedisDistributedLock;
use App\Infrastructure\Redis\RedisIdempotencyStore;
use App\Messaging\Consumers\ConsumerPipeline;
use App\Messaging\Producers\DomainEventProducer;
use App\Messaging\Serializers\JsonEventSerializer;
use Illuminate\Support\ServiceProvider;
use Throwable;

class InfrastructureServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(KafkaConfig::class, static fn (): KafkaConfig => KafkaConfig::fromConfig());

        $this->app->singleton(KafkaSerializer::class);
        $this->app->singleton(JsonEventSerializer::class);
        $this->app->singleton(KafkaTopic::class);

        $this->registerRedisBindings();
        $this->registerKafkaBindings();
        $this->registerEventBusBindings();

        $this->app->singleton(DomainEventProducer::class);
        $this->app->singleton(OutboxPublisher::class);
        $this->app->singleton(ConsumerPipeline::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                OutboxPublishCommand::class,
                KafkaConsumeCommand::class,
            ]);
        }
    }

    private function registerRedisBindings(): void
    {
        $this->app->singleton(CacheStore::class, function ($app): CacheStore {
            $store = (string) config('kafka.redis.cache_store', config('cache.default', 'redis'));

            return new LaravelCacheStore($store);
        });

        $this->app->singleton(IdempotencyStore::class, function ($app): IdempotencyStore {
            $prefix = (string) config('kafka.redis.idempotency_prefix', 'idempotency:');

            return new RedisIdempotencyStore($prefix);
        });

        $this->app->singleton(DistributedLock::class, function ($app): DistributedLock {
            $prefix = (string) config('kafka.redis.lock_prefix', 'lock:');

            return new RedisDistributedLock($prefix);
        });
    }

    private function registerKafkaBindings(): void
    {
        $this->app->singleton(KafkaProducer::class, function ($app): KafkaProducer {
            /** @var KafkaConfig $config */
            $config = $app->make(KafkaConfig::class);
            $driver = $config->producerDriver();

            if ($driver === 'auto') {
                if ($config->enabled() && extension_loaded('rdkafka')) {
                    return new RdKafkaKafkaProducer($config);
                }

                return $config->enabled()
                    ? new LogKafkaProducer($config)
                    : new NullKafkaProducer;
            }

            return match ($driver) {
                'rdkafka' => new RdKafkaKafkaProducer($config),
                'log' => new LogKafkaProducer($config),
                'null' => new NullKafkaProducer,
                default => $config->enabled()
                    ? new LogKafkaProducer($config)
                    : new NullKafkaProducer,
            };
        });

        $this->app->singleton(KafkaConsumer::class, function ($app): KafkaConsumer {
            /** @var KafkaConfig $config */
            $config = $app->make(KafkaConfig::class);
            $driver = $config->consumerDriver();

            if ($driver === 'auto') {
                if ($config->enabled() && extension_loaded('rdkafka')) {
                    return new RdKafkaKafkaConsumer($config, $app->make(KafkaSerializer::class));
                }

                // No hard dependency on ext-rdkafka; use log/file consumer for local/dev.
                return $config->enabled()
                    ? new LogKafkaConsumer($config, $app->make(KafkaSerializer::class))
                    : new NullKafkaConsumer;
            }

            return match ($driver) {
                'rdkafka' => new RdKafkaKafkaConsumer($config, $app->make(KafkaSerializer::class)),
                'log' => new LogKafkaConsumer($config, $app->make(KafkaSerializer::class)),
                'null' => new NullKafkaConsumer,
                default => $config->enabled()
                    ? new LogKafkaConsumer($config, $app->make(KafkaSerializer::class))
                    : new NullKafkaConsumer,
            };
        });
    }

    private function registerEventBusBindings(): void
    {
        $this->app->singleton(LaravelEventBus::class, function ($app): LaravelEventBus {
            return new LaravelEventBus(
                dispatcher: $app['events'],
                producer: $app->make(DomainEventProducer::class),
                publishToKafka: true,
            );
        });

        $this->app->singleton(OutboxEventBus::class, function ($app): OutboxEventBus {
            return new OutboxEventBus(
                dispatcher: $app['events'],
                topics: $app->make(KafkaTopic::class),
                serializer: $app->make(JsonEventSerializer::class),
                dispatchLaravelEvents: true,
            );
        });

        $this->app->singleton(EventBus::class, function ($app): EventBus {
            try {
                $useOutbox = (bool) config('kafka.use_outbox', true);
            } catch (Throwable) {
                $useOutbox = true;
            }

            return $useOutbox
                ? $app->make(OutboxEventBus::class)
                : $app->make(LaravelEventBus::class);
        });
    }
}
