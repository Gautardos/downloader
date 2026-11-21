<?php

namespace App\Service\Automation;

use App\Service\JsonStorage;
use App\Service\LogRotator;
use Symfony\Component\Process\Process;

class ReleaseIntentService
{
    private AutomationStore $store;
    private AutomationSettings $settings;
    private ReleaseResolverService $resolver;
    private AutomationNotifier $notifier;
    private JsonStorage $storage;
    private string $projectDir;
    private ?EpisodeNumberingService $numbering;

    public function __construct(
        AutomationStore $store,
        AutomationSettings $settings,
        ReleaseResolverService $resolver,
        AutomationNotifier $notifier,
        JsonStorage $storage,
        string $projectDir,
        ?EpisodeNumberingService $numbering = null
    ) {
        $this->store = $store;
        $this->settings = $settings;
        $this->resolver = $resolver;
        $this->notifier = $notifier;
        $this->storage = $storage;
        $this->projectDir = $projectDir;
        $this->numbering = $numbering;
    }

    public function hasAutoDownloadSources(): bool
    {
        foreach ($this->settings->getSources() as $source) {
            if (!is_array($source) || empty($source['enabled'])) {
                continue;
            }
            $config = is_array($source['config'] ?? null) ? $source['config'] : [];
            if (!empty($config['auto_download'])) {
                return true;
            }
        }

        return false;
    }

    public function hasAutoDownloadSeriesSources(): bool
    {
        foreach ($this->settings->getSources() as $source) {
            if (!is_array($source) || empty($source['enabled'])) {
                continue;
            }
            $config = is_array($source['config'] ?? null) ? $source['config'] : [];
            if (!empty($config['auto_download_series'])) {
                return true;
            }
        }

        return false;
    }

    public function dryRunValidated(?string $sourceId = null): bool
    {
        $run = $this->store->getLastListRun($sourceId);
        if ($run === null) {
            return false;
        }
        if (empty($run['finished_at']) || in_array((string) $run['status'], ['error', 'running', 'requested'], true)) {
            return false;
        }

        return (int) ($run['items_seen'] ?? 0) > 0;
    }

    public function queueMissingMovies(bool $autoOnly = false): array
    {
        $stats = [
            'sources' => 0,
            'created' => 0,
            'existing' => 0,
            'library_present' => 0,
            'reactivated' => 0,
            'skipped' => 0,
            'warnings' => [],
        ];

        $backfill = $this->settings->getBackfill();
        $maxPerRun = max(1, min(500, (int) ($backfill['max_intents_per_run'] ?? 50)));
        $queueGuard = max(1, (int) ($backfill['queue_guard'] ?? 20));
        $queueDepth = $this->getQueueDepth();
        if ($queueDepth >= $queueGuard) {
            $stats['warnings'][] = 'Queue guard reached (' . $queueDepth . '/' . $queueGuard . '); movie intents were not created.';

            return [
                'success' => true,
                'stats' => $stats,
                'message' => $stats['warnings'][0],
            ];
        }
        $limitReached = false;

        foreach ($this->settings->getSources() as $source) {
            if ($limitReached) {
                break;
            }
            if (!is_array($source) || empty($source['enabled'])) {
                continue;
            }
            $sourceId = (string) ($source['id'] ?? $source['provider'] ?? '');
            $config = is_array($source['config'] ?? null) ? $source['config'] : [];
            if ($sourceId === '' || ($autoOnly && empty($config['auto_download']))) {
                continue;
            }
            if (!$this->dryRunValidated($sourceId)) {
                $stats['skipped']++;
                $stats['warnings'][] = $sourceId . ': dry-run not validated, items skipped.';
                continue;
            }

            $listIds = array_values(array_filter(array_map(
                static fn ($value) => trim((string) $value),
                is_array($config['list_ids'] ?? null) ? $config['list_ids'] : []
            )));
            $items = $this->store->getListItems([
                'status' => 'missing',
                'source' => $sourceId,
                'type' => 'movie',
            ], 5000);
            $stats['sources']++;

            foreach ($items as $item) {
                if ($limitReached) {
                    break;
                }
                if ($stats['created'] >= $maxPerRun) {
                    $limitReached = true;
                    $stats['warnings'][] = 'Maximum intents per run reached (' . $maxPerRun . '); remaining movies were skipped.';
                    break;
                }
                if (!empty($item['ignored_at']) || (string) ($item['match_status'] ?? '') !== 'missing') {
                    $stats['skipped']++;
                    continue;
                }
                $listRef = (string) ($item['list_ref'] ?? '');
                if (!empty($listIds) && $listRef !== '' && !in_array($listRef, $listIds, true)) {
                    $stats['skipped']++;
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
                    $stats['library_present']++;
                    continue;
                }

                $mediaKey = $this->movieMediaKey($tmdbId, $imdbId, $titleNormalized, $year);

                $result = $this->store->createIntent([
                    'source_id' => $sourceId,
                    'source_list_id' => $listRef,
                    'media_key' => $mediaKey,
                    'media_type' => 'movie',
                    'tmdb_id' => $tmdbId,
                    'imdb_id' => $imdbId !== '' ? $imdbId : null,
                    'title' => $title,
                    'year' => $year,
                ], true);

                if (($result['status'] ?? '') === 'inserted') {
                    $stats['created']++;
                } elseif (($result['status'] ?? '') === 'reactivated') {
                    $stats['reactivated']++;
                } else {
                    $stats['existing']++;
                }
            }
        }

        $message = sprintf(
            '%d intent(s) created, %d reactivated, %d already present, %d already in library, %d skipped.',
            $stats['created'],
            $stats['reactivated'],
            $stats['existing'],
            $stats['library_present'],
            $stats['skipped']
        );
        $this->log('Intent queueing: ' . $message);

        return [
            'success' => true,
            'stats' => $stats,
            'message' => $message,
        ];
    }

