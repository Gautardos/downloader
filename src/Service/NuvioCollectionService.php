<?php

namespace App\Service;

class NuvioCollectionService
{
    private const STORAGE_KEY = 'media_collections';
    private const MAX_JSON_BYTES = 8 * 1024 * 1024;

    private JsonStorage $storage;

    public function __construct(JsonStorage $storage)
    {
        $this->storage = $storage;
    }

    public function getCollections(): array
    {
        $stored = $this->storage->get(self::STORAGE_KEY, []);
        if (!is_array($stored)) {
            return [];
        }

        $collections = $stored['collections'] ?? $stored;
        if (!is_array($collections)) {
            return [];
        }

        $configuredAddons = $this->configuredAddonIds();
        $result = [];

        foreach ($collections as $collection) {
            if (!is_array($collection)) {
                continue;
            }

            $folders = [];
            foreach ($collection['folders'] ?? [] as $folder) {
                if (!is_array($folder)) {
                    continue;
                }

                $allSources = is_array($folder['sources'] ?? null) ? $folder['sources'] : [];
                $availableSources = [];
                $pendingAddons = [];

                foreach ($allSources as $source) {
                    if (!is_array($source)) {
                        continue;
                    }

                    $addonId = (string) ($source['addon_id'] ?? '');
                    if ($addonId !== '' && isset($configuredAddons[$addonId])) {
                        $availableSources[] = $source;
                    } elseif ($addonId !== '') {
                        $pendingAddons[$addonId] = ($pendingAddons[$addonId] ?? 0) + 1;
                    }
                }

                $folder['sources'] = $availableSources;
                $folder['source_count'] = count($allSources);
                $folder['matched_count'] = count($availableSources);
                $folder['pending_addons'] = $pendingAddons;
                $folders[] = $folder;
            }

            $collection['folders'] = $folders;
            $result[] = $collection;
        }

        return $result;
    }

    public function getMeta(): array
    {
        $stored = $this->storage->get(self::STORAGE_KEY, []);
        if (!is_array($stored) || !isset($stored['collections'])) {
            return [];
        }

        return [
            'imported_at' => $stored['imported_at'] ?? null,
            'report' => $stored['report'] ?? [],
        ];
    }

    private function configuredAddonIds(): array
    {
        $configuredAddons = $this->storage->get('media_addons', []);
        $ids = [];
        foreach ($configuredAddons as $addon) {
            if (is_array($addon) && !empty($addon['id'])) {
                $ids[(string) $addon['id']] = true;
            }
        }

        return $ids;
    }

    public function importJson(string $json): array
    {
        if (strlen($json) > self::MAX_JSON_BYTES) {
            return ['success' => false, 'message' => 'Nuvio collections file is too large.'];
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded) || !array_is_list($decoded)) {
            return ['success' => false, 'message' => 'Invalid Nuvio collections JSON: expected a list of collections.'];
        }

        $configuredAddons = $this->storage->get('media_addons', []);
        $addonIds = [];
        foreach ($configuredAddons as $addon) {
            if (is_array($addon) && !empty($addon['id'])) {
                $addonIds[(string) $addon['id']] = (string) ($addon['name'] ?? $addon['id']);
            }
        }

        $collections = [];
        $report = [
            'collections' => 0,
            'folders' => 0,
            'sources' => 0,
            'matched' => 0,
            'pending' => 0,
            'skipped' => 0,
            'skipped_reasons' => [],
            'unmatched_addons' => [],
            'configured_addons' => array_keys($addonIds),
        ];

