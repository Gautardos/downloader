<?php

namespace App\Service;

class NavigationService
{
    private const MUSIC_LIBRARY_ROUTES = [
        'music_files',
        'music_stream',
        'music_update_genres',
        'fetch_lyrics',
        'move_to_library',
        'file_tags',
        'update_tags',
    ];

    private const ICONS = [
        'download' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v12"/><path d="m7 10 5 5 5-5"/><path d="M5 21h14"/></svg>',
        'bolt' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m13 2-8 12h6l-1 8 8-12h-6z"/></svg>',
        'library' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 19V5m5 14V5m5 14V5"/><path d="m18 5 3 14"/></svg>',
        'compass' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="m16 8-2.5 5.5L8 16l2.5-5.5Z"/></svg>',
        'shield' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 11.5 11.5 14 16 9"/><path d="M12 3 4 6v6c0 4.5 3.4 7.7 8 9 4.6-1.3 8-4.5 8-9V6Z"/></svg>',
        'list' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M8 6h13M8 12h13M8 18h13"/><circle cx="4" cy="6" r="1"/><circle cx="4" cy="12" r="1"/><circle cx="4" cy="18" r="1"/></svg>',
        'clock' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 8v5l3 2"/></svg>',
        'chart' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/></svg>',
        'wrench' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14.7 6.3a4 4 0 0 0-5.4 5.4L4 17v3h3l5.3-5.3a4 4 0 0 0 5.4-5.4l-2.4 2.4-3-3 2.4-2.4Z"/></svg>',
        'sliders' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 6h10M18 6h2M4 12h4M12 12h8M4 18h12M20 18h0"/><circle cx="16" cy="6" r="2"/><circle cx="10" cy="12" r="2"/><circle cx="18" cy="18" r="2"/></svg>',
    ];

    public function __construct(private JsonStorage $storage)
    {
    }

    private function icon(string $name): string
    {
        return self::ICONS[$name] ?? self::ICONS['download'];
    }

    public function getNavigation(?string $currentRoute = null): array
    {
        $activeKey = $this->resolveActiveKey($currentRoute);

        if (!$this->storage->isConfigured()) {
            return [[
                'key' => 'setup',
                'label' => 'Setup',
                'route' => 'config',
                'enabled' => true,
                'active' => $activeKey === 'configuration',
                'topLevel' => true,
                'type' => 'link',
                'icon' => $this->icon('sliders'),
                'items' => [],
            ]];
        }

        $groups = $this->buildGroups();

        foreach ($groups as &$group) {
            $groupActive = $group['key'] === $activeKey;

            foreach ($group['items'] as &$item) {
                $item['active'] = $item['key'] === $activeKey;
                $groupActive = $groupActive || $item['active'];
            }
            unset($item);

            $group['active'] = $groupActive;
        }
        unset($group);

        return $groups;
    }

    public function resolveActiveKey(?string $route): ?string
    {
        if ($route === null || $route === '') {
            return null;
        }

        if (str_starts_with($route, 'music_library') || in_array($route, self::MUSIC_LIBRARY_ROUTES, true)) {
            return 'music_library';
        }

        if ($route === 'music_automations' || str_starts_with($route, 'music_automations_')) {
            return 'music_automations';
        }

        if ($route === 'music' || str_starts_with($route, 'music_') || str_starts_with($route, 'spotify_')) {
            return 'music_download';
        }

        if (str_starts_with($route, 'media')) {
            return 'video_discover';
        }

        if ($route === 'video_integrity' || str_starts_with($route, 'video_integrity_')) {
            return 'video_integrity';
        }

        if (str_starts_with($route, 'automation')) {
            return 'video_automations';
        }

        if ($route === 'torrent' || $route === 'debrid' || $route === 'debrid_recent' || str_starts_with($route, 'torrent_db_')) {
            return 'debrid';
        }

        if (str_starts_with($route, 'queue') || in_array($route, ['progress', 'queue_active_log'], true)) {
            return 'queue';
        }

        if (in_array($route, ['history', 'history_log'], true)) {
            return 'history';
        }

        if (in_array($route, ['statistics', 'statistics_export'], true)) {
            return 'statistics';
        }

        if ($route === 'maintenance' || str_starts_with($route, 'maintenance_')) {
            return 'maintenance';
        }

        if ($route === 'config' || str_starts_with($route, 'config_')) {
            return 'configuration';
        }

        return null;
    }

