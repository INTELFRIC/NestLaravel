<?php

namespace App\Modules\Notifications\Infrastructure\Services;

use App\Modules\Notifications\Domain\Contracts\Notifier;
use Illuminate\Support\Facades\Log;

final class LogNotifier implements Notifier
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function send(string $recipient, string $channelOrTemplate, array $context = []): void
    {
        Log::info('notification.sent', [
            'recipient' => $recipient,
            'template' => $channelOrTemplate,
            'context' => $context,
        ]);
    }
}
