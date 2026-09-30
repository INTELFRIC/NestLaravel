<?php

namespace NestLaravel\Kafka\Inbox;

enum InboxResult: string
{
    /** The handler ran and its effects + the dedup record were committed atomically. */
    case Processed = 'processed';

    /** The event had already been processed (or is being processed by a concurrent worker): nothing ran. */
    case Duplicate = 'duplicate';
}
