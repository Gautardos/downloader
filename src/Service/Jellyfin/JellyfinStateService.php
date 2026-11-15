<?php

namespace App\Service\Jellyfin;

use App\Service\JsonStorage;

class JellyfinStateService
{
    private const STATE_KEY = 'jellyfin_playback_state';

    private JsonStorage $storage;

    public function __construct(JsonStorage $storage)
    {
        $this->storage = $storage;
    }

    public function get(string $userId, string $itemId): array
    {
        $state = $this->all();

        return $state[$this->key($userId, $itemId)] ?? [
            'user_id' => $userId,
            'item_id' => $itemId,
            'position_ticks' => 0,
            'duration_ticks' => 0,
            'played' => false,
            'last_played' => null,
            'updated_at' => null,
        ];
    }

    public function setProgress(string $userId, string $itemId, int $positionTicks, ?int $durationTicks = null): void
    {
        $positionTicks = max(0, $positionTicks);
        $durationTicks = max(0, (int) $durationTicks);
        $current = $this->get($userId, $itemId);

        if ($durationTicks <= 0) {
            $durationTicks = (int) ($current['duration_ticks'] ?? 0);
        }

        $played = (bool) ($current['played'] ?? false);
        if ($durationTicks > 0 && $positionTicks >= (int) floor($durationTicks * 0.95)) {
            $played = true;
            $positionTicks = 0;
        } elseif ($positionTicks > 0) {
            $played = false;
        }

        $this->write($userId, $itemId, [
            'position_ticks' => $positionTicks,
            'duration_ticks' => $durationTicks,
            'played' => $played,
            'last_played' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
    }

    public function setPlayed(string $userId, string $itemId, bool $played): void
    {
        $current = $this->get($userId, $itemId);
        $this->write($userId, $itemId, [
            'position_ticks' => 0,
            'duration_ticks' => (int) ($current['duration_ticks'] ?? 0),
            'played' => $played,
            'last_played' => $played ? gmdate('Y-m-d\TH:i:s\Z') : ($current['last_played'] ?? null),
        ]);
    }

    public function resume(string $userId, int $limit = 200): array
    {
        $state = $this->all();
        $items = [];
        foreach ($state as $entry) {
            if (!is_array($entry) || (string) ($entry['user_id'] ?? '') !== $userId) {
                continue;
            }
            if (!empty($entry['played']) || (int) ($entry['position_ticks'] ?? 0) <= 0) {
                continue;
            }
            $items[] = $entry;
        }

        usort($items, static fn ($a, $b) => (string) ($b['last_played'] ?? '') <=> (string) ($a['last_played'] ?? ''));

        return array_slice($items, 0, max(1, $limit));
    }

    private function write(string $userId, string $itemId, array $values): void
    {
        $state = $this->all();
        $key = $this->key($userId, $itemId);
        $current = $state[$key] ?? [];
        $state[$key] = array_merge([
            'user_id' => $userId,
            'item_id' => $itemId,
            'position_ticks' => 0,
            'duration_ticks' => 0,
            'played' => false,
            'last_played' => null,
            'updated_at' => null,
        ], $current, $values, [
            'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
        $this->storage->set(self::STATE_KEY, $state);
    }

    private function all(): array
    {
        $state = $this->storage->get(self::STATE_KEY, []);

        return is_array($state) ? $state : [];
    }

    private function key(string $userId, string $itemId): string
    {
        return $userId . '|' . $itemId;
    }
}
