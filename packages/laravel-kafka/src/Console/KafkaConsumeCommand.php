<?php

namespace NestLaravel\Kafka\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use NestLaravel\Kafka\Consumers\ConsumerPipeline;
use NestLaravel\Kafka\Consumers\MessageHandler;
use NestLaravel\Kafka\KafkaConsumer;
use NestLaravel\Kafka\Observability\Metrics;
use NestLaravel\Kafka\Support\KafkaErrorClassifier;
use Throwable;

/**
 * Long-running consumer.
 *
 *   receive → pipeline (validate, dedup, handler, retry, DLQ) → ONLY THEN commit the offset
 *
 * Failure behaviour (all covered by tests):
 *   • transient broker/network errors  → exponential backoff, keep running, metric + log
 *   • fatal errors (auth/TLS/ACL)      → log critical, exit 1 (supervisor/Kubernetes restarts and alerts)
 *   • offset commit failure            → logged + counted; the message will be redelivered and deduplicated
 *   • DLQ unavailable                  → exit 1 WITHOUT committing (never skip a message that is neither handled nor parked)
 *   • SIGTERM/SIGINT                   → finish the in-flight message, commit, leave the group, close DB/Redis, exit 0
 *   • --max-runtime / --memory         → clean exit so the supervisor starts a fresh process (leak protection)
 *
 * Scale by running more processes with the same group id (≤ partitions). Each process handles one message at a time.
 */
class KafkaConsumeCommand extends Command
{
    protected $signature = 'kafka:consume
                            {topic : Kafka topic name}
                            {handler : Fully-qualified MessageHandler class}
                            {--timeout=1000 : Poll timeout in milliseconds}
                            {--max=0 : Stop after N messages (0 = run until interrupted)}
                            {--max-runtime=0 : Stop cleanly after N seconds (0 = unlimited)}
                            {--memory=0 : Stop cleanly when memory use exceeds N MB (0 = unlimited)}';

    protected $description = 'Consume Kafka messages for a topic through the reliability pipeline';

    private bool $shouldStop = false;

