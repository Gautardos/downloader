<?php

namespace App\Service\Automation;

use App\Service\JsonStorage;

class ReleaseResolverService
{
    private const MAX_CANDIDATES = 5;

    private AutomationStore $store;
    private AutomationSettings $settings;
    private ReleasePreferenceService $preferences;
    private ReleaseStreamProviderInterface $streams;
    private ReleaseDownloaderInterface $downloader;
    private MovieIdentityResolverInterface $identityResolver;
    private SeriesIdentityResolverInterface $seriesIdentityResolver;
    private JsonStorage $storage;
    private ?EpisodeNumberingService $numbering;

    public function __construct(
        AutomationStore $store,
        AutomationSettings $settings,
        ReleasePreferenceService $preferences,
        ReleaseStreamProviderInterface $streams,
        ReleaseDownloaderInterface $downloader,
        MovieIdentityResolverInterface $identityResolver,
        SeriesIdentityResolverInterface $seriesIdentityResolver,
        JsonStorage $storage,
        ?EpisodeNumberingService $numbering = null
    ) {
        $this->store = $store;
        $this->settings = $settings;
        $this->preferences = $preferences;
        $this->streams = $streams;
        $this->downloader = $downloader;
        $this->identityResolver = $identityResolver;
        $this->seriesIdentityResolver = $seriesIdentityResolver;
        $this->storage = $storage;
        $this->numbering = $numbering;
    }

    public function processIntent(array $intent, ?callable $heartbeat = null): array
    {
        $mediaType = (string) ($intent['media_type'] ?? 'movie');
        if (in_array($mediaType, ['series', 'anime'], true)) {
            return $this->processSeriesIntent($intent, $heartbeat);
        }
        if ($mediaType !== 'movie') {
            return ['status' => 'skipped', 'reason' => 'unsupported_media_type'];
        }

        return $this->processMovieIntent($intent, $heartbeat);
    }

    private function processMovieIntent(array $intent, ?callable $heartbeat = null): array
    {
        $id = (int) ($intent['id'] ?? 0);
        if ($id <= 0) {
            return ['status' => 'skipped', 'reason' => 'invalid_intent'];
        }

        $attempts = (int) ($intent['attempts'] ?? 0) + 1;
        $this->store->updateIntent($id, [
            'status' => 'resolving',
            'attempts' => $attempts,
            'error_message' => null,
            'next_attempt_at' => null,
        ]);
        $intent['attempts'] = $attempts;

        $identity = $this->identityResolver->resolve($intent);
        if (empty($identity['success'])) {
            $reason = (string) ($identity['reason'] ?? 'identity_unresolved');
            $noSourceReasons = ['missing_identity', 'identity_not_found', 'ambiguous_identity'];
            $status = in_array($reason, $noSourceReasons, true) ? 'no_source' : 'error';

            return $this->scheduleRetry($intent, $status, 'Identity: ' . $reason);
        }

        $tmdbId = !empty($identity['tmdb_id']) ? (int) $identity['tmdb_id'] : null;
        $imdbId = trim((string) ($identity['imdb_id'] ?? ''));
        $title = trim((string) ($identity['title'] ?? $intent['title'] ?? ''));
        $year = !empty($identity['year']) ? (int) $identity['year'] : (!empty($intent['year']) ? (int) $intent['year'] : null);
        $mediaKey = $this->mediaKey($tmdbId, $imdbId, $title, $year);

        $this->store->updateIntent($id, [
            'tmdb_id' => $tmdbId,
            'imdb_id' => $imdbId !== '' ? $imdbId : null,
            'title' => $title !== '' ? $title : ($intent['title'] ?? ''),
            'year' => $year,
            'media_key' => $mediaKey,
        ]);

        if ($this->store->hasActiveMovieEntry($tmdbId, $imdbId !== '' ? $imdbId : null, $this->normalizeTitle($title), $year)) {
            $this->store->updateIntent($id, [
                'status' => 'success',
                'next_attempt_at' => null,
                'error_message' => 'Already present in the library.',
            ]);

            return ['status' => 'skipped', 'reason' => 'already_present', 'intent_id' => $id];
        }

        if ($queued = $this->findQueuedDownload($id, $mediaKey, $title, $year)) {
            $this->store->updateIntent($id, [
                'status' => 'queued',
                'download_id' => $queued['download_id'] ?? null,
                'info_hash' => $queued['info_hash'] ?? null,
                'next_attempt_at' => null,
                'error_message' => 'Already queued.',
            ]);

            return ['status' => 'skipped', 'reason' => 'already_queued', 'intent_id' => $id];
        }

        if (!preg_match('/^tt\d+$/', $imdbId)) {
            return $this->scheduleRetry($intent, 'no_source', 'No IMDb ID available for release lookup.');
        }

        if ($heartbeat) {
            $heartbeat();
        }
        $result = $this->streams->getStreams('movie', $imdbId);
        $streams = is_array($result['streams'] ?? null) ? $result['streams'] : [];
        if (empty($streams)) {
            $errors = is_array($result['errors'] ?? null) ? $result['errors'] : [];
            $reason = empty($errors) ? 'No stream returned by the configured addons.' : 'No usable stream returned by the configured addons.';

            return $this->scheduleRetry($intent, 'no_source', $reason);
        }

        $ranked = $this->preferences->rank($streams);
        $candidates = array_slice($ranked['accepted'], 0, self::MAX_CANDIDATES);
        if (empty($candidates)) {
            $failure = $ranked['rejected'][0]['evaluation']['failures'] ?? [];

            return $this->scheduleRetry(
                $intent,
                'no_source',
                'No stream passed the quality profile' . (!empty($failure) ? ' (' . implode(', ', $failure) . ')' : '') . '.'
            );
        }

        $destination = $this->movieDestination();
        if ($destination === '') {
            return $this->scheduleRetry($intent, 'error', 'No movie destination path configured.');
        }

        $errors = [];
        foreach ($candidates as $candidate) {
            if ($heartbeat) {
                $heartbeat();
            }
            $metadata = $candidate['evaluation']['metadata'] ?? [];
            $movieTitle = $title !== '' ? $title : (string) ($intent['title'] ?? '');
            $target = $this->movieTarget($destination, $movieTitle, $year, $candidate);
            $payload = [
                'info_hash' => $candidate['info_hash'] ?? null,
                'url' => $candidate['url'] ?? null,
                'file_idx' => $candidate['file_idx'] ?? null,
                'filename' => $candidate['filename'] ?? null,
                'title' => $candidate['title'] ?? null,
                'sources' => $candidate['sources'] ?? [],
                'size' => $candidate['size'] ?? null,
                'addon' => $candidate['addon'] ?? null,
                'path' => $target['path'],
                'override_filename' => $target['filename'],
                'media_title' => trim($movieTitle . ($year ? ' (' . $year . ')' : '')),
                'automation_intent_id' => $id,
                'automation_media_key' => $mediaKey,
                'automation' => [
                    'intent_id' => $id,
                    'media_key' => $mediaKey,
                    'tmdb_id' => $tmdbId,
                    'imdb_id' => $imdbId,
                ],
            ];

            $download = $this->downloader->addStream($payload, $heartbeat);
            if (!empty($download['success'])) {
                $this->store->updateIntent($id, [
                    'status' => 'queued',
                    'download_id' => $download['download_id'] ?? null,
                    'info_hash' => $candidate['info_hash'] ?? null,
                    'file_idx' => isset($download['file_index']) && $download['file_index'] !== null ? (int) $download['file_index'] : ($candidate['file_idx'] ?? null),
                    'selected_source' => $candidate['addon'] ?? null,
                    'score' => $candidate['score'] ?? null,
                    'release_explanation' => $candidate['explanation'] ?? null,
                    'next_attempt_at' => null,
                    'error_message' => null,
                ]);

                return [
                    'status' => 'queued',
                    'intent_id' => $id,
                    'download_id' => $download['download_id'] ?? null,
                    'addon' => $candidate['addon'] ?? null,
                    'score' => $candidate['score'] ?? null,
                    'metadata' => $metadata,
                ];
            }

            $errors[] = (string) ($download['message'] ?? 'Unknown Alldebrid error.');
        }

        return $this->scheduleRetry($intent, 'error', 'All release candidates failed: ' . implode(' | ', array_slice($errors, 0, 3)));
    }

