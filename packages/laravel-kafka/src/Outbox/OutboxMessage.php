<?php

namespace NestLaravel\Kafka\Outbox;

use Illuminate\Database\Eloquent\Model;

/**
 * State machine:
 *
 *   pending ──claim──▶ processing ──flush ok──▶ published
 *      ▲                   │
 *      │   backoff         ├── failure, attempts < max ──▶ pending (available_at = now + backoff)
 *      └───────────────────┤
 *                          ├── failure, attempts ≥ max ──▶ failed (+ copy on <topic>.dlq)
 *                          └── publisher died (locked_at too old) ──▶ pending (stale recovery)
 *
 * @property int $id
 * @property string $event_id
 * @property string $event_type
 * @property string $aggregate_id
 * @property string $aggregate_type
 * @property array $payload
 * @property string $topic
 * @property string|null $correlation_id
 * @property string|null $tenant_id
 * @property string $status
 * @property int $attempts
 * @property \Illuminate\Support\Carbon|null $available_at
 * @property \Illuminate\Support\Carbon|null $locked_at
 * @property string|null $locked_by
 * @property string|null $last_error
 * @property \Illuminate\Support\Carbon|null $published_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class OutboxMessage extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_FAILED = 'failed';

    protected $table = 'outbox_messages';

    protected $fillable = [
        'event_id',
        'event_type',
        'aggregate_id',
        'aggregate_type',
        'payload',
        'topic',
        'correlation_id',
        'tenant_id',
        'status',
        'attempts',
        'available_at',
        'locked_at',
        'locked_by',
        'last_error',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempts' => 'integer',
            'available_at' => 'datetime',
            'locked_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    /** @deprecated 1.0 API kept for compatibility; the publisher now uses lock-checked updates. */
    public function markPublished(): void
    {
        $this->forceFill([
            'status' => self::STATUS_PUBLISHED,
            'published_at' => now(),
            'locked_at' => null,
            'locked_by' => null,
            'last_error' => null,
        ])->save();
    }

    /** @deprecated 1.0 API kept for compatibility. */
    public function markFailed(): void
    {
        $this->forceFill(['status' => self::STATUS_FAILED, 'locked_at' => null, 'locked_by' => null])->save();
    }

    /** @deprecated 1.0 API kept for compatibility. */
    public function scheduleRetry(int $delaySeconds): void
    {
        $this->forceFill([
            'status' => self::STATUS_PENDING,
            'available_at' => now()->addSeconds($delaySeconds),
            'locked_at' => null,
            'locked_by' => null,
        ])->save();
    }
}
