<?php

namespace NestLaravel\Kafka\Console;

use Illuminate\Console\Command;
use NestLaravel\Kafka\Health\HealthChecker;
use NestLaravel\Kafka\KafkaConfig;
use NestLaravel\Kafka\KafkaTopic;

/** Broker reachability + configured topics. */
class KafkaHealthCommand extends Command
{
    protected $signature = 'kafka:health {--json}';

    protected $description = 'Check Kafka connectivity and print the effective client settings (no secrets)';

    public function handle(HealthChecker $health, KafkaConfig $config, KafkaTopic $topics): int
    {
        $check = $health->check('kafka');
        $report = [
            'enabled' => $config->enabled(),
            'reachable' => $check['status'] === 'ok',
            'detail' => $check,
            'security_protocol' => (string) config('kafka.security.protocol'),
            'client_id' => $config->clientId(),
            'group_id' => $config->groupId(),
            'default_topic' => $topics->resolve('default'),
            'topics' => $config->topics(),
            'rdkafka_extension' => extension_loaded('rdkafka'),
            'inbox_enabled' => $config->inboxEnabled(),
        ];

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_UNESCAPED_SLASHES));
        } else {
            foreach ($report as $k => $v) {
                $this->line(str_pad($k, 20).' '.(is_scalar($v) || $v === null ? var_export($v, true) : json_encode($v)));
            }
        }

        return ! $report['enabled'] || $report['reachable'] ? self::SUCCESS : self::FAILURE;
    }
}