    public function previewIntent(array $intent): array
    {
        $mediaType = (string) ($intent['media_type'] ?? 'movie');
        if (in_array($mediaType, ['series', 'anime'], true)) {
            return $this->previewSeriesIntent($intent);
        }
        if ($mediaType !== 'movie') {
            return ['status' => 'skipped', 'reason' => 'unsupported_media_type', 'candidates' => [], 'rejected' => []];
        }

        return $this->previewMovieIntent($intent);
    }

    private function previewMovieIntent(array $intent): array
    {
        $identity = $this->identityResolver->resolve($intent);
        if (empty($identity['success'])) {
            $reason = (string) ($identity['reason'] ?? 'identity_unresolved');

            return [
                'status' => 'no_source',
                'reason' => 'Identity: ' . $reason,
                'identity' => $identity,
                'candidates' => [],
                'rejected' => [],
                'rejection_summary' => [],
                'selected' => null,
                'streams_count' => 0,
            ];
        }

        $tmdbId = !empty($identity['tmdb_id']) ? (int) $identity['tmdb_id'] : null;
        $imdbId = trim((string) ($identity['imdb_id'] ?? ''));
        $title = trim((string) ($identity['title'] ?? $intent['title'] ?? ''));
        $year = !empty($identity['year']) ? (int) $identity['year'] : (!empty($intent['year']) ? (int) $intent['year'] : null);
        $mediaKey = $this->mediaKey($tmdbId, $imdbId, $title, $year);

        if (!preg_match('/^tt\d+$/', $imdbId)) {
            return [
                'status' => 'no_source',
                'reason' => 'No IMDb ID available for release lookup.',
                'identity' => $identity,
                'media_key' => $mediaKey,
                'candidates' => [],
                'rejected' => [],
                'rejection_summary' => [],
                'selected' => null,
                'streams_count' => 0,
            ];
        }

        $result = $this->streams->getStreams('movie', $imdbId);
        $streams = is_array($result['streams'] ?? null) ? $result['streams'] : [];
        if (empty($streams)) {
            $errors = is_array($result['errors'] ?? null) ? $result['errors'] : [];

            return [
                'status' => 'no_source',
                'reason' => empty($errors) ? 'No stream returned by the configured addons.' : 'No usable stream returned by the configured addons.',
                'identity' => $identity,
                'media_key' => $mediaKey,
                'candidates' => [],
                'rejected' => [],
                'rejection_summary' => [],
                'selected' => null,
                'streams_count' => 0,
            ];
        }

        $ranked = $this->preferences->rank($streams);
        $accepted = array_map(fn ($candidate) => $this->previewCandidate($candidate), array_slice($ranked['accepted'], 0, 8));
        $rejected = array_map(fn ($candidate) => $this->previewCandidate($candidate), array_slice($ranked['rejected'], 0, 6));
        $rejectionSummary = [];
        foreach ($ranked['rejected'] as $candidate) {
            foreach (($candidate['evaluation']['failures'] ?? []) as $failure) {
                $rejectionSummary[$failure] = ($rejectionSummary[$failure] ?? 0) + 1;
            }
        }
        arsort($rejectionSummary);
        $selected = $accepted[0] ?? null;

        return [
            'status' => $selected !== null ? 'ok' : 'no_source',
            'reason' => $selected !== null ? null : 'No stream passed the quality profile.',
            'identity' => $identity,
            'media_key' => $mediaKey,
            'candidates' => $accepted,
            'rejected' => $rejected,
            'rejection_summary' => $rejectionSummary,
            'selected' => $selected,
            'streams_count' => count($streams),
        ];
    }

    public function previewManualSelection(array $intent, int $limit = 80): array
    {
        if (in_array((string) ($intent['media_type'] ?? 'movie'), ['series', 'anime'], true)) {
            return $this->previewSeriesManualSelection($intent, $limit);
        }

        $identity = $this->identityResolver->resolve($intent);
        if (empty($identity['success'])) {
            return [
                'success' => false,
                'message' => 'Identity: ' . (string) ($identity['reason'] ?? 'identity_unresolved'),
                'identity' => $identity,
                'candidates' => [],
                'streams_count' => 0,
            ];
        }

        $tmdbId = !empty($identity['tmdb_id']) ? (int) $identity['tmdb_id'] : null;
        $imdbId = trim((string) ($identity['imdb_id'] ?? ''));
        $title = trim((string) ($identity['title'] ?? $intent['title'] ?? ''));
        $year = !empty($identity['year']) ? (int) $identity['year'] : (!empty($intent['year']) ? (int) $intent['year'] : null);

        if (!preg_match('/^tt\d+$/', $imdbId)) {
            return [
                'success' => false,
                'message' => 'No IMDb ID available for release lookup.',
                'identity' => $identity,
                'candidates' => [],
                'streams_count' => 0,
            ];
        }

        $result = $this->streams->getStreams('movie', $imdbId);
        $streams = is_array($result['streams'] ?? null) ? $result['streams'] : [];
        if (empty($streams)) {
            return [
                'success' => false,
                'message' => 'No stream returned by the configured addons.',
                'identity' => $identity,
                'candidates' => [],
                'streams_count' => 0,
            ];
        }

        $ranked = $this->preferences->rank($streams);
        $candidates = [];
        foreach ($ranked['accepted'] as $candidate) {
            $candidates[] = $this->manualCandidate($candidate);
        }
        foreach ($ranked['rejected'] as $candidate) {
            $candidates[] = $this->manualCandidate($candidate);
        }

        return [
            'success' => true,
            'message' => null,
            'identity' => $identity,
            'media_key' => $this->mediaKey($tmdbId, $imdbId, $title, $year),
            'candidates' => array_slice($candidates, 0, max(1, min(200, $limit))),
            'streams_count' => count($streams),
        ];
    }

