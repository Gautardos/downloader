<?php

namespace App\Service\Automation;

use App\Service\JsonStorage;
use App\Service\LogRotator;
class ReleaseDryRunService
{
    private const STATE_KEY = 'automation_release_dry_run';

    private AutomationStore $store;
    private AutomationSettings $settings;
    private ReleaseResolverService $resolver;
    private JsonStorage $storage;
    private string $projectDir;

    public function __construct(
        AutomationStore $store,
        AutomationSettings $settings,
        ReleaseResolverService $resolver,
        JsonStorage $storage,
        string $projectDir
    ) {
        $this->store = $store;
        $this->settings = $settings;
        $this->resolver = $resolver;
        $this->storage = $storage;
        $this->projectDir = $projectDir;
    }

    public function getState(): array
    {
        $state = $this->storage->get(self::STATE_KEY, []);
        if (!is_array($state) || empty($state)) {
            return [
                'status' => 'idle',
                'started_at' => null,
                'requested_at' => null,
                'updated_at' => null,
                'finished_at' => null,
                'total' => 0,
                'processed' => 0,
                'ok' => 0,
                'no_source' => 0,
                'current' => null,
                'current_index' => 0,
                'events' => [],
                'results' => [],
                'error' => null,
                'source_ids' => [],
                'limit' => 0,
            ];
        }

        return array_merge([
            'status' => 'idle',
            'started_at' => null,
            'requested_at' => null,
            'updated_at' => null,
            'finished_at' => null,
            'total' => 0,
            'processed' => 0,
            'ok' => 0,
            'no_source' => 0,
            'current' => null,
            'current_index' => 0,
            'events' => [],
            'results' => [],
            'error' => null,
            'source_ids' => [],
            'limit' => 0,
        ], $state);
    }

    public function isRunning(): bool
    {
        $state = $this->getState();

        return in_array((string) ($state['status'] ?? ''), ['requested', 'running'], true);
    }

    public function getProfileHash(): string
    {
        return md5((string) json_encode($this->settings->getQualityProfile()));
    }

    public function startBackgroundRun(?int $limit = null, ?string $sourceId = null): array
    {
        $state = $this->getState();
        $status = (string) ($state['status'] ?? '');
        if (in_array($status, ['requested', 'running'], true)) {
            $updatedAt = strtotime((string) ($state['updated_at'] ?? $state['started_at'] ?? '')) ?: 0;
            if ($updatedAt > 0 && (time() - $updatedAt) < 60) {
                return ['success' => false, 'message' => 'A release dry-run is already running.'];
            }
        }
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $limit = max(1, min(100, $limit ?? (int) ($this->settings->getBackfill()['max_intents_per_run'] ?? 50)));
        $this->storage->set(self::STATE_KEY, [
            'status' => 'requested',
            'started_at' => $now,
            'requested_at' => $now,
            'updated_at' => $now,
            'finished_at' => null,
            'total' => 0,
            'processed' => 0,
            'ok' => 0,
            'no_source' => 0,
            'current' => null,
            'current_index' => 0,
            'events' => [['time' => $now, 'message' => 'Dry-run requested.']],
            'profile_hash' => $this->getProfileHash(),
            'results' => [],
            'error' => null,
            'source_ids' => $sourceId !== null && $sourceId !== '' ? [$sourceId] : [],
            'limit' => $limit,
        ]);

        $consolePath = $this->projectDir . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'console';
        $phpPath = PHP_BINARY;
        if (!str_contains($phpPath, 'bin/php') && !str_ends_with($phpPath, 'php.exe') && basename($phpPath) !== 'php') {
            $phpPath = 'php';
        }

        $command = escapeshellarg($phpPath) . ' ' . escapeshellarg($consolePath) . ' app:release-dry-run --limit=' . (int) $limit;
        if ($sourceId !== null && $sourceId !== '') {
            $command .= ' --source=' . escapeshellarg($sourceId);
        }
        $logFile = $this->store->getStorageDir() . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'automation_release_dry_run.log';
        $command .= ' >> ' . escapeshellarg($logFile) . ' 2>&1 &';

        LogRotator::append($logFile, '');

        try {
            @exec($command);
        } catch (\Throwable $e) {
            $this->storage->set(self::STATE_KEY, array_merge($this->getState(), [
                'status' => 'error',
                'finished_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'error' => 'Could not start the dry-run: ' . $e->getMessage(),
            ]));

            return ['success' => false, 'message' => 'Could not start the release dry-run: ' . $e->getMessage()];
        }

        return ['success' => true, 'message' => 'Release dry-run requested.'];
    }

