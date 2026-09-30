<?php

namespace NestLaravel\Kafka\Console;

use NestLaravel\Kafka\KafkaConsumer;
use NestLaravel\Kafka\Consumers\ConsumerPipeline;
use NestLaravel\Kafka\Consumers\MessageHandler;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

class KafkaConsumeCommand extends Command
{
    private bool $shouldStop = false;

    protected $signature = 'kafka:consume
                            {topic : Kafka topic name}
                            {handler : Fully-qualified MessageHandler class}
                            {--timeout=1000 : Poll timeout in milliseconds}
                            {--max=0 : Stop after N messages (0 = run until interrupted)}';

    protected $description = 'Consume Kafka messages for a topic through the consumer pipeline';

    public function handle(KafkaConsumer $consumer, ConsumerPipeline $pipeline): int
    {
        $topic = (string) $this->argument('topic');
        $handlerClass = (string) $this->argument('handler');
        $timeout = (int) $this->option('timeout');
        $max = (int) $this->option('max');

        $handler = $this->resolveHandler($handlerClass);

        $consumer->subscribe([$topic]);
        $this->info("Consuming topic [{$topic}] with [{$handlerClass}]...");

        $processed = 0;

        // Graceful shutdown: finish the in-flight message, commit, leave the group.
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            foreach ([SIGTERM, SIGINT] as $signal) {
                pcntl_signal($signal, function (): void {
                    $this->shouldStop = true;
                });
            }
        }

        try {
            while (! $this->shouldStop) {
                if ($max > 0 && $processed >= $max) {
                    break;
                }

                $message = $consumer->consume($timeout);

                if ($message === null) {
                    if ($max > 0) {
                        // In finite mode, exit when the queue is drained.
                        break;
                    }

                    continue;
                }

                $ok = $pipeline->process($message, $handler);

                if ($ok) {
                    $consumer->acknowledge($message);
                    $processed++;
                    $this->line("Processed key={$message->key} offset={$message->offset}");
                } else {
                    // Dead-lettered successfully (a failed DLQ publish throws instead and is NOT acked).
                    $this->error("Failed (sent to DLQ) key={$message->key}");
                    $consumer->acknowledge($message);
                    $processed++;
                }
            }
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            $consumer->close();
        }

        $this->info("Done. Processed {$processed} message(s).");

        return self::SUCCESS;
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