    public function queueManualCandidate(array $intent, array $candidate): array
    {
        if (in_array((string) ($intent['media_type'] ?? 'movie'), ['series', 'anime'], true)) {
            return $this->queueSeriesManualCandidate($intent, $candidate);
        }

        $infoHash = strtolower(trim((string) ($candidate['info_hash'] ?? '')));
        $url = trim((string) ($candidate['url'] ?? ''));
        if (!preg_match('/^[a-f0-9]{40}$/', $infoHash) && !preg_match('#^https?://#i', $url)) {
            return ['success' => false, 'message' => 'Invalid candidate: missing torrent hash or direct URL.'];
        }

        $identity = $this->identityResolver->resolve($intent);
        $tmdbId = !empty($identity['tmdb_id']) ? (int) $identity['tmdb_id'] : (!empty($intent['tmdb_id']) ? (int) $intent['tmdb_id'] : null);
        $imdbId = trim((string) ($identity['imdb_id'] ?? $intent['imdb_id'] ?? ''));
        $title = trim((string) ($identity['title'] ?? $intent['title'] ?? ''));
        $year = !empty($identity['year']) ? (int) $identity['year'] : (!empty($intent['year']) ? (int) $intent['year'] : null);
        $mediaKey = $this->mediaKey($tmdbId, $imdbId, $title, $year);

        $destination = $this->movieDestination();
        if ($destination === '') {
            return ['success' => false, 'message' => 'No movie destination path configured.'];
        }

        $target = $this->movieTarget($destination, $title, $year, $candidate);
        $payload = [
            'info_hash' => $infoHash !== '' ? $infoHash : null,
            'url' => $url !== '' ? $url : null,
            'file_idx' => isset($candidate['file_idx']) && is_numeric($candidate['file_idx']) ? (int) $candidate['file_idx'] : null,
            'filename' => $candidate['filename'] ?? null,
            'title' => $candidate['title'] ?? null,
            'sources' => is_array($candidate['sources'] ?? null) ? $candidate['sources'] : [],
            'size' => $candidate['size'] ?? null,
            'addon' => $candidate['addon'] ?? null,
            'cached' => (bool) ($candidate['metadata']['cached'] ?? $candidate['cached'] ?? false),
            'path' => $target['path'],
            'override_filename' => $target['filename'],
            'media_title' => trim($title . ($year ? ' (' . $year . ')' : '')),
            'automation_intent_id' => (int) $intent['id'],
            'automation_media_key' => $mediaKey,
            'automation' => [
                'intent_id' => (int) $intent['id'],
                'media_key' => $mediaKey,
                'tmdb_id' => $tmdbId,
                'imdb_id' => $imdbId,
            ],
        ];

        $download = $this->downloader->addStream($payload);
        if (!empty($download['success'])) {
            $this->store->updateIntent((int) $intent['id'], [
                'status' => 'queued',
                'download_id' => $download['download_id'] ?? null,
                'info_hash' => $infoHash !== '' ? $infoHash : null,
                'file_idx' => isset($download['file_index']) && $download['file_index'] !== null ? (int) $download['file_index'] : ($payload['file_idx'] ?? null),
                'selected_source' => $candidate['addon'] ?? null,
                'score' => $candidate['score'] ?? null,
                'release_explanation' => $candidate['explanation'] ?? null,
                'next_attempt_at' => null,
                'error_message' => null,
            ]);
        }

        return $download;
    }

    private function manualCandidate(array $candidate): array
    {
        $metadata = $candidate['evaluation']['metadata'] ?? [];

        return [
            'addon' => $candidate['addon'] ?? null,
            'addon_id' => $candidate['addon_id'] ?? null,
            'kind' => $candidate['kind'] ?? null,
            'info_hash' => $candidate['info_hash'] ?? null,
            'url' => $candidate['url'] ?? null,
            'file_idx' => $candidate['file_idx'] ?? null,
            'filename' => $candidate['filename'] ?? null,
            'title' => $candidate['title'] ?? null,
            'sources' => $candidate['sources'] ?? [],
            'size' => $candidate['size'] ?? null,
            'seeders' => $candidate['seeders'] ?? ($metadata['seeders'] ?? null),
            'priority' => $candidate['priority'] ?? 100,
            'score' => $candidate['score'] ?? 0,
            'accepted' => !empty($candidate['evaluation']['accepted']),
            'failures' => $candidate['evaluation']['failures'] ?? [],
            'explanation' => $candidate['explanation'] ?? '',
            'metadata' => [
                'resolution' => $metadata['resolution'] ?? null,
                'codec' => $metadata['codec'] ?? null,
                'hdr' => $metadata['hdr'] ?? null,
                'audio_langs' => $metadata['audio_langs'] ?? [],
                'source_type' => $metadata['source_type'] ?? null,
                'release_group' => $metadata['release_group'] ?? null,
                'size_gb' => $metadata['size_gb'] ?? null,
                'seeders' => $metadata['seeders'] ?? null,
                'cached' => $metadata['cached'] ?? null,
            ],
        ];
    }

