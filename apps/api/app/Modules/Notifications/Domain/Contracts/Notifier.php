<?php

namespace App\Modules\Notifications\Domain\Contracts;

interface Notifier
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function send(string $recipient, string $channelOrTemplate, array $context = []): void;
}
