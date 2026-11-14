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
    private JsonStorage $storage;

    public function __construct(
        AutomationStore $store,
        AutomationSettings $settings,
        ReleasePreferenceService $preferences,
        ReleaseStreamProviderInterface $streams,
        ReleaseDownloaderInterface $downloader,
        MovieIdentityResolverInterface $identityResolver,
        JsonStorage $storage
    ) {
        $this->store = $store;
        $this->settings = $settings;
        $this->preferences = $preferences;
        $this->streams = $streams;
        $this->downloader = $downloader;
        $this->identityResolver = $identityResolver;
        $this->storage = $storage;
    }

    public function processIntent(array $intent, ?callable $heartbeat = null): array
    {
        $id = (int) ($intent['id'] ?? 0);
        if ($id <= 0) {
            return ['status' => 'skipped', 'reason' => 'invalid_intent'];
        }
        if (($intent['media_type'] ?? 'movie') !== 'movie') {
            return ['status' => 'skipped', 'reason' => 'series_not_enabled'];
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
        if (($intent['media_type'] ?? 'movie') !== 'movie') {
            return ['status' => 'skipped', 'reason' => 'series_not_enabled', 'candidates' => [], 'rejected' => []];
        }

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

    private function previewCandidate(array $candidate): array
    {
        $metadata = $candidate['evaluation']['metadata'] ?? [];
        $evaluation = $candidate['evaluation'] ?? [];

        return [
            'addon' => $candidate['addon'] ?? null,
            'addon_id' => $candidate['addon_id'] ?? null,
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

    private function findQueuedDownload(int $intentId, string $mediaKey, string $title, ?int $year): ?array
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
            $mediaTitle = $this->normalizeTitle((string) ($item['media_title'] ?? ''));
            if ($mediaTitle !== '' && ($mediaTitle === $targetTitle || $mediaTitle === $targetMediaTitle)) {
                return $item;
            }
        }

        return null;
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
