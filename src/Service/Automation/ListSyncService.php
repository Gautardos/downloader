<?php

namespace App\Service\Automation;

use Symfony\Component\Process\Process;

class ListSyncService
{
    private const PROVIDERS = [
        'scrob' => [
            'label' => 'Scrob',
            'config' => ['base_url' => '', 'api_prefix' => '/api/proxy', 'api_key' => '', 'list_ids' => []],
        ],
        'mdblist' => [
            'label' => 'MDBList',
            'config' => ['api_key' => '', 'list_ids' => []],
        ],
    ];

    private AutomationStore $store;
    private AutomationSettings $settings;
    private ListMatcher $matcher;
    private ListSourceRegistry $registry;
    private string $projectDir;

    public function __construct(
        AutomationStore $store,
        AutomationSettings $settings,
        ListMatcher $matcher,
        ListSourceRegistry $registry,
        string $projectDir
    ) {
        $this->store = $store;
        $this->settings = $settings;
        $this->matcher = $matcher;
        $this->registry = $registry;
        $this->projectDir = $projectDir;
    }

    public function getSources(): array
    {
        $stored = $this->settings->getSources();
        $byProvider = [];
        $unknown = [];
        foreach ($stored as $source) {
            $provider = strtolower((string) ($source['provider'] ?? ''));
            if (isset(self::PROVIDERS[$provider])) {
                $byProvider[$provider] = $source;
            } else {
                $unknown[] = $source;
            }
        }

        $sources = [];
        foreach (self::PROVIDERS as $provider => $default) {
            $source = $byProvider[$provider] ?? [];
            $sourceConfig = is_array($source['config'] ?? null) ? $source['config'] : [];
            $sources[] = self::normalizeSource(array_merge([
                'id' => $provider,
                'provider' => $provider,
                'label' => $default['label'],
                'enabled' => false,
                'config' => array_merge($default['config'], $sourceConfig),
            ], $source, [
                'provider' => $provider,
                'config' => array_merge($default['config'], $sourceConfig),
            ]));
        }

        return array_merge($sources, array_map(fn ($source) => self::normalizeSource($source), $unknown));
    }

    public function getSource(string $sourceId): ?array
    {
        foreach ($this->getSources() as $source) {
            if ((string) $source['id'] === $sourceId) {
                return $source;
            }
        }

        return null;
    }

    public function isConfigured(array $source): bool
    {
        $client = $this->registry->get((string) ($source['provider'] ?? ''));
        if ($client === null) {
            return false;
        }

        return $client->isConfigured($source);
    }

    public function canTestSource(array $source): bool
    {
        $client = $this->registry->get((string) ($source['provider'] ?? ''));

        return $client !== null && $client->canTest($source);
    }

    public function testSource(array $source): array
    {
        $client = $this->registry->get((string) ($source['provider'] ?? ''));
        if ($client === null) {
            return ['success' => false, 'message' => 'Unsupported list provider.', 'lists' => []];
        }

        $result = $client->test($source);
        if (!empty($result['success']) && !empty($result['lists'])) {
            $this->rememberDiscoveredLists((string) $source['provider'], $result['lists']);
        }

        return $result;
    }

    public function rememberDiscoveredLists(string $provider, array $lists): void
    {
        $sources = $this->getSources();
        $changed = false;
        foreach ($sources as &$source) {
            if ((string) $source['provider'] !== $provider) {
                continue;
            }
            $config = is_array($source['config'] ?? null) ? $source['config'] : [];
            $newLists = array_values(array_map(static function ($list) {
                return [
                    'ref' => (string) ($list['ref'] ?? ''),
                    'name' => (string) ($list['name'] ?? ''),
                    'count' => (int) ($list['count'] ?? 0),
                ];
            }, is_array($lists) ? $lists : []));
            if (($config['available_lists'] ?? null) === $newLists) {
                break;
            }
            $config['available_lists'] = $newLists;
            $source['config'] = $config;
            $changed = true;
            break;
        }
        unset($source);

        if ($changed) {
            $this->saveSources($sources);
        }
    }

    public function saveSources(array $sources): void
    {
        $normalized = [];
        foreach ($sources as $source) {
            if (is_array($source)) {
                $normalized[] = self::normalizeSource($source);
            }
        }
        $this->settings->save(['sources' => $normalized]);
    }

