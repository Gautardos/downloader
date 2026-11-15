<?php

namespace App\Service\Automation;

use App\Service\TmdbService;
use Symfony\Component\Process\Process;

class LibraryMetadataResolver
{
    private AutomationStore $store;
    private AutomationSettings $settings;
    private TmdbService $tmdb;
    private string $projectDir;

    public function __construct(
        AutomationStore $store,
        AutomationSettings $settings,
        TmdbService $tmdb,
        string $projectDir
    ) {
        $this->store = $store;
        $this->settings = $settings;
        $this->tmdb = $tmdb;
        $this->projectDir = $projectDir;
    }

    public function isConfigured(): bool
    {
        return $this->tmdb->isConfigured();
    }

    public function requestBackgroundResolve(): array
    {
        if (!$this->tmdb->isConfigured()) {
            return ['success' => false, 'message' => 'TMDB is not configured.'];
        }

        $this->store->setSetting('library_resolve_requested', true);
        $this->store->setSetting('library_resolve_requested_at', time());

        $consolePath = $this->projectDir . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'console';
        $phpPath = PHP_BINARY;
        if (!str_contains($phpPath, 'bin/php') && !str_ends_with($phpPath, 'php.exe') && basename($phpPath) !== 'php') {
            $phpPath = 'php';
        }

        try {
            $process = new Process([$phpPath, $consolePath, 'app:library-resolve']);
            $process->setWorkingDirectory($this->projectDir);
            $process->start();
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Could not start the automation worker: ' . $e->getMessage()];
        }

        return ['success' => true, 'message' => 'Metadata resolution requested.'];
    }

