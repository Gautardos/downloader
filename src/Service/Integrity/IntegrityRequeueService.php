<?php

namespace App\Service\Integrity;

use App\Service\AlldebridService;
use App\Service\JsonStorage;
use App\Service\QueueManager;
use DateTimeImmutable;
use DateTimeZone;

class IntegrityRequeueService
{
    private IntegrityStore $store;
    private IntegritySettings $settings;
    private JsonStorage $storage;
    private QueueManager $queueManager;
    private AlldebridService $alldebrid;

    public function __construct(
        IntegrityStore $store,
        IntegritySettings $settings,
        JsonStorage $storage,
        QueueManager $queueManager,
        AlldebridService $alldebrid
    ) {
        $this->store = $store;
        $this->settings = $settings;
        $this->storage = $storage;
        $this->queueManager = $queueManager;
        $this->alldebrid = $alldebrid;
    }

    public function requeueCheck(array $check, bool $dryRun = false): array
    {
        $status = (string) ($check['status'] ?? '');
        if ($status === FileIntegrityChecker::STATUS_HEALTHY) {
            return $this->failure('File is healthy; requeue is not required.');
        }
        if ($status === FileIntegrityChecker::STATUS_UNSUPPORTED) {
            return $this->failure('Unsupported file type.');
        }
        if ($status === FileIntegrityChecker::STATUS_OUTSIDE_ROOT) {
            return $this->failure('File path is outside the allowed roots; requeue refused.');
        }

        $maxAttempts = (int) $this->settings->get('max_requeue_attempts', 3);
        $attempts = (int) ($check['attempts'] ?? 0);
        if ($maxAttempts >= 0 && $attempts >= $maxAttempts) {
            return $this->failure('Maximum requeue attempts reached (' . $attempts . '/' . $maxAttempts . ').');
        }

        $historyEntry = $this->findHistoryEntry($check);
        if ($historyEntry === null) {
            return $this->failure('Source is unknown; manual retry required.');
        }

        $source = $historyEntry['source_url'] ?? ($historyEntry['original_url'] ?? null);
        $source = is_string($source) ? trim($source) : '';
        if ($source === '') {
            return $this->failure('Source is unknown; manual retry required.');
        }
        if (preg_match('#^https?://#i', $source) !== 1) {
            return $this->failure('Source is not HTTP(S); manual retry required.');
        }

        $filename = trim((string) ($historyEntry['filename'] ?? ($check['filename'] ?? '')));
        $path = trim((string) ($historyEntry['path'] ?? ''));
        if ($filename === '' || $path === '') {
            return $this->failure('Stored destination is incomplete; manual retry required.');
        }

        $target = rtrim($path, '/\\') . DIRECTORY_SEPARATOR . $filename;
        if ($this->isAlreadyQueued($target, $source)) {
            return $this->failure('File is already queued.');
        }

        if ($dryRun) {
            return [
                'success' => true,
                'dry_run' => true,
                'message' => 'Would requeue from ' . (VideoIntegrityService::safeSource($source) ?? 'source hidden') . '.',
                'source_display' => VideoIntegrityService::safeSource($source),
            ];
        }

        $unlocked = $this->alldebrid->unlockLink($source);
        if (!is_string($unlocked) || trim($unlocked) === '') {
            return $this->failure('Alldebrid could not refresh the source link.');
        }

        $downloadId = bin2hex(random_bytes(8));
        $item = [
            'url' => $unlocked,
            'original_url' => $source,
            'filename' => $filename,
            'path' => $path,
            'download_id' => $downloadId,
            'overwrite' => true,
            'date_added' => date('Y-m-d H:i:s'),
            'type' => 'video',
        ];

        $this->queueManager->enqueue($item, 'video');
        $this->queueManager->recordHistory($item, 'requeued', 0, [
            'message' => 'Requeued after an integrity check.',
        ]);

        $attempts++;
        $extra = is_array($check['extra'] ?? null) ? $check['extra'] : [];
        $extra['last_requeue_download_id'] = $downloadId;
        $extra['last_requeue_at'] = $this->utcNow();

        $this->store->updateCheck((int) $check['id'], [
            'attempts' => $attempts,
            'last_requeued_at' => $this->utcNow(),
            'extra_json' => $extra,
        ]);

        return [
            'success' => true,
            'dry_run' => false,
            'message' => 'Requeued from ' . (VideoIntegrityService::safeSource($source) ?? 'source hidden') . '.',
            'download_id' => $downloadId,
            'attempts' => $attempts,
            'source_display' => VideoIntegrityService::safeSource($source),
        ];
    }