    public static function normalizeSource(array $source): array
    {
        $provider = strtolower(trim((string) ($source['provider'] ?? '')));
        $config = is_array($source['config'] ?? null) ? $source['config'] : [];
        $listIds = $config['list_ids'] ?? [];
        if (is_string($listIds)) {
            $listIds = preg_split('/[\s,]+/', $listIds) ?: [];
        }
        $listIds = is_array($listIds) ? $listIds : [];
        $listIds = array_values(array_filter(array_map(
            static fn ($value) => trim((string) $value),
            $listIds
        ), static fn ($value) => $value !== ''));

        $config['list_ids'] = $listIds;
        if (isset($config['base_url'])) {
            $config['base_url'] = trim((string) $config['base_url']);
        }
        if (isset($config['api_prefix'])) {
            $config['api_prefix'] = trim((string) $config['api_prefix']);
        }
        if (isset($config['api_key'])) {
            $config['api_key'] = trim((string) $config['api_key']);
        }

        return [
            'id' => trim((string) ($source['id'] ?? $provider)) ?: $provider,
            'provider' => $provider,
            'label' => trim((string) ($source['label'] ?? ($provider !== '' ? ucfirst($provider) : 'Source'))),
            'enabled' => !empty($source['enabled']),
            'config' => $config,
        ];
    }

    public function startBackgroundSync(?string $sourceId = null): array
    {
        $state = $this->getRunState();
        if ($state['error'] !== null) {
            return ['success' => false, 'message' => $state['error']];
        }
        if ($state['running']) {
            return ['success' => false, 'message' => 'A list sync is already running.'];
        }

        $this->store->setSetting('lists_sync_requested', true);
        $this->store->setSetting('lists_sync_requested_source', $sourceId ?: null);
        $this->store->setSetting('lists_sync_requested_at', time());

        $consolePath = $this->projectDir . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'console';
        $phpPath = PHP_BINARY;
        if (!str_contains($phpPath, 'bin/php') && !str_ends_with($phpPath, 'php.exe') && basename($phpPath) !== 'php') {
            $phpPath = 'php';
        }

        $arguments = [$phpPath, $consolePath, 'app:lists-sync'];
        if ($sourceId !== null && $sourceId !== '') {
            $arguments[] = '--source=' . $sourceId;
        }

        try {
            $process = new Process($arguments);
            $process->setWorkingDirectory($this->projectDir);
            $process->start();
        } catch (\Throwable $e) {
            $this->store->setSetting('lists_sync_requested', false);
            return ['success' => false, 'message' => 'Could not start the list sync: ' . $e->getMessage()];
        }

        return ['success' => true, 'message' => 'List sync requested.'];
    }

