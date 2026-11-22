<?php

namespace App\Service\MusicAutomation;

use App\Service\JsonStorage;
use App\Service\LogRotator;
use App\Service\ServerNotificationService;
use DateTimeImmutable;
use DateTimeZone;

class MusicAutomationRunner
{
    public function __construct(
        private MusicAutomationStore $store,
        private MusicAutomationScheduler $scheduler,
        private PlaylistMetadataProviderInterface $metadataProvider,
        private MusicTrackMatcher $matcher,
        private MusicLibraryIndexInterface $libraryIndex,
        private MusicQueueService $queueService,
        private JsonStorage $storage,
        private ServerNotificationService $notifications
    ) {
    }

    public function runRule(array $rule, bool $dryRun = false, bool $force = false, string $trigger = 'schedule'): array
    {
        $ruleId = (int) ($rule['id'] ?? 0);
        if ($ruleId <= 0) {
            return [
                'success' => false,
                'status' => 'error',
                'message' => 'Invalid music automation rule.',
                'found' => 0,
                'new' => 0,
                'skipped' => 0,
                'queued' => 0,
                'would_queue' => 0,
                'preview' => [],
            ];
        }

        $runId = $this->store->startRun($ruleId, $dryRun ? 'dry_run' : $trigger);

        try {
            return $this->execute($rule, $runId, $dryRun, $force);
        } catch (\Throwable $e) {
            $message = $e->getMessage() !== '' ? $e->getMessage() : 'Music automation rule failed.';
            $now = $this->now();
            $next = $this->scheduler->computeNextRun(array_merge($rule, ['last_run_at' => $now]), new DateTimeImmutable($now, new DateTimeZone('UTC')));
            $this->store->updateRule($ruleId, [
                'last_run_at' => $now,
                'next_run_at' => $next,
                'last_status' => 'error',
                'last_summary' => $message,
            ]);
            $stats = ['found' => 0, 'new' => 0, 'skipped' => 0, 'queued' => 0, 'would_queue' => 0, 'error' => $message];
            $this->store->finishRun($runId, $stats, 'error', $message);
            $this->log('Rule "' . (string) ($rule['name'] ?? 'Playlist') . '" failed: ' . $message);
            if (!$dryRun) {
                $this->notify($rule, 'error', 'Music automation failed: ' . $message);
            }

            return array_merge($stats, [
                'success' => false,
                'status' => 'error',
                'rule_id' => $ruleId,
                'run_id' => $runId,
                'message' => $message,
                'preview' => [],
            ]);
        }
    }