    private function buildGroups(): array
    {
        return [
            [
                'key' => 'music',
                'label' => 'Music',
                'route' => null,
                'enabled' => true,
                'active' => false,
                'topLevel' => true,
                'type' => 'group',
                'items' => [
                    ['key' => 'music_download', 'label' => 'Download', 'route' => 'music', 'icon' => $this->icon('download'), 'enabled' => true, 'active' => false, 'topLevel' => false],
                    ['key' => 'music_automations', 'label' => 'Automations', 'route' => 'music_automations', 'icon' => $this->icon('bolt'), 'enabled' => true, 'active' => false, 'topLevel' => false],
                    ['key' => 'music_library', 'label' => 'Library', 'route' => 'music_library', 'icon' => $this->icon('library'), 'enabled' => true, 'active' => false, 'topLevel' => false],
                ],
            ],
            [
                'key' => 'video',
                'label' => 'Video',
                'route' => null,
                'enabled' => true,
                'active' => false,
                'topLevel' => true,
                'type' => 'group',
                'items' => [
                    ['key' => 'video_discover', 'label' => 'Discover', 'route' => 'media_index', 'icon' => $this->icon('compass'), 'enabled' => true, 'active' => false, 'topLevel' => false],
                    ['key' => 'video_automations', 'label' => 'Library Automations', 'route' => 'automation', 'icon' => $this->icon('bolt'), 'enabled' => true, 'active' => false, 'topLevel' => false],
                    ['key' => 'video_integrity', 'label' => 'Integrity', 'route' => 'video_integrity', 'icon' => $this->icon('shield'), 'enabled' => true, 'active' => false, 'topLevel' => false],
                ],
            ],
            [
                'key' => 'debrid',
                'label' => 'Debrid',
                'route' => 'torrent',
                'enabled' => true,
                'active' => false,
                'topLevel' => true,
                'type' => 'link',
                'icon' => $this->icon('download'),
                'items' => [],
            ],
            [
                'key' => 'downloads',
                'label' => 'Downloads',
                'route' => null,
                'enabled' => true,
                'active' => false,
                'topLevel' => true,
                'type' => 'group',
                'items' => [
                    ['key' => 'queue', 'label' => 'Queue', 'route' => 'queue', 'icon' => $this->icon('list'), 'enabled' => true, 'active' => false, 'topLevel' => false],
                    ['key' => 'history', 'label' => 'History', 'route' => 'history', 'icon' => $this->icon('clock'), 'enabled' => true, 'active' => false, 'topLevel' => false],
                    ['key' => 'statistics', 'label' => 'Statistics', 'route' => 'statistics', 'icon' => $this->icon('chart'), 'enabled' => true, 'active' => false, 'topLevel' => false],
                ],
            ],
            [
                'key' => 'settings',
                'label' => 'Settings',
                'route' => null,
                'enabled' => true,
                'active' => false,
                'topLevel' => true,
                'type' => 'group',
                'items' => [
                    ['key' => 'maintenance', 'label' => 'Maintenance', 'route' => 'maintenance', 'icon' => $this->icon('wrench'), 'enabled' => true, 'active' => false, 'topLevel' => false],
                    ['key' => 'configuration', 'label' => 'Configuration', 'route' => 'config', 'icon' => $this->icon('sliders'), 'enabled' => true, 'active' => false, 'topLevel' => false],
                ],
            ],
        ];
    }
}
