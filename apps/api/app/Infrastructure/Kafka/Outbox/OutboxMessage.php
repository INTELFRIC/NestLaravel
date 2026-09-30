<?php

namespace App\Infrastructure\Kafka\Outbox;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $event_id
 * @property string $event_type
 * @property string $aggregate_id
 * @property string $aggregate_type
 * @property array $payload
 * @property string $topic
 * @property string|null $correlation_id
 * @property string $status
 * @property int $attempts
 * @property Carbon|null $available_at
 * @property Carbon|null $published_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class OutboxMessage extends Model
{
    public const STATUS_PENDING = 'pending';

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
        'status',
        'attempts',
        'available_at',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempts' => 'integer',
            'available_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    public function markPublished(): void
    {
        $this->forceFill([
            'status' => self::STATUS_PUBLISHED,
            'published_at' => now(),
        ])->save();
    }

    public function markFailed(): void
    {
        $this->forceFill([
            'status' => self::STATUS_FAILED,
        ])->save();
    }

    public function scheduleRetry(int $delaySeconds): void
    {
        $this->forceFill([
            'status' => self::STATUS_PENDING,
            'available_at' => now()->addSeconds($delaySeconds),
        ])->save();
    }
}