    public function requeueMany(array $identifiers, bool $dryRun = false, ?int $limit = null): array
    {
        $bulkLimit = $limit !== null && $limit > 0
            ? $limit
            : (int) $this->settings->get('requeue_bulk_limit', 10);
        $bulkLimit = max(1, min(200, $bulkLimit));

        $summary = [
            'requested' => 0,
            'processed' => 0,
            'succeeded' => 0,
            'failed' => 0,
            'skipped' => 0,
            'dry_run' => $dryRun,
            'results' => [],
        ];

        foreach ($identifiers as $identifier) {
            $summary['requested']++;
            if ($summary['processed'] >= $bulkLimit) {
                $summary['skipped']++;
                $summary['results'][] = [
                    'identifier' => (string) $identifier,
                    'success' => false,
                    'message' => 'Bulk limit reached (' . $bulkLimit . ').',
                ];
                continue;
            }

            $check = $this->store->findCheck((string) $identifier);
            if ($check === null) {
                $summary['failed']++;
                $summary['results'][] = [
                    'identifier' => (string) $identifier,
                    'success' => false,
                    'message' => 'Integrity check not found.',
                ];
                continue;
            }

            $summary['processed']++;
            $result = $this->requeueCheck($check, $dryRun);
            $result['identifier'] = (string) $identifier;
            $summary['results'][] = $result;

            if (!empty($result['success'])) {
                $summary['succeeded']++;
            } else {
                $summary['failed']++;
            }
        }

        return $summary;
    }

    public function requeueCandidates(bool $dryRun = false, ?int $limit = null): array
    {
        $bulkLimit = $limit !== null && $limit > 0
            ? $limit
            : (int) $this->settings->get('requeue_bulk_limit', 10);
        $bulkLimit = max(1, min(200, $bulkLimit));

        $checks = $this->store->getChecks([
            'status' => [
                FileIntegrityChecker::STATUS_INCOMPLETE,
                FileIntegrityChecker::STATUS_CORRUPTED,
                FileIntegrityChecker::STATUS_MISSING,
            ],
        ], $bulkLimit);

        $identifiers = array_map(static fn (array $check): string => (string) ($check['check_key'] ?? ''), $checks);

        return $this->requeueMany($identifiers, $dryRun, $bulkLimit);
    }

    private function findHistoryEntry(array $check): ?array
    {
        $history = $this->storage->get('history', []);
        if (!is_array($history)) {
            return null;
        }

        $downloadId = trim((string) ($check['download_id'] ?? ''));
        $target = rtrim((string) ($check['path'] ?? ''), '/\\');

        foreach (array_reverse($history) as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $entryDownloadId = trim((string) ($entry['download_id'] ?? ''));
            $entryPath = rtrim((string) ($entry['path'] ?? ''), '/\\');
            $entryFilename = trim((string) ($entry['filename'] ?? ''));
            $entryTarget = $entryPath . DIRECTORY_SEPARATOR . $entryFilename;

            if ($downloadId !== '' && $entryDownloadId === $downloadId) {
                return $entry;
            }
            if ($target !== '' && $entryTarget === $target) {
                return $entry;
            }
        }

        return null;
    }

    private function isAlreadyQueued(string $target, string $source): bool
    {
        $queue = $this->storage->get('server_queue', []);
        $active = $this->storage->get('active_worker_task', null);

        $items = is_array($queue) ? $queue : [];
        if (is_array($active)) {
            $items[] = $active;
        }

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $itemTarget = rtrim((string) ($item['path'] ?? ''), '/\\') . DIRECTORY_SEPARATOR . (string) ($item['filename'] ?? '');
            if ($itemTarget === $target) {
                return true;
            }

            $itemSource = $item['source_url'] ?? ($item['original_url'] ?? null);
            if (is_string($itemSource) && $itemSource === $source) {
                return true;
            }
        }

        return false;
    }

    private function failure(string $message): array
    {
        return ['success' => false, 'message' => $message];
    }

    private function utcNow(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }
}