    private function previewCandidate(array $candidate): array
    {
        $metadata = $candidate['evaluation']['metadata'] ?? [];
        $evaluation = $candidate['evaluation'] ?? [];

        return [
            'addon' => $candidate['addon'] ?? null,
            'addon_id' => $candidate['addon_id'] ?? null,
            'kind' => $candidate['kind'] ?? null,
            'info_hash' => $candidate['info_hash'] ?? null,
            'file_idx' => $candidate['file_idx'] ?? null,
            'filename' => $candidate['filename'] ?? null,
            'size' => $candidate['size'] ?? null,
            'seeders' => $candidate['seeders'] ?? ($metadata['seeders'] ?? null),
            'score' => $candidate['score'] ?? 0,
            'accepted' => !empty($evaluation['accepted']),
            'failures' => $evaluation['failures'] ?? [],
            'explanation' => $candidate['explanation'] ?? '',
            'metadata' => [
                'resolution' => $metadata['resolution'] ?? null,
                'codec' => $metadata['codec'] ?? null,
                'hdr' => $metadata['hdr'] ?? null,
                'audio_langs' => $metadata['audio_langs'] ?? [],
                'source_type' => $metadata['source_type'] ?? null,
                'release_group' => $metadata['release_group'] ?? null,
                'size_gb' => $metadata['size_gb'] ?? null,
                'seeders' => $metadata['seeders'] ?? null,
                'cached' => $metadata['cached'] ?? false,
            ],
        ];
    }

    private function processSeriesIntent(array $intent, ?callable $heartbeat = null): array
    {
        $id = (int) ($intent['id'] ?? 0);
        if ($id <= 0) {
            return ['status' => 'skipped', 'reason' => 'invalid_intent'];
        }

        $attempts = (int) ($intent['attempts'] ?? 0) + 1;
        $this->store->updateIntent($id, [
            'status' => 'resolving',
            'attempts' => $attempts,
            'error_message' => null,
            'next_attempt_at' => null,
        ]);
        $intent['attempts'] = $attempts;

        $season = isset($intent['season']) ? (int) $intent['season'] : null;
        $episode = isset($intent['episode']) ? (int) $intent['episode'] : null;
        if ($season === null || $episode === null || $episode <= 0) {
            return $this->scheduleRetry($intent, 'error', 'Invalid episode intent: missing season or episode number.');
        }

        $identity = $this->seriesIdentityResolver->resolve($intent);
        if (empty($identity['success'])) {
            $reason = (string) ($identity['reason'] ?? 'identity_unresolved');
            $noSourceReasons = ['missing_identity', 'identity_not_found', 'ambiguous_identity'];
            $status = in_array($reason, $noSourceReasons, true) ? 'no_source' : 'error';

            return $this->scheduleRetry($intent, $status, 'Series identity: ' . $reason);
        }

        $tmdbId = !empty($identity['tmdb_id']) ? (int) $identity['tmdb_id'] : null;
        $imdbId = trim((string) ($identity['imdb_id'] ?? ''));
        $title = trim((string) ($identity['title'] ?? $intent['title'] ?? ''));
        $year = !empty($identity['year']) ? (int) $identity['year'] : (!empty($intent['year']) ? (int) $intent['year'] : null);
        $mediaKey = $this->seriesMediaKey($tmdbId, $imdbId, $title, $year, $season, $episode);

        $this->store->updateIntent($id, [
            'tmdb_id' => $tmdbId,
            'imdb_id' => $imdbId !== '' ? $imdbId : null,
            'title' => $title !== '' ? $title : ($intent['title'] ?? ''),
            'year' => $year,
            'media_key' => $mediaKey,
            'season' => $season,
            'episode' => $episode,
        ]);

        $coordinates = $this->seriesCoordinates($intent, $tmdbId, $imdbId, $title, $year, $season, $episode, true);
        if (empty($coordinates['success'])) {
            return $this->scheduleRetry($intent, 'no_source', (string) ($coordinates['reason'] ?? 'Series numbering unavailable.'));
        }
        $releaseSeason = (int) $coordinates['release_season'];
        $releaseEpisode = (int) $coordinates['release_episode'];
        $this->store->updateIntent($id, [
            'release_season' => $releaseSeason,
            'release_episode' => $releaseEpisode,
            'numbering_source' => $coordinates['numbering_source'],
        ]);

        $libraryPresent = $this->store->hasActiveEpisodeEntry(
            $tmdbId,
            $imdbId !== '' ? $imdbId : null,
            $this->normalizeTitle($title),
            $year,
            $releaseSeason,
            $releaseEpisode,
            true
        );
        if (!$libraryPresent && ($releaseSeason !== $season || $releaseEpisode !== $episode)) {
            $libraryPresent = $this->store->hasActiveEpisodeEntry(
                $tmdbId,
                $imdbId !== '' ? $imdbId : null,
                $this->normalizeTitle($title),
                $year,
                $season,
                $episode,
                true
            );
        }
        if ($libraryPresent) {
            $this->store->updateIntent($id, [
                'status' => 'success',
                'next_attempt_at' => null,
                'error_message' => 'Episode already present in the library.',
            ]);

            return ['status' => 'skipped', 'reason' => 'already_present', 'intent_id' => $id];
        }

        if ($queued = $this->findQueuedDownload($id, $mediaKey, $title, $year, false)) {
            $this->store->updateIntent($id, [
                'status' => 'queued',
                'download_id' => $queued['download_id'] ?? null,
                'info_hash' => $queued['info_hash'] ?? null,
                'next_attempt_at' => null,
                'error_message' => 'Already queued.',
            ]);

            return ['status' => 'skipped', 'reason' => 'already_queued', 'intent_id' => $id];
        }

        if (!preg_match('/^tt\d+$/', $imdbId)) {
            return $this->scheduleRetry($intent, 'no_source', 'No IMDb ID available for series release lookup.');
        }

        if ($heartbeat) {
            $heartbeat();
        }
        $videoId = $imdbId . ':' . $releaseSeason . ':' . $releaseEpisode;
        $result = $this->streams->getStreams('series', $videoId);
        $streams = is_array($result['streams'] ?? null) ? $result['streams'] : [];
        if (empty($streams)) {
            $errors = is_array($result['errors'] ?? null) ? $result['errors'] : [];
            $reason = empty($errors) ? 'No stream returned by the configured addons.' : 'No usable stream returned by the configured addons.';

            return $this->scheduleRetry($intent, 'no_source', $reason);
        }

        $ranked = $this->preferences->rank($streams, null, 'series');
        $candidates = array_slice($ranked['accepted'], 0, self::MAX_CANDIDATES);
        if (empty($candidates)) {
            $failure = $ranked['rejected'][0]['evaluation']['failures'] ?? [];

            return $this->scheduleRetry(
                $intent,
                'no_source',
                'No episode stream passed the quality profile' . (!empty($failure) ? ' (' . implode(', ', $failure) . ')' : '') . '.'
            );
        }

        $destination = $this->seriesDestination((string) ($intent['media_type'] ?? 'series'));
        if ($destination === '') {
            return $this->scheduleRetry($intent, 'error', 'No series or anime destination path configured.');
        }

        $errors = [];
        foreach ($candidates as $candidate) {
            if ($heartbeat) {
                $heartbeat();
            }
            if ($this->isMultiEpisodeStream($candidate)) {
                $errors[] = 'Multi-episode release ignored.';
                continue;
            }

            $metadata = $candidate['evaluation']['metadata'] ?? [];
            $targetSeason = ($coordinates['target_mode'] ?? 'local') === 'tmdb' ? $season : $releaseSeason;
            $targetEpisode = ($coordinates['target_mode'] ?? 'local') === 'tmdb' ? $episode : $releaseEpisode;
            $target = $this->seriesTarget($destination, $title, $year, $targetSeason, $targetEpisode, $candidate);
            $payload = [
                'info_hash' => $candidate['info_hash'] ?? null,
                'url' => $candidate['url'] ?? null,
                'file_idx' => $candidate['file_idx'] ?? null,
                'filename' => $candidate['filename'] ?? null,
                'title' => $candidate['title'] ?? null,
                'sources' => $candidate['sources'] ?? [],
                'size' => $candidate['size'] ?? null,
                'addon' => $candidate['addon'] ?? null,
                'path' => $target['path'],
                'override_filename' => $target['filename'],
                'expected_filename' => $candidate['filename'] ?? $candidate['title'] ?? null,
                'season' => $season,
                'episode' => $episode,
                'release_season' => $releaseSeason,
                'release_episode' => $releaseEpisode,
                'numbering_source' => $coordinates['numbering_source'],
                'media_title' => trim($target['show_folder'] . ' ' . $this->episodeCode($targetSeason, $targetEpisode)),
                'automation_intent_id' => $id,
                'automation_media_key' => $mediaKey,
                'automation' => [
                    'intent_id' => $id,
                    'media_key' => $mediaKey,
                    'media_type' => 'series',
                    'tmdb_id' => $tmdbId,
                    'imdb_id' => $imdbId,
                    'season' => $season,
                    'episode' => $episode,
                    'release_season' => $releaseSeason,
                    'release_episode' => $releaseEpisode,
                    'numbering_source' => $coordinates['numbering_source'],
                ],
            ];

            $download = $this->downloader->addStream($payload, $heartbeat);
            if (!empty($download['success'])) {
                $this->store->updateIntent($id, [
                    'status' => 'queued',
                    'download_id' => $download['download_id'] ?? null,
                    'info_hash' => $candidate['info_hash'] ?? null,
                    'file_idx' => isset($download['file_index']) && $download['file_index'] !== null ? (int) $download['file_index'] : ($candidate['file_idx'] ?? null),
                    'selected_source' => $candidate['addon'] ?? null,
                    'score' => $candidate['score'] ?? null,
                    'release_explanation' => $candidate['explanation'] ?? null,
                    'next_attempt_at' => null,
                    'error_message' => null,
                ]);

                return [
                    'status' => 'queued',
                    'intent_id' => $id,
                    'download_id' => $download['download_id'] ?? null,
                    'addon' => $candidate['addon'] ?? null,
                    'score' => $candidate['score'] ?? null,
                    'metadata' => $metadata,
                    'season' => $season,
                    'episode' => $episode,
                ];
            }

            $errors[] = (string) ($download['message'] ?? 'Unknown Alldebrid error.');
        }

        if (empty($errors)) {
            return $this->scheduleRetry($intent, 'no_source', 'No single-episode stream passed the quality profile.');
        }

        return $this->scheduleRetry($intent, 'error', 'All episode candidates failed: ' . implode(' | ', array_slice($errors, 0, 3)));
    }

