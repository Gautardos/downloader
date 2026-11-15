<?php

namespace App\Service\Automation;

use App\Service\JsonStorage;
use App\Service\ServerNotificationService;

class AutomationNotifier
{
    private JsonStorage $storage;
    private ServerNotificationService $notifications;

    public function __construct(JsonStorage $storage, ?ServerNotificationService $notifications = null)
    {
        $this->storage = $storage;
        $this->notifications = $notifications ?? new ServerNotificationService();
    }

    public function notify(string $type, string $message, string $action = '', string $item = '', string $mediaType = ''): void
    {
        $this->notifications->push($this->storage, [
            'type' => $type,
            'message' => $message,
            'action' => $action,
            'item' => $item,
            'media_type' => $mediaType,
            'time' => date('H:i:s'),
        ]);
    }

    public function notifyIntentRun(array $stats, ?string $error = null): void
    {
        $queued = (int) ($stats['intents_queued'] ?? 0);
        $noSource = (int) ($stats['intents_no_source'] ?? 0);
        $failed = (int) ($stats['intents_error'] ?? 0);

        if ($queued === 0 && $noSource === 0 && $failed === 0) {
            return;
        }

        $parts = [];
        if ($queued > 0) {
            $parts[] = $queued . ' queued';
        }
        if ($noSource > 0) {
            $parts[] = $noSource . ' without a valid source';
        }
        if ($failed > 0) {
            $parts[] = $failed . ' failed';
        }

        $type = $failed > 0 ? 'error' : ($noSource > 0 ? 'warning' : 'success');
        $this->notify($type, 'Automation: ' . implode(', ', $parts) . '.', 'Automation', $error ?? '', 'movie');
    }

    public function notifyProblem(string $message, string $item = '', string $type = 'error', string $mediaType = 'movie'): void
    {
        $this->notify($type, $message, 'Automation', $item, $mediaType);
    }
}
