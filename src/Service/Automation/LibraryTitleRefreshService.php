<?php

namespace App\Service\Automation;

use App\Service\JsonStorage;
use App\Service\TmdbService;

class LibraryTitleRefreshService
{
    private const STATE_KEY = 'automation_title_refresh';

    private AutomationStore $store;
    private AutomationSettings $settings;
    private TmdbService $tmdb;
    private JsonStorage $storage;
    private string $projectDir;

    public function __construct(
        AutomationStore $store,
        AutomationSettings $settings,
        TmdbService $tmdb,
        JsonStorage $storage,
        string $projectDir
    ) {
        $this->store = $store;
        $this->settings = $settings;
        $this->tmdb = $tmdb;
        $this->storage = $storage;
        $this->projectDir = $projectDir;
    }

    public function getState(): array
    {
        $state = $this->storage->get(self::STATE_KEY, []);

        return array_merge([
            'status' => 'idle',
            'language' => $this->titleLanguage(),
            'started_at' => null,
            'finished_at' => null,
            'total' => 0,
            'processed' => 0,
            'updated' => 0,
            'skipped' => 0,
            'error' => null,
        ], is_array($state) ? $state : []);
    }

    public function isRunning(): bool
    {
        return in_array((string) ($this->getState()['status'] ?? ''), ['requested', 'running'], true);
    }

    public function startBackgroundRefresh(?int $limit = null, ?string $language = null): array
    {
        $state = $this->getState();
        if (in_array((string) ($state['status'] ?? ''), ['requested', 'running'], true)) {
            $updatedAt = strtotime((string) ($state['started_at'] ?? '')) ?: 0;
            if ($updatedAt > 0 && (time() - $updatedAt) < 120) {
                return ['success' => false, 'message' => 'A title refresh is already running.'];
            }
        }

        $language = $language ?: $this->titleLanguage();
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->storage->set(self::STATE_KEY, [
            'status' => 'requested',
            'language' => $language,
            'started_at' => $now,
            'finished_at' => null,
            'total' => 0,
            'processed' => 0,
            'updated' => 0,
            'skipped' => 0,
            'error' => null,
        ]);

        $consolePath = $this->projectDir . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'console';
        $phpPath = PHP_BINARY;
        if (!str_contains($phpPath, 'bin/php') && !str_ends_with($phpPath, 'php.exe') && basename($phpPath) !== 'php') {
            $phpPath = 'php';
        }

        $command = escapeshellarg($phpPath) . ' ' . escapeshellarg($consolePath) . ' app:library-title-refresh --language=' . escapeshellarg($language);
        if ($limit !== null && $limit > 0) {
            $command .= ' --limit=' . (int) $limit;
        }
        $logFile = $this->store->getStorageDir() . DIRECTORY_SEPARATOR . 'automation_title_refresh.log';
        $command .= ' >> ' . escapeshellarg($logFile) . ' 2>&1 &';

        try {
            @exec($command);
        } catch (\Throwable $e) {
            $this->storage->set(self::STATE_KEY, array_merge($this->getState(), [
                'status' => 'error',
                'finished_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'error' => 'Could not start title refresh: ' . $e->getMessage(),
            ]));

            return ['success' => false, 'message' => 'Could not start title refresh: ' . $e->getMessage()];
        }

        return ['success' => true, 'message' => 'English title refresh requested.'];
    }

    public function refresh(?int $limit = null, ?string $language = null, ?callable $onProgress = null, bool $apply = true): array
    {
        if (!$this->tmdb->isConfigured()) {
            return ['success' => false, 'message' => 'TMDB is not configured.'];
        }

        $lock = $this->tryAcquireLock();
        if ($lock['error'] !== null) {
            return ['success' => false, 'message' => $lock['error']];
        }
        if ($lock['busy']) {
            return ['success' => false, 'message' => 'A title refresh is already running.'];
        }

        $language = $language ?: $this->titleLanguage();
        $limit = max(1, min(5000, $limit ?? 1000));
        $entries = $this->store->getMoviesForTitleRefresh(10000);
        $groups = [];
        foreach ($entries as $entry) {
            $tmdbId = (int) ($entry['tmdb_id'] ?? 0);
            if ($tmdbId > 0) {
                $groups[$tmdbId][] = $entry;
            }
        }

        $state = [
            'status' => 'running',
            'language' => $language,
            'started_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'finished_at' => null,
            'total' => min(count($groups), $limit),
            'processed' => 0,
            'updated' => 0,
            'skipped' => 0,
            'dry_run' => !$apply,
            'error' => null,
        ];
        $this->storage->set(self::STATE_KEY, $state);

        try {
            foreach (array_slice($groups, 0, $limit, true) as $tmdbId => $movieEntries) {
                if ($onProgress) {
                    $onProgress($state);
                }

                $details = $this->tmdb->details('movie', (int) $tmdbId, $language);
                if ($details === null || trim((string) ($details['title'] ?? '')) === '') {
                    $state['skipped']++;
                    $state['processed']++;
                    $this->storage->set(self::STATE_KEY, $state);
                    continue;
                }

                $title = trim((string) $details['title']);
                $titleNormalized = $this->normalizeTitle($title);
                $year = !empty($details['year']) ? (int) $details['year'] : null;

                foreach ($movieEntries as $entry) {
                    $currentTitle = (string) ($entry['title'] ?? '');
                    $currentNormalized = (string) ($entry['title_normalized'] ?? '');
                    $currentYear = $entry['year'] !== null ? (int) $entry['year'] : null;
                    if ($currentTitle === $title && $currentNormalized === $titleNormalized && $currentYear === $year) {
                        continue;
                    }
                    if ($apply) {
                        $this->store->updateEntryTitle((int) $entry['id'], $title, $titleNormalized, $year);
                    }
                    $state['updated']++;
                }

                $state['processed']++;
                $this->storage->set(self::STATE_KEY, $state);
            }

            $state['status'] = 'success';
            $state['finished_at'] = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
            $this->storage->set(self::STATE_KEY, $state);

            return ['success' => true, 'state' => $state];
        } catch (\Throwable $e) {
            $state['status'] = 'error';
            $state['finished_at'] = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
            $state['error'] = $e->getMessage();
            $this->storage->set(self::STATE_KEY, $state);

            return ['success' => false, 'message' => $e->getMessage(), 'state' => $state];
        } finally {
            $this->releaseLock($lock['handle']);
        }
    }

    private function titleLanguage(): string
    {
        $metadata = $this->settings->get('metadata', []);
        $language = trim((string) ($metadata['title_language'] ?? 'en-US'));

        return $language !== '' ? $language : 'en-US';
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
        $lockFile = $this->store->getStorageDir() . DIRECTORY_SEPARATOR . 'automation_title_refresh.lock';
        if (!is_dir(dirname($lockFile))) {
            mkdir(dirname($lockFile), 0777, true);
        }
        $handle = @fopen($lockFile, 'c+');
        if (!$handle) {
            $error = error_get_last();

            return ['handle' => null, 'busy' => false, 'error' => 'Unable to open title refresh lock: ' . ($error['message'] ?? $lockFile)];
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