    public function run(?int $limit = null, ?string $sourceId = null, ?callable $heartbeat = null): array
    {
        $lock = $this->tryAcquireLock();
        if ($lock['error'] !== null) {
            return ['success' => false, 'message' => $lock['error'], 'state' => $this->getState()];
        }
        if ($lock['busy']) {
            return ['success' => false, 'message' => 'A release dry-run is already in progress.', 'state' => $this->getState()];
        }

        $started = microtime(true);
        $limit = max(1, min(100, $limit ?? (int) ($this->settings->getBackfill()['max_intents_per_run'] ?? 50)));
        $items = $this->collectMissingMovies($limit, $sourceId);
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $state = [
            'status' => 'running',
            'started_at' => $now,
            'requested_at' => $this->getState()['requested_at'] ?? $now,
            'updated_at' => $now,
            'finished_at' => null,
            'total' => count($items),
            'processed' => 0,
            'ok' => 0,
            'no_source' => 0,
            'current' => null,
            'current_index' => 0,
            'events' => [['time' => $now, 'message' => 'Collected ' . count($items) . ' missing movie(s).']],
            'profile_hash' => $this->getProfileHash(),
            'results' => [],
            'error' => null,
            'source_ids' => $sourceId !== null && $sourceId !== '' ? [$sourceId] : array_values(array_unique(array_map(
                static fn ($item) => (string) $item['source_id'],
                $items
            ))),
            'limit' => $limit,
        ];
        $this->storage->set(self::STATE_KEY, $state);

        try {
            foreach ($items as $item) {
                if ($heartbeat) {
                    $heartbeat();
                }

                $state['current'] = $item['title'] . (!empty($item['year']) ? ' (' . $item['year'] . ')' : '');
                $state['current_index'] = $state['processed'] + 1;
                $state['updated_at'] = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
                $this->storage->set(self::STATE_KEY, $state);

                $preview = $this->resolver->previewIntent([
                    'media_type' => 'movie',
                    'title' => $item['title'],
                    'year' => $item['year'],
                    'tmdb_id' => $item['tmdb_id'],
                    'imdb_id' => $item['imdb_id'],
                ]);
                $result = array_merge($item, $preview);
                $state['results'][] = $result;
                $state['processed']++;
                if (($preview['status'] ?? '') === 'ok') {
                    $state['ok']++;
                } else {
                    $state['no_source']++;
                }
                $state['current'] = null;
                $state['updated_at'] = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
                $state['events'][] = [
                    'time' => $state['updated_at'],
                    'message' => ($item['title'] ?? 'Movie') . ' → ' . (($preview['status'] ?? '') === 'ok'
                        ? 'release selected (' . (($preview['selected']['addon'] ?? 'unknown addon')) . ', score ' . ($preview['selected']['score'] ?? 0) . ')'
                        : 'no source'),
                ];
                $state['events'] = array_slice($state['events'], -20);
                $state['duration'] = round(microtime(true) - $started, 2);
                $this->storage->set(self::STATE_KEY, $state);
            }

            $state['status'] = 'success';
            $state['finished_at'] = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
            $state['updated_at'] = $state['finished_at'];
            $state['current'] = null;
            $state['events'][] = ['time' => $state['finished_at'], 'message' => 'Dry-run finished.'];
            $state['events'] = array_slice($state['events'], -20);
            $state['duration'] = round(microtime(true) - $started, 2);
            $this->storage->set(self::STATE_KEY, $state);

            return ['success' => true, 'state' => $state];
        } catch (\Throwable $e) {
            $state['status'] = 'error';
            $state['finished_at'] = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
            $state['updated_at'] = $state['finished_at'];
            $state['current'] = null;
            $state['events'][] = ['time' => $state['finished_at'], 'message' => 'Dry-run failed: ' . $e->getMessage()];
            $state['events'] = array_slice($state['events'], -20);
            $state['duration'] = round(microtime(true) - $started, 2);
            $state['error'] = $e->getMessage();
            $this->storage->set(self::STATE_KEY, $state);

            return ['success' => false, 'message' => $e->getMessage(), 'state' => $state];
        } finally {
            $this->releaseLock($lock['handle']);
        }
    }