    public function sync(?array $sourceIds = null, ?callable $onProgress = null, ?callable $onHeartbeat = null): array
    {
        $lockState = $this->tryAcquireLock();
        if ($lockState['error'] !== null) {
            return ['success' => false, 'message' => $lockState['error']];
        }
        if ($lockState['busy']) {
            return ['success' => false, 'message' => 'A list sync is already running.'];
        }

        $lock = $lockState['handle'];
        $sources = array_values(array_filter($this->getSources(), static function ($source) use ($sourceIds) {
            if (empty($source['enabled'])) {
                return false;
            }

            return $sourceIds === null || in_array((string) $source['id'], $sourceIds, true);
        }));

        if (empty($sources)) {
            $this->releaseLock($lock);
            $this->store->setSetting('lists_sync_requested', false);
            $this->store->setSetting('lists_sync_requested_source', null);
            $this->store->setSetting('lists_sync_requested_at', null);

            return ['success' => true, 'stats' => ['sources' => 0], 'message' => 'No enabled list source to sync.'];
        }

        $settings = $this->settings->getListSettings();
        $maxItems = max(1, min(5000, (int) ($settings['max_items_per_run'] ?? 500)));
        $maxLookups = max(0, (int) ($settings['max_show_lookups_per_run'] ?? 150));
        $overall = ['sources' => count($sources), 'present' => 0, 'missing' => 0, 'ambiguous' => 0, 'ignored' => 0, 'items_seen' => 0];

        try {
            foreach ($sources as $source) {
                if ($onHeartbeat) {
                    $onHeartbeat();
                }

                $sourceId = (string) $source['id'];
                $provider = (string) $source['provider'];
                $started = microtime(true);
                $runId = $this->store->startListRun($sourceId, $provider, 'dry_run');
                $stats = [
                    'lists_seen' => 0,
                    'items_seen' => 0,
                    'items_added' => 0,
                    'items_removed' => 0,
                    'present' => 0,
                    'missing' => 0,
                    'ambiguous' => 0,
                    'ignored' => 0,
                    'episodes_present' => 0,
                    'episodes_missing' => 0,
                    'lookups' => 0,
                    'warnings' => [],
                ];

                try {
                    $client = $this->registry->get($provider);
                    if ($client === null) {
                        throw new \RuntimeException('Unsupported list provider: ' . $provider);
                    }
                    if (!$client->isConfigured($source)) {
                        $stats['warnings'][] = 'Source is not configured.';
                        $this->store->finishListRun($runId, $stats, 'warning', 'Source is not configured.');
                        continue;
                    }

                    $this->matcher->beginRun($maxLookups);
                    $fetched = $client->fetch($source, $maxItems);
                    $stats['lists_seen'] = count($fetched['lists'] ?? []);
                    $stats['warnings'] = array_values($fetched['warnings'] ?? []);
                    $prepared = [];
                    foreach ($fetched['items'] ?? [] as $item) {
                        $prepared[] = $this->prepareItem($item, $source);
                    }

                    foreach ($prepared as $item) {
                        $result = $this->store->upsertListItem($item, $runId);
                        if ($result === 'inserted') {
                            $stats['items_added']++;
                        }
                    }
                    $stats['items_seen'] = count($prepared);

                    if (!empty($prepared) && empty($stats['warnings'])) {
                        $stats['items_removed'] = $this->store->markListItemsRemoved($sourceId, $runId);
                    } elseif (empty($prepared)) {
                        $stats['warnings'][] = 'No list item returned; previous items preserved.';
                    } else {
                        $stats['warnings'][] = 'Fetch returned warnings; previous items were preserved.';
                    }

                    $this->store->updateListRunProgress($runId, $stats);

                    $storedItems = $this->store->getActiveListItems($sourceId, $maxItems);
                    $matched = 0;
                    foreach ($storedItems as $storedItem) {
                        if ($onHeartbeat) {
                            $onHeartbeat();
                        }

                        $match = $this->matcher->match($storedItem);
                        $this->store->updateListItemMatch((int) $storedItem['id'], $match, $runId);
                        $status = (string) ($match['status'] ?? 'ambiguous');
                        if (isset($stats[$status])) {
                            $stats[$status]++;
                        }
                        $stats['episodes_present'] += (int) ($match['episodes_present'] ?? 0);
                        $stats['episodes_missing'] += (int) ($match['episodes_missing'] ?? 0);
                        $matched++;
                        $stats['lookups'] = $this->matcher->getLookups();

                        if ($matched % 10 === 0) {
                            $this->store->updateListRunProgress($runId, $stats);
                        }
                        if ($onProgress) {
                            $onProgress($stats);
                        }
                    }

                    $this->store->updateListRunProgress($runId, $stats);
                    $stats['lookups'] = $this->matcher->getLookups();
                    $stats['duration'] = round(microtime(true) - $started, 2);
                    $status = empty($stats['warnings']) ? 'success' : 'warning';
                    $error = empty($stats['warnings']) ? null : implode(' | ', array_slice($stats['warnings'], 0, 20));
                    $this->store->finishListRun($runId, $stats, $status, $error);
                } catch (\Throwable $e) {
                    $stats['duration'] = round(microtime(true) - $started, 2);
                    $this->store->finishListRun($runId, $stats, 'error', $e->getMessage());
                }

                foreach (['items_seen', 'present', 'missing', 'ambiguous', 'ignored'] as $key) {
                    $overall[$key] += (int) ($stats[$key] ?? 0);
                }
                if ($onHeartbeat) {
                    $onHeartbeat();
                }
            }

            return ['success' => true, 'stats' => $overall];
        } finally {
            $this->store->setSetting('lists_sync_requested', false);
            $this->store->setSetting('lists_sync_requested_source', null);
            $this->store->setSetting('lists_sync_requested_at', null);
            $this->releaseLock($lock);
        }
    }

    public function dueSourceIds(): array
    {
        $schedule = $this->settings->getSchedule();
        $due = [];
        foreach ($this->getSources() as $source) {
            if (empty($source['enabled'])) {
                continue;
            }
            $provider = (string) $source['provider'];
            $interval = max(300, (int) ($schedule[$provider] ?? 3600));
            $last = $this->store->getLastListRun((string) $source['id']);
            $lastAt = $last !== null
                ? (strtotime((string) ($last['finished_at'] ?? $last['started_at'] ?? '')) ?: 0)
                : 0;
            if ($lastAt === 0 || (time() - $lastAt) >= $interval) {
                $due[] = (string) $source['id'];
            }
        }

        return $due;
    }

