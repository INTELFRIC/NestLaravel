<?php

namespace NestLaravel\Kafka;

use Illuminate\Support\ServiceProvider;
use NestLaravel\Kafka\Consumers\ConsumerPipeline;
use NestLaravel\Kafka\Console\KafkaConsumeCommand;
use NestLaravel\Kafka\Console\OutboxPublishCommand;
use NestLaravel\Kafka\Console\SagaRecoverCommand;
use NestLaravel\Kafka\Saga\SagaOrchestrator;
use NestLaravel\Kafka\Contracts\EventBus;
use NestLaravel\Kafka\Contracts\IdempotencyStore;
use NestLaravel\Kafka\Inbox\EventInbox;
use NestLaravel\Kafka\Http\Middleware\ObserveRequest;
use NestLaravel\Kafka\Observability\ContextProcessor;
use NestLaravel\Kafka\Observability\JsonLogFormatter;
use NestLaravel\Kafka\Observability\Metrics;
use NestLaravel\Kafka\Outbox\OutboxPublisher;
use NestLaravel\Kafka\Producers\DomainEventProducer;
use NestLaravel\Kafka\Schema\EventSchemaRegistry;
use NestLaravel\Kafka\Serializers\JsonEventSerializer;

/**
 * NestLaravel reliability kit for Laravel microservices.
 *
 * Standard event envelope + schema registry, transactional outbox (concurrency-safe publishers), transactional
 * inbox (idempotent consumption), hardened producer/consumer (acks=all, idempotence, TLS/SASL, manual commits),
 * retry + dead-letter pipeline, metrics, structured logs, W3C trace propagation, health endpoints, sagas,
 * circuit breaker / retry policy for inter-service HTTP.
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

        $this->app->singleton(EventSchemaRegistry::class, function ($app): EventSchemaRegistry {
            $config = $app->make(KafkaConfig::class);
            $registry = new EventSchemaRegistry($config->enforceProducerSchema(), $config->enforceConsumerSchema());

            foreach ($config->eventClasses() as $class) {
                $registry->registerClass($class);
            }

            return $registry;
        });

        $this->app->singleton(SagaOrchestrator::class, static fn (): SagaOrchestrator => new SagaOrchestrator((int) config('kafka.saga.compensation_retries', 3)));

        $this->app->singleton(EventInbox::class, static function ($app): EventInbox {
            $config = $app->make(KafkaConfig::class);

            return new EventInbox($config->inboxTable(), $config->groupId(), $config->inboxDeadlockAttempts());
        });

        $this->app->singleton(ConsumerPipeline::class, static fn ($app): ConsumerPipeline => new ConsumerPipeline(
            $app->make(JsonEventSerializer::class),
            $app->make(IdempotencyStore::class),
            $app->make(KafkaConfig::class),
            $app->make(KafkaProducer::class),
            $app->make(KafkaTopic::class),
            $app->make(EventInbox::class),
            $app->make(EventSchemaRegistry::class),
        ));

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
                ? new OutboxEventBus($app['events'], $app->make(KafkaTopic::class), $app->make(JsonEventSerializer::class), true, $app->make(EventSchemaRegistry::class))
                : new LaravelEventBus($app['events'], $app->make(DomainEventProducer::class));
        });

        // Structured JSON log channel: LOG_CHANNEL=nestlaravel (unless the app already defines one).
        if (! $this->app['config']->has('logging.channels.nestlaravel')) {
            $this->app['config']->set('logging.channels.nestlaravel', [
                'driver' => 'monolog',
                'level' => env('LOG_LEVEL', 'info'),
                'handler' => \Monolog\Handler\StreamHandler::class,
                'with' => ['stream' => 'php://stderr'],
                'formatter' => JsonLogFormatter::class,
                'processors' => [ContextProcessor::class],
            ]);
        }
    }

    public function boot(): void
    {
        // Apps that keep their own copy of these tables/commands (the API gateway) set `kafka.register.*` to false.
        if (config('kafka.register.migrations', true)) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }

        // Ops endpoints (/liveness /startup /readiness /health /metrics). Apps with their own health routes set
        // `kafka.health.routes` = false (the API gateway does).
        if (! $this->app->routesAreCached()) {
            \Illuminate\Support\Facades\Route::group([], __DIR__.'/../routes/ops.php');
        }

        // Request id / correlation id / trace context / RED metrics on every HTTP request (global middleware, so it
        // also covers routes registered later and does not depend on middleware-group initialisation order).
        if (config('kafka.observability.http', true) && $this->app->bound(\Illuminate\Contracts\Http\Kernel::class)) {
            $kernel = $this->app->make(\Illuminate\Contracts\Http\Kernel::class);

            if (method_exists($kernel, 'prependMiddleware')) {
                $kernel->prependMiddleware(ObserveRequest::class);
            }
        }

        $this->registerRuntimeMetrics();
        $this->registerDatabaseGuards();

        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/kafka.php' => config_path('kafka.php')], 'nestlaravel-kafka-config');

            if (config('kafka.register.commands', true)) {
                $this->commands([
                    OutboxPublishCommand::class,
                    KafkaConsumeCommand::class,
                    SagaRecoverCommand::class,
                    Console\ProductionCheckCommand::class,
                    Console\OutboxStatusCommand::class,
                    Console\KafkaHealthCommand::class,
                    Console\EventsListCommand::class,
                    Console\EventsCheckCommand::class,
                    Console\InboxPruneCommand::class,
                    Console\DlqListCommand::class,
                ]);
            }
        }
    }

    /**
     * A runaway query must not hold a connection (and a request) forever: apply a per-session statement timeout as
     * soon as a connection is opened. 0 = leave the database default.
     */
    private function registerDatabaseGuards(): void
    {
        $ms = (int) config('kafka.database.statement_timeout_ms', 0);

        if ($ms <= 0 || ! class_exists(\Illuminate\Database\Events\ConnectionEstablished::class)) {
            return;
        }

        $this->app['events']->listen(\Illuminate\Database\Events\ConnectionEstablished::class, static function ($event) use ($ms): void {
            match ($event->connection->getDriverName()) {
                'pgsql' => $event->connection->statement('SET statement_timeout = '.$ms),
                'mysql', 'mariadb' => $event->connection->statement('SET SESSION max_execution_time = '.$ms),
                default => null,
            };
        });
    }

    /** Database query timing and queue job outcomes → metrics (cheap; disable with METRICS_ENABLED=false). */
    private function registerRuntimeMetrics(): void
    {
        if (! config('kafka.metrics.enabled', true)) {
            return;
        }

        if (config('kafka.metrics.db_queries', true)) {
            $this->app['db']->listen(static function ($query): void {
                Metrics::observe('nestlaravel_db_query_duration_seconds', $query->time / 1000, ['connection' => $query->connectionName], 'Database query duration');
            });
        }

        $events = $this->app['events'];
        $events->listen(\Illuminate\Queue\Events\JobProcessed::class, static fn ($e) => Metrics::inc('nestlaravel_jobs_total', ['queue' => (string) $e->job->getQueue(), 'result' => 'processed'], help: 'Queue jobs by result'));
        $events->listen(\Illuminate\Queue\Events\JobFailed::class, static fn ($e) => Metrics::inc('nestlaravel_jobs_total', ['queue' => (string) $e->job->getQueue(), 'result' => 'failed'], help: 'Queue jobs by result'));
    }
}
