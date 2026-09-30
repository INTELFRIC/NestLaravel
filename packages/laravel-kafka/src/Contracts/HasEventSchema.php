<?php

namespace NestLaravel\Kafka\Contracts;

use NestLaravel\Kafka\Schema\EventSchema;

/** Implemented by event classes that declare their payload schema (all generated events do). */
interface HasEventSchema
{
    public static function eventSchema(): EventSchema;
}
