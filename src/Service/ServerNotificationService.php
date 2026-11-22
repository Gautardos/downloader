<?php

namespace App\Service;

use App\Service\Pwa\PushNotifierInterface;

class ServerNotificationService
{
    public function __construct(private ?PushNotifierInterface $pushNotifier = null)
    {
    }

    public function all(JsonStorage $storage): array
    {
        $notifications = $storage->get('server_notifications', []);
        if (!is_array($notifications)) {
            return [];
        }

        $result = [];
        foreach ($notifications as $notification) {
            if (!is_array($notification)) {
                continue;
            }
            if (empty($notification['id']) || !is_string($notification['id'])) {
                $notification['id'] = $this->legacyId($notification);
            }
            $result[] = $notification;
        }

        return array_values($result);
    }

    public function push(JsonStorage $storage, array $notification): void
    {
        $notifications = $storage->get('server_notifications', []);
        if (!is_array($notifications)) {
            $notifications = [];
        }

        if (empty($notification['id']) || !is_string($notification['id'])) {
            $notification['id'] = bin2hex(random_bytes(8));
        }
        if (empty($notification['timestamp'])) {
            $notification['timestamp'] = time();
        }

        $notifications[] = $notification;
        $storage->set('server_notifications', array_values($notifications));

        if ($this->pushNotifier !== null) {
            try {
                $event = $this->pushEvent($notification);
                $body = (string) ($notification['message'] ?? '');
                if ($body === '') {
                    $body = (string) ($notification['item'] ?? ($notification['action'] ?? 'New activity'));
                }
                $this->pushNotifier->notify($event, 'Downloader', $body, $this->pushUrl($event));
            } catch (\Throwable $e) {
                // Push delivery must never block notification persistence.
            }
        }
    }

    private function pushEvent(array $notification): string
    {
        $explicit = $notification['pwa_event'] ?? null;
        if (is_string($explicit) && in_array($explicit, ['downloads', 'errors', 'automation', 'integrity', 'daily_summary'], true)) {
            return $explicit;
        }

        $type = strtolower((string) ($notification['type'] ?? ''));
        $action = strtolower((string) ($notification['action'] ?? ''));
        $message = strtolower((string) ($notification['message'] ?? ''));
        $mediaType = strtolower((string) ($notification['media_type'] ?? ''));
        $haystack = $action . ' ' . $message . ' ' . $mediaType;

        if (str_contains($haystack, 'integrity')) {
            return 'integrity';
        }

        if (str_contains($haystack, 'automation') || in_array($mediaType, ['movie', 'series', 'anime'], true)) {
            return 'automation';
        }

        if ($type === 'error') {
            return 'errors';
        }

        if (str_contains($haystack, 'daily') && str_contains($haystack, 'summary')) {
            return 'daily_summary';
        }

        return 'downloads';
    }

    private function pushUrl(string $event): string
    {
        return match ($event) {
            'errors' => '/history',
            'automation' => '/automation',
            'integrity' => '/video/integrity',
            'daily_summary' => '/statistics',
            default => '/queue',
        };
    }

    public function acknowledge(JsonStorage $storage, array $ids): int
    {
        $ids = array_values(array_unique(array_filter(array_map(
            static fn ($id) => is_string($id) ? trim($id) : '',
            $ids
        ))));
        if ($ids === []) {
            return 0;
        }

        $notifications = $storage->get('server_notifications', []);
        if (!is_array($notifications)) {
            return 0;
        }

        $kept = [];
        $removed = 0;
        foreach ($notifications as $notification) {
            if (!is_array($notification)) {
                continue;
            }
            $id = !empty($notification['id']) && is_string($notification['id'])
                ? $notification['id']
                : $this->legacyId($notification);
            if (in_array($id, $ids, true)) {
                $removed++;
                continue;
            }
            $kept[] = $notification;
        }

        if ($removed > 0) {
            $storage->set('server_notifications', array_values($kept));
        }

        return $removed;
    }

    private function legacyId(array $notification): string
    {
        return 'legacy-' . substr(hash('sha256', json_encode([
            (string) ($notification['type'] ?? ''),
            (string) ($notification['message'] ?? ''),
            (string) ($notification['action'] ?? ''),
            (string) ($notification['item'] ?? ''),
            (string) ($notification['media_type'] ?? ''),
            (string) ($notification['time'] ?? ''),
            (string) ($notification['timestamp'] ?? ''),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), 0, 24);
    }
}