    private function execute(array $rule, int $runId, bool $dryRun, bool $force): array
    {
        $ruleId = (int) $rule['id'];
        $playlistId = (string) ($rule['playlist_id'] ?? '');
        $config = $this->storage->get('config', []);
        $now = $this->now();

        $metadata = $this->metadataProvider->getPlaylist($playlistId);
        if ($metadata === null) {
            throw new \RuntimeException($this->metadataProvider->getLastError() ?: 'Unable to read the Spotify playlist with the user token.');
        }

        $tracks = $this->matcher->deduplicate(is_array($metadata['tracks'] ?? null) ? $metadata['tracks'] : []);
        $found = count($tracks);
        $snapshotId = isset($metadata['snapshot_id']) && $metadata['snapshot_id'] !== ''
            ? (string) $metadata['snapshot_id']
            : null;

        if (!$dryRun && !$force && $snapshotId !== null && ($rule['last_snapshot_id'] ?? null) === $snapshotId) {
            $summary = 'Playlist unchanged since the last run (snapshot ' . substr($snapshotId, 0, 10) . ').';
            $next = $this->scheduler->computeNextRun(array_merge($rule, ['last_run_at' => $now]), new DateTimeImmutable($now, new DateTimeZone('UTC')));
            $this->store->updateRule($ruleId, [
                'last_run_at' => $now,
                'next_run_at' => $next,
                'last_status' => 'skipped',
                'last_summary' => $summary,
                'last_snapshot_id' => $snapshotId,
            ]);
            $stats = ['found' => $found, 'new' => 0, 'skipped' => $found, 'queued' => 0, 'would_queue' => 0, 'snapshot_unchanged' => true];
            $this->store->finishRun($runId, $stats, 'skipped');
            $this->log('Rule "' . (string) ($rule['name'] ?? 'Playlist') . '": ' . $summary);

            return array_merge($stats, [
                'success' => true,
                'status' => 'skipped',
                'rule_id' => $ruleId,
                'run_id' => $runId,
                'message' => $summary,
                'preview' => [],
                'snapshot_id' => $snapshotId,
            ]);
        }

        $archive = $this->matcher->archiveFromFile($config['music_archive'] ?? '');
        $library = $this->libraryIndex->getIndex(false);
        $history = $this->matcher->historyIndex($this->storage->get('history', []));
        $queuedUrls = array_fill_keys($this->queueService->queuedUrls(), true);

        $newTracks = [];
        $skipped = 0;
        foreach ($tracks as $track) {
            $url = $this->queueService->trackUrl($track);
            if (($url !== null && isset($queuedUrls[$url])) || $this->matcher->isKnown($track, $archive, $library, $history)) {
                $skipped++;
                continue;
            }
            $newTracks[] = $track;
        }

        $cap = max(1, (int) ($config['music_automation_max_per_run'] ?? 50));
        $guard = max(1, (int) ($config['music_automation_queue_guard'] ?? 20));
        $queueCount = $this->queueService->queueCount();
        $available = max(0, $guard - $queueCount);
        $batch = array_slice($newTracks, 0, min($cap, $available));
        $newCount = count($newTracks);

        if ($dryRun) {
            $preview = array_map([$this, 'previewTrack'], array_slice($batch, 0, 15));
            $summary = sprintf(
                'Dry run: %d found, %d new, %d skipped, %d would be queued.',
                $found,
                $newCount,
                $skipped,
                count($batch)
            );
            $stats = [
                'found' => $found,
                'new' => $newCount,
                'skipped' => $skipped,
                'queued' => 0,
                'would_queue' => count($batch),
                'queue_count' => $queueCount,
                'queue_guard' => $guard,
                'cap' => $cap,
                'preview' => $preview,
            ];
            $this->store->finishRun($runId, $stats, 'dry_run');
            $this->log('Rule "' . (string) ($rule['name'] ?? 'Playlist') . '": ' . $summary);

            return array_merge($stats, [
                'success' => true,
                'status' => 'dry_run',
                'rule_id' => $ruleId,
                'run_id' => $runId,
                'message' => $summary,
                'preview' => $preview,
                'snapshot_id' => $snapshotId,
            ]);
        }

        $queued = 0;
        $enqueueErrors = [];
        foreach ($batch as $track) {
            try {
                $result = $this->queueService->enqueueTrack($track, $rule, $config, $runId);
                if ($result !== null) {
                    $queued++;
                } else {
                    $enqueueErrors[] = 'Could not build a Spotify track URL for ' . (string) ($track['song_name'] ?? 'a track') . '.';
                }
            } catch (\Throwable $e) {
                $enqueueErrors[] = $e->getMessage();
            }
        }

        $status = $queued > 0 ? 'success' : 'no_changes';
        $guardNote = $available === 0 ? ' Queue guard reached, nothing was queued.' : '';
        $errorNote = $enqueueErrors !== [] ? ' ' . count($enqueueErrors) . ' track(s) could not be queued.' : '';
        $summary = sprintf(
            '%d found, %d new, %d skipped, %d queued.%s%s',
            $found,
            $newCount,
            $skipped,
            $queued,
            $guardNote,
            $errorNote
        );
        $next = $this->scheduler->computeNextRun(array_merge($rule, ['last_run_at' => $now]), new DateTimeImmutable($now, new DateTimeZone('UTC')));

        $this->store->updateRule($ruleId, [
            'last_run_at' => $now,
            'next_run_at' => $next,
            'last_status' => $status,
            'last_summary' => $summary,
            'last_snapshot_id' => $snapshotId ?? ($rule['last_snapshot_id'] ?? null),
        ]);

        $stats = [
            'found' => $found,
            'new' => $newCount,
            'skipped' => $skipped,
            'queued' => $queued,
            'would_queue' => count($batch),
            'queue_count' => $queueCount,
            'queue_guard' => $guard,
            'cap' => $cap,
            'errors' => array_slice($enqueueErrors, 0, 20),
        ];
        $this->store->finishRun($runId, $stats, $status, $enqueueErrors !== [] ? implode(' ', array_slice($enqueueErrors, 0, 5)) : null);
        $this->log('Rule "' . (string) ($rule['name'] ?? 'Playlist') . '": ' . $summary);

        if ($queued > 0 || $enqueueErrors !== []) {
            $this->notify(
                $rule,
                $enqueueErrors !== [] ? 'warning' : 'info',
                'Music automation "' . (string) ($rule['name'] ?? 'Playlist') . '": ' . $summary
            );
        }

        return array_merge($stats, [
            'success' => true,
            'status' => $status,
            'rule_id' => $ruleId,
            'run_id' => $runId,
            'message' => $summary,
            'preview' => array_map([$this, 'previewTrack'], array_slice($batch, 0, 15)),
            'snapshot_id' => $snapshotId,
            'next_run_at' => $next,
        ]);
    }

    private function previewTrack(array $track): array
    {
        return [
            'id' => (string) ($track['id'] ?? ''),
            'artist' => (string) ($track['artist'] ?? ''),
            'album' => (string) ($track['album'] ?? ''),
            'song_name' => (string) ($track['song_name'] ?? ''),
            'url' => $this->queueService->trackUrl($track),
            'duration_ms' => (int) ($track['duration_ms'] ?? 0),
        ];
    }

    private function notify(array $rule, string $type, string $message): void
    {
        $this->notifications->push($this->storage, [
            'type' => $type,
            'message' => $message,
            'action' => 'Music automation',
            'item' => (string) ($rule['name'] ?? 'Playlist'),
            'media_type' => 'music',
            'time' => date('H:i'),
        ]);
    }

    private function log(string $message): void
    {
        LogRotator::append($this->store->getLogPath(), '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n");
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }
}