    public function resolve(?int $limit = null, ?callable $onProgress = null, ?callable $onHeartbeat = null): array
    {
        if (!$this->tmdb->isConfigured()) {
            return ['success' => false, 'message' => 'TMDB is not configured.'];
        }

        $lockState = $this->tryAcquireLock();
        if ($lockState['error'] !== null) {
            return ['success' => false, 'message' => $lockState['error']];
        }
        if ($lockState['busy']) {
            return ['success' => false, 'message' => 'A metadata resolution is already running.'];
        }

        $lock = $lockState['handle'];
        $metadataSettings = $this->settings->get('metadata', []);
        $limit = $limit ?? (int) ($metadataSettings['max_lookups_per_run'] ?? 50);
        $limit = max(1, min(500, $limit));
        $positiveTtl = max(3600, (int) ($metadataSettings['cache_positive_days'] ?? 30) * 86400);
        $negativeTtl = max(3600, (int) ($metadataSettings['cache_negative_days'] ?? 2) * 86400);

        $groups = $this->store->getUnresolvedGroups($limit);
        $stats = [
            'groups_seen' => 0,
            'groups_resolved' => 0,
            'entries_resolved' => 0,
            'groups_skipped' => 0,
            'lookups' => 0,
            'skipped_reasons' => [],
            'errors' => [],
            'limit' => $limit,
        ];
        $started = microtime(true);
        $runId = $this->store->startResolutionRun($stats);

        try {
            foreach ($groups as $group) {
                if ($onHeartbeat) {
                    $onHeartbeat();
                }

                $stats['groups_seen']++;
                $type = $this->tmdbType((string) $group['media_type']);

                if ($type === 'series' && (int) ($group['folders'] ?? 0) !== 1) {
                    $this->skip($stats, 'ambiguous_show_folder');
                    if ($onProgress) {
                        $onProgress($stats);
                    }
                    continue;
                }

                $cacheKey = $this->cacheKey($group);
                $cached = $this->store->getMetadataCache($cacheKey);
                if ($cached !== null) {
                    if (($cached['status'] ?? '') === 'resolved' && !empty($cached['tmdb_id'])) {
                        $entries = $this->applyMatch($group, [
                            'id' => (int) $cached['tmdb_id'],
                            'imdb_id' => $cached['imdb_id'] ?? null,
                            'year' => $cached['year'] ?? null,
                            'title' => $cached['title'] ?? $group['title'],
                        ]);
                        if ($entries > 0) {
                            $stats['groups_resolved']++;
                            $stats['entries_resolved'] += $entries;
                        } else {
                            $this->skip($stats, 'cache_no_entries');
                        }
                    } else {
                        $this->skip($stats, 'cached_unresolved');
                    }
                    if ($onProgress) {
                        $onProgress($stats);
                    }
                    continue;
                }

                $match = $this->lookup($group);
                $stats['lookups']++;

                if ($match === null) {
                    $this->store->setMetadataCache($cacheKey, ['status' => 'unresolved', 'reason' => 'no_confident_match'], $negativeTtl);
                    $this->skip($stats, 'no_confident_match');
                    if ($onProgress) {
                        $onProgress($stats);
                    }
                    continue;
                }

                $details = $this->tmdb->details($type, (int) $match['id'], $match['language'] ?? null);
                if (!$details) {
                    $this->skip($stats, 'details_unavailable');
                    if ($onProgress) {
                        $onProgress($stats);
                    }
                    continue;
                }

                $detailsTitleScore = max(
                    $this->titleScore((string) ($group['title'] ?? ''), (string) ($details['title'] ?? '')),
                    $this->titleScore((string) ($group['title'] ?? ''), (string) ($details['original_title'] ?? ''))
                );
                if ($detailsTitleScore < 85) {
                    $this->store->setMetadataCache($cacheKey, ['status' => 'unresolved', 'reason' => 'details_title_mismatch'], $negativeTtl);
                    $this->skip($stats, 'details_title_mismatch');
                    if ($onProgress) {
                        $onProgress($stats);
                    }
                    continue;
                }

                if ($group['year'] !== null && !empty($details['year']) && abs((int) $group['year'] - (int) $details['year']) > 1) {
                    $this->store->setMetadataCache($cacheKey, ['status' => 'unresolved', 'reason' => 'details_year_mismatch'], $negativeTtl);
                    $this->skip($stats, 'details_year_mismatch');
                    if ($onProgress) {
                        $onProgress($stats);
                    }
                    continue;
                }

                $entries = $this->applyMatch($group, $details);
                $this->store->setMetadataCache($cacheKey, [
                    'status' => 'resolved',
                    'tmdb_id' => (int) $details['id'],
                    'imdb_id' => $details['imdb_id'] ?? null,
                    'year' => $details['year'] ?? null,
                    'title' => $details['title'] ?? null,
                    'type' => $type,
                ], $positiveTtl);

                if ($entries > 0) {
                    $stats['groups_resolved']++;
                    $stats['entries_resolved'] += $entries;
                } else {
                    $this->skip($stats, 'resolved_no_entries');
                }

                if ($onProgress) {
                    $onProgress($stats);
                }
            }

            $stats['duration'] = round(microtime(true) - $started, 2);
            $stats['finished_at'] = date('Y-m-d H:i:s');
            $stats['success'] = true;
            $this->store->setSetting('library_resolution_last', $stats);
            $this->store->finishResolutionRun($runId, $stats, 'success');

            return ['success' => true, 'stats' => $stats];
        } catch (\Throwable $e) {
            $stats['duration'] = round(microtime(true) - $started, 2);
            $stats['finished_at'] = date('Y-m-d H:i:s');
            $stats['success'] = false;
            $stats['error'] = $e->getMessage();
            $this->store->setSetting('library_resolution_last', $stats);
            $this->store->finishResolutionRun($runId, $stats, 'error', $e->getMessage());
            throw $e;
        } finally {
            $this->store->setSetting('library_resolve_requested', false);
            $this->releaseLock($lock);
        }
    }