    public function shouldRunScheduled(): bool
    {
        $state = $this->getRunState();
        if ($state['error'] !== null || $state['running']) {
            return false;
        }
        if ((bool) $this->store->getSetting('lists_sync_requested', false)) {
            return true;
        }

        return !empty($this->dueSourceIds());
    }

    public function getRequestedSourceId(): ?string
    {
        $value = $this->store->getSetting('lists_sync_requested_source', null);

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function getRequestedAt(): ?int
    {
        $value = $this->store->getSetting('lists_sync_requested_at', null);

        return is_numeric($value) ? (int) $value : null;
    }

    public function getRunState(): array
    {
        $state = $this->tryAcquireLock();
        if ($state['handle']) {
            $this->releaseLock($state['handle']);
        }

        return ['running' => $state['busy'], 'error' => $state['error']];
    }

    public function isRunning(): bool
    {
        return $this->getRunState()['running'];
    }

    public function getLockError(): ?string
    {
        return $this->getRunState()['error'];
    }

    private function prepareItem(array $item, array $source): array
    {
        $titleNormalized = $this->normalizeTitle((string) ($item['title'] ?? ''));
        $mediaType = (string) ($item['media_type'] ?? 'movie');
        $season = isset($item['season']) ? (int) $item['season'] : null;
        $episode = isset($item['episode']) ? (int) $item['episode'] : null;

        $identity = '';
        if (!empty($item['tmdb_id'])) {
            $identity = 'tmdb:' . (int) $item['tmdb_id'];
        } elseif (!empty($item['imdb_id'])) {
            $identity = 'imdb:' . (string) $item['imdb_id'];
        } elseif (!empty($item['tvdb_id'])) {
            $identity = 'tvdb:' . (int) $item['tvdb_id'];
        } else {
            $identity = 'title:' . $titleNormalized . ':' . (!empty($item['year']) ? (int) $item['year'] : 'unknown');
        }
        if ($season !== null) {
            $identity .= ':s' . str_pad((string) $season, 2, '0', STR_PAD_LEFT);
        }
        if ($episode !== null) {
            $identity .= ':e' . str_pad((string) $episode, 2, '0', STR_PAD_LEFT);
        }

        return [
            'source_id' => (string) $source['id'],
            'provider' => (string) $source['provider'],
            'list_ref' => (string) ($item['list_ref'] ?? ''),
            'remote_key' => (string) ($item['list_ref'] ?? '') . '|' . $mediaType . '|' . $identity,
            'remote_id' => $item['remote_id'] ?? null,
            'media_type' => $mediaType,
            'tmdb_id' => $item['tmdb_id'] ?? null,
            'imdb_id' => $item['imdb_id'] ?? null,
            'tvdb_id' => $item['tvdb_id'] ?? null,
            'title' => (string) ($item['title'] ?? ''),
            'title_normalized' => $titleNormalized,
            'original_title' => $item['original_title'] ?? null,
            'year' => $item['year'] ?? null,
            'season' => $season,
            'episode' => $episode,
            'raw' => $item['raw'] ?? $item,
        ];
    }

    private function normalizeTitle(string $title): string
    {
        $title = trim($title);
        if (class_exists(\Normalizer::class)) {
            $decomposed = \Normalizer::normalize($title, \Normalizer::FORM_D);
            if (is_string($decomposed)) {
                $title = $decomposed;
            }
        }
        $title = preg_replace('/\p{M}+/u', '', $title) ?? $title;
        $title = mb_strtolower($title, 'UTF-8');
        $title = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $title) ?? $title;

        return trim(preg_replace('/\s+/u', ' ', $title) ?? $title);
    }

    private function tryAcquireLock(): array
    {
        $lockFile = $this->store->getStorageDir() . DIRECTORY_SEPARATOR . 'automation_lists.lock';
        if (!is_dir(dirname($lockFile))) {
            mkdir(dirname($lockFile), 0777, true);
        }

        $handle = @fopen($lockFile, 'c+');
        if (!$handle) {
            $error = error_get_last();

            return [
                'handle' => null,
                'busy' => false,
                'error' => 'Unable to open list sync lock: ' . ($error['message'] ?? $lockFile),
            ];
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return ['handle' => null, 'busy' => true, 'error' => null];
        }

        return ['handle' => $handle, 'busy' => false, 'error' => null];
    }

    private function releaseLock($handle): void
    {
        if (is_resource($handle)) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