        foreach ($decoded as $collection) {
            if (!is_array($collection)) {
                continue;
            }

            $folders = [];
            foreach ($collection['folders'] ?? [] as $folder) {
                if (!is_array($folder)) {
                    continue;
                }

                $report['folders']++;
                $matchedSources = [];
                $pendingAddons = [];
                $sourceCount = 0;

                foreach ($folder['catalogSources'] ?? ($folder['sources'] ?? []) as $source) {
                    if (!is_array($source)) {
                        continue;
                    }

                    $sourceCount++;
                    $report['sources']++;
                    $catalogId = trim((string) ($source['catalogId'] ?? ''));
                    $addonId = trim((string) ($source['addonId'] ?? ''));

                    if ($catalogId === '') {
                        $reason = 'missing_catalog_id';
                        $report['skipped']++;
                        $report['skipped_reasons'][$reason] = ($report['skipped_reasons'][$reason] ?? 0) + 1;
                        continue;
                    }

                    if ($addonId === '') {
                        $reason = 'missing_addon_id';
                        $report['skipped']++;
                        $report['skipped_reasons'][$reason] = ($report['skipped_reasons'][$reason] ?? 0) + 1;
                        continue;
                    }

                    $matchedSources[] = [
                        'type' => (string) ($source['type'] ?? 'movie'),
                        'catalog_id' => $catalogId,
                        'addon_id' => $addonId,
                        'genre' => isset($source['genre']) ? (string) $source['genre'] : '',
                    ];

                    if (isset($addonIds[$addonId])) {
                        $report['matched']++;
                    } else {
                        $report['pending']++;
                        $pendingAddons[$addonId] = ($pendingAddons[$addonId] ?? 0) + 1;
                        $report['unmatched_addons'][$addonId] = ($report['unmatched_addons'][$addonId] ?? 0) + 1;
                    }
                }

                $folders[] = [
                    'id' => (string) ($folder['id'] ?? md5((string) ($folder['title'] ?? 'folder') . $report['folders'])),
                    'title' => (string) ($folder['title'] ?? 'Untitled'),
                    'cover' => $folder['coverImageUrl'] ?? null,
                    'hero' => $folder['heroBackdropUrl'] ?? ($folder['coverImageUrl'] ?? null),
                    'logo' => $folder['titleLogoUrl'] ?? null,
                    'focus_gif' => $folder['focusGifUrl'] ?? null,
                    'hide_title' => (bool) ($folder['hideTitle'] ?? false),
                    'tile_shape' => (string) ($folder['tileShape'] ?? 'LANDSCAPE'),
                    'source_count' => $sourceCount,
                    'matched_count' => count($matchedSources),
                    'pending_addons' => $pendingAddons,
                    'sources' => $matchedSources,
                ];
            }

            $collections[] = [
                'id' => (string) ($collection['id'] ?? md5((string) ($collection['title'] ?? 'collection') . $report['collections'])),
                'title' => (string) ($collection['title'] ?? 'Untitled'),
                'backdrop' => $collection['backdropImageUrl'] ?? null,
                'view_mode' => (string) ($collection['viewMode'] ?? 'TABBED_GRID'),
                'show_all' => (bool) ($collection['showAllTab'] ?? false),
                'pin_to_top' => (bool) ($collection['pinToTop'] ?? false),
                'folders' => $folders,
            ];
            $report['collections']++;
        }

        $this->storage->set(self::STORAGE_KEY, [
            'imported_at' => time(),
            'collections' => $collections,
            'report' => $report,
        ]);

        return [
            'success' => true,
            'message' => 'Nuvio collections imported.',
            'collections' => $collections,
            'report' => $report,
        ];
    }

    public function deleteCollection(string $collectionId): bool
    {
        $stored = $this->storage->get(self::STORAGE_KEY, []);
        if (!is_array($stored) || !isset($stored['collections']) || !is_array($stored['collections'])) {
            return false;
        }

        $before = count($stored['collections']);
        $stored['collections'] = array_values(array_filter(
            $stored['collections'],
            static fn ($collection) => !is_array($collection) || (string) ($collection['id'] ?? '') !== $collectionId
        ));

        if (count($stored['collections']) === $before) {
            return false;
        }

        $this->storage->set(self::STORAGE_KEY, $stored);
        return true;
    }

    public function deleteFolder(string $collectionId, string $folderId): bool
    {
        $stored = $this->storage->get(self::STORAGE_KEY, []);
        if (!is_array($stored) || !isset($stored['collections']) || !is_array($stored['collections'])) {
            return false;
        }

        $deleted = false;
        foreach ($stored['collections'] as &$collection) {
            if (!is_array($collection) || (string) ($collection['id'] ?? '') !== $collectionId) {
                continue;
            }

            $folders = is_array($collection['folders'] ?? null) ? $collection['folders'] : [];
            $remaining = array_values(array_filter(
                $folders,
                static fn ($folder) => !is_array($folder) || (string) ($folder['id'] ?? '') !== $folderId
            ));

            if (count($remaining) === count($folders)) {
                break;
            }

            $collection['folders'] = $remaining;
            $deleted = true;
            break;
        }
        unset($collection);

        if ($deleted) {
            $this->storage->set(self::STORAGE_KEY, $stored);
        }

        return $deleted;
    }

    public function clear(): void
    {
        $this->storage->set(self::STORAGE_KEY, []);
    }
}
