<?php

namespace App\Service\Automation;

class AutomationSettings
{
    private const SETTINGS_KEY = 'automation_settings';
    private const REPLACE_KEYS = ['roots', 'sources'];

    private AutomationStore $store;

    public function __construct(AutomationStore $store)
    {
        $this->store = $store;
    }

    public function defaults(): array
    {
        return [
            'roots' => [
                'movies' => [
                    'label' => 'Movies',
                    'path' => '/nas/Video/Movies',
                    'type' => 'movie',
                    'enabled' => true,
                ],
                'series' => [
                    'label' => 'TV Shows',
                    'path' => '/nas/Video/TV Shows',
                    'type' => 'series',
                    'enabled' => true,
                ],
                'anime' => [
                    'label' => 'Anime',
                    'path' => '/nas/Video/Anime',
                    'type' => 'anime',
                    'enabled' => false,
                ],
            ],
            'scanner' => [
                'extensions' => ['mkv', 'mp4', 'avi', 'm4v', 'webm', 'ts', 'm2ts', 'mpg', 'mpeg'],
                'skip_samples' => true,
                'read_nfo' => true,
                'follow_symlinks' => false,
                'min_size_mb' => 20,
                'scan_interval' => 21600,
                'full_folder_tree' => false,
            ],
            'quality_profile' => [
                'resolution_order' => ['2160p', '1080p', '720p'],
                'max_resolution' => '2160p',
                'min_resolution' => '720p',
                'codecs' => ['x265', 'h264', 'av1'],
                'hdr' => 'prefer',
                'audio_langs' => ['VF', 'VOSTFR', 'MULTI'],
                'min_seeders' => 5,
                'max_movie_size_gb' => 25,
                'max_episode_size_gb' => 5,
                'exclude' => ['CAM', 'TS', '3D', 'SAMPLE'],
                'addon_priority' => true,
            ],
            'backfill' => [
                'enabled' => true,
                'exclude_specials' => true,
                'monitor_future' => true,
                'max_intents_per_run' => 50,
                'queue_guard' => 20,
                'grace_hours' => 6,
            ],
            'intents' => [
                'retry_backoff_minutes' => 60,
                'max_attempts' => 5,
                'stale_resolving_minutes' => 30,
                'min_interval_seconds' => 60,
            ],
            'schedule' => [
                'scrob' => 3600,
                'mdblist' => 3600,
                'library' => 21600,
            ],
            'metadata' => [
                'max_lookups_per_run' => 50,
                'cache_positive_days' => 30,
                'cache_negative_days' => 2,
                'title_language' => 'en-US',
            ],
            'lists' => [
                'max_items_per_run' => 500,
                'max_show_lookups_per_run' => 150,
                'cache_positive_days' => 7,
                'cache_negative_days' => 1,
                'include_unaired' => false,
            ],
            'notifications' => [
                'mode' => 'in_app',
            ],
            'sources' => [],
        ];
    }

    public function all(): array
    {
        $stored = $this->store->getSetting(self::SETTINGS_KEY, []);

        return $this->merge($this->defaults(), is_array($stored) ? $stored : []);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $settings = $this->all();
        return $settings[$key] ?? $default;
    }

    public function save(array $settings): void
    {
        $this->store->setSetting(self::SETTINGS_KEY, $this->merge($this->all(), $settings));
    }

    public function getRoots(): array
    {
        $roots = $this->get('roots', []);
        return is_array($roots) ? $roots : [];
    }

    public function getEnabledRoots(): array
    {
        return array_filter($this->getRoots(), static fn ($root) => is_array($root) && !empty($root['enabled']) && !empty($root['path']));
    }

    public function getScanner(): array
    {
        $scanner = $this->get('scanner', []);
        return is_array($scanner) ? $scanner : [];
    }

    public function getSchedule(): array
    {
        $schedule = $this->get('schedule', []);
        return is_array($schedule) ? $schedule : [];
    }

    public function getBackfill(): array
    {
        $backfill = $this->get('backfill', []);
        return is_array($backfill) ? $backfill : [];
    }

    public function getListSettings(): array
    {
        $lists = $this->get('lists', []);
        return is_array($lists) ? $lists : [];
    }

    public function getQualityProfile(): array
    {
        $profile = $this->get('quality_profile', []);
        return is_array($profile) ? $profile : [];
    }

    public function getIntentSettings(): array
    {
        $intents = $this->get('intents', []);
        return is_array($intents) ? $intents : [];
    }

    public function getSources(): array
    {
        $sources = $this->get('sources', []);
        return is_array($sources) ? array_values(array_filter($sources, 'is_array')) : [];
    }

    public function updateRoot(string $key, array $data): void
    {
        $settings = $this->all();
        $roots = is_array($settings['roots'] ?? null) ? $settings['roots'] : [];
        $roots[$key] = array_merge($roots[$key] ?? [], $data);
        $settings['roots'] = $roots;
        $this->save($settings);
    }

    private function merge(array $base, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            if (in_array($key, self::REPLACE_KEYS, true)) {
                $base[$key] = $value;
                continue;
            }

            if (is_array($value) && isset($base[$key]) && is_array($base[$key]) && !array_is_list($value)) {
                $base[$key] = $this->merge($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }
}