    public function queueMissingEpisodes(bool $autoOnly = false): array
    {
        $stats = [
            'sources' => 0,
            'created' => 0,
            'existing' => 0,
            'library_present' => 0,
            'reactivated' => 0,
            'skipped' => 0,
            'invalid' => 0,
            'warnings' => [],
        ];

        $backfill = $this->settings->getBackfill();
        $maxPerRun = max(1, min(500, (int) ($backfill['max_intents_per_run'] ?? 50)));
        $queueGuard = max(1, (int) ($backfill['queue_guard'] ?? 20));
        $queueDepth = $this->getQueueDepth();
        if ($queueDepth >= $queueGuard) {
            $stats['warnings'][] = 'Queue guard reached (' . $queueDepth . '/' . $queueGuard . '); episode intents were not created.';

            return [
                'success' => true,
                'stats' => $stats,
                'message' => $stats['warnings'][0],
            ];
        }

        $excludeSpecials = (bool) ($this->settings->getBackfill()['exclude_specials'] ?? true);
        $limitReached = false;

        foreach ($this->settings->getSources() as $source) {
            if ($limitReached) {
                break;
            }
            if (!is_array($source) || empty($source['enabled'])) {
                continue;
            }
            $sourceId = (string) ($source['id'] ?? $source['provider'] ?? '');
            $config = is_array($source['config'] ?? null) ? $source['config'] : [];
            if ($sourceId === '' || ($autoOnly && empty($config['auto_download_series']))) {
                continue;
            }
            if (!$this->dryRunValidated($sourceId)) {
                $stats['skipped']++;
                $stats['warnings'][] = $sourceId . ': dry-run not validated, episodes skipped.';
                continue;
            }

            $listIds = array_values(array_filter(array_map(
                static fn ($value) => trim((string) $value),
                is_array($config['list_ids'] ?? null) ? $config['list_ids'] : []
            )));
            $items = $this->store->getListItems([
                'status' => 'missing',
                'source' => $sourceId,
                'type' => 'series',
            ], 5000);
            $stats['sources']++;

            foreach ($items as $item) {
                if ($limitReached) {
                    break;
                }
                if (!empty($item['ignored_at']) || (string) ($item['match_status'] ?? '') !== 'missing') {
                    $stats['skipped']++;
                    continue;
                }
                $listRef = (string) ($item['list_ref'] ?? '');
                if (!empty($listIds) && $listRef !== '' && !in_array($listRef, $listIds, true)) {
                    $stats['skipped']++;
                    continue;
                }

                $missing = json_decode((string) ($item['missing_json'] ?? '[]'), true);
                if (!is_array($missing) || empty($missing)) {
                    $stats['skipped']++;
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

                $profile = null;
                if ($this->numbering !== null && $tmdbId !== null) {
                    $profile = $this->numbering->profileFor([
                        'tmdb_id' => $tmdbId,
                        'imdb_id' => $imdbId,
                        'title' => $title,
                        'year' => $year,
                        'title_normalized' => $titleNormalized,
                    ], false, true);
                    if (!$this->numbering->isUsable($profile)) {
                        $stats['skipped']++;
                        $stats['warnings'][] = $title . ': series numbering unavailable, ambiguous or not confirmed, episodes skipped.';
                        continue;
                    }
                }

                foreach ($missing as $code) {
                    if ($stats['created'] >= $maxPerRun) {
                        $limitReached = true;
                        $stats['warnings'][] = 'Maximum intents per run reached (' . $maxPerRun . '); remaining episodes were skipped.';
                        break;
                    }
                    $parsed = $this->parseEpisodeCode((string) $code);
                    if ($parsed === null) {
                        $stats['invalid']++;
                        continue;
                    }
                    [$season, $episode] = $parsed;
                    if ($season <= 0 && $excludeSpecials) {
                        $stats['skipped']++;
                        continue;
                    }

                    $releaseSeason = $season;
                    $releaseEpisode = $episode;
                    $numberingSource = null;
                    if ($profile !== null) {
                        $acquisition = $this->numbering->acquisitionFor($profile, $season, $episode);
                        if ($acquisition === null) {
                            $stats['skipped']++;
                            $stats['warnings'][] = $title . ': no release numbering for TMDB S' . str_pad((string) $season, 2, '0', STR_PAD_LEFT) . 'E' . str_pad((string) $episode, 2, '0', STR_PAD_LEFT) . '.';
                            continue;
                        }
                        $releaseSeason = $acquisition['season'];
                        $releaseEpisode = $acquisition['episode'];
                        $numberingSource = $acquisition['numbering_source'];
                    }

                    $libraryPresent = $this->store->hasActiveEpisodeEntry(
                        $tmdbId,
                        $imdbId !== '' ? $imdbId : null,
                        $titleNormalized,
                        $year,
                        $releaseSeason,
                        $releaseEpisode,
                        true
                    );
                    if (!$libraryPresent && ($releaseSeason !== $season || $releaseEpisode !== $episode)) {
                        $libraryPresent = $this->store->hasActiveEpisodeEntry(
                            $tmdbId,
                            $imdbId !== '' ? $imdbId : null,
                            $titleNormalized,
                            $year,
                            $season,
                            $episode,
                            true
                        );
                    }
                    if ($libraryPresent) {
                        $stats['library_present']++;
                        continue;
                    }

                    $result = $this->store->createIntent([
                        'source_id' => $sourceId,
                        'source_list_id' => $listRef,
                        'media_key' => $this->episodeMediaKey($tmdbId, $imdbId, $titleNormalized, $year, $season, $episode),
                        'media_type' => 'series',
                        'tmdb_id' => $tmdbId,
                        'imdb_id' => $imdbId !== '' ? $imdbId : null,
                        'title' => $title,
                        'year' => $year,
                        'season' => $season,
                        'episode' => $episode,
                        'release_season' => $releaseSeason,
                        'release_episode' => $releaseEpisode,
                        'numbering_source' => $numberingSource,
                    ], true);

                    if (($result['status'] ?? '') === 'inserted') {
                        $stats['created']++;
                    } elseif (($result['status'] ?? '') === 'reactivated') {
                        $stats['reactivated']++;
                    } else {
                        $stats['existing']++;
                    }
                }
            }
        }

        $message = sprintf(
            '%d episode intent(s) created, %d reactivated, %d already present, %d already in library, %d skipped, %d invalid.',
            $stats['created'],
            $stats['reactivated'],
            $stats['existing'],
            $stats['library_present'],
            $stats['skipped'],
            $stats['invalid']
        );
        $this->log('Episode intent queueing: ' . $message);

        return [
            'success' => true,
            'stats' => $stats,
            'message' => $message,
        ];
    }

    public function autoQueueDue(int $intervalSeconds = 300): int
    {
        if (!$this->hasAutoDownloadSources() && !$this->hasAutoDownloadSeriesSources()) {
            return 0;
        }
        $last = (int) $this->store->getSetting('intents_auto_queue_last_at', 0);
        if ($last > 0 && (time() - $last) < max(60, $intervalSeconds)) {
            return 0;
        }
        $this->store->setSetting('intents_auto_queue_last_at', time());
        $movies = $this->queueMissingMovies(true);
        $episodes = $this->queueMissingEpisodes(true);

        return (int) ($movies['stats']['created'] ?? 0) + (int) ($episodes['stats']['created'] ?? 0);
    }

    public function shouldRunAutomatically(): bool
    {
        $backfill = $this->settings->getBackfill();
        $queueGuard = max(1, (int) ($backfill['queue_guard'] ?? 20));
        if ($this->getQueueDepth() >= $queueGuard) {
            if ($this->isRunRequested()) {
                $this->store->setSetting('intent_run_requested', false);
            }

            return false;
        }
        if ($this->isRunRequested()) {
            return true;
        }
        if (!$this->hasAutoDownloadSources() && !$this->hasAutoDownloadSeriesSources()) {
            return false;
        }
        $intentSettings = $this->settings->getIntentSettings();
        $interval = max(15, (int) ($intentSettings['min_interval_seconds'] ?? 60));
        $last = (int) $this->store->getSetting('intents_run_last_at', 0);
        if ($last > 0 && (time() - $last) < $interval) {
            return false;
        }

        return !empty($this->store->getPendingIntents(1));
    }

    public function isRunRequested(): bool
    {
        return (bool) $this->store->getSetting('intent_run_requested', false);
    }

    public function requestRun(): void
    {
        $this->store->setSetting('intent_run_requested', true);
    }

    public function startBackgroundRun(): array
    {
        $this->requestRun();
        $consolePath = $this->projectDir . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'console';
        $phpPath = PHP_BINARY;
        if (!str_contains($phpPath, 'bin/php') && !str_ends_with($phpPath, 'php.exe') && basename($phpPath) !== 'php') {
            $phpPath = 'php';
        }

        try {
            $process = new Process([$phpPath, $consolePath, 'app:release-intents', '--run']);
            $process->setWorkingDirectory($this->projectDir);
            $process->start();
        } catch (\Throwable $e) {
            $this->store->setSetting('intent_run_requested', false);

            return ['success' => false, 'message' => 'Could not start the automation run: ' . $e->getMessage()];
        }

        return ['success' => true, 'message' => 'Automation run requested.'];
    }

    public function getQueueDepth(): int
    {
        $queue = $this->storage->get('server_queue', []);
        $depth = is_array($queue) ? count($queue) : 0;
        $active = $this->storage->get('active_worker_task', null);
        if (is_array($active) && !empty($active)) {
            $depth++;
        }

        return $depth;
    }

    public function recoverStaleDownloads(int $hours = 2, bool $throttle = true): array
    {
        if ($throttle) {
            $last = (int) $this->store->getSetting('intents_stale_check_last_at', 0);
            if ($last > 0 && (time() - $last) < 300) {
                return ['success' => true, 'recovered' => 0, 'skipped' => 0, 'throttled' => true];
            }
            $this->store->setSetting('intents_stale_check_last_at', time());
        }

        $lock = $this->tryAcquireLock();
        if ($lock['error'] !== null) {
            return ['success' => false, 'message' => $lock['error'], 'recovered' => 0, 'skipped' => 0];
        }
        if ($lock['busy']) {
            return ['success' => true, 'recovered' => 0, 'skipped' => 0, 'busy' => true];
        }

        try {
            return $this->recoverStaleDownloadsLocked($hours);
        } finally {
            $this->releaseLock($lock['handle']);
        }
    }

    private function recoverStaleDownloadsLocked(int $hours = 2): array
    {
        $hours = max(1, $hours);
        $threshold = (new \DateTimeImmutable('-' . $hours . ' hours'))->format('Y-m-d H:i:s');
        $intents = $this->store->getStaleQueuedOrDownloadingIntents($threshold);
        if ($intents === []) {
            return ['success' => true, 'recovered' => 0, 'skipped' => 0];
        }

        $activeIds = [];
        $queue = $this->storage->get('server_queue', []);
        foreach (is_array($queue) ? $queue : [] as $item) {
            if (is_array($item) && !empty($item['download_id'])) {
                $activeIds[(string) $item['download_id']] = true;
            }
        }
        $active = $this->storage->get('active_worker_task', null);
        if (is_array($active) && !empty($active['download_id'])) {
            $activeIds[(string) $active['download_id']] = true;
        }
        $history = $this->storage->get('history', []);
        foreach (is_array($history) ? $history : [] as $entry) {
            if (is_array($entry) && !empty($entry['download_id'])) {
                $activeIds[(string) $entry['download_id']] = true;
            }
        }

        $intentSettings = $this->settings->getIntentSettings();
        $backoff = max(1, (int) ($intentSettings['retry_backoff_minutes'] ?? 60));
        $recovered = 0;
        $skipped = 0;
        foreach ($intents as $intent) {
            $downloadId = trim((string) ($intent['download_id'] ?? ''));
            if ($downloadId !== '' && isset($activeIds[$downloadId])) {
                $skipped++;
                continue;
            }

            $attempts = max(1, (int) ($intent['attempts'] ?? 0));
            $minutes = min(1440, $backoff * (2 ** max(0, $attempts - 1)));
            $nextAttempt = (new \DateTimeImmutable())->modify('+' . $minutes . ' minutes')->format('Y-m-d H:i:s');
            $this->store->updateIntent((int) $intent['id'], [
                'status' => 'pending',
                'next_attempt_at' => $nextAttempt,
                'download_id' => null,
                'info_hash' => null,
                'file_idx' => null,
                'error_message' => 'Stale queued/downloading task recovered; retry scheduled.',
            ]);
            $recovered++;
            $this->log('Intent #' . (int) $intent['id'] . ' (' . (string) ($intent['title'] ?? '') . ') recovered from stale ' . (string) ($intent['status'] ?? '') . '.');
        }

        return ['success' => true, 'recovered' => $recovered, 'skipped' => $skipped];
    }

    public function processDueIntents(?int $max = null, ?callable $heartbeat = null): array
    {
        $lock = $this->tryAcquireLock();
        if ($lock['error'] !== null) {
            return ['success' => false, 'message' => $lock['error'], 'stats' => []];
        }
        if ($lock['busy']) {
            return ['success' => false, 'message' => 'An intent run is already in progress.', 'stats' => []];
        }

        $started = microtime(true);
        $backfill = $this->settings->getBackfill();
        $intentSettings = $this->settings->getIntentSettings();
        $max = $max ?? (int) ($backfill['max_intents_per_run'] ?? 50);
        $max = max(1, min(200, $max));
        $queueGuard = max(1, (int) ($backfill['queue_guard'] ?? 20));
        $maxAttempts = max(1, (int) ($intentSettings['max_attempts'] ?? 5));

        $this->recoverStaleDownloadsLocked(2);
        $this->store->markStaleResolvingIntents((int) ($intentSettings['stale_resolving_minutes'] ?? 30));
        $queueDepth = $this->getQueueDepth();
        if ($queueDepth >= $queueGuard) {
            $this->store->setSetting('intent_run_requested', false);
            $this->releaseLock($lock['handle']);

            return [
                'success' => true,
                'message' => 'Queue guard reached (' . $queueDepth . '/' . $queueGuard . '); intent processing paused.',
                'stats' => ['queue_depth' => $queueDepth, 'intents_seen' => 0],
            ];
        }

        $stats = [
            'intents_seen' => 0,
            'intents_queued' => 0,
            'intents_skipped' => 0,
            'intents_no_source' => 0,
            'intents_error' => 0,
            'queue_depth' => $queueDepth,
            'max' => $max,
        ];
        $runId = $this->store->startIntentRun($stats);
        $this->store->setSetting('intents_run_last_at', time());

        try {
            $intents = $this->store->getPendingIntents($max);
            foreach ($intents as $intent) {
                if ($this->getQueueDepth() >= $queueGuard) {
                    break;
                }
                if ($heartbeat) {
                    $heartbeat();
                }
                if ((int) ($intent['attempts'] ?? 0) >= $maxAttempts && ($intent['next_attempt_at'] ?? null) === null) {
                    $stats['intents_skipped']++;
                    continue;
                }

                $result = $this->resolver->processIntent($intent, $heartbeat);
                $stats['intents_seen']++;
                $status = (string) ($result['status'] ?? 'skipped');
                if ($status === 'queued') {
                    $stats['intents_queued']++;
                } elseif ($status === 'no_source') {
                    $stats['intents_no_source']++;
                } elseif ($status === 'error') {
                    $stats['intents_error']++;
                } else {
                    $stats['intents_skipped']++;
                }
                $stats['queue_depth'] = $this->getQueueDepth();
                $this->store->updateIntentRunProgress($runId, $stats);
                $this->log(sprintf(
                    'Intent #%d (%s) => %s%s',
                    (int) ($intent['id'] ?? 0),
                    (string) ($intent['title'] ?? ''),
                    $status,
                    !empty($result['reason']) ? ': ' . $result['reason'] : ''
                ));

                if ($this->getQueueDepth() >= $queueGuard) {
                    break;
                }
            }

            $stats['duration'] = round(microtime(true) - $started, 2);
            $runStatus = ($stats['intents_error'] > 0 || $stats['intents_no_source'] > 0) ? 'warning' : 'success';
            $this->store->finishIntentRun($runId, $stats, $runStatus);
            $this->notifier->notifyIntentRun($stats);
            $this->store->setSetting('intent_run_requested', false);

            return ['success' => true, 'stats' => $stats, 'run_id' => $runId, 'message' => 'Intent run finished.'];
        } catch (\Throwable $e) {
            $stats['duration'] = round(microtime(true) - $started, 2);
            $this->store->finishIntentRun($runId, $stats, 'error', $e->getMessage());
            $this->notifier->notifyProblem('Automation run failed: ' . $e->getMessage(), '', 'error');
            $this->store->setSetting('intent_run_requested', false);

            return ['success' => false, 'stats' => $stats, 'run_id' => $runId, 'message' => $e->getMessage()];
        } finally {
            $this->releaseLock($lock['handle']);
        }
    }

    public function onDownloadStarted(array $item): void
    {
        $id = (int) ($item['automation_intent_id'] ?? 0);
        if ($id <= 0) {
            return;
        }
        $this->store->updateIntent($id, [
            'status' => 'downloading',
            'download_id' => $item['download_id'] ?? null,
            'error_message' => null,
        ]);
        $this->log('Intent #' . $id . ' download started (' . (string) ($item['filename'] ?? '') . ').');
    }

    public function onDownloadFinished(array $item, string $status, ?string $error = null): void
    {
        $id = (int) ($item['automation_intent_id'] ?? 0);
        if ($id <= 0) {
            return;
        }
        $intent = $this->store->getIntent($id);
        if ($intent === null) {
            return;
        }

        $mediaType = (string) ($intent['media_type'] ?? 'movie');
        $isSeries = in_array($mediaType, ['series', 'anime'], true);
        $label = trim((string) ($intent['title'] ?? $item['filename'] ?? 'Unknown'));
        if ($isSeries) {
            $season = isset($intent['season']) ? (int) $intent['season'] : null;
            $episode = isset($intent['episode']) ? (int) $intent['episode'] : null;
            if ($season !== null && $episode !== null) {
                $label = trim($label . ' ' . $this->episodeCode($season, $episode));
            }
        }

        if ($status === 'success') {
            $this->store->updateIntent($id, [
                'status' => 'success',
                'download_id' => $item['download_id'] ?? ($intent['download_id'] ?? null),
                'next_attempt_at' => null,
                'error_message' => null,
            ]);
            $this->store->setSetting('library_scan_requested', true);
            $this->store->setSetting(
                'library_scan_requested_root',
                $mediaType === 'anime' ? 'anime' : ($isSeries ? 'series' : 'movies')
            );
            $this->store->setSetting('library_resolve_requested', true);
            $this->store->setSetting('library_resolve_requested_at', time());
            $this->log('Intent #' . $id . ' download succeeded.');
            $this->notifier->queueDownloadSummary($isSeries ? 'series' : 'movie', 'success', $label);

            return;
        }

        $error = trim((string) $error);
        $attempts = max(1, (int) ($intent['attempts'] ?? 1));
        $intentSettings = $this->settings->getIntentSettings();
        $maxAttempts = max(1, (int) ($intentSettings['max_attempts'] ?? 5));
        $backoff = max(1, (int) ($intentSettings['retry_backoff_minutes'] ?? 60));
        $minutes = min(1440, $backoff * (2 ** max(0, $attempts - 1)));
        $nextAttempt = $attempts < $maxAttempts
            ? (new \DateTimeImmutable())->modify('+' . $minutes . ' minutes')->format('Y-m-d H:i:s')
            : null;

        $message = $error !== '' ? $error : 'Download interrupted or failed.';
        $this->store->updateIntent($id, [
            'status' => 'error',
            'next_attempt_at' => $nextAttempt,
            'error_message' => $message,
        ]);
        $this->log('Intent #' . $id . ' download failed: ' . $message);

        $lower = strtolower($message);
        if (str_contains($lower, 'no space left') || str_contains($lower, 'disk full')) {
            $alarm = 'Disk full while downloading ' . $label . '.';
        } elseif (str_contains($lower, 'interrupt') || str_contains($lower, 'resume')) {
            $alarm = 'Download interrupted for ' . $label . '.';
        } else {
            $alarm = 'Download failed for ' . $label . ': ' . $message;
        }
        $this->notifier->queueDownloadSummary($isSeries ? 'series' : 'movie', 'error', $label, $alarm);
    }

    public function flushDownloadSummary(bool $force = false): void
    {
        $this->notifier->flushDownloadSummary($force);
    }

    public function retryIntent(int $id): array
    {
        $intent = $this->store->getIntent($id);
        if ($intent === null) {
            return ['success' => false, 'message' => 'Intent not found.'];
        }
        $this->store->updateIntent($id, [
            'status' => 'pending',
            'attempts' => 0,
            'next_attempt_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'error_message' => null,
        ]);

        return ['success' => true, 'message' => 'Intent queued for retry.'];
    }

    public function ignoreIntent(int $id, bool $ignored = true): array
    {
        $intent = $this->store->getIntent($id);
        if ($intent === null) {
            return ['success' => false, 'message' => 'Intent not found.'];
        }
        $this->store->updateIntent($id, [
            'status' => $ignored ? 'ignored' : 'pending',
            'next_attempt_at' => $ignored ? null : (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'error_message' => $ignored ? ($intent['error_message'] ?? 'Ignored manually.') : null,
        ]);

        return ['success' => true, 'ignored' => $ignored];
    }

    public function restoreAllIgnored(): array
    {
        $restored = $this->store->restoreIgnoredIntents();
        if ($restored > 0) {
            $this->log('Restored ' . $restored . ' ignored intent(s).');
        }

        return [
            'success' => true,
            'restored' => $restored,
            'message' => $restored === 1
                ? '1 ignored intent restored.'
                : $restored . ' ignored intents restored.',
        ];
    }

    public function deleteIgnoredIntent(int $id): array
    {
        $intent = $this->store->getIntent($id);
        if ($intent === null) {
            return ['success' => false, 'reason' => 'not_found', 'message' => 'Intent not found.'];
        }
        if ((string) ($intent['status'] ?? '') !== 'ignored') {
            return ['success' => false, 'reason' => 'not_ignored', 'message' => 'Only ignored intents can be deleted.'];
        }
        if (!$this->store->deleteIgnoredIntent($id)) {
            return ['success' => false, 'reason' => 'delete_failed', 'message' => 'Could not delete this ignored intent.'];
        }
        $this->log('Deleted ignored intent #' . $id . '.');

        return ['success' => true, 'message' => 'Ignored intent deleted.'];
    }

    public function reconcileFromLists(bool $dryRun = false): array
    {
        $lock = $this->tryAcquireLock();
        if ($lock['error'] !== null) {
            return ['success' => false, 'message' => $lock['error'], 'stats' => []];
        }
        if ($lock['busy']) {
            return ['success' => false, 'message' => 'An intent operation is already running.', 'stats' => []];
        }

        $stats = [
            'enabled_sources' => 0,
            'desired_keys' => 0,
            'intents_seen' => 0,
            'kept_desired' => 0,
            'kept_protected' => 0,
            'skipped' => 0,
            'would_delete' => 0,
            'deleted' => 0,
            'warnings' => [],
        ];
        $candidates = [];

        try {
            $desiredInfo = $this->desiredMediaKeys();
            $stats['enabled_sources'] = (int) ($desiredInfo['enabled_sources'] ?? 0);
            $desired = $desiredInfo['keys'];
            $stats['desired_keys'] = count($desired);
            if ($stats['enabled_sources'] === 0) {
                return [
                    'success' => false,
                    'skipped' => true,
                    'message' => 'No enabled list source; intent reconciliation was skipped.',
                    'stats' => $stats,
                    'candidates' => [],
                ];
            }

            $protectedStatuses = ['success', 'resolving', 'queued', 'downloading'];
            $actionableStatuses = ['pending', 'no_source', 'error', 'ignored'];

            foreach ($this->store->getAllIntentsForReconcile() as $intent) {
                $stats['intents_seen']++;
                $status = (string) ($intent['status'] ?? '');
                if (in_array($status, $protectedStatuses, true)) {
                    $stats['kept_protected']++;
                    continue;
                }
                if (!in_array($status, $actionableStatuses, true)) {
                    $stats['skipped']++;
                    continue;
                }

                $mediaKey = $this->intentMediaKey($intent);
                if ($mediaKey === null) {
                    $stats['skipped']++;
                    $stats['warnings'][] = 'Intent #' . (int) ($intent['id'] ?? 0) . ' has no usable media key; kept.';
                    continue;
                }
                if (isset($desired[$mediaKey])) {
                    $stats['kept_desired']++;
                    continue;
                }

                $candidates[] = [
                    'id' => (int) ($intent['id'] ?? 0),
                    'status' => $status,
                    'media_key' => $mediaKey,
                    'title' => (string) ($intent['title'] ?? ''),
                    'season' => $intent['season'] !== null ? (int) $intent['season'] : null,
                    'episode' => $intent['episode'] !== null ? (int) $intent['episode'] : null,
                ];
            }

            $stats['would_delete'] = count($candidates);
            if (!$dryRun && $candidates !== []) {
                $stats['deleted'] = $this->store->deleteIntentsByIds(array_column($candidates, 'id'));
                $this->log('Intent reconciliation deleted ' . $stats['deleted'] . ' non-desired intent(s).');
            }

            return [
                'success' => true,
                'dry_run' => $dryRun,
                'message' => $dryRun
                    ? $stats['would_delete'] . ' intent(s) would be deleted.'
                    : $stats['deleted'] . ' intent(s) deleted.',
                'stats' => $stats,
                'candidates' => $candidates,
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage(), 'stats' => $stats, 'candidates' => $candidates];
        } finally {
            $this->releaseLock($lock['handle']);
        }
    }

    private function desiredMediaKeys(): array
    {
        $desired = [];
        $enabledSources = 0;

        foreach ($this->settings->getSources() as $source) {
            if (!is_array($source) || empty($source['enabled'])) {
                continue;
            }
            $sourceId = (string) ($source['id'] ?? $source['provider'] ?? '');
            if ($sourceId === '') {
                continue;
            }
            $enabledSources++;
            $config = is_array($source['config'] ?? null) ? $source['config'] : [];
            $listIds = array_values(array_filter(array_map(
                static fn ($value) => trim((string) $value),
                is_array($config['list_ids'] ?? null) ? $config['list_ids'] : []
            )));
            $items = $this->store->getListItems(['status' => 'missing', 'source' => $sourceId], 100000);

            foreach ($items as $item) {
                if (!is_array($item) || !empty($item['ignored_at']) || (string) ($item['match_status'] ?? '') !== 'missing') {
                    continue;
                }
                $listRef = (string) ($item['list_ref'] ?? '');
                if ($listIds !== [] && $listRef !== '' && !in_array($listRef, $listIds, true)) {
                    continue;
                }

                $mediaType = (string) ($item['media_type'] ?? 'movie');
                $tmdbId = !empty($item['tmdb_id']) ? (int) $item['tmdb_id'] : null;
                $imdbId = trim((string) ($item['imdb_id'] ?? ''));
                $title = trim((string) ($item['title'] ?? ''));
                $year = !empty($item['year']) ? (int) $item['year'] : null;
                $titleNormalized = trim((string) ($item['title_normalized'] ?? ''));
                if ($titleNormalized === '') {
                    $titleNormalized = $this->normalizeTitle($title);
                }

                if (in_array($mediaType, ['series', 'anime', 'episode'], true)) {
                    $missing = json_decode((string) ($item['missing_json'] ?? '[]'), true);
                    $missing = is_array($missing) ? $missing : [];
                    if ($missing === [] && $item['season'] !== null && $item['episode'] !== null) {
                        $missing[] = $this->episodeCode((int) $item['season'], (int) $item['episode']);
                    }
                    foreach ($missing as $code) {
                        $parsed = $this->parseEpisodeCode((string) $code);
                        if ($parsed === null) {
                            continue;
                        }
                        if ($tmdbId === null && $imdbId === '' && $titleNormalized === '') {
                            continue;
                        }
                        $desired[$this->episodeMediaKey($tmdbId, $imdbId, $titleNormalized, $year, $parsed[0], $parsed[1])] = true;
                    }
                    continue;
                }

                if ($tmdbId === null && $imdbId === '' && $titleNormalized === '') {
                    continue;
                }
                $desired[$this->movieMediaKey($tmdbId, $imdbId, $titleNormalized, $year)] = true;
            }
        }

        return ['enabled_sources' => $enabledSources, 'keys' => $desired];
    }

    private function intentMediaKey(array $intent): ?string
    {
        $mediaType = (string) ($intent['media_type'] ?? 'movie');
        $tmdbId = !empty($intent['tmdb_id']) ? (int) $intent['tmdb_id'] : null;
        $imdbId = trim((string) ($intent['imdb_id'] ?? ''));
        $titleNormalized = $this->normalizeTitle((string) ($intent['title'] ?? ''));
        $year = !empty($intent['year']) ? (int) $intent['year'] : null;

        if (in_array($mediaType, ['series', 'anime', 'episode'], true)) {
            if ($intent['season'] === null || $intent['episode'] === null) {
                return null;
            }
            if ($tmdbId === null && $imdbId === '' && $titleNormalized === '') {
                return null;
            }

            return $this->episodeMediaKey($tmdbId, $imdbId, $titleNormalized, $year, (int) $intent['season'], (int) $intent['episode']);
        }

        if ($tmdbId === null && $imdbId === '' && $titleNormalized === '') {
            return null;
        }

        return $this->movieMediaKey($tmdbId, $imdbId, $titleNormalized, $year);
    }

    private function movieMediaKey(?int $tmdbId, string $imdbId, string $titleNormalized, ?int $year): string
    {
        if ($tmdbId !== null && $tmdbId > 0) {
            return 'movie:tmdb:' . $tmdbId;
        }
        if ($imdbId !== '') {
            return 'movie:imdb:' . $imdbId;
        }

        return 'movie:title:' . $titleNormalized . ':' . ($year ?? 'unknown');
    }

    private function tryAcquireLock(): array
    {
        $lockFile = $this->store->getStorageDir() . DIRECTORY_SEPARATOR . 'automation_intents.lock';
        if (!is_dir(dirname($lockFile))) {
            mkdir(dirname($lockFile), 0777, true);
        }
        $handle = @fopen($lockFile, 'c+');
        if (!$handle) {
            $error = error_get_last();

            return ['handle' => null, 'busy' => false, 'error' => 'Unable to open intent lock: ' . ($error['message'] ?? $lockFile)];
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

    private function log(string $message): void
    {
        $file = $this->store->getStorageDir() . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'automation_worker.log';
        LogRotator::append($file, '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n");
    }

    private function episodeMediaKey(?int $tmdbId, string $imdbId, string $titleNormalized, ?int $year, int $season, int $episode): string
    {
        $episodeCode = 's' . str_pad((string) $season, 2, '0', STR_PAD_LEFT) . 'e' . str_pad((string) $episode, 2, '0', STR_PAD_LEFT);
        if ($tmdbId !== null && $tmdbId > 0) {
            return 'series:tmdb:' . $tmdbId . ':' . $episodeCode;
        }
        if ($imdbId !== '') {
            return 'series:imdb:' . $imdbId . ':' . $episodeCode;
        }

        return 'series:title:' . $titleNormalized . ':' . ($year ?? 'unknown') . ':' . $episodeCode;
    }

    private function parseEpisodeCode(string $code): ?array
    {
        if (!preg_match('/^S(\d{1,3})E(\d{1,4})$/i', trim($code), $matches)) {
            return null;
        }

        return [(int) $matches[1], (int) $matches[2]];
    }

    private function episodeCode(int $season, int $episode): string
    {
        return 'S' . str_pad((string) $season, 2, '0', STR_PAD_LEFT)
            . 'E' . str_pad((string) $episode, 2, '0', STR_PAD_LEFT);
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