    private function previewSeriesIntent(array $intent): array
    {
        $season = isset($intent['season']) ? (int) $intent['season'] : null;
        $episode = isset($intent['episode']) ? (int) $intent['episode'] : null;
        $identity = $this->seriesIdentityResolver->resolve($intent);
        if (empty($identity['success'])) {
            return [
                'status' => 'no_source',
                'reason' => 'Series identity: ' . (string) ($identity['reason'] ?? 'identity_unresolved'),
                'identity' => $identity,
                'candidates' => [],
                'rejected' => [],
                'rejection_summary' => [],
                'selected' => null,
                'streams_count' => 0,
            ];
        }
        if ($season === null || $episode === null || $episode <= 0) {
            return [
                'status' => 'no_source',
                'reason' => 'Invalid episode intent: missing season or episode number.',
                'identity' => $identity,
                'candidates' => [],
                'rejected' => [],
                'rejection_summary' => [],
                'selected' => null,
                'streams_count' => 0,
            ];
        }

        $tmdbId = !empty($identity['tmdb_id']) ? (int) $identity['tmdb_id'] : null;
        $imdbId = trim((string) ($identity['imdb_id'] ?? ''));
        $title = trim((string) ($identity['title'] ?? $intent['title'] ?? ''));
        $year = !empty($identity['year']) ? (int) $identity['year'] : (!empty($intent['year']) ? (int) $intent['year'] : null);
        $mediaKey = $this->seriesMediaKey($tmdbId, $imdbId, $title, $year, $season, $episode);
        $coordinates = $this->seriesCoordinates($intent, $tmdbId, $imdbId, $title, $year, $season, $episode, true);
        if (empty($coordinates['success'])) {
            return [
                'status' => 'no_source',
                'reason' => (string) ($coordinates['reason'] ?? 'Series numbering unavailable.'),
                'identity' => $identity,
                'media_key' => $mediaKey,
                'candidates' => [],
                'rejected' => [],
                'rejection_summary' => [],
                'selected' => null,
                'streams_count' => 0,
            ];
        }
        $releaseSeason = (int) $coordinates['release_season'];
        $releaseEpisode = (int) $coordinates['release_episode'];

        if (!preg_match('/^tt\d+$/', $imdbId)) {
            return [
                'status' => 'no_source',
                'reason' => 'No IMDb ID available for series release lookup.',
                'identity' => $identity,
                'media_key' => $mediaKey,
                'candidates' => [],
                'rejected' => [],
                'rejection_summary' => [],
                'selected' => null,
                'streams_count' => 0,
            ];
        }

        $result = $this->streams->getStreams('series', $imdbId . ':' . $releaseSeason . ':' . $releaseEpisode);
        $streams = is_array($result['streams'] ?? null) ? $result['streams'] : [];
        if (empty($streams)) {
            $errors = is_array($result['errors'] ?? null) ? $result['errors'] : [];

            return [
                'status' => 'no_source',
                'reason' => empty($errors) ? 'No stream returned by the configured addons.' : 'No usable stream returned by the configured addons.',
                'identity' => $identity,
                'media_key' => $mediaKey,
                'candidates' => [],
                'rejected' => [],
                'rejection_summary' => [],
                'selected' => null,
                'streams_count' => 0,
            ];
        }

        $ranked = $this->preferences->rank($streams, null, 'series');
        $accepted = array_map(fn ($candidate) => $this->previewCandidate($candidate), array_slice($ranked['accepted'], 0, 8));
        $rejected = array_map(fn ($candidate) => $this->previewCandidate($candidate), array_slice($ranked['rejected'], 0, 6));
        $rejectionSummary = [];
        foreach ($ranked['rejected'] as $candidate) {
            foreach (($candidate['evaluation']['failures'] ?? []) as $failure) {
                $rejectionSummary[$failure] = ($rejectionSummary[$failure] ?? 0) + 1;
            }
        }
        arsort($rejectionSummary);
        $selected = $accepted[0] ?? null;

        return [
            'status' => $selected !== null ? 'ok' : 'no_source',
            'reason' => $selected !== null ? null : 'No episode stream passed the quality profile.',
            'identity' => $identity,
            'media_key' => $mediaKey,
            'season' => $season,
            'episode' => $episode,
            'release_season' => $releaseSeason,
            'release_episode' => $releaseEpisode,
            'candidates' => $accepted,
            'rejected' => $rejected,
            'rejection_summary' => $rejectionSummary,
            'selected' => $selected,
            'streams_count' => count($streams),
        ];
    }

