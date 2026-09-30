<?php

namespace NestLaravel\Kafka\Saga;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Persisted saga state.
 *
 *   running ─▶ waiting ─▶ running … ─▶ completed
 *      │          │
 *      └──────────┴─(failure | timeout | step exhausted)─▶ compensating ─▶ compensated
 *                                                             └─(a compensation kept failing)─▶ failed   (needs a human)
 *
 * @property string $id
 * @property string $name
 * @property string $correlation_id
 * @property string $status
 * @property int $step_index
 * @property int $attempts
 * @property string|null $waiting_for
 * @property list<string>|null $failure_events
 * @property \Illuminate\Support\Carbon|null $deadline_at
 * @property array<string, mixed> $context
 * @property list<array<string, mixed>> $history
 * @property string|null $last_error
 * @property string|null $tenant_id
 */
class SagaInstance extends Model
{
    use HasUuids;

    public const RUNNING = 'running';

    public const WAITING = 'waiting';

    public const COMPENSATING = 'compensating';

    public const COMPLETED = 'completed';

    public const COMPENSATED = 'compensated';

    public const FAILED = 'failed';

    protected $table = 'saga_instances';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'history' => 'array',
            'failure_events' => 'array',
            'deadline_at' => 'datetime',
            'locked_at' => 'datetime',
        ];
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::COMPLETED, self::COMPENSATED, self::FAILED], true);
    }

    /** @param array<string, mixed> $entry */
    public function record(array $entry): void
    {
        $history = $this->history ?? [];
        $history[] = ['at' => now()->toIso8601String()] + $entry;
        $this->history = $history;
    }
}