    public function searchCandidates(array $group, int $limit = 20, ?string $query = null): array
    {
        if (!$this->tmdb->isConfigured()) {
            return [];
        }

        $type = $this->tmdbType((string) ($group['media_type'] ?? 'series'));
        $title = trim((string) ($group['title'] ?? $group['title_normalized'] ?? ''));
        $titleNormalized = (string) ($group['title_normalized'] ?? $title);
        if ($query !== null && trim($query) !== '') {
            $title = trim($query);
            $titleNormalized = $title;
        }
        if ($title === '' && $titleNormalized === '') {
            return [];
        }

        $languages = [$this->tmdb->getLanguage()];
        if (!in_array('en-US', $languages, true)) {
            $languages[] = 'en-US';
        }

        $candidates = [];
        $seen = [];
        foreach ($languages as $language) {
            $results = $this->tmdb->search($title !== '' ? $title : $titleNormalized, $type, null, $language);
            if (empty($results) && $title !== $titleNormalized) {
                $results = $this->tmdb->search($titleNormalized, $type, null, $language);
            }

            foreach ($results as $result) {
                if (($result['type'] ?? '') !== $type) {
                    continue;
                }
                $id = (int) ($result['id'] ?? 0);
                if ($id <= 0 || isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                $candidates[] = [
                    'id' => $id,
                    'type' => $type,
                    'title' => (string) ($result['title'] ?? 'Unknown'),
                    'original_title' => $result['original_title'] ?? null,
                    'year' => !empty($result['year']) ? (int) $result['year'] : null,
                    'poster' => $result['poster'] ?? null,
                    'rating' => $result['rating'] ?? null,
                    'overview' => (string) ($result['overview'] ?? ''),
                ];
                if (count($candidates) >= $limit) {
                    return $candidates;
                }
            }
        }

        return $candidates;
    }

    public function resolveGroupManually(array $group, int $tmdbId, ?string $type = null): array
    {
        if (!$this->tmdb->isConfigured()) {
            return ['success' => false, 'message' => 'TMDB is not configured.'];
        }

        $mediaType = (string) ($group['media_type'] ?? '');
        if (!in_array($mediaType, ['movie', 'series', 'anime'], true)) {
            return ['success' => false, 'message' => 'Invalid media type.'];
        }

        $type = $type ?: $this->tmdbType($mediaType);
        if (!in_array($type, ['movie', 'series'], true) || $type !== $this->tmdbType($mediaType)) {
            return ['success' => false, 'message' => 'Invalid TMDB type for this group.'];
        }
        if ($tmdbId <= 0) {
            return ['success' => false, 'message' => 'Missing TMDB ID.'];
        }

        $details = $this->tmdb->details($type, $tmdbId);
        if (!$details) {
            return ['success' => false, 'message' => 'Could not load TMDB details.'];
        }

        $entries = $this->applyMatch($group, $details, 'manual', true);
        if ($entries === 0) {
            return ['success' => false, 'message' => 'No active or missing library entry matches this group.'];
        }
        $this->store->ignoreGroup($group, false);

        return [
            'success' => true,
            'entries' => $entries,
            'media_key' => $this->buildMediaKey($type, $tmdbId, [
                'season' => null,
                'episode' => null,
            ]),
            'details' => [
                'id' => (int) $details['id'],
                'title' => $details['title'] ?? null,
                'year' => $details['year'] ?? null,
                'imdb_id' => $details['imdb_id'] ?? null,
            ],
        ];
    }

    public function resolveShowFolderManually(array $group, int $tmdbId, ?string $type = null): array
    {
        if (!$this->tmdb->isConfigured()) {
            return ['success' => false, 'message' => 'TMDB is not configured.'];
        }

        $mediaType = (string) ($group['media_type'] ?? '');
        $showFolder = trim((string) ($group['show_folder'] ?? ''));
        if (!in_array($mediaType, ['series', 'anime'], true) || $showFolder === '') {
            return ['success' => false, 'message' => 'Invalid show folder group.'];
        }
        if ($tmdbId <= 0) {
            return ['success' => false, 'message' => 'Missing TMDB ID.'];
        }
        if ($type !== null && $type !== 'series') {
            return ['success' => false, 'message' => 'A series group must use the series TMDB type.'];
        }

        $details = $this->tmdb->details('series', $tmdbId);
        if (!$details) {
            return ['success' => false, 'message' => 'Could not load TMDB details.'];
        }

        $entries = $this->store->getEntriesByShowFolder($mediaType, $showFolder);
        if (empty($entries)) {
            return ['success' => false, 'message' => 'No active entry found in this show folder.'];
        }

        $year = !empty($details['year']) ? (int) $details['year'] : null;
        $imdbId = !empty($details['imdb_id']) ? (string) $details['imdb_id'] : null;
        $updated = 0;
        foreach ($entries as $entry) {
            $mediaKey = $this->buildMediaKey('series', $tmdbId, $entry);
            $this->store->updateEntryResolution(
                (int) $entry['id'],
                $tmdbId,
                $imdbId,
                $year,
                $mediaKey,
                'manual'
            );
            $updated++;
        }

        $this->store->unignoreByShowFolder($mediaType, $showFolder);

        return [
            'success' => true,
            'entries' => $updated,
            'details' => [
                'id' => (int) $details['id'],
                'title' => $details['title'] ?? null,
                'year' => $details['year'] ?? null,
                'imdb_id' => $details['imdb_id'] ?? null,
            ],
        ];
    }

    private function lookup(array $group): ?array
    {
        $type = $this->tmdbType((string) $group['media_type']);
        $title = trim((string) ($group['title'] ?? $group['title_normalized'] ?? ''));
        if ($title === '') {
            return null;
        }

        $languages = [$this->tmdb->getLanguage()];
        if (!in_array('en-US', $languages, true)) {
            $languages[] = 'en-US';
        }

        foreach ($languages as $language) {
            $results = $this->tmdb->search($title, $type, null, $language);
            if (empty($results) && $title !== (string) $group['title_normalized']) {
                $results = $this->tmdb->search((string) $group['title_normalized'], $type, null, $language);
            }

            $candidate = $this->selectCandidate($results, $group, $type);
            if ($candidate !== null) {
                $candidate['language'] = $language;
                return $candidate;
            }
        }

        return null;
    }

    private function selectCandidate(array $results, array $group, string $type): ?array
    {
        $title = trim((string) ($group['title'] ?? $group['title_normalized'] ?? ''));
        $targetYear = $group['year'] !== null ? (int) $group['year'] : null;
        $candidates = [];

        foreach ($results as $result) {
            if (($result['type'] ?? '') !== $type) {
                continue;
            }

            $titleScore = max(
                $this->titleScore($title, (string) ($result['title'] ?? '')),
                $this->titleScore($title, (string) ($result['original_title'] ?? ''))
            );
            if ($titleScore < 85) {
                continue;
            }

            $resultYear = !empty($result['year']) ? (int) $result['year'] : null;
            $yearScore = 0;
            if ($targetYear !== null) {
                if ($resultYear === null) {
                    continue;
                }
                $difference = abs($targetYear - $resultYear);
                if ($difference === 0) {
                    $yearScore = 50;
                } elseif ($difference === 1) {
                    $yearScore = 20;
                } else {
                    continue;
                }
            }

            $candidates[] = [
                'id' => (int) $result['id'],
                'type' => $type,
                'title' => (string) ($result['title'] ?? ''),
                'year' => $resultYear,
                'title_score' => $titleScore,
                'score' => $titleScore + $yearScore + min(10, (float) ($result['rating'] ?? 0)),
            ];
        }

        if (empty($candidates)) {
            return null;
        }

        if ($targetYear === null) {
            $exactCandidates = array_values(array_filter(
                $candidates,
                static fn ($candidate) => $candidate['title_score'] >= 95
            ));

            if (!empty($exactCandidates)) {
                $exactYears = [];
                foreach ($exactCandidates as $candidate) {
                    $exactYears[(string) ($candidate['year'] ?? 'unknown')] = true;
                }
                if (count($exactYears) > 1) {
                    if ($type !== 'series') {
                        return null;
                    }

                    $entries = $this->store->getEntriesForResolution(
                        (string) $group['media_type'],
                        (string) $group['title_normalized'],
                        null
                    );
                    $plausible = [];
                    foreach ($exactCandidates as $candidate) {
                        $details = $this->tmdb->details($type, $candidate['id']);
                        if ($details && $this->matchesLibraryStructure($details, $entries)) {
                            $plausible[] = $candidate;
                        }
                    }

                    if (count($plausible) !== 1) {
                        return null;
                    }

                    return $plausible[0];
                }
                $candidates = $exactCandidates;
            } else {
                $years = [];
                foreach ($candidates as $candidate) {
                    $years[(string) ($candidate['year'] ?? 'unknown')] = true;
                }
                if (count($years) > 1) {
                    return null;
                }
            }
        }

        usort($candidates, static fn ($a, $b) => $b['score'] <=> $a['score']);

        return $candidates[0];
    }

    private function applyMatch(array $group, array $details, string $source = 'tmdb', bool $includeMissing = false): int
    {
        $showFolder = isset($group['show_folder']) && $group['show_folder'] !== '' ? (string) $group['show_folder'] : null;
        $entries = $this->store->getEntriesForResolution(
            (string) $group['media_type'],
            (string) $group['title_normalized'],
            $group['year'] !== null ? (int) $group['year'] : null,
            $showFolder,
            $includeMissing
        );

        $tmdbId = (int) ($details['id'] ?? 0);
        if ($tmdbId <= 0) {
            return 0;
        }

        $imdbId = !empty($details['imdb_id']) ? (string) $details['imdb_id'] : null;
        $year = !empty($details['year']) ? (int) $details['year'] : ($group['year'] !== null ? (int) $group['year'] : null);
        $type = $this->tmdbType((string) $group['media_type']);

        $updated = 0;
        foreach ($entries as $entry) {
            $mediaKey = $this->buildMediaKey($type, $tmdbId, $entry);
            $this->store->updateEntryResolution((int) $entry['id'], $tmdbId, $imdbId, $year, $mediaKey, $source);
            $updated++;
        }

        return $updated;
    }

    private function buildMediaKey(string $type, int $tmdbId, array $entry): string
    {
        if ($type === 'movie') {
            return 'movie:tmdb:' . $tmdbId;
        }

        $key = 'series:tmdb:' . $tmdbId;
        $season = $entry['season'] !== null ? (int) $entry['season'] : null;
        $episode = $entry['episode'] !== null ? (int) $entry['episode'] : null;

        if ($season !== null && $episode !== null) {
            return $key . ':s' . str_pad((string) $season, 2, '0', STR_PAD_LEFT) . 'e' . str_pad((string) $episode, 2, '0', STR_PAD_LEFT);
        }
        if ($episode !== null) {
            return $key . ':e' . str_pad((string) $episode, 2, '0', STR_PAD_LEFT);
        }

        return $key;
    }

    private function matchesLibraryStructure(array $details, array $entries): bool
    {
        $seasons = [];
        foreach ($details['seasons'] ?? [] as $season) {
            if (!isset($season['season_number'])) {
                continue;
            }
            $seasons[(int) $season['season_number']] = (int) ($season['episode_count'] ?? 0);
        }
        if (empty($seasons)) {
            return false;
        }

        $checked = 0;
        foreach ($entries as $entry) {
            if ($entry['season'] === null || $entry['episode'] === null) {
                continue;
            }

            $season = (int) $entry['season'];
            $episode = (int) $entry['episode'];
            if (!isset($seasons[$season]) || $seasons[$season] < $episode) {
                return false;
            }
            $checked++;
        }

        return $checked > 0;
    }

    private function titleScore(string $target, string $candidate): int
    {
        $target = $this->normalizeTitle($target);
        $candidate = $this->normalizeTitle($candidate);
        if ($target === '' || $candidate === '') {
            return 0;
        }
        if ($target === $candidate) {
            return 100;
        }
        if (str_contains($candidate, $target) || str_contains($target, $candidate)) {
            return 85;
        }

        $simplifiedTarget = $this->simplifyTitle($target);
        $simplifiedCandidate = $this->simplifyTitle($candidate);
        if ($simplifiedTarget !== '' && $simplifiedTarget === $simplifiedCandidate) {
            return 92;
        }
        if ($simplifiedTarget !== '' && (str_contains($simplifiedCandidate, $simplifiedTarget) || str_contains($simplifiedTarget, $simplifiedCandidate))) {
            return 88;
        }

        if (preg_match('/^[\x20-\x7E]+$/', $target) && preg_match('/^[\x20-\x7E]+$/', $candidate)) {
            similar_text($target, $candidate, $percent);
            if ($percent >= 90) {
                return (int) round(80 + ($percent - 90));
            }
        }

        return 0;
    }

    private function simplifyTitle(string $title): string
    {
        $words = preg_split('/\s+/', $title) ?: [];
        $stopWords = ['the', 'a', 'an', 'movie', 'film'];
        $words = array_values(array_filter(
            $words,
            static fn ($word) => $word !== '' && !in_array($word, $stopWords, true)
        ));

        return implode(' ', $words);
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
        $title = preg_replace('/\s+/u', ' ', $title) ?? $title;

        return trim($title);
    }

    private function tmdbType(string $mediaType): string
    {
        return $mediaType === 'movie' ? 'movie' : 'series';
    }

    private function cacheKey(array $group): string
    {
        return strtolower((string) $group['media_type'])
            . '|' . (string) $group['title_normalized']
            . '|' . ($group['year'] !== null ? (int) $group['year'] : 'unknown');
    }

    private function skip(array &$stats, string $reason): void
    {
        $stats['groups_skipped']++;
        $stats['skipped_reasons'][$reason] = ($stats['skipped_reasons'][$reason] ?? 0) + 1;
    }

    private function tryAcquireLock(): array
    {
        $lockFile = $this->store->getStorageDir() . DIRECTORY_SEPARATOR . 'automation_resolve.lock';
        if (!is_dir(dirname($lockFile))) {
            mkdir(dirname($lockFile), 0777, true);
        }

        $handle = @fopen($lockFile, 'c+');
        if (!$handle) {
            $error = error_get_last();

            return [
                'handle' => null,
                'busy' => false,
                'error' => 'Unable to open metadata resolution lock: ' . ($error['message'] ?? $lockFile),
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