    private function previewSeriesManualSelection(array $intent, int $limit = 80): array
    {
        $season = isset($intent['season']) ? (int) $intent['season'] : null;
        $episode = isset($intent['episode']) ? (int) $intent['episode'] : null;
        if ($season === null || $episode === null || $episode <= 0) {
            return [
                'success' => false,
                'message' => 'Invalid episode intent: missing season or episode number.',
                'identity' => [],
                'candidates' => [],
                'streams_count' => 0,
            ];
        }

        $identity = $this->seriesIdentityResolver->resolve($intent);
        if (empty($identity['success'])) {
            return [
                'success' => false,
                'message' => 'Series identity: ' . (string) ($identity['reason'] ?? 'identity_unresolved'),
                'identity' => $identity,
                'candidates' => [],
                'streams_count' => 0,
            ];
        }

        $tmdbId = !empty($identity['tmdb_id']) ? (int) $identity['tmdb_id'] : null;
        $imdbId = trim((string) ($identity['imdb_id'] ?? ''));
        $title = trim((string) ($identity['title'] ?? $intent['title'] ?? ''));
        $year = !empty($identity['year']) ? (int) $identity['year'] : (!empty($intent['year']) ? (int) $intent['year'] : null);
        $coordinates = $this->seriesCoordinates($intent, (int) $tmdbId, $imdbId, $title, $year, $season, $episode, true);
        if (empty($coordinates['success'])) {
            return [
                'success' => false,
                'message' => (string) ($coordinates['reason'] ?? 'Series numbering unavailable.'),
                'identity' => $identity,
                'candidates' => [],
                'streams_count' => 0,
            ];
        }
        $releaseSeason = (int) $coordinates['release_season'];
        $releaseEpisode = (int) $coordinates['release_episode'];

        if (!preg_match('/^tt\d+$/', $imdbId)) {
            return [
                'success' => false,
                'message' => 'No IMDb ID available for series release lookup.',
                'identity' => $identity,
                'candidates' => [],
                'streams_count' => 0,
            ];
        }

        $result = $this->streams->getStreams('series', $imdbId . ':' . $releaseSeason . ':' . $releaseEpisode);
        $streams = is_array($result['streams'] ?? null) ? $result['streams'] : [];
        if (empty($streams)) {
            return [
                'success' => false,
                'message' => 'No stream returned by the configured addons.',
                'identity' => $identity,
                'candidates' => [],
                'streams_count' => 0,
            ];
        }

        $ranked = $this->preferences->rank($streams, null, 'series');
        $candidates = [];
        foreach ($ranked['accepted'] as $candidate) {
            $candidates[] = $this->manualCandidate($candidate);
        }
        foreach ($ranked['rejected'] as $candidate) {
            $candidates[] = $this->manualCandidate($candidate);
        }

        return [
            'success' => true,
            'message' => null,
            'identity' => $identity,
            'media_key' => $this->seriesMediaKey($tmdbId, $imdbId, $title, $year, $season, $episode),
            'season' => $season,
            'episode' => $episode,
            'release_season' => $releaseSeason,
            'release_episode' => $releaseEpisode,
            'candidates' => array_slice($candidates, 0, max(1, min(200, $limit))),
            'streams_count' => count($streams),
        ];
    }