    public function collectMissingMovies(int $limit = 50, ?string $sourceId = null): array
    {
        $limit = max(1, min(100, $limit));
        $items = [];
        $seen = [];

        foreach ($this->settings->getSources() as $source) {
            if (!is_array($source) || empty($source['enabled'])) {
                continue;
            }
            $id = (string) ($source['id'] ?? $source['provider'] ?? '');
            if ($id === '' || ($sourceId !== null && $sourceId !== '' && $id !== $sourceId)) {
                continue;
            }
            if (!$this->dryRunValidated($id)) {
                continue;
            }
            $config = is_array($source['config'] ?? null) ? $source['config'] : [];
            $listIds = array_values(array_filter(array_map(
                static fn ($value) => trim((string) $value),
                is_array($config['list_ids'] ?? null) ? $config['list_ids'] : []
            )));
            $label = trim((string) ($source['label'] ?? $id));

            $listItems = $this->store->getListItems([
                'status' => 'missing',
                'source' => $id,
                'type' => 'movie',
            ], 5000);

            foreach ($listItems as $item) {
                if (!empty($item['ignored_at']) || (string) ($item['match_status'] ?? '') !== 'missing') {
                    continue;
                }
                $listRef = (string) ($item['list_ref'] ?? '');
                if (!empty($listIds) && $listRef !== '' && !in_array($listRef, $listIds, true)) {
                    continue;
                }

                $tmdbId = !empty($item['tmdb_id']) ? (int) $item['tmdb_id'] : null;
                $imdbId = trim((string) ($item['imdb_id'] ?? ''));
                $title = trim((string) ($item['title'] ?? ''));
                $year = !empty($item['year']) ? (int) $item['year'] : null;
                $titleNormalized = trim((string) ($item['title_normalized'] ?? ''));
                if ($titleNormalized === '') {
                    $titleNormalized = $this->normalizeTitle($title);
                }

                if ($this->store->hasActiveMovieEntry($tmdbId, $imdbId !== '' ? $imdbId : null, $titleNormalized, $year)) {
                    continue;
                }

                $mediaKey = $tmdbId !== null
                    ? 'movie:tmdb:' . $tmdbId
                    : ($imdbId !== '' ? 'movie:imdb:' . $imdbId : 'movie:title:' . $titleNormalized . ':' . ($year ?? 'unknown'));
                if (isset($seen[$mediaKey])) {
                    continue;
                }
                $seen[$mediaKey] = true;

                $items[] = [
                    'title' => $title,
                    'year' => $year,
                    'tmdb_id' => $tmdbId,
                    'imdb_id' => $imdbId !== '' ? $imdbId : null,
                    'media_key' => $mediaKey,
                    'source_id' => $id,
                    'source_label' => $label,
                    'source_list_id' => $listRef,
                    'list_title' => (string) ($item['title'] ?? ''),
                ];

                if (count($items) >= $limit) {
                    return $items;
                }
            }
        }

        return $items;
    }

    private function dryRunValidated(string $sourceId): bool
    {
        $run = $this->store->getLastListRun($sourceId);
        if ($run === null || empty($run['finished_at']) || in_array((string) $run['status'], ['error', 'running', 'requested'], true)) {
            return false;
        }

        return (int) ($run['items_seen'] ?? 0) > 0;
    }

    private function tryAcquireLock(): array
    {
        $lockFile = $this->store->getStorageDir() . DIRECTORY_SEPARATOR . 'automation_release_dry_run.lock';
        if (!is_dir(dirname($lockFile))) {
            mkdir(dirname($lockFile), 0777, true);
        }
        $handle = @fopen($lockFile, 'c+');
        if (!$handle) {
            $error = error_get_last();

            return ['handle' => null, 'busy' => false, 'error' => 'Unable to open dry-run lock: ' . ($error['message'] ?? $lockFile)];
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
}
