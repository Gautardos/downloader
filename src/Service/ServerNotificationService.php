<?php

namespace App\Service;

class ServerNotificationService
{
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