    private function queueSeriesManualCandidate(array $intent, array $candidate): array
    {
        $season = isset($intent['season']) ? (int) $intent['season'] : null;
        $episode = isset($intent['episode']) ? (int) $intent['episode'] : null;
        if ($season === null || $episode === null || $episode <= 0) {
            return ['success' => false, 'message' => 'Invalid episode intent: missing season or episode number.'];
        }

        $infoHash = strtolower(trim((string) ($candidate['info_hash'] ?? '')));
        $url = trim((string) ($candidate['url'] ?? ''));
        if (!preg_match('/^[a-f0-9]{40}$/', $infoHash) && !preg_match('#^https?://#i', $url)) {
            return ['success' => false, 'message' => 'Invalid candidate: missing torrent hash or direct URL.'];
        }
        if ($this->isMultiEpisodeStream($candidate)) {
            return ['success' => false, 'message' => 'Multi-episode releases cannot be queued for a single episode.'];
        }

        $identity = $this->seriesIdentityResolver->resolve($intent);
        $tmdbId = !empty($identity['tmdb_id']) ? (int) $identity['tmdb_id'] : (!empty($intent['tmdb_id']) ? (int) $intent['tmdb_id'] : null);
        $imdbId = trim((string) ($identity['imdb_id'] ?? $intent['imdb_id'] ?? ''));
        $title = trim((string) ($identity['title'] ?? $intent['title'] ?? ''));
        $year = !empty($identity['year']) ? (int) $identity['year'] : (!empty($intent['year']) ? (int) $intent['year'] : null);
        $mediaKey = $this->seriesMediaKey($tmdbId, $imdbId, $title, $year, $season, $episode);
        $coordinates = $this->seriesCoordinates($intent, (int) $tmdbId, $imdbId, $title, $year, $season, $episode, true);
        if (empty($coordinates['success'])) {
            return ['success' => false, 'message' => (string) ($coordinates['reason'] ?? 'Series numbering unavailable.')];
        }
        $releaseSeason = (int) $coordinates['release_season'];
        $releaseEpisode = (int) $coordinates['release_episode'];

        $destination = $this->seriesDestination((string) ($intent['media_type'] ?? 'series'));
        if ($destination === '') {
            return ['success' => false, 'message' => 'No series or anime destination path configured.'];
        }

        $targetSeason = ($coordinates['target_mode'] ?? 'local') === 'tmdb' ? $season : $releaseSeason;
        $targetEpisode = ($coordinates['target_mode'] ?? 'local') === 'tmdb' ? $episode : $releaseEpisode;
        $target = $this->seriesTarget($destination, $title, $year, $targetSeason, $targetEpisode, $candidate);
        $payload = [
            'info_hash' => $infoHash !== '' ? $infoHash : null,
            'url' => $url !== '' ? $url : null,
            'file_idx' => isset($candidate['file_idx']) && is_numeric($candidate['file_idx']) ? (int) $candidate['file_idx'] : null,
            'filename' => $candidate['filename'] ?? null,
            'title' => $candidate['title'] ?? null,
            'sources' => is_array($candidate['sources'] ?? null) ? $candidate['sources'] : [],
            'size' => $candidate['size'] ?? null,
            'addon' => $candidate['addon'] ?? null,
            'cached' => (bool) ($candidate['metadata']['cached'] ?? $candidate['cached'] ?? false),
            'path' => $target['path'],
            'override_filename' => $target['filename'],
            'expected_filename' => $candidate['filename'] ?? $candidate['title'] ?? null,
            'season' => $season,
            'episode' => $episode,
            'release_season' => $releaseSeason,
            'release_episode' => $releaseEpisode,
            'numbering_source' => $coordinates['numbering_source'],
            'media_title' => trim($target['show_folder'] . ' ' . $this->episodeCode($targetSeason, $targetEpisode)),
            'automation_intent_id' => (int) $intent['id'],
            'automation_media_key' => $mediaKey,
            'automation' => [
                'intent_id' => (int) $intent['id'],
                'media_key' => $mediaKey,
                'media_type' => 'series',
                'tmdb_id' => $tmdbId,
                'imdb_id' => $imdbId,
                'season' => $season,
                'episode' => $episode,
                'release_season' => $releaseSeason,
                'release_episode' => $releaseEpisode,
                'numbering_source' => $coordinates['numbering_source'],
            ],
        ];

        $download = $this->downloader->addStream($payload);
        if (!empty($download['success'])) {
            $this->store->updateIntent((int) $intent['id'], [
                'status' => 'queued',
                'download_id' => $download['download_id'] ?? null,
                'info_hash' => $infoHash !== '' ? $infoHash : null,
                'file_idx' => isset($download['file_index']) && $download['file_index'] !== null ? (int) $download['file_index'] : ($payload['file_idx'] ?? null),
                'selected_source' => $candidate['addon'] ?? null,
                'score' => $candidate['score'] ?? null,
                'release_explanation' => $candidate['explanation'] ?? null,
                'next_attempt_at' => null,
                'error_message' => null,
            ]);
        }

        return $download;
    }

    private function seriesCoordinates(
        array $intent,
        int $tmdbId,
        string $imdbId,
        string $title,
        ?int $year,
        int $season,
        int $episode,
        bool $detectIfNeeded
    ): array {
        $profile = null;
        if ($this->numbering !== null) {
            $profile = $this->numbering->profileFor([
                'tmdb_id' => $tmdbId,
                'imdb_id' => $imdbId,
                'title' => $title,
                'year' => $year,
                'title_normalized' => $this->normalizeTitle($title),
            ], false, $detectIfNeeded);
        }

        $releaseSeason = isset($intent['release_season']) && $intent['release_season'] !== null ? (int) $intent['release_season'] : null;
        $releaseEpisode = isset($intent['release_episode']) && $intent['release_episode'] !== null ? (int) $intent['release_episode'] : null;
        $numberingSource = isset($intent['numbering_source']) ? (string) $intent['numbering_source'] : null;

        if ($releaseSeason !== null && $releaseEpisode !== null) {
            return [
                'success' => true,
                'release_season' => $releaseSeason,
                'release_episode' => $releaseEpisode,
                'numbering_source' => $numberingSource,
                'target_mode' => (string) ($profile['target_mode'] ?? 'local'),
                'profile' => $profile,
            ];
        }

        if ($this->numbering === null || ($profile === null && $tmdbId <= 0)) {
            return [
                'success' => true,
                'release_season' => $season,
                'release_episode' => $episode,
                'numbering_source' => null,
                'target_mode' => 'local',
                'profile' => null,
            ];
        }

        if ($this->numbering->isUsable($profile) === false) {
            return ['success' => false, 'reason' => $profile === null ? 'Series numbering unavailable.' : 'Series numbering ambiguous, incomplete or not confirmed.'];
        }
        $acquisition = $this->numbering->acquisitionFor($profile, $season, $episode);
        if ($acquisition === null) {
            return ['success' => false, 'reason' => 'No acquisition numbering for this episode.'];
        }

        return [
            'success' => true,
            'release_season' => $acquisition['season'],
            'release_episode' => $acquisition['episode'],
            'numbering_source' => $acquisition['numbering_source'],
            'target_mode' => (string) ($profile['target_mode'] ?? 'local'),
            'profile' => $profile,
        ];
    }

    private function scheduleRetry(array $intent, string $status, string $message): array
    {
        $id = (int) $intent['id'];
        $settings = $this->settings->getIntentSettings();
        $attempts = max(1, (int) ($intent['attempts'] ?? 1));
        $maxAttempts = max(1, (int) ($settings['max_attempts'] ?? 5));
        $backoff = max(1, (int) ($settings['retry_backoff_minutes'] ?? 60));
        $minutes = min(1440, $backoff * (2 ** max(0, $attempts - 1)));
        $nextAttempt = $attempts < $maxAttempts
            ? (new \DateTimeImmutable())->modify('+' . $minutes . ' minutes')->format('Y-m-d H:i:s')
            : null;

        $this->store->updateIntent($id, [
            'status' => $status,
            'next_attempt_at' => $nextAttempt,
            'error_message' => $message,
        ]);

        return [
            'status' => $status,
            'intent_id' => $id,
            'reason' => $message,
            'retry_at' => $nextAttempt,
        ];
    }