    public function handle(KafkaConsumer $consumer, ConsumerPipeline $pipeline): int
    {
        $topic = (string) $this->argument('topic');
        $handlerClass = (string) $this->argument('handler');
        $timeout = (int) $this->option('timeout');
        $max = (int) $this->option('max');
        $maxRuntime = (int) $this->option('max-runtime');
        $memoryLimit = (int) $this->option('memory') * 1024 * 1024;
        $started = time();

        $handler = $this->resolveHandler($handlerClass);
        $this->installSignalHandlers();

        $consumer->subscribe([$topic]);
        $this->info("Consuming topic [{$topic}] with [{$handlerClass}]...");

        $processed = 0;
        $consecutiveErrors = 0;
        $exit = self::SUCCESS;

        try {
            while (! $this->shouldStop) {
                if ($max > 0 && $processed >= $max) {
                    break;
                }
                if ($maxRuntime > 0 && time() - $started >= $maxRuntime) {
                    $this->info('Max runtime reached; exiting cleanly.');
                    break;
                }
                if ($memoryLimit > 0 && memory_get_usage(true) >= $memoryLimit) {
                    $this->warn('Memory limit reached; exiting cleanly so the supervisor can restart the process.');
                    break;
                }

                try {
                    $message = $consumer->consume($timeout);
                    $consecutiveErrors = 0;
                } catch (Throwable $e) {
                    $consecutiveErrors++;
                    $kind = KafkaErrorClassifier::classify($e);
                    Metrics::inc('nestlaravel_kafka_consumer_errors_total', ['kind' => $kind], help: 'Consumer client errors');

                    if ($kind === KafkaErrorClassifier::FATAL) {
                        Log::critical('Kafka consumer hit a fatal error; stopping', ['error' => $e->getMessage()]);
                        $this->error('Fatal Kafka error: '.$e->getMessage());
                        $exit = self::FAILURE;
                        break;
                    }

                    $maxTransient = (int) config('kafka.consumer_options.max_consecutive_errors', 0);
                    if ($maxTransient > 0 && $consecutiveErrors >= $maxTransient) {
                        Log::critical('Kafka consumer gave up after repeated errors', ['errors' => $consecutiveErrors, 'error' => $e->getMessage()]);
                        $exit = self::FAILURE;
                        break;
                    }

                    $delay = KafkaErrorClassifier::backoffMs($consecutiveErrors, (int) config('kafka.consumer_options.error_backoff_ms', 500));
                    Log::warning('Transient Kafka error; backing off', ['error' => $e->getMessage(), 'consecutive' => $consecutiveErrors, 'sleep_ms' => $delay]);
                    $this->sleepMs($delay);

                    continue;
                }

                if ($message === null) {
                    if ($max > 0) {
                        break; // finite mode: exit when drained
                    }

                    continue;
                }

                // Throws only when the message could not even be dead-lettered → we must not commit past it.
                $ok = $pipeline->process($message, $handler);

                try {
                    $consumer->acknowledge($message);
                } catch (Throwable $e) {
                    Metrics::inc('nestlaravel_kafka_commit_failures_total', ['topic' => $message->topic], help: 'Offset commit failures');
                    Log::error('Offset commit failed; message will be redelivered and deduplicated', [
                        'topic' => $message->topic, 'partition' => $message->partition, 'offset' => $message->offset, 'error' => $e->getMessage(),
                    ]);
                }

                $processed++;
                $this->line(($ok ? 'Processed' : 'Dead-lettered')." key={$message->key} offset={$message->offset}");
            }
        } catch (Throwable $e) {
            Log::critical('Kafka consumer stopped without committing the current message', ['error' => $e->getMessage()]);
            $this->error($e->getMessage());
            $exit = self::FAILURE;
        } finally {
            $this->shutdown($consumer);
        }

        $this->info("Done. Processed {$processed} message(s).");

        return $exit;
    }

    private function installSignalHandlers(): void
    {
        if (! function_exists('pcntl_async_signals')) {
            return;
        }

        pcntl_async_signals(true);
        foreach ([SIGTERM, SIGINT] as $signal) {
            pcntl_signal($signal, function (): void {
                $this->shouldStop = true;
            });
        }
    }

    /** Leave the consumer group (commits, triggers a fast rebalance) and release DB/Redis connections. */
    private function shutdown(KafkaConsumer $consumer): void
    {
        try {
            $consumer->close();
        } catch (Throwable $e) {
            Log::warning('Error while closing the Kafka consumer', ['error' => $e->getMessage()]);
        }

        if (! config('kafka.consumer_options.close_connections_on_exit', true)) {
            return;
        }

        try {
            DB::disconnect();
        } catch (Throwable) {
        }

        try {
            if (class_exists(\Illuminate\Support\Facades\Redis::class) && config('database.redis.default')) {
                \Illuminate\Support\Facades\Redis::connection()->disconnect();
            }
        } catch (Throwable) {
            // Redis may simply not be configured/reachable in this service.
        }
    }

    private function sleepMs(int $ms): void
    {
        $until = microtime(true) + $ms / 1000;
        while (! $this->shouldStop && microtime(true) < $until) {
            usleep(min(100_000, max(1, (int) (($until - microtime(true)) * 1_000_000))));
        }
    }

    private function resolveHandler(string $handlerClass): MessageHandler
    {
        if (! class_exists($handlerClass)) {
            throw new InvalidArgumentException("Handler class [{$handlerClass}] does not exist.");
        }

        $handler = $this->laravel->make($handlerClass);

        if (! $handler instanceof MessageHandler) {
            throw new InvalidArgumentException("Handler [{$handlerClass}] must implement ".MessageHandler::class);
        }

        return $handler;
    }
}
