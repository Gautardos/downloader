<?php

namespace App\Service\Automation;

use App\Service\JsonStorage;

class AutomationNotifier
{
    private JsonStorage $storage;

    public function __construct(JsonStorage $storage)
    {
        $this->storage = $storage;
    }

    public function notify(string $type, string $message, string $action = '', string $item = '', string $mediaType = ''): void
    {
        $notifications = $this->storage->get('server_notifications', []);
        if (!is_array($notifications)) {
            $notifications = [];
        }
        $notifications[] = [
            'type' => $type,
            'message' => $message,
            'action' => $action,
            'item' => $item,
            'media_type' => $mediaType,
            'time' => date('H:i:s'),
        ];
        $this->storage->set('server_notifications', array_values($notifications));
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