    private function findQueuedDownload(int $intentId, string $mediaKey, string $title, ?int $year, bool $allowTitleMatch = true): ?array
    {
        $candidates = [];
        $queue = $this->storage->get('server_queue', []);
        if (is_array($queue)) {
            $candidates = array_merge($candidates, array_values(array_filter($queue, 'is_array')));
        }
        $active = $this->storage->get('active_worker_task', null);
        if (is_array($active) && !empty($active)) {
            $candidates[] = $active;
        }

        $targetTitle = $this->normalizeTitle($title);
        $targetMediaTitle = $this->normalizeTitle(trim($title . ($year ? ' (' . $year . ')' : '')));

        foreach ($candidates as $item) {
            if ((int) ($item['automation_intent_id'] ?? 0) === $intentId) {
                return $item;
            }
            if (!empty($item['automation_media_key']) && (string) $item['automation_media_key'] === $mediaKey) {
                return $item;
            }
            if (!$allowTitleMatch) {
                continue;
            }
            $mediaTitle = $this->normalizeTitle((string) ($item['media_title'] ?? ''));
            if ($mediaTitle !== '' && ($mediaTitle === $targetTitle || $mediaTitle === $targetMediaTitle)) {
                return $item;
            }
        }

        return null;
    }

    private function seriesMediaKey(?int $tmdbId, string $imdbId, string $title, ?int $year, int $season, int $episode): string
    {
        $episodeCode = 's' . str_pad((string) $season, 2, '0', STR_PAD_LEFT) . 'e' . str_pad((string) $episode, 2, '0', STR_PAD_LEFT);
        if ($tmdbId !== null && $tmdbId > 0) {
            return 'series:tmdb:' . $tmdbId . ':' . $episodeCode;
        }
        if ($imdbId !== '') {
            return 'series:imdb:' . $imdbId . ':' . $episodeCode;
        }

        return 'series:title:' . $this->normalizeTitle($title) . ':' . ($year ?? 'unknown') . ':' . $episodeCode;
    }

    private function episodeCode(int $season, int $episode): string
    {
        return 'S' . str_pad((string) $season, 2, '0', STR_PAD_LEFT)
            . 'E' . str_pad((string) $episode, 2, '0', STR_PAD_LEFT);
    }

    private function isMultiEpisodeStream(array $candidate): bool
    {
        $text = trim(implode(' ', array_filter([
            (string) ($candidate['name'] ?? ''),
            (string) ($candidate['title'] ?? ''),
            (string) ($candidate['filename'] ?? ''),
        ])));

        return preg_match('/\bS\d{1,3}E\d{1,4}(?:[._ -]?E\d{1,4})+\b/i', $text) === 1
            || preg_match('/\bS\d{1,3}E\d{1,4}\s*-\s*E?\d{1,4}\b/i', $text) === 1;
    }

    private function seriesDestination(string $mediaType = 'series'): string
    {
        $roots = $this->settings->getRoots();
        $preferred = $mediaType === 'anime' ? ['anime', 'series'] : ['series', 'anime'];
        foreach ($preferred as $key) {
            $root = $roots[$key] ?? null;
            if (is_array($root) && !empty($root['enabled']) && !empty($root['path'])) {
                return rtrim((string) $root['path'], '/\\');
            }
        }
        foreach ($roots as $key => $root) {
            if (!is_array($root) || empty($root['enabled']) || empty($root['path'])) {
                continue;
            }
            if (in_array($key, ['series', 'anime'], true) || in_array((string) ($root['type'] ?? ''), ['series', 'anime'], true)) {
                return rtrim((string) $root['path'], '/\\');
            }
        }

        return '';
    }

    private function seriesTarget(string $root, string $title, ?int $year, int $season, int $episode, array $candidate): array
    {
        $extension = strtolower((string) pathinfo((string) ($candidate['filename'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($extension, ['mkv', 'mp4', 'avi', 'm4v', 'webm', 'mov', 'ts', 'm2ts', 'mpg', 'mpeg'], true)) {
            $extension = 'mkv';
        }

        $title = $this->sanitizeName($title, 160);
        if ($title === '') {
            $title = 'Series';
        }
        $showFolder = $title . ($year ? ' (' . $year . ')' : '');
        $seasonFolder = 'Season ' . str_pad((string) $season, 2, '0', STR_PAD_LEFT);
        $filename = $this->sanitizeName($showFolder . ' - ' . $this->episodeCode($season, $episode) . '.' . $extension, 220);

        return [
            'path' => rtrim($root, '/\\') . DIRECTORY_SEPARATOR . $showFolder . DIRECTORY_SEPARATOR . $seasonFolder,
            'filename' => $filename,
            'show_folder' => $showFolder,
        ];
    }

    private function mediaKey(?int $tmdbId, string $imdbId, string $title, ?int $year): string
    {
        if ($tmdbId !== null && $tmdbId > 0) {
            return 'movie:tmdb:' . $tmdbId;
        }
        if ($imdbId !== '') {
            return 'movie:imdb:' . $imdbId;
        }
        $normalized = $this->normalizeTitle($title);

        return 'movie:title:' . $normalized . ':' . ($year ?? 'unknown');
    }

    private function movieDestination(): string
    {
        $roots = $this->settings->getRoots();
        foreach ($roots as $key => $root) {
            if (!is_array($root) || empty($root['enabled'])) {
                continue;
            }
            if (($key === 'movies' || ($root['type'] ?? '') === 'movie') && !empty($root['path'])) {
                return rtrim((string) $root['path'], '/\\');
            }
        }
        $config = $this->storage->get('config', []);

        return rtrim((string) ($config['default_path'] ?? ''), '/\\');
    }

    private function movieTarget(string $root, string $title, ?int $year, array $candidate): array
    {
        $extension = strtolower((string) pathinfo((string) ($candidate['filename'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($extension, ['mkv', 'mp4', 'avi', 'm4v', 'webm', 'mov', 'ts', 'm2ts', 'mpg', 'mpeg'], true)) {
            $extension = 'mkv';
        }

        $title = $this->sanitizeName($title, 160);
        if ($title === '') {
            $title = 'Movie';
        }
        $folder = $title . ($year ? ' (' . $year . ')' : '');
        $filename = $this->sanitizeName($folder . '.' . $extension, 220);

        return [
            'path' => rtrim($root, '/\\') . DIRECTORY_SEPARATOR . $folder,
            'filename' => $filename,
        ];
    }

    private function sanitizeName(string $name, int $maxLength): string
    {
        $name = preg_replace('/[\/\\\\]+/u', ' - ', $name) ?? $name;
        $name = preg_replace('/[<>:"|?*\x00-\x1F]+/u', ' ', $name) ?? $name;
        $name = preg_replace('/\s+/u', ' ', $name) ?? $name;
        $name = trim($name, " .\t\n\r\0\x0B");
        if (mb_strlen($name, 'UTF-8') > $maxLength) {
            $name = rtrim(mb_substr($name, 0, $maxLength, 'UTF-8'), " .");
        }

        return $name;
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
