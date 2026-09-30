<?php

namespace App\Messaging\Consumers;

/**
 * @param  array<string, mixed>  $event
 */
interface MessageHandler
{
    /**
     * Handle a deserialized domain event payload.
     *
     * @param  array<string, mixed>  $event
     */
    public function handle(array $event): void;
}
